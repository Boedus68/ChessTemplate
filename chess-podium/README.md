# Chess Podium

WordPress plugin for fast chess tournament management:
- creazione torneo
- iscrizione giocatori
- modifica giocatori (nome/rating) anche dopo inserimento
- eliminazione giocatori (solo se non ancora usati in abbinamenti)
- generazione turni (svizzero base)
- inserimento risultati
- annullamento turno corrente (rollback rapido)
- generazione turno con tabellone stampabile immediato
- export CSV di turni e classifica
- classifica automatica (Punti, Buchholz, Sonneborn-Berger)
- pagina pubblica su `/torneo/`

## Installazione rapida

1. Copy plugin folder to `wp-content/plugins/` (recommended folder name: `chess-podium`)
2. Activate **Chess Podium** from WordPress Plugins
3. Go to **Settings > Permalinks** and click **Save** (needed for `/torneo/`)

## Uso immediato

1. Open **Chess Podium** in WordPress admin menu.
2. Crea un torneo (nome + numero turni).
3. Apri il torneo creato e inserisci tutti i giocatori con rating.
4. Clicca **Genera prossimo turno**.
5. Inserisci i risultati del turno corrente e salva.
6. Rigenera il turno successivo.
7. Se sbagli pairing/risultati del turno appena creato, usa **Annulla turno corrente**.
8. Per avere subito il foglio da affiggere, usa **Genera turno e stampa abbinamenti**.
9. Per condividere dati torneo, usa **Esporta turni (CSV)** e **Esporta classifica (CSV)**.

## Pubblicazione

- URL pubblico: `https://tuodominio.it/torneo/`
- You can also use shortcode:
  - `[chess_podium_tournament]` (latest tournament)
  - `[chess_podium_tournament id="1"]` (specific tournament)
  - Legacy shortcode still supported: `[regina_torneo]`

## Note MVP

- Pairing svizzero base (non implementa tutte le regole FIDE avanzate).
- BYE gestito con 1 punto.
- Progetto pensato per partire subito e migliorare poi.
