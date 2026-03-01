# Chess Podium Pairing API

Questo script permette di usare **bbpPairings** (motore FIDE Dutch) anche quando `exec()` è disabilitato sul server WordPress.

## Come funziona

1. Il plugin Chess Podium invia i dati del torneo (formato TRF) all'API via HTTP POST
2. L'API esegue bbpPairings sul server e restituisce gli abbinamenti in JSON
3. Il plugin riceve gli abbinamenti e li applica al torneo

## Requisiti

- Server con **PHP** e **exec()** abilitato (es. Keliweb, VPS)
- **bbpPairings** installato (vedi sotto)
- HTTPS consigliato per la trasmissione dei dati

## Installazione di bbpPairings

### Metodo 1: SSH (consigliato per Keliweb con accesso SSH)

1. Connettiti via SSH al server bedo.it
2. Vai nella cartella pairing-api: `cd public_html/pairing-api` (o il percorso dove l'hai caricata)
3. Scarica il binario Linux (64-bit):
   ```bash
   wget https://github.com/BieremaBoyzProgramming/bbpPairings/releases/download/v6.0.0/bbpPairings-v6.0.0-x86_64-pc-linux.tar.gz
   ```
4. Estrai e rinomina:
   ```bash
   tar -xzf bbpPairings-v6.0.0-x86_64-pc-linux.tar.gz
   mv bbpPairings-v6.0.0-x86_64-pc-linux/bbpPairings ./
   rm -rf bbpPairings-v6.0.0-x86_64-pc-linux bbpPairings-v6.0.0-x86_64-pc-linux.tar.gz
   ```
5. Rendi eseguibile: `chmod +x bbpPairings`
6. Verifica: `./bbpPairings` (dovrebbe mostrare l'help)

### Metodo 2: Download manuale + FTP/File Manager

1. Scarica da: https://github.com/BieremaBoyzProgramming/bbpPairings/releases (file `bbpPairings-v6.0.0-x86_64-pc-linux.tar.gz`)
2. Estrai sul tuo PC: otterrai una cartella con il file `bbpPairings`
3. Carica il file `bbpPairings` nella cartella `pairing-api` su bedo.it (stesso livello di `pair.php`)
4. Via SSH o File Manager, imposta i permessi: 755 (eseguibile)

### Percorso automatico

Lo script cerca bbpPairings in questo ordine:
- `pairing-api/bbpPairings` (stessa cartella di pair.php)
- `pairing-api/bin/bbpPairings`
- `/usr/local/bin/bbpPairings`

## Configurazione WordPress

1. Vai in **Chess Podium → Pairing (bbpPairings)**
2. In **Pairing API URL** inserisci: `https://bedo.it/pairing-api/pair.php` (adatta il percorso se la cartella è in un sottodominio o sottocartella)
3. Salva

## Sicurezza

- L'API accetta richieste da qualsiasi origine (CORS `*`). Per limitare l'accesso, aggiungi controlli (es. token, IP) in `pair.php`
- Usa HTTPS in produzione
- I dati inviati contengono nomi e punteggi dei giocatori

## Licenza

Stessa licenza del plugin Chess Podium (GPL v2 o successiva).
