<?php
/**
 * Tournament registration with Stripe and PayPal payments.
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_Registration
{
    private const OPTION_STRIPE_SECRET = 'chess_podium_registration_stripe_secret';
    private const OPTION_STRIPE_WEBHOOK = 'chess_podium_registration_stripe_webhook';
    private const OPTION_PAYPAL_CLIENT_ID = 'chess_podium_registration_paypal_client_id';
    private const OPTION_PAYPAL_SECRET = 'chess_podium_registration_paypal_secret';
    private const OPTION_PAYPAL_MODE = 'chess_podium_registration_paypal_mode';
    private const TABLE_PAYMENTS = 'chess_podium_registration_payments';

    public static function init(): void
    {
        add_shortcode('chess_podium_registration', [self::class, 'render_shortcode']);
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
        add_action('init', [self::class, 'register_rewrite']);
        add_filter('query_vars', [self::class, 'register_query_vars']);
        add_action('template_redirect', [self::class, 'handle_webhook']);
        add_action('template_redirect', [self::class, 'handle_paypal_return']);
    }

    public static function create_tables(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PAYMENTS;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_id varchar(255) NOT NULL,
            tournament_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY payment_id (payment_id)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function register_rewrite(): void
    {
        add_rewrite_rule('^chess-podium-registration-webhook/?$', 'index.php?chess_podium_reg_webhook=1', 'top');
    }

    public static function register_query_vars(array $vars): array
    {
        $vars[] = 'chess_podium_reg_webhook';
        return $vars;
    }

    public static function handle_paypal_return(): void
    {
        if (empty($_GET['cp_paypal_return']) || empty($_GET['token'])) {
            return;
        }
        $orderId = sanitize_text_field(wp_unslash($_GET['token']));
        $tid = (int) ($_GET['tournament_id'] ?? 0);
        if ($orderId === '' || $tid <= 0) {
            return;
        }
        self::capture_paypal_order($orderId);
        $redirectBase = isset($_GET['return_url']) ? esc_url_raw(urldecode(wp_unslash($_GET['return_url']))) : home_url('/');
        if ($redirectBase === '') {
            $redirectBase = home_url('/');
        }
        wp_safe_redirect(add_query_arg(['cp_reg_success' => '1', 'tournament_id' => $tid], $redirectBase));
        exit;
    }

    public static function handle_webhook(): void
    {
        if ((int) get_query_var('chess_podium_reg_webhook') !== 1) {
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            status_header(405);
            exit;
        }
        $payload = file_get_contents('php://input');
        $sig = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
        self::process_stripe_webhook($payload, $sig);
        exit;
    }

    public static function register_rest_routes(): void
    {
        register_rest_route('chess-podium/v1', '/registration/checkout', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_create_checkout'],
            'permission_callback' => '__return_true',
            'args' => [
                'tournament_id' => ['required' => true, 'type' => 'integer'],
                'name' => ['required' => true, 'type' => 'string'],
                'email' => ['required' => true, 'type' => 'string'],
                'rating' => ['type' => 'integer', 'default' => 0],
                'country' => ['type' => 'string'],
                'fide_id' => ['type' => 'string'],
                'payment_method' => ['required' => true, 'enum' => ['stripe', 'paypal']],
                'return_url' => ['type' => 'string'],
            ],
        ]);
        register_rest_route('chess-podium/v1', '/registration/register-free', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_register_free'],
            'permission_callback' => '__return_true',
            'args' => [
                'tournament_id' => ['required' => true, 'type' => 'integer'],
                'name' => ['required' => true, 'type' => 'string'],
                'email' => ['required' => true, 'type' => 'string'],
                'rating' => ['type' => 'integer', 'default' => 0],
                'country' => ['type' => 'string'],
                'fide_id' => ['type' => 'string'],
                'return_url' => ['type' => 'string'],
            ],
        ]);
    }

    public static function rest_register_free(WP_REST_Request $request): WP_REST_Response
    {
        $tid = (int) $request->get_param('tournament_id');
        $name = trim((string) $request->get_param('name'));
        $email = sanitize_email((string) $request->get_param('email'));
        $rating = max(0, min(3500, (int) $request->get_param('rating')));
        $country = ChessPodiumPlugin::sanitize_country_code((string) $request->get_param('country'));
        $fideId = ChessPodiumPlugin::sanitize_fide_id((string) $request->get_param('fide_id'));
        $returnUrl = esc_url_raw((string) $request->get_param('return_url'));
        if ($returnUrl === '') {
            $returnUrl = home_url('/');
        }

        if ($name === '' || !is_email($email)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Invalid name or email.', 'chess-podium')], 400);
        }

        if (!ChessPodium_License::is_pro()) {
            return new WP_REST_Response(['success' => false, 'message' => __('Online registration is a Pro feature.', 'chess-podium')], 403);
        }
        $tournament = ChessPodiumPlugin::get_tournament($tid);
        if (!$tournament || !self::is_registration_enabled($tid)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Registration is not open for this tournament.', 'chess-podium')], 400);
        }

        $fee = (float) self::get_registration_fee($tid);
        if ($fee > 0) {
            return new WP_REST_Response(['success' => false, 'message' => __('This tournament requires a paid registration. Use the payment buttons.', 'chess-podium')], 400);
        }

        $players = ChessPodiumPlugin::get_players($tid);
        if (!ChessPodium_License::can_add_players(count($players), 1)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Tournament is full.', 'chess-podium')], 400);
        }

        ChessPodiumPlugin::insert_player(
            $tid,
            $name,
            $rating,
            $fideId ?: null,
            $country ?: null
        );
        self::send_registration_confirmation($email, $name, $tid);

        $redirectUrl = add_query_arg(['cp_reg_success' => '1', 'tournament_id' => $tid], $returnUrl);
        return new WP_REST_Response(['success' => true, 'url' => $redirectUrl]);
    }

    public static function rest_create_checkout(WP_REST_Request $request): WP_REST_Response
    {
        $tid = (int) $request->get_param('tournament_id');
        $name = trim((string) $request->get_param('name'));
        $email = sanitize_email((string) $request->get_param('email'));
        $rating = max(0, min(3500, (int) $request->get_param('rating')));
        $country = ChessPodiumPlugin::sanitize_country_code((string) $request->get_param('country'));
        $fideId = ChessPodiumPlugin::sanitize_fide_id((string) $request->get_param('fide_id'));
        $method = $request->get_param('payment_method');
        $returnUrl = esc_url_raw((string) $request->get_param('return_url'));
        if ($returnUrl === '') {
            $returnUrl = home_url('/');
        }

        if ($name === '' || !is_email($email)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Invalid name or email.', 'chess-podium')], 400);
        }

        if (!ChessPodium_License::is_pro()) {
            return new WP_REST_Response(['success' => false, 'message' => __('Online registration is a Pro feature.', 'chess-podium')], 403);
        }
        $tournament = ChessPodiumPlugin::get_tournament($tid);
        if (!$tournament || !self::is_registration_enabled($tid)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Registration is not open for this tournament.', 'chess-podium')], 400);
        }

        $fee = (float) self::get_registration_fee($tid);
        if ($fee <= 0) {
            return new WP_REST_Response(['success' => false, 'message' => __('Registration fee not configured.', 'chess-podium')], 400);
        }

        $currency = strtoupper(self::get_registration_currency($tid) ?: 'EUR');
        $players = ChessPodiumPlugin::get_players($tid);
        if (!ChessPodium_License::can_add_players(count($players), 1)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Tournament is full.', 'chess-podium')], 400);
        }

        if ($method === 'stripe') {
            $url = self::create_stripe_checkout($tid, $name, $email, $rating, $country, $fideId, $fee, $currency, $returnUrl);
            if (is_wp_error($url)) {
                return new WP_REST_Response(['success' => false, 'message' => $url->get_error_message()], 500);
            }
            return new WP_REST_Response(['success' => true, 'url' => $url]);
        }

        if ($method === 'paypal') {
            $data = self::create_paypal_order($tid, $name, $email, $rating, $country, $fideId, $fee, $currency, $returnUrl);
            if (is_wp_error($data)) {
                return new WP_REST_Response(['success' => false, 'message' => $data->get_error_message()], 500);
            }
            $approveUrl = '';
            foreach ($data['links'] ?? [] as $link) {
                if (($link['rel'] ?? '') === 'approve') {
                    $approveUrl = $link['href'] ?? '';
                    break;
                }
            }
            if ($approveUrl === '') {
                return new WP_REST_Response(['success' => false, 'message' => __('PayPal did not return a checkout URL.', 'chess-podium')], 500);
            }
            return new WP_REST_Response(['success' => true, 'url' => $approveUrl, 'redirectUrl' => $approveUrl]);
        }

        return new WP_REST_Response(['success' => false, 'message' => __('Invalid payment method.', 'chess-podium')], 400);
    }

    private static function create_stripe_checkout(int $tid, string $name, string $email, int $rating, ?string $country, ?string $fideId, float $fee, string $currency, string $returnUrl = ''): string|WP_Error
    {
        $secret = get_option(self::OPTION_STRIPE_SECRET, '');
        if ($secret === '') {
            return new WP_Error('config', __('Stripe is not configured. Contact the tournament organizer.', 'chess-podium'));
        }

        $baseUrl = $returnUrl !== '' ? $returnUrl : home_url('/');
        $successUrl = add_query_arg(['cp_reg_success' => '1', 'tournament_id' => $tid], $baseUrl);
        $cancelUrl = add_query_arg(['tournament_id' => $tid], $baseUrl);

        $playerData = base64_encode(wp_json_encode([
            'name' => $name,
            'email' => $email,
            'rating' => $rating,
            'country' => $country,
            'fide_id' => $fideId ?: '',
        ]));

        $amountCents = (int) round($fee * 100);
        $body = [
            'mode' => 'payment',
            'customer_email' => $email,
            'line_items[0][price_data][currency]' => strtolower($currency),
            'line_items[0][price_data][product_data][name]' => sprintf(__('Tournament registration', 'chess-podium')),
            'line_items[0][price_data][unit_amount]' => (string) $amountCents,
            'line_items[0][quantity]' => '1',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata[tournament_id]' => (string) $tid,
            'metadata[player_data]' => $playerData,
        ];

        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || empty($data['url'])) {
            $err = $data['error']['message'] ?? __('Stripe error.', 'chess-podium');
            return new WP_Error('stripe', $err);
        }
        return $data['url'];
    }

    private static function create_paypal_order(int $tid, string $name, string $email, int $rating, ?string $country, ?string $fideId, float $fee, string $currency, string $returnUrl = ''): array|WP_Error
    {
        $clientId = get_option(self::OPTION_PAYPAL_CLIENT_ID, '');
        $secret = get_option(self::OPTION_PAYPAL_SECRET, '');
        if ($clientId === '' || $secret === '') {
            return new WP_Error('config', __('PayPal is not configured. Contact the tournament organizer.', 'chess-podium'));
        }

        $mode = get_option(self::OPTION_PAYPAL_MODE, 'sandbox');
        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        $playerData = base64_encode(wp_json_encode([
            'name' => $name,
            'email' => $email,
            'rating' => $rating,
            'country' => $country,
            'fide_id' => $fideId ?: '',
        ]));

        $redirectBase = $returnUrl !== '' ? $returnUrl : home_url('/');
        $paypalReturnUrl = add_query_arg([
            'cp_paypal_return' => '1',
            'tournament_id' => $tid,
            'return_url' => urlencode($redirectBase),
        ], home_url('/'));
        $cancelUrl = $redirectBase;

        $body = wp_json_encode([
            'intent' => 'CAPTURE',
            'application_context' => [
                'return_url' => $paypalReturnUrl,
                'cancel_url' => $cancelUrl,
            ],
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($fee, 2, '.', ''),
                ],
                'description' => __('Tournament registration', 'chess-podium'),
                'custom_id' => $tid . '|' . $playerData,
            ]],
        ]);

        $auth = base64_encode($clientId . ':' . $secret);
        $tokenResponse = wp_remote_post($baseUrl . '/v1/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => 'grant_type=client_credentials',
            'timeout' => 15,
        ]);

        if (is_wp_error($tokenResponse)) {
            return new WP_Error('paypal', __('PayPal connection error:', 'chess-podium') . ' ' . $tokenResponse->get_error_message());
        }
        $tokenCode = wp_remote_retrieve_response_code($tokenResponse);
        $tokenBody = wp_remote_retrieve_body($tokenResponse);
        $tokenData = json_decode($tokenBody, true);
        if ($tokenCode !== 200) {
            $errMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? __('PayPal authentication failed.', 'chess-podium');
            if (($tokenData['error'] ?? '') === 'invalid_client') {
                $errMsg = __('Wrong Client ID or Secret. Check that you use Live credentials with Live mode, or Sandbox credentials with Sandbox mode.', 'chess-podium');
            }
            return new WP_Error('paypal', $errMsg);
        }
        $accessToken = $tokenData['access_token'] ?? '';
        if ($accessToken === '') {
            return new WP_Error('paypal', __('PayPal did not return an access token.', 'chess-podium'));
        }

        $orderResponse = wp_remote_post($baseUrl . '/v2/checkout/orders', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
            'timeout' => 15,
        ]);

        if (is_wp_error($orderResponse)) {
            return new WP_Error('paypal', __('PayPal connection error:', 'chess-podium') . ' ' . $orderResponse->get_error_message());
        }
        $orderCode = wp_remote_retrieve_response_code($orderResponse);
        $orderBody = wp_remote_retrieve_body($orderResponse);
        $orderData = json_decode($orderBody, true);
        if ($orderCode > 299) {
            $details = $orderData['details'][0]['description'] ?? $orderData['message'] ?? __('PayPal order creation failed.', 'chess-podium');
            return new WP_Error('paypal', $details);
        }
        return $orderData;
    }

    public static function process_stripe_webhook(string $payload, string $sig): void
    {
        $secret = get_option(self::OPTION_STRIPE_WEBHOOK, '');
        if ($secret === '') {
            status_header(400);
            exit;
        }
        $parts = explode(',', $sig);
        $timestamp = $v1 = '';
        foreach ($parts as $p) {
            if (strpos($p, 't=') === 0) {
                $timestamp = substr($p, 2);
            }
            if (strpos($p, 'v1=') === 0) {
                $v1 = substr($p, 3);
            }
        }
        if (!$timestamp || !$v1 || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $payload, $secret), $v1)) {
            status_header(400);
            exit;
        }
        $event = json_decode($payload, true);
        if (($event['type'] ?? '') !== 'checkout.session.completed') {
            status_header(200);
            echo 'OK';
            return;
        }
        $session = $event['data']['object'] ?? [];
        $sessionId = $session['id'] ?? '';
        if ($sessionId === '') {
            status_header(200);
            echo 'OK';
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PAYMENTS;
        if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE payment_id = %s", $sessionId))) {
            status_header(200);
            echo 'OK';
            return;
        }
        $tid = (int) ($session['metadata']['tournament_id'] ?? 0);
        $playerDataEnc = $session['metadata']['player_data'] ?? '';
        $playerData = json_decode(base64_decode($playerDataEnc, true) ?: '{}', true);
        if (!$tid || !is_array($playerData) || empty($playerData['name'])) {
            status_header(200);
            echo 'OK';
            return;
        }
        ChessPodiumPlugin::insert_player(
            $tid,
            $playerData['name'],
            (int) ($playerData['rating'] ?? 0),
            !empty($playerData['fide_id']) ? $playerData['fide_id'] : null,
            !empty($playerData['country']) ? $playerData['country'] : null
        );
        $wpdb->insert($table, ['payment_id' => $sessionId, 'tournament_id' => $tid], ['%s', '%d']);
        self::send_registration_confirmation($playerData['email'] ?? '', $playerData['name'], $tid);
        status_header(200);
        echo 'OK';
    }

    public static function capture_paypal_order(string $orderId): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PAYMENTS;
        if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE payment_id = %s", $orderId))) {
            return true;
        }
        $order = self::fetch_paypal_order($orderId);
        if (!$order || !in_array($order['status'] ?? '', ['APPROVED', 'CREATED'], true)) {
            return false;
        }
        $customId = $order['purchase_units'][0]['custom_id'] ?? '';
        $parts = explode('|', $customId, 2);
        $tid = (int) ($parts[0] ?? 0);
        $playerData = json_decode(base64_decode($parts[1] ?? '', true) ?: '{}', true);
        if (!$tid || !is_array($playerData) || empty($playerData['name'])) {
            return false;
        }
        if (!self::capture_paypal_payment($orderId)) {
            return false;
        }
        ChessPodiumPlugin::insert_player(
            $tid,
            $playerData['name'],
            (int) ($playerData['rating'] ?? 0),
            !empty($playerData['fide_id']) ? $playerData['fide_id'] : null,
            !empty($playerData['country']) ? $playerData['country'] : null
        );
        $wpdb->insert($table, ['payment_id' => $orderId, 'tournament_id' => $tid], ['%s', '%d']);
        self::send_registration_confirmation($playerData['email'] ?? '', $playerData['name'], $tid);
        return true;
    }

    private static function fetch_paypal_order(string $orderId): ?array
    {
        $clientId = get_option(self::OPTION_PAYPAL_CLIENT_ID, '');
        $secret = get_option(self::OPTION_PAYPAL_SECRET, '');
        $mode = get_option(self::OPTION_PAYPAL_MODE, 'sandbox');
        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $auth = base64_encode($clientId . ':' . $secret);
        $tokenRes = wp_remote_post($baseUrl . '/v1/oauth2/token', [
            'headers' => ['Authorization' => 'Basic ' . $auth, 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => 'grant_type=client_credentials',
            'timeout' => 15,
        ]);
        if (is_wp_error($tokenRes) || wp_remote_retrieve_response_code($tokenRes) !== 200) {
            return null;
        }
        $token = json_decode(wp_remote_retrieve_body($tokenRes), true)['access_token'] ?? '';
        $getRes = wp_remote_get($baseUrl . '/v2/checkout/orders/' . $orderId, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 15,
        ]);
        if (is_wp_error($getRes) || wp_remote_retrieve_response_code($getRes) !== 200) {
            return null;
        }
        return json_decode(wp_remote_retrieve_body($getRes), true);
    }

    private static function capture_paypal_payment(string $orderId): bool
    {
        $clientId = get_option(self::OPTION_PAYPAL_CLIENT_ID, '');
        $secret = get_option(self::OPTION_PAYPAL_SECRET, '');
        $mode = get_option(self::OPTION_PAYPAL_MODE, 'sandbox');
        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $auth = base64_encode($clientId . ':' . $secret);
        $tokenRes = wp_remote_post($baseUrl . '/v1/oauth2/token', [
            'headers' => ['Authorization' => 'Basic ' . $auth, 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => 'grant_type=client_credentials',
            'timeout' => 15,
        ]);
        if (is_wp_error($tokenRes) || wp_remote_retrieve_response_code($tokenRes) !== 200) {
            return false;
        }
        $token = json_decode(wp_remote_retrieve_body($tokenRes), true)['access_token'] ?? '';
        $captureRes = wp_remote_post($baseUrl . '/v2/checkout/orders/' . $orderId . '/capture', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => '{}',
            'timeout' => 15,
        ]);
        return !is_wp_error($captureRes) && wp_remote_retrieve_response_code($captureRes) >= 200 && wp_remote_retrieve_response_code($captureRes) < 300;
    }

    private static function send_registration_confirmation(string $email, string $name, int $tid): void
    {
        if ($email === '' || !is_email($email)) {
            return;
        }
        $tournament = ChessPodiumPlugin::get_tournament($tid);
        $tournamentName = $tournament ? $tournament->name : __('Tournament', 'chess-podium');
        $subject = sprintf(__('[%s] Registration confirmed', 'chess-podium'), get_bloginfo('name'));
        $message = sprintf(
            __("Hello %s,\n\nYour registration for %s has been confirmed.\n\nYou can follow the tournament at: %s\n\nGood luck!", 'chess-podium'),
            $name,
            $tournamentName,
            ChessPodiumPlugin::get_tournament_url()
        );
        wp_mail($email, $subject, $message, ['Content-Type: text/plain; charset=UTF-8']);
    }

    public static function is_registration_enabled(int $tournamentId): bool
    {
        if (!ChessPodium_License::is_pro()) {
            return false;
        }
        $t = ChessPodiumPlugin::get_tournament($tournamentId);
        return isset($t->registration_enabled) && (int) $t->registration_enabled === 1;
    }

    public static function get_registration_fee(int $tournamentId): float
    {
        $t = ChessPodiumPlugin::get_tournament($tournamentId);
        return isset($t->registration_fee) ? (float) $t->registration_fee : 0.0;
    }

    public static function get_registration_currency(int $tournamentId): string
    {
        $t = ChessPodiumPlugin::get_tournament($tournamentId);
        return isset($t->registration_currency) ? (string) $t->registration_currency : 'EUR';
    }

    public static function has_stripe(): bool
    {
        return trim((string) get_option(self::OPTION_STRIPE_SECRET, '')) !== '';
    }

    public static function has_paypal(): bool
    {
        return trim((string) get_option(self::OPTION_PAYPAL_CLIENT_ID, '')) !== '';
    }

    private static function get_paypal_client_id(): string
    {
        return trim((string) get_option(self::OPTION_PAYPAL_CLIENT_ID, ''));
    }

    public static function render_shortcode(array $atts): string
    {
        if (!ChessPodium_License::is_pro()) {
            $upgradeUrl = apply_filters('chess_podium_upgrade_url', 'https://chesspodium.com/pricing/');
            return '<p class="cp-reg-pro-required">' . esc_html__('Online registration is a Pro feature. Upgrade to Chess Podium Pro to accept registrations with Stripe and PayPal.', 'chess-podium') . ' <a href="' . esc_url($upgradeUrl) . '">' . esc_html__('Upgrade', 'chess-podium') . '</a></p>';
        }
        $atts = shortcode_atts(['id' => 0, 'tournament_id' => 0], $atts, 'chess_podium_registration');
        $tid = (int) ($atts['id'] ?: $atts['tournament_id']);
        $tournament = null;
        if ($tid > 0) {
            $tournament = ChessPodiumPlugin::get_tournament($tid);
        }
        if (!$tournament) {
            $tournament = ChessPodiumPlugin::get_current_tournament();
            if ($tournament) {
                $tid = (int) $tournament->id;
            }
        }
        if (!$tournament) {
            return '<p>' . esc_html__('No tournament found. Create a tournament in Chess Podium admin first.', 'chess-podium') . '</p>';
        }
        if (!self::is_registration_enabled($tid)) {
            return '<p>' . esc_html__('Registration is not open for this tournament.', 'chess-podium') . '</p>';
        }
        $fee = self::get_registration_fee($tid);
        $currency = self::get_registration_currency($tid);
        $players = ChessPodiumPlugin::get_players($tid);
        $canRegister = ChessPodium_License::can_add_players(count($players), 1);
        $hasStripe = self::has_stripe();
        $hasPaypal = self::has_paypal();
        $isFree = $fee <= 0;
        if (!$isFree && !$hasStripe && !$hasPaypal) {
            return '<p>' . esc_html__('Online registration is not configured. Contact the organizer.', 'chess-podium') . '</p>';
        }
        $countries = ChessPodium_CountryHelper::get_countries();
        $restUrl = rest_url('chess-podium/v1/registration/checkout');
        $freeRestUrl = rest_url('chess-podium/v1/registration/register-free');
        $nonce = wp_create_nonce('wp_rest');
        $success = isset($_GET['cp_reg_success']) && (int) ($_GET['tournament_id'] ?? 0) === $tid;

        ob_start();
        ?>
        <div class="cp-registration" id="cp-reg-<?php echo (int) $tid; ?>">
            <?php if ($success): ?>
                <div class="cp-reg-success"><?php echo esc_html__('Registration confirmed! Check your email for details.', 'chess-podium'); ?></div>
            <?php elseif (!$canRegister): ?>
                <p><?php echo esc_html__('This tournament is full. Registration is closed.', 'chess-podium'); ?></p>
            <?php else: ?>
                <h3><?php echo esc_html($tournament->name); ?></h3>
                <p class="cp-reg-fee"><?php echo $isFree ? esc_html__('Free registration', 'chess-podium') : esc_html(sprintf(__('Registration fee: %s %s', 'chess-podium'), number_format($fee, 2), $currency)); ?></p>
                <form class="cp-reg-form" data-tid="<?php echo (int) $tid; ?>" data-rest="<?php echo esc_url($restUrl); ?>" data-free-rest="<?php echo esc_url($freeRestUrl); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-return="<?php echo esc_attr(get_permalink() ?: home_url('/')); ?>" data-free="<?php echo $isFree ? '1' : '0'; ?>">
                    <p>
                        <label><?php esc_html_e('Name', 'chess-podium'); ?> *</label><br>
                        <input type="text" name="name" required>
                    </p>
                    <p>
                        <label><?php esc_html_e('Email', 'chess-podium'); ?> *</label><br>
                        <input type="email" name="email" required>
                    </p>
                    <p>
                        <label><?php esc_html_e('Nationality', 'chess-podium'); ?></label><br>
                        <select name="country">
                            <option value="">— <?php esc_html_e('Select', 'chess-podium'); ?> —</option>
                            <?php foreach ($countries as $code => $label): ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label><?php esc_html_e('Rating', 'chess-podium'); ?></label><br>
                        <input type="number" name="rating" min="0" max="3500" value="0">
                    </p>
                    <p>
                        <label><?php esc_html_e('FIDE ID', 'chess-podium'); ?></label><br>
                        <input type="text" name="fide_id" pattern="[0-9]+" placeholder="<?php esc_attr_e('Optional', 'chess-podium'); ?>">
                    </p>
                    <p class="cp-reg-buttons">
                        <?php if ($isFree): ?>
                            <button type="button" class="cp-reg-free"><?php esc_html_e('Register', 'chess-podium'); ?></button>
                        <?php else: ?>
                            <?php if ($hasStripe): ?>
                                <button type="button" class="cp-pay-stripe"><?php esc_html_e('Pay with Stripe', 'chess-podium'); ?></button>
                            <?php endif; ?>
                            <?php if ($hasPaypal): ?>
                                <button type="button" class="cp-pay-paypal"><?php esc_html_e('Pay with PayPal', 'chess-podium'); ?></button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
        $pluginDir = dirname(dirname(__FILE__));
        wp_enqueue_style('chess-podium-registration', plugins_url('assets/registration.css', $pluginDir . '/chess-podium.php'), [], '0.3.0');
        wp_enqueue_script('chess-podium-registration', plugins_url('assets/registration.js', $pluginDir . '/chess-podium.php'), [], '0.3.0', true);
        return ob_get_clean();
    }

    public static function render_settings_page(): void
    {
        if (!ChessPodium_License::is_pro()) {
            echo '<div class="wrap"><p>' . esc_html__('Online registration is a Pro feature. Upgrade to Chess Podium Pro.', 'chess-podium') . '</p></div>';
            return;
        }
        if (isset($_POST['chess_podium_registration_save']) && check_admin_referer('chess_podium_registration_settings')) {
            update_option(self::OPTION_STRIPE_SECRET, sanitize_text_field(wp_unslash($_POST['stripe_secret'] ?? '')));
            update_option(self::OPTION_STRIPE_WEBHOOK, sanitize_text_field(wp_unslash($_POST['stripe_webhook'] ?? '')));
            update_option(self::OPTION_PAYPAL_CLIENT_ID, sanitize_text_field(wp_unslash($_POST['paypal_client_id'] ?? '')));
            update_option(self::OPTION_PAYPAL_SECRET, sanitize_text_field(wp_unslash($_POST['paypal_secret'] ?? '')));
            update_option(self::OPTION_PAYPAL_MODE, sanitize_key(wp_unslash($_POST['paypal_mode'] ?? 'sandbox')));
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'chess-podium') . '</p></div>';
        }
        $webhookUrl = home_url('/chess-podium-registration-webhook');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Registration payments', 'chess-podium'); ?></h1>
            <p><?php esc_html_e('Configure Stripe and/or PayPal so that players can pay the registration fee online. You can enable one or both. Enable registration per tournament in the tournament edit page.', 'chess-podium'); ?></p>

            <div class="notice notice-info" style="margin:20px 0;padding:15px;">
                <p><strong><?php esc_html_e('How it works', 'chess-podium'); ?></strong></p>
                <p><?php esc_html_e('You need a free account on Stripe and/or PayPal. They handle the payment securely. The money goes to your club\'s account. Chess Podium only needs a few codes (keys) to connect your site to these services.', 'chess-podium'); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('chess_podium_registration_settings'); ?>
                <input type="hidden" name="chess_podium_registration_save" value="1">

                <h2>Stripe</h2>
                <details class="cp-payment-help" style="margin-bottom:15px;padding:12px;border:1px solid #c3c4c7;border-radius:4px;background:#f6f7f7;">
                    <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('How to get Stripe keys (step by step)', 'chess-podium'); ?></summary>
                    <ol style="margin:15px 0 0 20px;line-height:1.8;">
                        <li><?php esc_html_e('Go to stripe.com and click "Start now" to create a free account.', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('Complete the registration with your club\'s details (name, address, bank account for receiving payments).', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('In the Stripe dashboard, click "Developers" in the top menu, then "API keys".', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('Under "Standard keys", copy the "Secret key" (starts with sk_live_ or sk_test_) and paste it below.', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('For the webhook: click "Developers" → "Webhooks" → "Add endpoint".', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('Endpoint URL: paste this address exactly:', 'chess-podium'); ?> <code style="background:#fff;padding:2px 6px;"><?php echo esc_html($webhookUrl); ?></code></li>
                        <li><?php esc_html_e('Click "Select events" and choose only "checkout.session.completed", then "Add endpoint".', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('After creating the webhook, click on it and reveal the "Signing secret" (starts with whsec_). Copy it and paste it in the "Webhook secret" field below.', 'chess-podium'); ?></li>
                    </ol>
                    <p style="margin-top:12px;"><strong><?php esc_html_e('Test mode:', 'chess-podium'); ?></strong> <?php esc_html_e('Use keys starting with sk_test_ and whsec_ from a test webhook to try without real payments. Switch to live keys when ready.', 'chess-podium'); ?></p>
                    <p style="margin-top:8px;"><strong><?php esc_html_e('Webhook not working?', 'chess-podium'); ?></strong> <?php esc_html_e('Go to Settings → Permalinks and click Save. This refreshes the site URLs so the webhook can receive Stripe notifications.', 'chess-podium'); ?></p>
                </details>
                <table class="form-table">
                    <tr>
                        <th><label for="stripe_secret"><?php esc_html_e('Secret key', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="stripe_secret" name="stripe_secret" type="password" class="regular-text" value="<?php echo esc_attr(get_option(self::OPTION_STRIPE_SECRET, '')); ?>">
                            <p class="description"><?php esc_html_e('From Stripe → Developers → API keys. Starts with sk_live_ or sk_test_.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="stripe_webhook"><?php esc_html_e('Webhook secret', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="stripe_webhook" name="stripe_webhook" type="password" class="large-text" value="<?php echo esc_attr(get_option(self::OPTION_STRIPE_WEBHOOK, '')); ?>">
                            <p class="description"><?php esc_html_e('Webhook URL:', 'chess-podium'); ?> <code><?php echo esc_html($webhookUrl); ?></code>. <?php esc_html_e('Event: checkout.session.completed. From Developers → Webhooks → your endpoint → Signing secret.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2>PayPal</h2>
                <details class="cp-payment-help" style="margin-bottom:15px;padding:12px;border:1px solid #c3c4c7;border-radius:4px;background:#f6f7f7;">
                    <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('How to get PayPal keys (step by step)', 'chess-podium'); ?></summary>
                    <ol style="margin:15px 0 0 20px;line-height:1.8;">
                        <li><?php esc_html_e('Go to developer.paypal.com and log in with your PayPal account (or create one).', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('In the top menu, click "Apps & Credentials".', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('Under "REST API apps", click "Create App". Give it a name (e.g. "Chess Club Registration") and click Create.', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('You will see "Client ID" and "Secret". Click "Show" next to Secret to reveal it.', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('Copy the Client ID and paste it in the field below.', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('Copy the Secret and paste it in the Secret field below.', 'chess-podium'); ?></li>
                        <li><?php esc_html_e('Choose "Sandbox" to test with fake payments, or "Live" when you are ready to accept real payments.', 'chess-podium'); ?></li>
                    </ol>
                    <p style="margin-top:12px;"><strong><?php esc_html_e('Sandbox vs Live:', 'chess-podium'); ?></strong> <?php esc_html_e('Sandbox uses test credentials (from the Sandbox tab) for practice. Live uses your real PayPal business account. For Live, your PayPal account must be verified.', 'chess-podium'); ?></p>
                    <p style="margin-top:8px;"><strong><?php esc_html_e('Button not working?', 'chess-podium'); ?></strong> <?php esc_html_e('Ensure Mode matches your credentials: if you copied Client ID and Secret from the Live tab in PayPal, set Mode to Live. If from Sandbox tab, set Mode to Sandbox. Using Live credentials with Sandbox mode (or vice versa) will fail.', 'chess-podium'); ?></p>
                </details>
                <table class="form-table">
                    <tr>
                        <th><label for="paypal_client_id"><?php esc_html_e('Client ID', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="paypal_client_id" name="paypal_client_id" type="text" class="regular-text" value="<?php echo esc_attr(get_option(self::OPTION_PAYPAL_CLIENT_ID, '')); ?>">
                            <p class="description"><?php esc_html_e('From developer.paypal.com → Apps & Credentials → your app.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="paypal_secret"><?php esc_html_e('Secret', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="paypal_secret" name="paypal_secret" type="password" class="regular-text" value="<?php echo esc_attr(get_option(self::OPTION_PAYPAL_SECRET, '')); ?>">
                            <p class="description"><?php esc_html_e('Click "Show" next to Secret in your PayPal app, then copy it here.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="paypal_mode"><?php esc_html_e('Mode', 'chess-podium'); ?></label></th>
                        <td>
                            <select id="paypal_mode" name="paypal_mode">
                                <option value="sandbox" <?php selected(get_option(self::OPTION_PAYPAL_MODE), 'sandbox'); ?>><?php esc_html_e('Sandbox (test)', 'chess-podium'); ?></option>
                                <option value="live" <?php selected(get_option(self::OPTION_PAYPAL_MODE), 'live'); ?>><?php esc_html_e('Live (real payments)', 'chess-podium'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Use Sandbox to test. Switch to Live when ready to accept real payments.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php esc_html_e('Save', 'chess-podium'); ?></button></p>
            </form>
        </div>
        <?php
    }
}
