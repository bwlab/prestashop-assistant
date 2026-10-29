# prestashop-assistant

Plugin per Claude che aiuta il titolare di uno shop PrestaShop a gestirlo in autonomia: interrogare e correggere il database, piccoli ritocchi al tema, report ed export. Claude lavora sui server dello shop via SSH, spiega ogni modifica in parole semplici, fa il backup e chiede conferma prima di scrivere (con la parola "PRODUZIONE" sullo shop vero).

Non fa sviluppo, installazione di moduli, upgrade o interventi sul server: per questo prepara la richiesta per chi segue tecnicamente lo shop.

## Requisiti

- Claude Desktop, **scheda Code** (la chat normale e Cowork non possono collegarsi via SSH al tuo server).
- Windows 10/11 o Mac.
- Accesso SSH al server dello shop (lo fornisce chi ti segue tecnicamente o il tuo hosting).
- Node.js 20 o superiore (il setup ti guida a installarlo se manca).

## Installazione

1. In Claude Desktop apri **Customize › Plugins › Add › Add marketplace** e scrivi `bwlab/prestashop-assistant`.
2. Installa il plugin **prestashop-assistant**. Gli aggiornamenti arrivano da soli.

In alternativa, se te lo hanno dato come file: **Customize › Plugins › Add › Upload plugin** e scegli `prestashop-assistant.zip` (senza estrarlo). In questo caso ogni nuova versione va ricaricata.

## Primo avvio

Apri la **scheda Code** e scrivi `/prestashop-assistant:setup`. Claude ti guida un passo alla volta: collegamento SSH, chiave, ambienti dello shop (prova e produzione), cartella di lavoro dove salva log, backup e report, contatti di chi ti supporta.

Da quel momento basta aprire la scheda Code e chiedere quello che ti serve. Per cambiare un server o un indirizzo, rilancia il setup.

## Privacy

I dati dello shop passano solo tra il tuo computer, i tuoi server e Anthropic (il tuo piano Claude). La chiave privata SSH resta sul tuo computer: Claude non la apre, e i comandi che potrebbero aggirare questo divieto chiedono il tuo permesso. La memoria tra le sessioni (claude-mem) è installata in modalità locale, senza account esterni e senza telemetria.

## Collaudo (per chi distribuisce il plugin)

Da fare su un PC Windows 11 pulito prima di darlo a un merchant:

- [ ] Installazione da marketplace (`bwlab/prestashop-assistant`) riuscita.
- [ ] Lo zip (`scripts/build.sh` → `dist/prestashop-assistant.zip`) è accettato da Upload plugin.
- [ ] `/prestashop-assistant:setup` completo fino alla ricognizione, con `ssh-keygen` senza passphrase da PowerShell.
- [ ] Aprendo la scheda Code in un'altra cartella, Claude conosce comunque shop, ambienti e cartella di lavoro.
- [ ] I comandi `ssh` partono senza richiesta di permesso; `ssh host "sudo ..."` (con le virgolette) e `ssh -o ...` chiedono il permesso.
- [ ] claude-mem installato senza login a cmem.ai.
- [ ] Una richiesta di modifica in produzione: Claude legge, mostra cosa cambia, fa il backup e chiede conferma con "PRODUZIONE".

## Sviluppo

- Test: `node --test "tests/*.test.js"`
- Validazione: `claude plugin validate plugins/prestashop-assistant`
- Zip: `scripts/build.sh`

Autore: bwlab.
