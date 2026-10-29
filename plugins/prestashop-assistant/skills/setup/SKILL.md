---
name: setup
description: Configura il plugin prestashop-assistant sul computer del merchant (SSH, chiave, ambienti dello shop, cartella di lavoro, memoria). Usala al primo avvio, quando il plugin risulta non configurato, quando cambiano server o indirizzi, o quando il collegamento SSH non funziona.
---

# Setup del plugin prestashop-assistant

Guida il merchant **un passo alla volta**, in italiano, con frasi brevi. Esegui tu i comandi; aspetta il "fatto" quando serve un'azione sua. Il setup è ripetibile: a ogni passo controlla prima cosa c'è già e salta quello che è a posto.

Contatti di supporto: quelli salvati in `assistente.md` (passo 7). Prima di quel passo, se serve aiuto, chiedi al merchant chi lo segue.

Mai leggere, mostrare o copiare la chiave privata SSH. Mai chiedere password.

## 1. Dove stai lavorando
Prova un comando innocuo (`echo ok`) e controlla che giri sul computer del merchant (`hostname`, cartella home). Se non puoi eseguire comandi, o girano in un ambiente remoto (chat di Claude, Cowork), spiega che il plugin funziona solo nella **scheda Code** di Claude Desktop e fermati.

## 1b. Configurazione già esistente
Il merchant potrebbe usare il vecchio prompt dell'assistente. Cerca:
- in `~/.ssh/config` gli alias `<slug>-stage` e `<slug>-prod`;
- le chiavi `~/.ssh/prestashop_<slug>` o `~/.ssh/prestashop_<slug>_np` (controlla solo che esistano, non aprirle);
- la vecchia cartella di lavoro: chiedi al merchant dov'è (contiene `assistente.md` e `dati-accesso.md`).

Se li trovi, riusali: quella cartella diventa la cartella di lavoro (passo 6), `<slug>-stage` diventa l'ambiente `stage` con ruolo **prova**, `<slug>-prod` l'ambiente `prod` con ruolo **produzione**, la chiave resta la stessa. Prima di convertire `dati-accesso.md` al nuovo formato, copia il vecchio in `backup/` con la data nel nome. Non creare doppioni né una seconda chiave. Poi salta i passi già coperti.

## 2. Sistema e SSH
Rileva Windows o Mac. Esegui `ssh -V`.
- **Mac**: SSH c'è già.
- **Windows**: usa PowerShell. Il client OpenSSH è incluso in Windows 10 (1809+) e 11. Se `ssh -V` non risponde, fallo attivare da **Impostazioni › App › Funzionalità facoltative › Aggiungi funzionalità › Client OpenSSH**, oppure in PowerShell avviata come amministratore: `Add-WindowsCapability -Online -Name OpenSSH.Client~~~~0.0.1.0`. Poi riavviare Claude e ricontrollare.

## 3. Node.js
Esegui `node -v`. Serve la versione 20 o superiore (lo usa il plugin e la memoria). Se manca o è vecchia, fai scaricare la versione LTS da https://nodejs.org e installarla con le opzioni predefinite, poi riavviare Claude.

## 4. Memoria tra le sessioni (claude-mem, facoltativa)
Spiega in due righe: Claude ricorderà il lavoro fatto nelle sessioni precedenti; i dati restano sul computer e passano solo dal piano Claude del merchant, nessun servizio esterno. Con il suo sì esegui:
```
npx claude-mem install --provider claude
npx claude-mem telemetry disable
```
Poi fai riavviare la scheda Code. Se l'installazione fallisce, spiega l'errore in parole semplici, annotalo e prosegui: il plugin funziona anche senza memoria. Consiglia di aprire sempre la stessa cartella (quella di lavoro) nella scheda Code: la memoria si tiene per cartella.

## 5. Livello del merchant
Fai 5 domande brevi, una alla volta:
1. Sa cos'è un database e una tabella?
2. Ha mai scritto o letto una query SQL?
3. Ha mai modificato un file del tema (tpl/css)?
4. Usa il back-office PrestaShop ogni giorno?
5. Preferisce spiegazioni brevi o dettagliate?

Deduci **base**, **intermedio** o **esperto**. Lo salvi in `assistente.md` al passo 7.

## 6. Cartella di lavoro
Chiedi dove crearla e proponi `Documenti\Shop-<nome shop>` (Mac: `~/Documents/Shop-<nome shop>`). Creala con le sottocartelle `ricognizione`, `log`, `backup`, `report`, `export`.

Poi spiega cosa fa il passo seguente e chiedi il sì: il plugin registra la cartella così la ritrova da qualunque cartella aperta, e aggiorna le impostazioni di Claude (comandi `ssh` senza richiesta di permesso, chiave privata non leggibile, `sudo` sempre con richiesta). Le altre impostazioni restano come sono, e una copia di quelle attuali viene salvata. Con il sì esegui, con il percorso completo:
```
node "${CLAUDE_PLUGIN_ROOT}/scripts/registra-cartella.js" "<percorso completo della cartella>"
```
Se dà errore, spiega il messaggio e fermati.

## 7. Profilo e contatti di supporto
Chiedi nome dello shop, chi lo supporta tecnicamente (nome, email, telefono facoltativo) e scrivi `assistente.md` nella cartella di lavoro: nome dello shop, nome del merchant, livello (passo 5), preferenze, contatti di supporto, sistema operativo.

## 8. Ambienti dello shop
Spiega: un ambiente è una copia dello shop su un server (per esempio "stage" per le prove e "produzione" per lo shop vero). Ce ne può essere uno solo. Per ogni ambiente chiedi:
- nome (libero) e ruolo: **prova** o **produzione**;
- indirizzo del server, porta SSH, utente;
- cartella dello shop sul server;
- indirizzo del sito e del back-office.

Se il merchant non li conosce, prepara il messaggio per chi lo supporta, con oggetto "Dati di accesso SSH per <nome shop>" e l'elenco qui sopra per ogni ambiente, più l'**impronta del server** (fingerprint SSH). Fermati finché non tornano le risposte.

## 9. Chiave SSH
Nome della chiave: `prestashop_<slug>`, dove slug è il nome dello shop in minuscolo senza spazi. Deve essere **senza passphrase**, così Claude si collega senza chiedere nulla.
- Se esiste già, controlla che sia senza passphrase: `ssh-keygen -y -P "" -f ~/.ssh/prestashop_<slug>` (Windows: `-P '""'`). Se stampa la chiave pubblica va bene. Se dà errore ha una passphrase: non cancellarla, creane una nuova con lo stesso nome più `_np` e usa quel nome da qui in poi.
- Altrimenti creala:
  - **Mac**: `mkdir -p ~/.ssh && ssh-keygen -t ed25519 -N "" -C "<email> <shop>" -f ~/.ssh/prestashop_<slug>`
  - **Windows (PowerShell)**: `New-Item -ItemType Directory -Force "$HOME\.ssh" | Out-Null` e poi `ssh-keygen -t ed25519 -N '""' -C "<email> <shop>" -f "$HOME\.ssh\prestashop_<slug>"`. Scrivi `-N '""'`, non `-N ""`: con PowerShell 5.1 le virgolette vuote si perdono. Se chiede comunque una passphrase, premere Invio due volte senza scrivere.
- Mostra la chiave **pubblica** con `ssh-keygen -y -f ~/.ssh/prestashop_<slug>` (non aprire i file della chiave: è vietato dalle impostazioni).
- La chiave pubblica va inserita sul server, in uno di due modi:
  - il merchant la manda a chi lo supporta, che la inserisce;
  - il merchant ha un pannello di hosting (cPanel, Plesk o simili) e la inserisce lui: guidalo (sezione "Accesso SSH" o "Chiavi SSH", importa chiave pubblica, autorizzala).
- Aspetta la conferma che è stata inserita.

## 10. Collegamento
Aggiungi a `~/.ssh/config` (Windows: `$HOME\.ssh\config`, senza estensione) un blocco per ambiente, senza toccare il resto del file:
```
Host <slug>-<nome ambiente>
    HostName <server>
    Port <porta>
    User <utente>
    IdentityFile ~/.ssh/prestashop_<slug>
    IdentitiesOnly yes
```
Prova ogni ambiente: `ssh <slug>-<ambiente> "whoami && pwd"`. Alla prima connessione confronta l'impronta mostrata con quella data da chi lo supporta: se non corrisponde, **fermati** e avvisa il supporto, non accettarla.

## 11. Controlli
Per ogni ambiente: la cartella dello shop contiene `app`, `modules`, `themes`, `var`; `php -v` funziona; una query di sola lettura (nome dello shop dalla tabella `configuration`) con le credenziali lette dal PHP del server da `app/config/parameters.php`, mai in chiaro. Se un controllo fallisce, spiega cosa non va e prepara il messaggio per il supporto con l'errore esatto.

## 12. Ricognizione (sola lettura)
Per ogni ambiente raccogli: utente, spazio disco (`df -h`), PHP da riga di comando (`which php`, `php -v`), `mysql`/`mysqldump`; versione di PrestaShop (`src/Core/Version.php` o `app/AppKernel.php`); cartella di amministrazione (quella nella root con `init.php`); tema attivo (tabella `shop`, campo `theme_name`) e cartelle in `themes/`; moduli in `modules/` e installati con stato; contenuto di `override/`; database: host, nome, prefisso (mai la password), numero tabelle, lingue, multistore; dimensioni di `img/`, `var/cache/`, `var/logs/`.

**PHP usato dal sito** (spesso diverso da quello da riga di comando):
1. crea nella cartella dello shop `info-<8 caratteri casuali>.php` con `<?php phpinfo();`;
2. aprilo dal computer del merchant con `curl -s https://<dominio>/info-xxxx.php` (Windows: `curl.exe`);
3. annota PHP Version, Server API, Loaded Configuration File, DOCUMENT_ROOT, `memory_limit`, `max_execution_time`, `upload_max_filesize`, estensioni principali;
4. **cancella subito il file**, anche se qualcosa è andato storto, e verifica che l'indirizzo risponda 404;
5. se il PHP del sito è diverso, usa il suo binario (es. `/opt/plesk/php/8.2/bin/php`) per i comandi `php`.
Registra creazione e cancellazione nel log.

Salva in `ricognizione/<ambiente>.md` (con data e ora) e, se ci sono più ambienti, le differenze in `ricognizione/differenze.md`: segnala al merchant quelle importanti.

## 13. Dati di accesso e chiusura
Scrivi `dati-accesso.md` nella cartella di lavoro, una sezione per ambiente: nome, ruolo, alias SSH, server, porta, utente, impronta, cartella dello shop, binario PHP del sito, indirizzo del sito e del back-office, database (host, nome, prefisso; credenziali in `parameters.php` sul server). Niente password, niente chiavi.

Scrivi la prima voce nel log del giorno ("Setup completato"). Spiega al merchant che da ora basta aprire la scheda Code e chiedere: il plugin ricorda shop e ambienti. Per cambiare un server o un indirizzo basta rilanciare `/prestashop-assistant:setup`.
