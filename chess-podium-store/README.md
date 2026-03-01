# Chess Podium Store

Plugin WordPress per il backend di vendita di Chess Podium Pro. Da installare sul sito **chesspodium.com** (o dominio principale di vendita).

## Funzionalità

- **API validazione licenze**: endpoint `/api/validate-license` usato dal plugin Chess Podium sui siti dei clienti
- **Checkout Stripe**: pagamento €79/anno con Stripe Checkout
- **Webhook Stripe**: creazione automatica licenza e invio email al completamento pagamento
- **Licenze manuali**: creazione licenze da admin (ordini offline, beta tester, ecc.)
- **Gestione licenze**: elenco licenze attive, email, dominio, scadenza

## Installazione

1. Copia la cartella `chess-podium-store` in `wp-content/plugins/`
2. Attiva il plugin da WordPress Admin → Plugin
3. Vai a **CP Store → Settings**
4. Configura:
   - **Stripe Secret Key**: da Stripe Dashboard → Developers → API keys
   - **Stripe Price ID**: crea un prodotto €79 (one-time o subscription) e usa il Price ID (`price_xxx`)
   - **Stripe Webhook Secret**: da Stripe Dashboard → Developers → Webhooks

## Configurazione Stripe

### 1. Prodotto e prezzo

1. Stripe Dashboard → Products → Add product
2. Nome: "Chess Podium Pro"
3. Prezzo: €79, one-time (o recurring annuale)
4. Copia il **Price ID** (es. `price_1ABC...`)

### 2. Webhook

1. Stripe Dashboard → Developers → Webhooks → Add endpoint
2. URL: `https://tuodominio.com/api/stripe-webhook`
3. Eventi: seleziona `checkout.session.completed`
4. Copia il **Signing secret** (whsec_...)

## URL API

- **Validazione licenza**: `POST https://chesspodium.com/api/validate-license`
  - Body: `license_key`, `email`, `domain` (opzionale)
  - Risposta: `{"valid": true}` o `{"valid": false, "reason": "..."}`

- **Webhook Stripe**: `POST https://chesspodium.com/api/stripe-webhook`
  - Gestito automaticamente da Stripe

## Pagina Pricing

Il template `page-pricing.php` del tema Chess Podium mostra il pulsante "Buy Pro" solo se il plugin Store è attivo. Inserisci l'email e verrai reindirizzato a Stripe Checkout.

## Licenze manuali

Da **CP Store → Licenses** puoi creare licenze manualmente inserendo l'email. La chiave viene generata e inviata automaticamente all'indirizzo indicato.
