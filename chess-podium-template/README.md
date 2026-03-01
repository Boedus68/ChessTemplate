# Chess Podium Theme

English marketing theme for `chesspodium.com`.

## Included pages/templates

- Homepage (`front-page.php`)
- Features (`page-features.php`)
- Pricing (`page-pricing.php`)
- FAQ (`page-faq.php`)
- Contact (`page-contact.php`)
- Docs (`page-docs.php`)
- Blog (`home.php`)

## Store plugin (for chesspodium.com)

To enable checkout on the pricing page:

1. Install **Chess Podium Store** plugin (`chess-podium-store/` in `wp-content/plugins/`)
2. Activate it and configure Stripe in **CP Store → Settings**
3. The "Buy Pro" button will appear on the pricing page

See `chess-podium-store/README.md` for Stripe setup.

## Installation

1. Copy folder `chess-podium-template` to `wp-content/themes/` (rename to `chess-podium` if desired)
2. Activate **Chess Podium** in WordPress
3. Set static homepage in **Settings > Reading**
4. Create pages with slugs:
   - `features`
   - `pricing`
   - `faq`
   - `contact`
   - `docs`
5. Assign corresponding templates in page settings

## Logo files generated

- `C:\Users\marco\.cursor\projects\c-Users-marco-Desktop-ChessTemplate\assets\chess-podium-logo.png`
- `C:\Users\marco\.cursor\projects\c-Users-marco-Desktop-ChessTemplate\assets\chess-podium-logo-mark.png`
- `C:\Users\marco\.cursor\projects\c-Users-marco-Desktop-ChessTemplate\assets\chess-podium-logo-transparent.png`
- `C:\Users\marco\.cursor\projects\c-Users-marco-Desktop-ChessTemplate\assets\chess-podium-logo-mark-transparent.png`
- `C:\Users\marco\.cursor\projects\c-Users-marco-Desktop-ChessTemplate\assets\chess-podium-logo-flat-transparent.png`
- `C:\Users\marco\.cursor\projects\c-Users-marco-Desktop-ChessTemplate\assets\chess-podium-logo-mark-flat-transparent.png`

Upload one of these in WordPress:
- **Appearance > Customize > Site Identity > Logo**

## Hero image on homepage

Homepage now supports a hero visual from:

- `wp-content/themes/chess-podium/assets/chess-podium-hero.png`

Generated hero source file:

- `C:\Users\marco\.cursor\projects\c-Users-marco-Desktop-ChessTemplate\assets\chess-podium-hero.png`

Copy that PNG into the theme `assets` folder with this exact filename to show it automatically.

## Contact form

`page-contact.php` uses:

`[contact-form-7 id="ec8e004" title="Contact form 1"]`

Change the form ID if needed.
