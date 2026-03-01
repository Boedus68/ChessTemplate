# Invio a WordPress.org – Chess Podium

## Pre-submission checklist

- [x] Plugin funziona senza errori
- [x] Licenza GPL v2+ nel header
- [x] Nessun codice offuscato
- [x] `readme.txt` in formato WordPress.org
- [x] Limite Free chiaro (10 giocatori)
- [ ] Screenshot (vedi sotto)
- [ ] Test su installazione WordPress pulita

## Screenshot richiesti

WordPress.org richiede screenshot in `assets/` o nella root. Formato: `screenshot-N.png` (1200x900px consigliato).

Crea e aggiungi:
1. `assets/screenshot-1.png` – Dashboard gestione torneo
2. `assets/screenshot-2.png` – Aggiunta giocatori e generazione abbinamenti
3. `assets/screenshot-3.png` – Pagina pubblica classifica

Oppure usa il formato `assets/` nel repository SVN di WordPress.org.

## Procedura di invio

1. **Account**: crea/accedi su [wordpress.org](https://wordpress.org)
2. **Richiedi plugin**: [wordpress.org/plugins/developers/add/](https://wordpress.org/plugins/developers/add/)
3. Compila:
   - **Plugin Name**: Chess Podium
   - **Plugin URL**: URL del repository (GitHub) o ZIP
   - **Description**: breve descrizione
4. **SVN**: dopo l’approvazione riceverai accesso al repository SVN
5. **Carica file**: struttura tipica:
   ```
   trunk/
     chess-podium.php
     readme.txt
     includes/
     assets/
     languages/
   ```
6. **Tag**: crea tag `0.3.0` per la release

## Creare lo ZIP per l’invio

```bash
# Dalla cartella ChessTemplate
cd chess-podium
zip -r ../chess-podium-0.3.0.zip . -x "*.git*" -x "SUBMISSION.md" -x "compile-languages.php"
```

Su Windows (PowerShell):
```powershell
Compress-Archive -Path * -DestinationPath ..\chess-podium-0.3.0.zip -Force
```

## Note

- **Free vs Pro**: stesso plugin. Senza licenza = Free (10 giocatori). Con licenza valida = Pro (illimitato).
- **Link Upgrade**: il link a chesspodium.com/pricing è consentito.
- **API esterna**: la validazione licenza avviene solo quando l’utente inserisce una chiave (opt-in).
