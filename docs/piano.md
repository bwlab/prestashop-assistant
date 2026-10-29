# prestashop-assistant — progetto

Aggiornato il 29/10/2026. Sostituisce il piano del 07/10/2026, che si basava sul client `ma` ed è stato abbandonato.

## Perché

I merchant PrestaShop non tecnici devono poter gestire lo shop anche "a basso livello" senza chiamare l'agenzia per ogni cosa: interrogare e correggere il database, fare piccoli ritocchi al tema, estrarre dati e report. Oggi questo si fa con `prompt-merchant.md`, un prompt che il merchant incolla per generare un `CLAUDE.md`/`AGENTS.md` locale. Il prompt funziona ma va copiato a mano, non si aggiorna e va riadattato per ogni shop.

Il plugin sostituisce il prompt: si installa una volta, si aggiorna da solo, è uguale per tutti i merchant. Le istruzioni stanno nel plugin; i dati del singolo shop stanno sul computer del merchant.

## Obiettivi

- Un plugin Claude installabile da Claude Desktop dal marketplace GitHub `bwlab/prestashop-assistant` (aggiornamenti automatici); in riserva lo zip in Customize › Plugins › Upload plugin.
- Usato nella scheda **Code** di Claude Desktop, su Windows 11 (caso principale) e Mac.
- Claude lavora sullo shop via **SSH diretto** e scrive i comandi da solo.
- Il setup guidato porta un merchant non tecnico da zero a collegato, senza che debba scrivere comandi.
- Il plugin funziona qualunque cartella il merchant abbia aperto nella scheda Code.
- Memoria tra le sessioni con claude-mem.

## Non obiettivi

- Nessun connettore MCP, nessun programma da installare sui server dello shop.
- Nessun client intermedio tipo `ma`: il guardiano è la skill di regole d'uso, non un programma.
- Nessun supporto alla chat normale di Desktop né a Cowork: SSH richiede che i comandi girino sul computer del merchant. Se il plugin viene usato lì, Claude spiega che serve la scheda Code.
- Più shop sullo stesso computer: non è il caso tipico, non va gestito.
- Sviluppo, upgrade, installazione di moduli, interventi sul server: restano a chi supporta il merchant.

## Anti-obiettivi (sarebbe un fallimento anche se funziona)

- La chiave privata SSH lascia il computer del merchant, o Claude la legge o la mostra.
- Una password (database, back-office) compare in una riga di comando, in un file o in conversazione.
- Dati dei clienti dello shop finiscono a servizi terzi oltre ad Anthropic (per esempio il cloud di claude-mem).
- Una scrittura in produzione senza backup o senza l'ok esplicito del merchant.
- Dati di uno shop scritti dentro il plugin.

## Vincoli verificati (doc ufficiali, ottobre 2026)

- Un plugin con una cartella `bin/` alla radice viene rifiutato da Customize › Plugins. Gli script vanno in un'altra cartella (es. `scripts/`).
- `userConfig` e `${user_config.*}` funzionano solo in Claude Code; qui non servono.
- Un plugin caricato da zip non installa le dipendenze dichiarate: claude-mem lo installa il setup.
- Gli hook dei plugin girano in Claude Code (scheda Code compresa). Su Windows senza Git Bash la shell predefinita degli hook è PowerShell.
- Su Windows 11 il client OpenSSH è incluso; `ssh-keygen` con passphrase vuota da PowerShell 5.1 richiede `-N '""'`.

## Decisioni prese con Luigi

1. **Due repo separati**: `bwlab/odoo-assistant` e `bwlab/prestashop-assistant`, entrambi pubblici, nella cartella locale `merchant-assistant/`.
2. **SSH diretto**, Claude scrive i comandi. La skill di regole d'uso fa da guardiano.
3. **claude-mem installato dal setup**, configurato per usare solo il piano Claude del merchant, senza login a cmem.ai e senza sincronizzazione cloud.
4. **Dati di accesso**: il merchant li conosce oppure li chiede a chi lo supporta. Il setup prepara il messaggio con l'elenco di cosa serve.
5. **Cartella di lavoro scelta dal merchant** al setup (proposta: `Documenti\Shop-<nome>`). Il plugin la ritrova da qualunque cartella aperta.
6. **Uno shop, più ambienti**: elenco libero di ambienti dello stesso shop, ognuno con nome libero e ruolo `prova` o `produzione`.
7. **Contatti di supporto impostati dal merchant** al setup. Nel plugin non c'è nessun contatto: bwlab è solo l'autore.
8. **Shop senza ambiente di prova**: le scritture sono ammesse direttamente in produzione, con backup e conferma "PRODUZIONE".
9. **`ssh` pre-autorizzato** dal setup: niente richiesta di permesso a ogni comando. La conferma del merchant avviene in conversazione, spiegata in italiano, secondo le regole d'uso.
10. **Canale principale: marketplace GitHub** `bwlab/prestashop-assistant` (aggiornamenti automatici). Lo zip resta come riserva.
11. **Merchant esistenti**: ci sono merchant che usano `prompt-merchant.md`. Il setup deve riconoscere e riusare quanto già creato.

## Scelte di Jigen (da controllare)

- **Git for Windows non obbligatorio**: su Windows i comandi girano in PowerShell. Le istruzioni fisse delle skill hanno la versione Windows e quella Mac.
- **claude-mem facoltativo**: se l'installazione fallisce, il setup avvisa e prosegue. claude-mem tiene la memoria per cartella aperta: il setup consiglia di aprire sempre la cartella di lavoro, ma il plugin funziona comunque da qualunque cartella.
- **Regole d'uso sempre presenti**: lo script di avvio inietta anche il testo della skill regole-uso in ogni sessione, perché una skill da sola viene caricata solo quando Claude lo ritiene utile.
- **Protezioni nelle impostazioni di Claude Code** scritte dal setup, con il consenso del merchant e senza toccare il resto: `ssh` consentito; lettura della chiave privata (`~/.ssh/prestashop_*` senza `.pub`) vietata; `sudo` sempre con richiesta di permesso.

## Struttura del plugin

```
prestashop-assistant/                 (repo)
├── .claude-plugin/marketplace.json   marketplace "bwlab-prestashop-assistant"
├── plugins/prestashop-assistant/
│   ├── .claude-plugin/plugin.json
│   ├── skills/
│   │   ├── setup/SKILL.md            /prestashop-assistant:setup
│   │   └── regole-uso/SKILL.md       il guardiano, sempre attivo
│   ├── hooks/hooks.json              SessionStart → scripts/carica-contesto.js
│   └── scripts/carica-contesto.js    Node, nessuna dipendenza
├── scripts/build.sh                  crea dist/prestashop-assistant.zip
└── prompt-merchant.md                resta come fonte; rimosso quando il plugin lo sostituisce del tutto
```

Stessa impostazione del plugin Odoo: `dist/` escluso dal repo.

## Dove stanno i dati

| Cosa | Dove |
|---|---|
| Puntatore alla cartella di lavoro | file fisso `~/.prestashop-assistant` (Windows: `C:\Users\<nome>\.prestashop-assistant`), contiene solo il percorso |
| Cartella di lavoro | scelta dal merchant |
| `assistente.md` | profilo del merchant: livello tecnico, preferenze, contatti di supporto |
| `dati-accesso.md` | ambienti: nome, ruolo, host, porta, utente, alias SSH, impronta del server, cartella dello shop, binario PHP del sito, URL sito e back-office, DB (host, nome, prefisso). Mai password |
| `ricognizione/<ambiente>.md`, `ricognizione/differenze.md` | esito della ricognizione |
| `log/AAAA-MM-GG.md` | registro attività |
| `backup/`, `report/`, `export/` | come nel prompt attuale |
| Chiave SSH | `~/.ssh/prestashop_<shop>` (+ `.pub`) |
| Collegamento SSH | `~/.ssh/config`, un `Host <shop>-<ambiente>` per ambiente |

## Caricamento a ogni sessione

L'hook `SessionStart` lancia `scripts/carica-contesto.js` con Node:
- legge `~/.prestashop-assistant`;
- se manca: inietta "plugin non configurato, proponi /prestashop-assistant:setup";
- se c'è: inietta il percorso della cartella di lavoro e il contenuto di `assistente.md` e `dati-accesso.md` (sono piccoli). La ricognizione e il log li legge Claude quando servono.
- in ogni caso inietta il testo della skill regole-uso.

Il setup aggiunge la cartella di lavoro alle cartelle consentite nelle impostazioni utente di Claude Code (`permissions.additionalDirectories` in `~/.claude/settings.json`), dopo aver chiesto il consenso al merchant e senza toccare le altre impostazioni. Così Claude legge e scrive lì da qualunque cartella aperta senza chiedere ogni volta.

## Setup (`/prestashop-assistant:setup`)

Un passo alla volta, aspettando il "fatto". Ripetibile: a ogni passo controlla cosa c'è già e salta quello che è a posto.

0. **Installazione** (fuori dal setup, nella guida per il merchant): Customize › Plugins › Add › Add marketplace › `bwlab/prestashop-assistant`, poi installa il plugin. In riserva: Upload plugin con lo zip.
1. **Dove sei**: se i comandi non girano sul computer del merchant (chat, Cowork), spiega che serve la scheda Code e fermati.
1b. **Configurazione già esistente** (merchant che usavano `prompt-merchant.md`): cerca una cartella di lavoro con `assistente.md`/`dati-accesso.md` (chiede al merchant dov'è), chiavi `~/.ssh/prestashop_<slug>` e `_np`, alias `<slug>-stage`/`<slug>-prod` in `~/.ssh/config`. Se li trova li riusa: la cartella diventa la cartella di lavoro, gli alias diventano gli ambienti `stage` (prova) e `prod` (produzione), la chiave resta quella. Non crea doppioni né una seconda chiave; aggiorna `dati-accesso.md` al nuovo formato tenendo una copia del vecchio in `backup/`.
2. **Sistema**: Windows o Mac. Controlla `ssh -V`; su Windows, se manca, guida l'attivazione di OpenSSH (Impostazioni › Funzionalità facoltative), come nel prompt attuale.
3. **Node.js 20+**: se manca, guida l'installazione dal sito ufficiale.
4. **claude-mem** (facoltativo): lancia l'installer ufficiale con le opzioni per usare il piano Claude del merchant, senza login a cmem.ai e senza cloud sync. Fa riavviare la scheda Code e verifica che funzioni. Se fallisce, avvisa e prosegue.
4b. **Impostazioni di Claude Code**: con il consenso del merchant, aggiunge a `~/.claude/settings.json` le regole: `ssh` consentito, lettura della chiave privata vietata, `sudo` sempre con richiesta. Non tocca le altre impostazioni.
5. **Livello del merchant**: le 5 domande del prompt attuale, una alla volta.
6. **Cartella di lavoro**: chiede dove crearla, crea le sottocartelle, scrive il puntatore, registra la cartella nelle cartelle consentite.
7. **Contatti di supporto**: nome, email, telefono facoltativo. Salvati in `assistente.md`.
8. **Ambienti**: per ogni ambiente chiede nome, ruolo, host, porta, utente, cartella dello shop, URL. Se il merchant non li sa, prepara il messaggio per il supporto con l'elenco di cosa serve e si ferma lì finché non tornano.
9. **Chiave SSH**: crea la chiave senza passphrase (regole del prompt attuale: se esiste già con passphrase, ne crea una `_np`). Mostra solo la `.pub`. La chiave va inserita sul server in uno di due modi: il merchant la manda al supporto, oppure la inserisce lui dal pannello di hosting, guidato da Claude.
10. **Collegamento**: scrive `~/.ssh/config` senza toccare il resto, prova ogni ambiente; alla prima connessione fa confermare l'impronta del server al supporto, e se non corrisponde si ferma.
11. **Controlli**: cartella dello shop, PHP da riga di comando, una query di sola lettura, come nel prompt attuale.
12. **Ricognizione** in sola lettura di ogni ambiente, compreso il `phpinfo` temporaneo (creato e cancellato subito, verificando il 404), come nel prompt attuale. Salva in `ricognizione/`.

## Regole d'uso (il guardiano)

Riprende i §§ 2-6 del prompt attuale, con questi cambi:
- "stage/prod" diventa "ambienti di ruolo prova / produzione".
- Se c'è più di un ambiente e la richiesta non dice quale, Claude chiede.
- Ogni risposta con dati dello shop dice da quale ambiente vengono.
- Prima prova, poi produzione: ogni modifica si prova prima su un ambiente di prova. Se lo shop non ha ambienti di prova, la modifica si fa direttamente in produzione con le regole della produzione.
- Scrittura in produzione: spiegare cosa cambia, cosa tocca e come si torna indietro; backup; ok esplicito del merchant con la parola "PRODUZIONE" nella richiesta di conferma.
- Database: lettura libera; scritture solo con `WHERE` preciso e conteggio delle righe prima; vietati `DROP`, `TRUNCATE`, `ALTER`, `CREATE`, `GRANT` e modifiche di struttura; attenzione a multistore e multilingua.
- Accesso al DB senza esporre la password (PHP del server che legge `parameters.php`, o file di opzioni temporaneo con permessi 600 cancellato subito).
- Tema e file: solo piccoli ritocchi a template, CSS e traduzioni del tema. Niente core, moduli, override.
- Cache svuotata dopo modifiche, come utente del sito. Mai root, mai `sudo`.
- Report ed export in `report/` e `export/`, avviso se contengono dati personali.
- Log di ogni attività in `log/AAAA-MM-GG.md`, formato del prompt attuale con il nome dell'ambiente.
- Fuori perimetro (codice, moduli, upgrade, errori 500, server, email di sistema, pagamenti, sicurezza): non intervenire, preparare la richiesta per il contatto di supporto salvato in `assistente.md`.
- Linguaggio adattato al livello del merchant salvato in `assistente.md`.

## Lasciato a chi costruisce

- Testo esatto delle skill, partendo da `prompt-merchant.md`.
- Nome delle opzioni dell'installer di claude-mem: da verificare sul README/sorgente; se l'installer non permette di evitare cmem.ai in modo non interattivo, fermarsi e chiedere.
- Formato interno di `dati-accesso.md`, purché leggibile dal merchant.
- Come lo script di caricamento gestisce file mancanti o corrotti, purché non blocchi la sessione.

## Da verificare costruendo

- Che hook `SessionStart` e `additionalDirectories` funzionino nella scheda Code di Claude Desktop su Windows 11.
- Che lo zip venga accettato da Customize › Plugins con la cartella `hooks/` e `scripts/`.
- Prova completa su un PC Windows 11 pulito prima di darlo a un merchant.
