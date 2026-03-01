<?php
/**
 * Stripe Checkout integration for Chess Podium Pro.
 *
 * @package Chess_Podium_Store
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_Store_StripeCheckout
{
    private static function get_secret_key(): string
    {
        return trim((string) get_option('chess_podium_stripe_secret_key', ''));
    }

    private static function get_webhook_secret(): string
    {
        return trim((string) get_option('chess_podium_stripe_webhook_secret', ''));
    }

    private static function get_price_id(): string
    {
        return trim((string) get_option('chess_podium_stripe_price_id', ''));
    }

    public static function create_checkout_session(string $email, string $plan = 'pro'): string|WP_Error
    {
        $secret = self::get_secret_key();
        $priceId = self::get_price_id();
        if ($secret === '' || $priceId === '') {
            return new WP_Error('config', __('Stripe not configured. Contact site admin.', 'chess-podium-store'));
        }
        $successUrl = add_query_arg(['cp_success' => '1', 'session_id' => '{CHECKOUT_SESSION_ID}'], home_url('/pricing/'));
        $cancelUrl = home_url('/pricing/');
        $body = [
            'mode' => 'subscription',
            'customer_email' => $email,
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => '1',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata[plan]' => $plan,
            'metadata[product]' => 'chess-podium-pro',
        ];
        $response = wp_remote_post(
            'https://api.stripe.com/v1/checkout/sessions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
                'timeout' => 15,
            ]
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $bodyRaw = wp_remote_retrieve_body($response);
        $data = json_decode($bodyRaw, true);
        if ($code !== 200 || !isset($data['url'])) {
            $err = isset($data['error']['message']) ? $data['error']['message'] : $bodyRaw;
            return new WP_Error('stripe', $err ?: __('Stripe error.', 'chess-podium-store'));
        }
        return $data['url'];
    }

    public static function process_webhook(string $payload, string $sig): true|WP_Error
    {
        $secret = self::get_webhook_secret();
        if ($secret === '') {
            return new WP_Error('config', 'Webhook secret not set');
        }
        if (!function_exists('hash_hmac')) {
            return new WP_Error('php', 'hash_hmac required');
        }
        $parts = explode(',', $sig);
        $timestamp = null;
        $v1 = null;
        foreach ($parts as $part) {
            if (strpos($part, 't=') === 0) {
                $timestamp = substr($part, 2);
            }
            if (strpos($part, 'v1=') === 0) {
                $v1 = substr($part, 3);
            }
        }
        if (!$timestamp || !$v1) {
            return new WP_Error('signature', 'Invalid signature format');
        }
        $signed = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $secret);
        if (!hash_equals($expected, $v1)) {
            return new WP_Error('signature', 'Signature mismatch');
        }
        $event = json_decode($payload, true);
        if (!isset($event['type'])) {
            return new WP_Error('payload', 'Invalid event');
        }
        if ($event['type'] === 'checkout.session.completed') {
            $session = $event['data']['object'] ?? [];
            $email = $session['customer_email'] ?? $session['customer_details']['email'] ?? '';
            $paymentStatus = $session['payment_status'] ?? '';
            $status = $session['status'] ?? '';
            $isPaid = ($paymentStatus === 'paid') || ($status === 'complete');
            if ($email !== '' && $isPaid) {
                $license = ChessPodium_Store_LicenseManager::create_license($email);
                if ($license) {
                    ChessPodium_Store_LicenseManager::send_license_email($email, $license['license_key']);
                }
            }
        }
        return true;
    }

    public static function render_settings(): void
    {
        $saved = isset($_GET['saved']);
        $secret = get_option('chess_podium_stripe_secret_key', '');
        $webhook = get_option('chess_podium_stripe_webhook_secret', '');
        $priceId = get_option('chess_podium_stripe_price_id', '');
        $webhookUrl = home_url('/api/stripe-webhook');
        ?>
        <?php if ($saved): ?>
            <div class="notice notice-success"><p><?php echo esc_html__('Settings saved.', 'chess-podium-store'); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('chess_podium_store_settings'); ?>
            <input type="hidden" name="chess_podium_store_save_settings" value="1">
            <table class="form-table">
                <tr>
                    <th><label for="stripe_secret_key"><?php echo esc_html__('Stripe Secret Key', 'chess-podium-store'); ?></label></th>
                    <td>
                        <input id="stripe_secret_key" name="stripe_secret_key" type="password" class="regular-text" value="<?php echo esc_attr($secret); ?>" autocomplete="off">
                        <p class="description"><?php echo esc_html__('sk_live_... or sk_test_... from Stripe Dashboard → Developers → API keys', 'chess-podium-store'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="stripe_price_id"><?php echo esc_html__('Stripe Price ID', 'chess-podium-store'); ?></label></th>
                    <td>
                        <input id="stripe_price_id" name="stripe_price_id" type="text" class="regular-text" value="<?php echo esc_attr($priceId); ?>" placeholder="price_xxx">
                        <p class="description"><?php echo esc_html__('Create a Product in Stripe with a recurring price (€79/year). Use the Price ID (price_xxx). One-time prices are not supported.', 'chess-podium-store'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="stripe_webhook_secret"><?php echo esc_html__('Stripe Webhook Secret', 'chess-podium-store'); ?></label></th>
                    <td>
                        <input id="stripe_webhook_secret" name="stripe_webhook_secret" type="password" class="large-text" value="<?php echo esc_attr($webhook); ?>" autocomplete="off">
                        <p class="description">
                            <?php echo esc_html__('Webhook URL:', 'chess-podium-store'); ?>
                            <code><?php echo esc_html($webhookUrl); ?></code><br>
                            <?php echo esc_html__('In Stripe Dashboard → Developers → Webhooks, add endpoint with event checkout.session.completed. Copy the signing secret (whsec_...).', 'chess-podium-store'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php echo esc_html__('Save', 'chess-podium-store'); ?></button></p>
        </form>
        <?php
    }
}
