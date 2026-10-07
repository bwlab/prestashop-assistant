# Merchant Assistant: plugin per Claude Desktop (scheda Code)

## Context
I merchant PrestaShop non tecnici devono gestire lo shop anche "a basso livello": query e modifiche al DB, piccoli fix al tema e ai file, report ed export. Sviluppo e upgrade restano a bwlab. Il plugin li fa lavorare in autonomia. L'AI spiega, calibra il linguaggio sul livello del merchant e segue sempre il flusso **stage → ok merchant → produzione**, registrando tutto in un log.

## Decisioni prese
- **Ambiente**: Claude Desktop, scheda **Code**. È Claude Code locale con interfaccia grafica: shell e chiavi restano sul PC del merchant e i plugin sono completi (skill, comandi, hooks).
- **Niente MCP.** Prerequisiti: su Windows **Git for Windows** (bash, `ssh`, `ssh-keygen`); su Mac niente.
- **SSH**: l'utente del sito con chiave dedicata, mai root. Il merchant può leggere, scrivere e cancellare su stage e su prod: nessuna restrizione lato server.
- **Configurazione**: host e path arrivano in un **file di attivazione** preparato da bwlab per ogni cliente.
- **Distribuzione**: **zip** consegnato da bwlab. Il repo privato `bwlab/merchant-assistant` è solo per lo sviluppo.

Fatti verificati sulla doc (2026-10-07): Cowork esegue la shell nel cloud Anthropic, per questo è scartato; la chat di Desktop non ha shell; hooks e skill funzionano in Claude Code.

## Idea chiave: logica in PHP sul server, client minimale
Sul PC servono solo bash e ssh. Le operazioni delicate (SQL guard, cattura del rollback, esecuzione, lettura di `parameters.php`) stanno in **script PHP del plugin inviati via stdin**: `ssh host php -- args < tool.php`.

- Il PHP c'è sempre sul server: niente installazioni lato server e niente runtime sul PC.
- La password del DB non compare mai in una riga di comando: il PHP legge `app/config/parameters.php` sul server.
- Lo stato del gate, i token e il log restano in locale, nella cartella di lavoro.

## Struttura del plugin (zip)
```
merchant-assistant/
  .claude-plugin/plugin.json      # userConfig: cartella di lavoro (directory), file attivazione (file)
  skills/  onboarding, livello-tecnico, flusso-stage-prod, prestashop-db,
           tema-file, report-export, fuori-perimetro
  commands/ setup.md, profilo.md, report.md, log.md
  hooks/hooks.json                # PreToolUse(Bash) -> hooks/guard.sh
  scripts/
    ma                            # unico entrypoint bash: ma <comando> <env> ...
    lib.sh                        # connessione (opzioni ssh, host key pinnata), log, gate
    remote/ db_query.php db_write.php db_schema.php file_read.php
            file_patch.php cache_clear.php modules.php export.php check.php
  tests/                          # test logica pura PHP (sql guard, gate) + bash
docs/ procedura-bwlab.md, formato-attivazione.md
```

## Comandi `ma` (eseguiti da Claude tramite Bash)
| Comando | Cosa fa |
|---|---|
| `ma setup import <file>` | valida il file di attivazione e scrive `known_hosts` dedicato con le impronte pinnate |
| `ma setup keygen` | crea `~/.merchant-assistant/id_ed25519` se manca; stampa SOLO la pubblica |
| `ma check <env>` | verifica connessione, docroot, versione PS, PHP CLI, permessi |
| `ma schema/query <env> "<SELECT>"` | sola lettura, con limite di righe, output in TSV o JSON |
| `ma write <env> preview "<SQL>"` | righe reali impattate e campione; restituisce `change_id` (su prod anche il token) |
| `ma write <env> apply <change_id> [token]` | cattura il rollback, esegue, aggiorna il log |
| `ma file read/patch <env> ...` | patch con diff solo dentro `themes/` del tema figlio; limite di dimensione; copia di backup |
| `ma cache <env>` | svuota la cache PS come utente del sito |
| `ma modules <env>` | elenco e dettaglio di moduli e tabelle, per rispondere alle domande |
| `ma export <env> "<SELECT>" <nome>` | CSV nella cartella `export/` |

## Regole nel codice
- **SQL guard** (PHP, lato server):
  - una sola istruzione alla volta;
  - blocca `DROP`, `TRUNCATE`, `ALTER`, `GRANT`, `CREATE USER`;
  - blocca `UPDATE`/`DELETE` senza `WHERE`.
- **Gate prod** (`lib.sh`). `apply` su prod richiede:
  1. lo stesso `change_id` applicato con successo su stage;
  2. un preview su prod;
  3. un token monouso con scadenza a 30 minuti.
- **Stage e prod divergono** (gli ID non coincidono): il preview su prod mostra le righe reali coinvolte e il confronto con il conteggio di stage. Se lo scarto è grande, avvisa.
- **Rollback senza mysqldump**: `SELECT` delle righe coinvolte prima della scrittura, poi viene generato `rollback/<change_id>.sql`. Per i file: copia con timestamp sul server più una copia locale.
- **Hook `guard.sh`** (PreToolUse su Bash):
  - nega `ssh`, `scp`, `mysql`, `rsync` diretti verso gli host del file di attivazione: tutto deve passare da `ma`;
  - nega `ma write ... apply` su prod se il comando non contiene il token.
  - Matching con grep sul JSON, nessuna dipendenza da jq. Limite noto: è un filtro su pattern, non una sandbox. Per bloccare tutto davvero servirebbe un vincolo lato server, che è stato escluso.
- Ogni `apply` e ogni `patch` scrive la sua voce di log da sé: il log non dipende dal modello.

## Skill
- `onboarding`:
  1. verifica dei prerequisiti; su Windows, se manca Git for Windows, guida passo passo con link e screenshot;
  2. import del file di attivazione;
  3. keygen, poi invio della **sola pubblica** a bwlab, che la inserisce in `authorized_keys` su stage e prod;
  4. `ma check` su entrambi gli ambienti;
  5. scelta della **cartella di lavoro**, spiegando a cosa serve, e creazione delle sottocartelle `report/`, `export/`, `modifiche/`, `rollback/`, `log/`;
  6. avviso privacy: i dati del DB passano nella conversazione con Claude.
- `livello-tecnico`: 5-6 domande pratiche → profilo `base|intermedio|esperto` in `profilo.json`, riletto a ogni sessione e aggiornabile su richiesta.
- `flusso-stage-prod`: copione obbligatorio:
  1. capire la richiesta;
  2. preview su stage;
  3. apply su stage;
  4. far verificare al merchant;
  5. spiegare in parole semplici cosa cambierà in prod;
  6. attendere l'ok esplicito;
  7. preview su prod, poi apply con il token;
  8. svuotare la cache;
  9. riepilogo.
- `prestashop-db`: tabelle chiave per 1.7/8/9, multistore e multilingua (`_shop`, `_lang`), ricette (prezzi, stock, ordini, clienti, SEO, redirect).
- `tema-file`: cosa si può toccare (tpl/css del tema figlio, traduzioni); soglia oltre cui si apre un ticket.
- `report-export`: report tipici (vendite, prodotti senza immagini o descrizione, clienti inattivi, stock) ed export per altre applicazioni.
- `fuori-perimetro`: upgrade, sviluppo moduli, root o server portano a una bozza di ticket per bwlab.

## Log
Una voce per operazione in `<cartella>/log/AAAA-MM-GG.md`:
- ora, ambiente, richiesta del merchant;
- SQL o diff;
- righe o file toccati, percorso del rollback, esito, `change_id`.

## Installazione dallo zip
Il merchant scompatta lo zip in una cartella e lo aggiunge come marketplace locale dalla scheda Code (`/plugin marketplace add <cartella>` → install). Va verificato in Fase 1 che il flusso funzioni dalla GUI. Per aggiornare: nuovo zip, si sovrascrive la cartella, si fa update.

## Fasi
1. **Spike**: scheda Code su Windows pulito con Git for Windows. Da verificare:
   - installazione del plugin dallo zip;
   - esecuzione di hooks e script bash;
   - `ssh` verso lo stage di test.

   Stesso controllo su Mac.
2. `ma` con `setup`, `check`, `query` e gli script PHP remoti; test contro un server di stage bwlab. Il Docker locale p910 non ha SSH: si usa un container sshd o uno stage reale.
3. SQL guard, gate, rollback, `write`, log; test della logica pura scritti prima.
4. Hook `guard.sh` e skill `onboarding`, `livello-tecnico`, `flusso-stage-prod`.
5. File e tema, cache, export, report e le skill relative.
6. Procedura bwlab (generatore del file di attivazione, inserimento chiave) e pilota con un cliente.

## Verifica end-to-end
- **Windows pulito**: installazione di Git e del plugin, onboarding, keygen, connessione a stage e prod.
- **Scenario prezzo**:
  1. preview e apply su stage;
  2. il merchant verifica;
  3. preview su prod con confronto dei conteggi;
  4. ok del merchant;
  5. apply con token;
  6. rollback salvato, cache svuotata, voce di log scritta.
- **Rifiuti attesi**: apply su prod senza stage, senza token o con token scaduto; `DELETE` senza `WHERE`; `ssh` diretto bloccato dall'hook; host key diversa.
- **Rollback**: lo script ripristina davvero i dati su stage.
- **Export**: CSV nella cartella scelta e voce di log presente.
