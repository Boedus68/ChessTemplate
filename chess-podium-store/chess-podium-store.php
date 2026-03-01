<?php
/**
 * Plugin Name: Chess Podium Store
 * Description: License API, Stripe checkout, and license management for Chess Podium Pro.
 * Version: 1.0.0
 * Author: Chess Podium
 * Text Domain: chess-podium-store
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHESS_PODIUM_STORE_VERSION', '1.0.0');
define('CHESS_PODIUM_STORE_PATH', plugin_dir_path(__FILE__));
define('CHESS_PODIUM_STORE_URL', plugin_dir_url(__FILE__));

require_once CHESS_PODIUM_STORE_PATH . 'includes/class-license-manager.php';
require_once CHESS_PODIUM_STORE_PATH . 'includes/class-license-api.php';
require_once CHESS_PODIUM_STORE_PATH . 'includes/class-stripe-checkout.php';

final class ChessPodium_Store
{
    public static function init(): void
    {
        load_plugin_textdomain('chess-podium-store', false, dirname(plugin_basename(__FILE__)) . '/languages');
        add_filter('chess_podium_license_api_url', [self::class, 'filter_license_api_url']);
        add_action('init', [self::class, 'register_rewrite']);
        add_filter('query_vars', [self::class, 'register_query_vars']);
        add_action('template_redirect', [self::class, 'handle_license_api']);
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
        add_action('admin_menu', [self::class, 'register_admin_menu']);
        add_action('admin_init', [self::class, 'handle_admin_actions']);
        add_action('wp_ajax_chess_podium_create_checkout', [self::class, 'ajax_create_checkout']);
        add_action('wp_ajax_nopriv_chess_podium_create_checkout', [self::class, 'ajax_create_checkout']);
    }

    public static function activate(): void
    {
        ChessPodium_Store_LicenseManager::create_tables();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function register_rewrite(): void
    {
        add_rewrite_rule('^api/validate-license/?$', 'index.php?chess_podium_api=validate', 'top');
        add_rewrite_rule('^api/stripe-webhook/?$', 'index.php?chess_podium_api=stripe_webhook', 'top');
    }

    public static function register_query_vars(array $vars): array
    {
        $vars[] = 'chess_podium_api';
        return $vars;
    }

    public static function handle_license_api(): void
    {
        $action = get_query_var('chess_podium_api');
        if ($action === 'validate') {
            ChessPodium_Store_LicenseAPI::handle_request();
            exit;
        }
        if ($action === 'stripe_webhook') {
            self::handle_stripe_webhook();
            exit;
        }
    }

    public static function register_rest_routes(): void
    {
        register_rest_route('chess-podium/v1', '/validate-license', [
            'methods' => 'POST',
            'callback' => [ChessPodium_Store_LicenseAPI::class, 'rest_validate'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function register_admin_menu(): void
    {
        add_menu_page(
            __('Chess Podium Store', 'chess-podium-store'),
            __('CP Store', 'chess-podium-store'),
            'manage_options',
            'chess-podium-store',
            [self::class, 'render_admin_page'],
            'dashicons-cart',
            58
        );
    }

    public static function render_admin_page(): void
    {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'licenses';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Chess Podium Store', 'chess-podium-store'); ?></h1>
            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('admin.php?page=chess-podium-store&tab=licenses')); ?>" class="nav-tab <?php echo $tab === 'licenses' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Licenses', 'chess-podium-store'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=chess-podium-store&tab=settings')); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Settings', 'chess-podium-store'); ?></a>
            </nav>
            <?php
            if ($tab === 'settings') {
                ChessPodium_Store_StripeCheckout::render_settings();
            } else {
                ChessPodium_Store_LicenseManager::render_licenses_page();
            }
            ?>
        </div>
        <?php
    }

    public static function handle_admin_actions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['chess_podium_manual_license']) && check_admin_referer('chess_podium_manual_license')) {
            $email = isset($_POST['manual_email']) ? sanitize_email(wp_unslash($_POST['manual_email'])) : '';
            $domain = isset($_POST['manual_domain']) ? trim(sanitize_text_field(wp_unslash($_POST['manual_domain']))) : null;
            if ($domain === '') {
                $domain = null;
            }
            if ($email !== '' && is_email($email)) {
                $license = ChessPodium_Store_LicenseManager::create_license($email, null, null, $domain);
                if ($license) {
                    ChessPodium_Store_LicenseManager::send_license_email($email, $license['license_key']);
                    wp_safe_redirect(admin_url('admin.php?page=chess-podium-store&manual_created=1'));
                    exit;
                }
            }
        }
        if (isset($_POST['chess_podium_clear_domain']) && is_numeric($_POST['chess_podium_clear_domain'])) {
            $id = (int) $_POST['chess_podium_clear_domain'];
            if (wp_verify_nonce($_POST['_wpnonce'] ?? '', 'chess_podium_clear_domain_' . $id)) {
                ChessPodium_Store_LicenseManager::clear_domain($id);
                wp_safe_redirect(admin_url('admin.php?page=chess-podium-store&domain_cleared=1'));
                exit;
            }
        }
        if (isset($_POST['chess_podium_store_save_settings']) && check_admin_referer('chess_podium_store_settings')) {
            $secret = isset($_POST['stripe_secret_key']) ? sanitize_text_field(wp_unslash($_POST['stripe_secret_key'])) : '';
            $webhook = isset($_POST['stripe_webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['stripe_webhook_secret'])) : '';
            $price = isset($_POST['stripe_price_id']) ? sanitize_text_field(wp_unslash($_POST['stripe_price_id'])) : '';
            update_option('chess_podium_stripe_secret_key', $secret);
            update_option('chess_podium_stripe_webhook_secret', $webhook);
            update_option('chess_podium_stripe_price_id', $price);
            wp_safe_redirect(admin_url('admin.php?page=chess-podium-store&tab=settings&saved=1'));
            exit;
        }
    }

    public static function ajax_create_checkout(): void
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'chess_podium_checkout')) {
            wp_send_json_error(['message' => __('Security check failed.', 'chess-podium-store')]);
        }
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $plan = isset($_POST['plan']) ? sanitize_key(wp_unslash($_POST['plan'])) : 'pro';
        if ($email === '' || !is_email($email)) {
            wp_send_json_error(['message' => __('Valid email required.', 'chess-podium-store')]);
        }
        $result = ChessPodium_Store_StripeCheckout::create_checkout_session($email, $plan);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['url' => $result]);
    }

    public static function handle_stripe_webhook(): void
    {
        $payload = file_get_contents('php://input');
        $sig = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
        $result = ChessPodium_Store_StripeCheckout::process_webhook($payload, $sig);
        if (is_wp_error($result)) {
            status_header(400);
            echo $result->get_error_message();
            exit;
        }
        status_header(200);
        echo 'OK';
        exit;
    }

    public static function get_api_url(): string
    {
        return rest_url('chess-podium/v1/validate-license');
    }

    /** When Store is on the same site as Chess Podium, use local API for validation. */
    public static function filter_license_api_url(string $url): string
    {
        return self::get_api_url();
    }
}

add_action('plugins_loaded', ['ChessPodium_Store', 'init']);
register_activation_hook(__FILE__, ['ChessPodium_Store', 'activate']);
register_deactivation_hook(__FILE__, ['ChessPodium_Store', 'deactivate']);
