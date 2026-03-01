# Compilare bbpPairings per hosting con glibc vecchia

Se il tuo hosting (es. Keliweb) ha una versione vecchia di glibc e i binari precompilati non funzionano, puoi compilare bbpPairings v4.1.0 in un container Docker con CentOS 7 (glibc 2.17). Il binario risultante sarà compatibile con la maggior parte degli hosting condivisi.

## Requisiti

- **Docker Desktop** installato ([download](https://www.docker.com/products/docker-desktop/))

## Istruzioni (Windows)

1. Apri **PowerShell** nella cartella `build-for-old-glibc`
2. Esegui: `.\build.ps1`
3. Attendi la compilazione (2-5 minuti)
4. Il file `bbpPairings.exe` verrà creato nella cartella `pairing-api`
5. Carica `bbpPairings.exe` su bedo.it/pairing-api/ via FTP (sostituisci quello esistente)
6. Imposta i permessi a 755
7. Esegui di nuovo "Testa API" in Chess Podium

## Istruzioni (Linux/Mac)

```bash
cd build-for-old-glibc
chmod +x build.sh
./build.sh
```

## Se non hai Docker (virtualizzazione disabilitata)

Usa **GitHub Actions** per compilare nel cloud:

1. Crea un repository su GitHub (o usa quello esistente)
2. Assicurati che la cartella `.github/workflows/` con `build-bbp.yml` sia nella root del repo
3. Fai push del codice su GitHub
4. Vai in **Actions** → **Build bbpPairings for old glibc** → **Run workflow**
5. Quando la build è completata, clicca sulla run → nella sezione **Artifacts** scarica `bbpPairings-exe`
6. Estrai lo zip e carica `bbpPairings.exe` su bedo.it/pairing-api/ via FTP

## Altre opzioni

- **Opzione 1:** Chiedi a Keliweb se possono compilare bbpPairings sul loro server (se hanno g++ disponibile)
- **Opzione 2:** Usa un VPS con Ubuntu 22.04+ per l'API di pairing
