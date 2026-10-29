# prestashop-assistant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plugin Claude Code (scheda Code di Claude Desktop) che fa lavorare un merchant PrestaShop non tecnico sul suo shop via SSH, con setup guidato e regole d'uso come guardiano.

**Architecture:** Plugin di sole skill più un hook `SessionStart` (Node, nessuna dipendenza) che inietta regole e dati dello shop letti dalla cartella di lavoro del merchant. Un secondo script Node registra la cartella di lavoro (puntatore fisso + impostazioni di Claude Code). Distribuzione: marketplace GitHub `bwlab/prestashop-assistant`, zip come riserva.

**Tech Stack:** Markdown (SKILL.md), Node.js ≥ 20 (`node:test`, `node:fs`, `node:os`, `node:path`), bash per lo script di build, `claude plugin validate`.

**Spec:** `docs/piano.md` (leggerlo per intero prima di iniziare). Fonte dei testi delle skill: `prompt-merchant.md`.

## Global Constraints

- Lingua di tutti i testi per il merchant: italiano, frasi brevi, parole semplici.
- Nessuna cartella `bin/` nel plugin (Customize › Plugins rifiuta il plugin). Script in `scripts/`.
- Nessun `userConfig`, nessun `.mcp.json`, nessun dato di uno shop dentro il plugin.
- Node.js ≥ 20, solo moduli built-in. Script compatibili Windows e Mac (`os.homedir()`, `path`, nessuna shell).
- Hook in exec form: `"command": "node", "args": ["${CLAUDE_PLUGIN_ROOT}/scripts/..."]`.
- Contesto iniettato dall'hook: massimo 10.000 caratteri (oltre, Claude Code lo tronca in un file che Claude non legge).
- Puntatore: file `~/.prestashop-assistant` (Windows `C:\Users\<nome>\.prestashop-assistant`), contiene solo il percorso assoluto della cartella di lavoro.
- File nella cartella di lavoro: `assistente.md`, `dati-accesso.md`, `ricognizione/`, `log/AAAA-MM-GG.md`, `backup/`, `report/`, `export/`.
- Mai password in comandi, file o conversazione. Mai leggere o mostrare la chiave privata.
- Ambienti: nome libero, ruolo `prova` o `produzione`. Scrittura in produzione: backup + conferma con la parola "PRODUZIONE". Shop senza ambiente di prova: scritture ammesse direttamente in produzione con le stesse regole.
- Nome plugin `prestashop-assistant`, marketplace `bwlab-prestashop-assistant`, versione iniziale `0.1.0`.
- `dist/` escluso dal repo.

## Review Focus

1. **Puntatore verso una cartella spostata o cancellata**: la sessione parte comunque, Claude dice al merchant di rilanciare il setup. → test in Task 2.
2. **`~/.claude/settings.json` già pieno o malformato**: nessuna impostazione esistente persa; JSON malformato = nessuna scrittura ed errore chiaro. → test in Task 3.
3. **Dati dello shop lunghi** (molti ambienti, `assistente.md` cresciuto): il contesto resta sotto 10.000 caratteri e le regole d'uso non vengono mai tagliate. → test in Task 2.
4. **Percorsi Windows** con spazi, backslash, OneDrive (`C:\Users\Mario Rossi\OneDrive\Documenti\Shop`), puntatore con a capo `\r\n`. → test in Task 2 e 3.
5. **Merchant con la vecchia configurazione** del prompt (alias `<slug>-stage`/`<slug>-prod`, chiave `prestashop_<slug>` o `_np`): niente doppioni né seconda chiave. → controllo in Task 5.

---

### Task 1: Scheletro del plugin, marketplace e build

**Files:**
- Create: `.claude-plugin/marketplace.json`
- Create: `plugins/prestashop-assistant/.claude-plugin/plugin.json`
- Create: `scripts/build.sh`
- Create: `.gitignore` (`dist/`)

**Interfaces:**
- Produces: struttura cartelle usata da tutti i task; `scripts/build.sh` → stampa il percorso di `dist/prestashop-assistant.zip`.

- [ ] **Step 1:** `marketplace.json`: `name` `bwlab-prestashop-assistant`, `owner.name` `bwlab`, un plugin `prestashop-assistant` con `source` `./plugins/prestashop-assistant`. Modello: `../odoo-assistant/.claude-plugin/marketplace.json`.
- [ ] **Step 2:** `plugin.json`: `name` `prestashop-assistant`, `version` `0.1.0`, `description` in italiano, `author` `{name: bwlab, email: lmassa@bwlab.it}`. Nessun altro campo.
- [ ] **Step 3:** `scripts/build.sh` sul modello di `../odoo-assistant/scripts/build-odoo.sh`: zippa il contenuto di `plugins/prestashop-assistant/` in `dist/prestashop-assistant.zip`; fallisce se esiste `plugins/prestashop-assistant/bin`, se `plugin.json` non è alla radice dello zip, se compare `user_config` o un `.mcp.json`.
- [ ] **Step 4: Verifica**
  Run: `claude plugin validate plugins/prestashop-assistant && scripts/build.sh && mkdir -p plugins/prestashop-assistant/bin && scripts/build.sh; rmdir plugins/prestashop-assistant/bin`
  Expected: validate `Validation passed`, primo build stampa il percorso dello zip, secondo build esce con errore sulla cartella `bin`.
- [ ] **Step 5: Commit** `feat: scheletro plugin prestashop-assistant, marketplace e build`

### Task 2: Script di avvio `carica-contesto.js` e hook

**Files:**
- Create: `plugins/prestashop-assistant/scripts/carica-contesto.js`
- Create: `plugins/prestashop-assistant/hooks/hooks.json`
- Test: `tests/carica-contesto.test.js`

**Interfaces:**
- Consumes: `skills/regole-uso/SKILL.md` (Task 4) letto da `pluginRoot`; nei test si usa un file finto.
- Produces:
  - `buildContext({ homeDir: string, pluginRoot: string }) -> string` (esportata, pura salvo letture da disco, non lancia mai eccezioni).
  - eseguito come main: stampa `{"hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext": <buildContext>}}` con `homeDir = os.homedir()`, `pluginRoot = process.env.CLAUDE_PLUGIN_ROOT || path.join(__dirname, '..')`; exit 0 sempre.
  - costante esportata `MAX_CHARS = 9500`.

- [ ] **Step 1: Test che falliscono** (`node:test`, cartelle temporanee con `fs.mkdtempSync`, `regole-uso/SKILL.md` finto con frontmatter e corpo `REGOLE-TEST`):
  - `senza puntatore`: risultato contiene `REGOLE-TEST`, `non è ancora configurato` e `/prestashop-assistant:setup`.
  - `puntatore valido`: puntatore con `\r\n` finale verso cartella con `assistente.md` (`LIVELLO-TEST`) e `dati-accesso.md` (`AMBIENTI-TEST`) → contiene il percorso della cartella, `LIVELLO-TEST`, `AMBIENTI-TEST`, `REGOLE-TEST`.
  - `cartella non trovata`: puntatore verso percorso inesistente → contiene `REGOLE-TEST`, il percorso e `rilancia il setup`; nessuna eccezione.
  - `percorso con spazi`: cartella `Mario Rossi/OneDrive/Documenti/Shop` → letto correttamente.
  - `contesto troppo lungo`: `dati-accesso.md` di 20.000 caratteri → `result.length <= MAX_CHARS`, contiene ancora `REGOLE-TEST` intero e la frase `contenuto troncato, leggi il file`.
  - `frontmatter tolto`: il risultato non contiene `name: regole-uso`.
- [ ] **Step 2:** Run `node --test tests/` → FAIL (`Cannot find module`).
- [ ] **Step 3: Implementa `buildContext`.** Ordine del testo: regole d'uso (corpo senza frontmatter, mai troncato) → stato della configurazione (percorso cartella di lavoro o messaggio di setup) → `assistente.md` → `dati-accesso.md`. Se si supera `MAX_CHARS`, si troncano prima `dati-accesso.md` poi `assistente.md`, aggiungendo `[contenuto troncato, leggi il file <percorso>]`. Frasi descrittive, non ordini di sistema (vedi nota hook sul prompt injection): es. "La cartella di lavoro del merchant è …".
- [ ] **Step 4:** `hooks/hooks.json`: evento `SessionStart`, matcher `startup|resume|clear|compact`, `type: command`, `command: node`, `args: ["${CLAUDE_PLUGIN_ROOT}/scripts/carica-contesto.js"]`, `timeout: 10`.
- [ ] **Step 5:** Run `node --test tests/` → PASS. Run `CLAUDE_PLUGIN_ROOT=plugins/prestashop-assistant HOME=$(mktemp -d) node plugins/prestashop-assistant/scripts/carica-contesto.js | node -e 'JSON.parse(require("fs").readFileSync(0))'` → exit 0.
- [ ] **Step 6: Commit** `feat: hook SessionStart che carica regole e dati dello shop`

### Task 3: Script `registra-cartella.js`

**Files:**
- Create: `plugins/prestashop-assistant/scripts/registra-cartella.js`
- Test: `tests/registra-cartella.test.js`

**Interfaces:**
- Produces:
  - `mergeSettings(settings: object, workdir: string) -> object` (pura, non modifica l'input).
  - `RULES = { allow: ['Bash(ssh *)', 'PowerShell(ssh *)'], ask: ['Bash(ssh * sudo *)', 'PowerShell(ssh * sudo *)'], deny: ['Read(~/.ssh/prestashop_*)', 'Edit(~/.ssh/prestashop_*)'] }` (esportata).
  - CLI: `node registra-cartella.js <percorso-assoluto-cartella>` → scrive `~/.prestashop-assistant` (solo il percorso) e aggiorna `~/.claude/settings.json`; prima di scrivere copia il file esistente in `~/.claude/settings.json.bak-prestashop-assistant`; stampa una riga in italiano con l'esito. Exit 1 con messaggio se il percorso non esiste o non è assoluto, o se `settings.json` non è JSON valido (in quel caso non scrive nulla, neanche il puntatore).
- La skill di setup (Task 5) lo invoca come `node "${CLAUDE_PLUGIN_ROOT}/scripts/registra-cartella.js" "<cartella>"`.

- [ ] **Step 1: Test che falliscono:**
  - `settings vuoto`: `mergeSettings({}, W)` → `permissions.additionalDirectories` = `[W]`, `allow`/`ask`/`deny` uguali a `RULES`.
  - `conserva il resto`: input con `model`, `hooks`, `permissions.allow: ['Bash(git *)']` → tutto ancora presente, `Bash(git *)` incluso.
  - `idempotente`: `mergeSettings(mergeSettings(s, W), W)` deep-equal a `mergeSettings(s, W)` (nessun duplicato).
  - `percorso Windows`: `W = 'C:\\Users\\Mario Rossi\\Documenti\\Shop'` → presente identico.
  - `input non modificato`: l'oggetto passato resta deep-equal alla sua copia.
  - CLI con `HOME` temporanea: `settings.json` malformato → exit 1, file invariato, puntatore assente. `settings.json` assente → creato, backup non richiesto, puntatore scritto.
- [ ] **Step 2:** Run `node --test tests/` → FAIL.
- [ ] **Step 3:** Implementa `mergeSettings` e la CLI (scrittura atomica: file temporaneo + rename).
- [ ] **Step 4:** Run `node --test tests/` → PASS.
- [ ] **Step 5: Verifica delle regole su Claude Code reale** (HOME temporanea, `claude` locale): dopo `registra-cartella.js`, chiedere a Claude in una sessione `claude --plugin-dir plugins/prestashop-assistant` di eseguire `ssh -V` (nessuna richiesta di permesso), `ssh localhost sudo -n true` (richiesta di permesso), leggere `~/.ssh/prestashop_test` (negato). Se `Bash(ssh * sudo *)` non provoca la richiesta, annotarlo nel commit e segnalarlo: non cercare regole alternative.
- [ ] **Step 6: Commit** `feat: registrazione cartella di lavoro e regole di permesso`

### Task 4: Skill `regole-uso` (il guardiano)

**Files:**
- Create: `plugins/prestashop-assistant/skills/regole-uso/SKILL.md`

**Interfaces:**
- Consumes: cartella di lavoro e contatti di supporto come descritti in `docs/piano.md` § "Dove stanno i dati".
- Produces: testo iniettato dall'hook (Task 2). Frontmatter: `name: regole-uso`, `description` "Regole da seguire per ogni operazione sullo shop PrestaShop (database, file, tema, report). Caricala prima di qualsiasi comando sui server."

- [ ] **Step 1:** Scrivi il corpo partendo da `prompt-merchant.md` §§ 2-6, con i cambi di `docs/piano.md` § "Regole d'uso (il guardiano)". In più: niente contatti bwlab scritti dentro (il supporto si legge da `assistente.md`); livelli base/intermedio/esperto dal § 3; formato log del § 5 con `<nome ambiente>` al posto di `STAGE|PROD`.
- [ ] **Step 2: Verifica**
  Run: `f=plugins/prestashop-assistant/skills/regole-uso/SKILL.md; wc -m < $f; for s in PRODUZIONE DROP TRUNCATE ALTER parameters.php assistente.md "ambiente di prova" sudo "dati personali" log/; do grep -q "$s" $f || echo "MANCA $s"; done; grep -niE "bwlab|help@|support@" $f`
  Expected: meno di 6000 caratteri, nessun `MANCA`, nessuna riga trovata dall'ultimo grep.
- [ ] **Step 3:** Run `node --test tests/` e `scripts/build.sh` → PASS (l'hook ora legge la skill vera; aggiungere un test che `buildContext` con il `pluginRoot` reale resti sotto `MAX_CHARS` con `dati-accesso.md` di 3 ambienti).
- [ ] **Step 4: Commit** `feat: skill regole-uso`

### Task 5: Skill `setup`

**Files:**
- Create: `plugins/prestashop-assistant/skills/setup/SKILL.md`

**Interfaces:**
- Consumes: `registra-cartella.js` (Task 3) via `node "${CLAUDE_PLUGIN_ROOT}/scripts/registra-cartella.js" "<cartella>"`.
- Produces: `/prestashop-assistant:setup`. Frontmatter `name: setup`, `description` che dica: primo avvio, plugin non configurato, cambio di server/indirizzi, collegamento SSH che non funziona.

- [ ] **Step 1:** Scrivi la skill seguendo `docs/piano.md` § "Setup", passi 1-12 con 1b e 4b, nell'ordine. Testi di riferimento: `prompt-merchant.md` § 1 (OpenSSH su Windows, chiave con `-N '""'`, `_np`, `~/.ssh/config` senza toccare il resto, impronta, controlli, ricognizione con `phpinfo`, cartella di lavoro) e § 3 (5 domande). Regole aggiuntive:
  - Ogni passo controlla prima cosa esiste già e lo salta se è a posto (setup ripetibile).
  - Passo 1b: alias `<slug>-stage` → ambiente `stage` ruolo `prova`; `<slug>-prod` → `prod` ruolo `produzione`; copia del vecchio `dati-accesso.md` in `backup/` prima di convertirlo.
  - Passo 4 claude-mem: prima di scrivere il passo, leggere README e sorgente dell'installer (`npx claude-mem install --help`, repo `thedotmack/claude-mem`) e trovare le opzioni per: provider = piano Claude del merchant, niente login a cmem.ai, niente cloud sync, modalità non interattiva. Se non esistono, **fermarsi e chiedere a Luigi**, non inventare.
  - Passo 4b: spiegare al merchant in parole semplici cosa cambia nelle impostazioni e chiedere il sì prima di lanciare `registra-cartella.js`.
  - Passo 8: se il merchant non conosce i dati, preparare il messaggio per il supporto con l'elenco esatto (per ambiente: nome, ruolo, host, porta, utente, cartella dello shop, URL sito e back-office, impronta del server) e fermarsi.
  - Passo 9: la chiave pubblica si mostra con `ssh-keygen -y -f <chiave>` (la lettura diretta dei file `prestashop_*` è vietata dalle regole del Task 3).
  - Un passo alla volta, aspettando il "fatto". Comandi in doppia versione Windows (PowerShell) / Mac dove differiscono.
- [ ] **Step 2: Verifica**
  Run: `f=plugins/prestashop-assistant/skills/setup/SKILL.md; for s in 'registra-cartella.js' 'CLAUDE_PLUGIN_ROOT' "-N '\"\"'" '_np' 'IdentitiesOnly' 'phpinfo' '404' 'impronta' 'claude-mem' 'scheda Code' '-stage' '-prod' 'backup/'; do grep -qF -- "$s" $f || echo "MANCA $s"; done; grep -niE "bwlab|help@|support@" $f`
  Expected: nessun `MANCA`, nessuna riga dall'ultimo grep.
- [ ] **Step 3:** Run `claude plugin validate plugins/prestashop-assistant` → `Validation passed`.
- [ ] **Step 4: Commit** `feat: skill setup con migrazione dalla configurazione del prompt`

### Task 6: README per l'installazione e prova completa

**Files:**
- Create: `README.md`

- [ ] **Step 1:** `README.md` breve in italiano: cos'è, requisiti (Claude Desktop scheda Code, Windows 11 o Mac), installazione principale (Customize › Plugins › Add › Add marketplace › `bwlab/prestashop-assistant`, poi installa), riserva (Upload plugin con lo zip), primo avvio (`/prestashop-assistant:setup` nella scheda Code), cosa non fa (sviluppo, moduli, upgrade, server). Nessun contatto bwlab oltre all'autore.
- [ ] **Step 2: Prova end-to-end su Linux** con `claude --plugin-dir plugins/prestashop-assistant` e HOME temporanea, contro lo stage di un PrestaShop locale in Docker o un server di prova indicato da Luigi: setup completo fino alla ricognizione; nuova sessione da un'altra cartella → Claude conosce ambienti e cartella di lavoro; richiesta di UPDATE su ambiente `produzione` → Claude chiede conferma con "PRODUZIONE" e fa il backup. Annotare l'esito nel messaggio di commit.
- [ ] **Step 3: Checklist per Luigi su Windows 11 pulito** (in fondo al README, sezione "Collaudo"): installazione da marketplace; zip accettato da Upload plugin; hook attivo nella scheda Code (Claude conosce la cartella di lavoro aprendo un'altra cartella); `ssh` senza richiesta di permesso; claude-mem installato senza login cmem.ai.
- [ ] **Step 4: Commit** `docs: README di installazione e collaudo`
