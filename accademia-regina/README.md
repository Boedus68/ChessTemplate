# Accademia Scacchistica La Regina – Tema WordPress

Tema personalizzato per il sito dell'**Accademia Scacchistica La Regina di Cattolica** (reginacattolica.com).

## Installazione

1. Copia la cartella `accademia-regina` in `wp-content/themes/`
2. In WordPress: **Aspetto → Temi** → attiva "Accademia Scacchistica La Regina"

## Configurazione

### Homepage statica
1. **Impostazioni → Lettura** → seleziona "Una pagina statica"
2. Homepage: crea una pagina (es. "Home") e selezionala
3. Pagina articoli: crea una pagina "Novità" e selezionala

### Pagine da creare

| Pagina | Slug suggerito | Template |
|--------|----------------|----------|
| Home | home (o lascia vuoto) | Homepage (front-page.php) |
| Tornei | tornei | Tornei (page-tornei.php) |
| Partite | partite | Partite (page-partite.php) |
| Contatti | contatti | Contatti (page-contatti.php) |
| Novità | novita | — (usa come pagina articoli) |

Per assegnare il template: modifica la pagina → pannello "Template" → scegli "Tornei", "Partite" o "Contatti".

### Menu
**Aspetto → Menu** → crea un menu e assegnalo alla posizione "Menu principale". Aggiungi le voci: Home, Tornei, Partite, Novità, Contatti.

---

## Shortcode

### Tornei
Inserisci nella pagina Tornei:

```
[tornei]
[torneo nome="Torneo 2024" url="https://reginacattolica.com/tornei/torneo-2024/" anchor="round1"]
[torneo nome="Campionato Sociale" url="https://reginacattolica.com/tornei/campionato/"]
[/tornei]
```

**Parametri [torneo]:**
- `nome` – Testo del link
- `url` – URL completo (es. cartella con index.html generato da Vega)
- `anchor` – (opzionale) Anchor per sezione specifica (es. round3, classifica)

### Partite PGN
Inserisci nella pagina Partite:

**Da file PGN (carica in Media Library):**
```
[pgn url="https://reginacattolica.com/wp-content/uploads/partita.pgn" titolo="Partita memorabile"]
```

**PGN inline:**
```
[pgn titolo="Siciliana"]
[Event "Torneo"]
1. e4 c5 2. Nf3 d6 3. d4 cxd4 4. Nxd4 Nf6 5. Nc3 a6
[/pgn]
```

**Parametri [pgn]:**
- `url` – URL del file .pgn
- `titolo` – Titolo sopra la scacchiera
- `layout` – left, right, top, bottom (default: right)
- `theme` – brown, blue, green, zeit, falken, ecc.

### Contatti
La pagina Contatti include il modulo Contact Form 7. **Requisito:** plugin [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) installato e attivo.

Il template usa lo shortcode `[contact-form-7 id="ec8e004" title="Modulo di contatto 1"]`. Se l'ID del tuo modulo è diverso, modifica `page-contatti.php` e aggiorna l'ID nello shortcode.

---

## Struttura file

```
accademia-regina/
├── style.css          # Stili e info tema
├── functions.php      # Setup, shortcode
├── header.php
├── footer.php
├── index.php
├── front-page.php     # Homepage
├── home.php           # Blog/Novità
├── page.php
├── single.php
├── page-tornei.php    # Template Tornei
├── page-partite.php   # Template Partite PGN
├── page-contatti.php  # Template Contatti (Contact Form 7)
└── README.md
```

## Licenza
GPL v2 o successiva (compatibile con WordPress)
