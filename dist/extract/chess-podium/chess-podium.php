<?php
/**
 * Plugin Name: Chess Podium
 * Plugin URI: https://chesspodium.com
 * Description: Chess tournament manager for WordPress: players, rounds, Swiss pairings, results, standings, and exports. Free plan: up to 10 players per tournament. Upgrade to Pro for unlimited players.
 * Version: 0.3.0
 * Author: Chess Podium
 * Author URI: https://chesspodium.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: chess-podium
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-export-engine.php';
require_once __DIR__ . '/includes/class-chess-podium-widget.php';
require_once __DIR__ . '/includes/class-country-helper.php';
require_once __DIR__ . '/includes/class-license.php';

final class ChessPodiumPlugin
{
    private const DB_VERSION = '0.3.0';
    private const MENU_SLUG = 'chess-podium';

    public static function init(): void
    {
        add_action('plugins_loaded', [self::class, 'load_textdomain']);
        add_action('admin_menu', [self::class, 'register_admin_menu']);
        add_action('admin_head', [self::class, 'enqueue_admin_styles']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_external_tournaments_scripts']);
        add_action('admin_init', [self::class, 'handle_admin_actions']);
        add_action('admin_init', [self::class, 'handle_export_requests']);
        add_shortcode('chess_podium_tournament', [self::class, 'render_tournament_shortcode']);
        add_shortcode('chess_podium_tornei', [self::class, 'render_tornei_page_shortcode']);
        add_shortcode('checkmate_manager_tornei', [self::class, 'render_tornei_page_shortcode']);
        add_shortcode('storico_tornei', [self::class, 'render_tornei_page_shortcode']);
        // Backward compatibility with previous shortcode.
        add_shortcode('regina_torneo', [self::class, 'render_tournament_shortcode']);
        add_action('init', [self::class, 'register_rewrite']);
        add_action('chess_podium_license_check', [ChessPodium_License::class, 'force_revalidate']);
        add_action('widgets_init', [self::class, 'register_widgets']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_widget_styles']);
        add_filter('query_vars', [self::class, 'register_query_vars']);
        add_action('template_redirect', [self::class, 'handle_public_route']);
        self::maybe_run_schema_updates();
        self::maybe_create_tornei_page();
    }

    public static function activate(): void
    {
        self::create_tables();
        add_option('chess_podium_db_version', self::DB_VERSION);
        self::register_rewrite();
        flush_rewrite_rules();
        self::maybe_create_tornei_page();
        if (!wp_next_scheduled('chess_podium_license_check')) {
            wp_schedule_event(time(), 'daily', 'chess_podium_license_check');
        }
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('chess_podium_license_check');
    }

    public static function register_rewrite(): void
    {
        add_rewrite_rule('^torneo/?$', 'index.php?chess_podium_torneo_page=1', 'top');
        add_rewrite_rule('^currenttournament/?$', 'index.php?chess_podium_torneo_page=1', 'top');
    }

    public static function register_query_vars(array $vars): array
    {
        $vars[] = 'chess_podium_torneo_page';
        return $vars;
    }

    public static function handle_public_route(): void
    {
        if ((int) get_query_var('chess_podium_torneo_page') !== 1) {
            return;
        }

        $tournament = self::get_tournament_in_progress();
        status_header(200);
        nocache_headers();
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html(get_bloginfo('name') . ' - ' . ($tournament ? $tournament->name : __('Current tournament', 'chess-podium'))); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; background: #f7f7f7; color: #222; }
                .wrap { max-width: 1100px; margin: 2rem auto; background: #fff; padding: 1.5rem; border-radius: 10px; }
                h1,h2 { margin-top: 0; }
                table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
                th, td { border: 1px solid #ddd; padding: 0.5rem; text-align: left; }
                th { background: #f1f1f1; }
                .muted { color: #666; }
                .cp-no-tournament { text-align: center; padding: 3rem 1rem; }
            </style>
        </head>
        <body>
        <div class="wrap">
            <?php if (!$tournament): ?>
                <div class="cp-no-tournament">
                    <h1><?php echo esc_html__('No tournament in progress', 'chess-podium'); ?></h1>
                    <p class="muted"><?php echo esc_html__('There is no tournament currently being played. Check back later or contact the tournament director.', 'chess-podium'); ?></p>
                </div>
            <?php else: ?>
                <?php echo self::render_public_block($tournament); // phpcs:ignore ?>
            <?php endif; ?>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    public static function enqueue_external_tournaments_scripts(string $hook): void
    {
        if ($hook !== 'chess-podium_page_chess-podium-external-tournaments') {
            return;
        }
        wp_enqueue_media();
    }

    public static function enqueue_admin_styles(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, self::MENU_SLUG) === false) {
            return;
        }
        echo '<style>
            .cp-flag { font-size: 1.1em; vertical-align: middle; }
            @media print {
                .wrap h1, .wrap hr, .wrap form, .wrap .button, .wrap a[href*="admin.php"], #wpadminbar, #adminmenumain, .update-nag { display: none !important; }
                .checkmate-print-block { background: #fff !important; border: 1px solid #ccc !important; }
            }
        </style>';
    }

    public static function load_textdomain(): void
    {
        $langDir = __DIR__ . '/languages';
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $moFile = $langDir . '/chess-podium-' . $locale . '.mo';
        $poFile = $langDir . '/chess-podium-' . $locale . '.po';
        if (!file_exists($moFile) && file_exists($poFile)) {
            self::compile_po_to_mo($poFile, $moFile);
        }
        load_plugin_textdomain('chess-podium', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    private static function compile_po_to_mo(string $poFile, string $moFile): bool
    {
        $phpMo = dirname($poFile) . '/php-mo.php';
        if (!file_exists($phpMo)) {
            return false;
        }
        require_once $phpMo;
        return function_exists('phpmo_convert') && phpmo_convert($poFile, $moFile);
    }

    public static function render_add_to_page(): void
    {
        $shortcode = '[chess_podium_tornei]';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Add tournaments to a page', 'chess-podium'); ?></h1>
            <p><?php echo esc_html__('Use this shortcode to display published tournaments on any page or post:', 'chess-podium'); ?></p>
            <div style="background:#f0f0f1;padding:1rem;border-radius:4px;margin:1rem 0;font-family:monospace;font-size:1.1em;">
                <?php echo esc_html($shortcode); ?>
            </div>
            <p><strong><?php echo esc_html__('How to add:', 'chess-podium'); ?></strong></p>
            <ol style="list-style:decimal;margin-left:1.5rem;">
                <li><?php echo esc_html__('Edit the page or post where you want to show the tournaments.', 'chess-podium'); ?></li>
                <li><?php echo esc_html__('Add a "Shortcode" block (search for "Shortcode" in the block inserter).', 'chess-podium'); ?></li>
                <li><?php echo esc_html__('Paste the shortcode above into the block.', 'chess-podium'); ?></li>
                <li><?php echo esc_html__('Save and publish.', 'chess-podium'); ?></li>
            </ol>
            <p>
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=page')); ?>" class="button button-primary">
                    <?php echo esc_html__('Create new page', 'chess-podium'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public static function render_external_tournaments_page(): void
    {
        $msg = isset($_GET['cp_msg']) ? sanitize_key(wp_unslash($_GET['cp_msg'])) : '';
        $editId = isset($_GET['edit_ext']) ? sanitize_key(wp_unslash($_GET['edit_ext'])) : '';
        $external = self::get_external_tournaments();
        $editItem = null;
        if ($editId !== '') {
            foreach ($external as $e) {
                if (($e['id'] ?? '') === $editId) {
                    $editItem = $e;
                    break;
                }
            }
        }
        $isEdit = $editItem !== null;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('External tournaments', 'chess-podium'); ?></h1>
            <p><?php echo esc_html__('Add tournaments created by other software (e.g. Vega, Swiss Manager) that are already hosted on your site. They will appear on the tournaments page with the same layout as Chess Podium tournaments.', 'chess-podium'); ?></p>

            <?php if ($msg === 'ext_tournament_added'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('External tournament added.', 'chess-podium'); ?></p></div>
            <?php elseif ($msg === 'ext_tournament_updated'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('External tournament updated.', 'chess-podium'); ?></p></div>
            <?php elseif ($msg === 'ext_tournament_deleted'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('External tournament removed.', 'chess-podium'); ?></p></div>
            <?php elseif ($msg === 'ext_tournament_error'): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html__('Error: enter name and URL.', 'chess-podium'); ?></p></div>
            <?php endif; ?>

            <h2><?php echo $isEdit ? esc_html__('Edit external tournament', 'chess-podium') : esc_html__('Add external tournament', 'chess-podium'); ?></h2>
            <form method="post" enctype="multipart/form-data" style="max-width:600px;">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="<?php echo $isEdit ? 'update_external_tournament' : 'add_external_tournament'; ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="ext_id" value="<?php echo esc_attr($editItem['id'] ?? ''); ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="ext_name"><?php echo esc_html__('Tournament name', 'chess-podium'); ?></label></th>
                        <td><input id="ext_name" name="ext_name" type="text" required class="regular-text" placeholder="<?php echo esc_attr__('e.g. Campionato 2024', 'chess-podium'); ?>" value="<?php echo esc_attr($editItem['name'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="ext_url"><?php echo esc_html__('URL', 'chess-podium'); ?></label></th>
                        <td><input id="ext_url" name="ext_url" type="url" required class="large-text" placeholder="https://..." value="<?php echo esc_attr($editItem['url'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="ext_preview"><?php echo esc_html__('Preview image', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="ext_preview" name="ext_preview" type="url" class="large-text" placeholder="https://..." value="<?php echo esc_attr($editItem['preview_url'] ?? ''); ?>">
                            <p class="description"><?php echo esc_html__('URL or upload below. Optional. If empty, the default logo will be used.', 'chess-podium'); ?></p>
                            <p>
                                <input type="file" name="ext_preview_file" id="ext_preview_file" accept="image/*">
                                <button type="button" class="button" id="cp_ext_select_media"><?php echo esc_html__('Select from Media Library', 'chess-podium'); ?></button>
                            </p>
                            <?php if (!empty($editItem['preview_url'])): ?>
                                <p><img src="<?php echo esc_url($editItem['preview_url']); ?>" alt="" style="max-width:120px;max-height:80px;object-fit:cover;border:1px solid #ccc;"></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ext_rounds"><?php echo esc_html__('Rounds', 'chess-podium'); ?></label></th>
                        <td><input id="ext_rounds" name="ext_rounds" type="number" min="0" value="<?php echo esc_attr((int) ($editItem['rounds'] ?? 0)); ?>" class="small-text"> <span class="description"><?php echo esc_html__('Optional. Used for display (e.g. "5 rounds").', 'chess-podium'); ?></span></td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" type="submit"><?php echo $isEdit ? esc_html__('Update tournament', 'chess-podium') : esc_html__('Add tournament', 'chess-podium'); ?></button>
                    <?php if ($isEdit): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=chess-podium-external-tournaments')); ?>" class="button"><?php echo esc_html__('Cancel', 'chess-podium'); ?></a>
                    <?php endif; ?>
                </p>
            </form>

            <h2><?php echo esc_html__('External tournaments list', 'chess-podium'); ?></h2>
            <?php if (empty($external)): ?>
                <p><?php echo esc_html__('No external tournaments added.', 'chess-podium'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Name', 'chess-podium'); ?></th><th><?php echo esc_html__('URL', 'chess-podium'); ?></th><th><?php echo esc_html__('Preview', 'chess-podium'); ?></th><th><?php echo esc_html__('Rounds', 'chess-podium'); ?></th><th><?php echo esc_html__('Actions', 'chess-podium'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($external as $e): ?>
                        <tr>
                            <td><?php echo esc_html($e['name'] ?? ''); ?></td>
                            <td><a href="<?php echo esc_url($e['url'] ?? '#'); ?>" target="_blank" rel="noopener"><?php echo esc_html($e['url'] ?? ''); ?></a></td>
                            <td><?php echo !empty($e['preview_url']) ? '<img src="' . esc_url($e['preview_url']) . '" alt="" style="max-width:80px;max-height:50px;object-fit:cover;">' : '—'; ?></td>
                            <td><?php echo (int) ($e['rounds'] ?? 0); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=chess-podium-external-tournaments&edit_ext=' . rawurlencode($e['id'] ?? ''))); ?>" class="button"><?php echo esc_html__('Edit', 'chess-podium'); ?></a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Remove this external tournament?', 'chess-podium')); ?>');">
                                    <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                                    <input type="hidden" name="regina_action" value="delete_external_tournament">
                                    <input type="hidden" name="ext_id" value="<?php echo esc_attr($e['id'] ?? ''); ?>">
                                    <button type="submit" class="button button-link-delete"><?php echo esc_html__('Remove', 'chess-podium'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <script>
        (function() {
            var btn = document.getElementById('cp_ext_select_media');
            var input = document.getElementById('ext_preview');
            if (btn && input && typeof wp !== 'undefined' && wp.media) {
                btn.addEventListener('click', function() {
                    var frame = wp.media({
                        title: '<?php echo esc_js(__('Select preview image', 'chess-podium')); ?>',
                        library: { type: 'image' },
                        multiple: false,
                        button: { text: '<?php echo esc_js(__('Use image', 'chess-podium')); ?>' }
                    });
                    frame.on('select', function() {
                        var att = frame.state().get('selection').first().toJSON();
                        if (att && att.url) input.value = att.url;
                    });
                    frame.open();
                });
            }
        })();
        </script>
        <?php
    }

    public static function render_license_page(): void
    {
        $msg = isset($_GET['cp_msg']) ? sanitize_key(wp_unslash($_GET['cp_msg'])) : '';
        $data = ChessPodium_License::get_license_data();
        $isPro = ChessPodium_License::is_pro();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Chess Podium License', 'chess-podium'); ?></h1>

            <?php if ($msg === 'license_saved'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('License saved. Validating...', 'chess-podium'); ?></p></div>
            <?php elseif ($msg === 'license_removed'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('License removed.', 'chess-podium'); ?></p></div>
            <?php endif; ?>

            <div style="max-width:600px;margin:1.5rem 0;">
                <?php if ($isPro): ?>
                    <div class="notice notice-success" style="padding:1rem;">
                        <p><strong><?php echo esc_html__('Chess Podium Pro', 'chess-podium'); ?></strong> – <?php echo esc_html__('Unlimited players. License active.', 'chess-podium'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-info" style="padding:1rem;">
                        <p><strong><?php echo esc_html__('Free version', 'chess-podium'); ?></strong> – <?php echo esc_html(sprintf(__('Limit: %d players per tournament.', 'chess-podium'), ChessPodium_License::get_free_player_limit())); ?></p>
                        <p><?php echo esc_html__('Upgrade to Pro for unlimited players, priority support, and more. €79/year.', 'chess-podium'); ?></p>
                    </div>
                <?php endif; ?>

                <h2><?php echo esc_html__('License key', 'chess-podium'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('chess_podium_license', 'chess_podium_license_nonce'); ?>
                    <input type="hidden" name="chess_podium_license_action" value="save">
                    <table class="form-table">
                        <tr>
                            <th><label for="license_key"><?php echo esc_html__('License key', 'chess-podium'); ?></label></th>
                            <td><input id="license_key" name="license_key" type="text" class="regular-text" value="<?php echo esc_attr($data['key'] ?? ''); ?>" placeholder="XXXX-XXXX-XXXX-XXXX"></td>
                        </tr>
                        <tr>
                            <th><label for="license_email"><?php echo esc_html__('Email', 'chess-podium'); ?></label></th>
                            <td><input id="license_email" name="license_email" type="email" class="regular-text" value="<?php echo esc_attr($data['email'] ?? ''); ?>"></td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Save license', 'chess-podium'); ?></button>
                        <?php if (!empty($data['key'])): ?>
                            <button type="submit" name="chess_podium_license_action" value="remove" class="button" formnovalidate onclick="return confirm('<?php echo esc_js(__('Remove license?', 'chess-podium')); ?>');"><?php echo esc_html__('Remove license', 'chess-podium'); ?></button>
                        <?php endif; ?>
                    </p>
                </form>
                <p class="description"><?php echo esc_html__('Enter the license key you received after purchasing Chess Podium Pro. Validation runs periodically.', 'chess-podium'); ?></p>
            </div>
        </div>
        <?php
    }

    public static function register_widgets(): void
    {
        register_widget(ChessPodium_Widget::class);
    }

    public static function enqueue_widget_styles(): void
    {
        if (is_active_widget(false, false, 'chess_podium_tornei', true)) {
            wp_enqueue_style(
                'chess-podium-widget',
                plugins_url('assets/widget.css', __FILE__),
                [],
                '0.3.0'
            );
        }
    }

    public static function register_admin_menu(): void
    {
        add_menu_page(
            'Chess Podium',
            'Chess Podium',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render_admin_page'],
            'dashicons-games',
            26
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Add to page', 'chess-podium'),
            __('Add to page', 'chess-podium'),
            'edit_posts',
            'chess-podium-add-to-page',
            [self::class, 'render_add_to_page']
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('External tournaments', 'chess-podium'),
            __('External tournaments', 'chess-podium'),
            'manage_options',
            'chess-podium-external-tournaments',
            [self::class, 'render_external_tournaments_page']
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('License', 'chess-podium'),
            __('License', 'chess-podium'),
            'manage_options',
            'chess-podium-license',
            [self::class, 'render_license_page']
        );
    }

    public static function handle_admin_actions(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!empty($_POST['chess_podium_license_action'])) {
            check_admin_referer('chess_podium_license', 'chess_podium_license_nonce');
            $licAction = sanitize_text_field(wp_unslash($_POST['chess_podium_license_action']));
            if ($licAction === 'save') {
                $key = isset($_POST['license_key']) ? trim(sanitize_text_field(wp_unslash($_POST['license_key']))) : '';
                $email = isset($_POST['license_email']) ? trim(sanitize_email(wp_unslash($_POST['license_email']))) : '';
                ChessPodium_License::save_license($key, $email);
                wp_safe_redirect(admin_url('admin.php?page=chess-podium-license&cp_msg=license_saved'));
                exit;
            }
            if ($licAction === 'remove') {
                ChessPodium_License::remove_license();
                wp_safe_redirect(admin_url('admin.php?page=chess-podium-license&cp_msg=license_removed'));
                exit;
            }
        }

        if (empty($_POST['regina_action'])) {
            return;
        }

        check_admin_referer('regina_torneo_action', 'regina_nonce');

        $action = sanitize_text_field(wp_unslash($_POST['regina_action']));
        $tid = isset($_POST['tournament_id']) ? (int) $_POST['tournament_id'] : 0;
        $redirectArgs = [];
        $redirectToTournament = true;

        switch ($action) {
            case 'create_tournament':
                $name = isset($_POST['name']) ? trim(sanitize_text_field(wp_unslash($_POST['name']))) : '';
                $rounds = isset($_POST['rounds_total']) ? (int) $_POST['rounds_total'] : 5;
                $rounds = max(1, min(15, $rounds));
                $type = isset($_POST['tournament_type']) ? sanitize_key(wp_unslash($_POST['tournament_type'])) : 'swiss';
                $byePoints = isset($_POST['bye_points']) ? (float) $_POST['bye_points'] : 1.0;
                $tiebreakers = isset($_POST['tiebreakers']) ? sanitize_text_field(wp_unslash($_POST['tiebreakers'])) : 'buchholz,sb,rating';
                if ($name === '') {
                    $redirectArgs['cp_msg'] = 'create_error_name';
                } else {
                    $newId = self::insert_tournament($name, $rounds, $type, $byePoints, $tiebreakers);
                    $redirectArgs['cp_msg'] = 'create_success';
                    if ($newId > 0) {
                        $redirectArgs['tournament_id'] = $newId;
                    }
                }
                break;

            case 'add_player':
                $playerName = isset($_POST['player_name']) ? trim(sanitize_text_field(wp_unslash($_POST['player_name']))) : '';
                $rating = isset($_POST['player_rating']) ? (int) $_POST['player_rating'] : 0;
                $rating = max(0, min(3500, $rating));
                $country = isset($_POST['player_country']) ? self::sanitize_country_code(sanitize_text_field(wp_unslash($_POST['player_country']))) : null;
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'add_player_error_tournament';
                } elseif ($playerName === '') {
                    $redirectArgs['cp_msg'] = 'add_player_error_name';
                } elseif ($country === null || $country === '') {
                    $redirectArgs['cp_msg'] = 'add_player_error_country';
                } elseif (!ChessPodium_License::can_add_players(count(self::get_players($tid)), 1)) {
                    $redirectArgs['cp_msg'] = 'player_limit_reached';
                } else {
                    self::insert_player($tid, $playerName, $rating, null, $country);
                    $redirectArgs['cp_msg'] = 'add_player_success';
                }
                break;

            case 'import_fide_player':
                $fideIdRaw = isset($_POST['fide_id']) ? sanitize_text_field(wp_unslash($_POST['fide_id'])) : '';
                $fideId = self::sanitize_fide_id($fideIdRaw);
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'add_player_error_tournament';
                } elseif ($fideId === '') {
                    $redirectArgs['cp_msg'] = 'fide_invalid';
                } else {
                    $existing = self::get_player_by_fide_id($tid, $fideId);
                    if ($existing) {
                        $redirectArgs['cp_msg'] = 'fide_exists';
                    } elseif (!ChessPodium_License::can_add_players(count(self::get_players($tid)), 1)) {
                        $redirectArgs['cp_msg'] = 'player_limit_reached';
                    } else {
                        $fideData = self::fetch_fide_player_data($fideId);
                        if ($fideData !== null) {
                            $country = $fideData['country'] ?? null;
                            self::insert_player($tid, $fideData['name'], $fideData['rating'], $fideId, $country);
                            $redirectArgs['cp_msg'] = 'fide_imported';
                        } else {
                            $redirectArgs['cp_msg'] = 'fide_not_found';
                        }
                    }
                }
                break;

            case 'update_player':
                $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;
                $playerName = isset($_POST['player_name']) ? trim(sanitize_text_field(wp_unslash($_POST['player_name']))) : '';
                $rating = isset($_POST['player_rating']) ? (int) $_POST['player_rating'] : 0;
                $rating = max(0, min(3500, $rating));
                $country = isset($_POST['player_country']) ? self::sanitize_country_code(sanitize_text_field(wp_unslash($_POST['player_country']))) : null;
                if ($tid <= 0 || $playerId <= 0) {
                    $redirectArgs['cp_msg'] = 'update_player_error';
                } elseif ($playerName === '') {
                    $redirectArgs['cp_msg'] = 'update_player_error_name';
                } else {
                    self::update_player($tid, $playerId, $playerName, $rating, $country);
                    $redirectArgs['cp_msg'] = 'update_player_success';
                }
                break;

            case 'delete_player':
                $playerId = isset($_POST['player_id']) ? (int) $_POST['player_id'] : 0;
                if ($tid <= 0 || $playerId <= 0) {
                    $redirectArgs['cp_msg'] = 'delete_player_error';
                } else {
                    $deleted = self::delete_player($tid, $playerId);
                    $redirectArgs['cp_msg'] = $deleted ? 'delete_player_success' : 'delete_player_in_use';
                }
                break;

            case 'generate_round':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'generate_error';
                } else {
                    $result = self::generate_next_round($tid);
                    $redirectArgs['cp_msg'] = $result ? 'generate_success' : 'generate_failed';
                }
                break;

            case 'generate_round_print':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'generate_error';
                } else {
                    $result = self::generate_next_round($tid);
                    if ($result) {
                        $updatedTournament = self::get_tournament($tid);
                        if ($updatedTournament) {
                            $redirectArgs['print_round'] = (int) $updatedTournament->current_round;
                            $redirectArgs['cp_msg'] = 'generate_success';
                        }
                    } else {
                        $redirectArgs['cp_msg'] = 'generate_failed';
                    }
                }
                break;

            case 'rollback_round':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'rollback_error';
                } else {
                    $result = self::rollback_current_round($tid);
                    $redirectArgs['cp_msg'] = $result ? 'rollback_success' : 'rollback_failed';
                }
                break;

            case 'publish_tournament':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'publish_error';
                } else {
                    $result = self::publish_tournament_to_folder($tid);
                    $redirectArgs['cp_msg'] = $result['success'] ? 'publish_success' : 'publish_error';
                    if (!empty($result['url'])) {
                        $redirectArgs['cp_publish_url'] = $result['url'];
                    }
                }
                break;

            case 'add_photo':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'photo_error';
                } elseif (!empty($_POST['photo_url'])) {
                    $url = esc_url_raw(wp_unslash($_POST['photo_url']));
                    $caption = isset($_POST['photo_caption']) ? sanitize_text_field(wp_unslash($_POST['photo_caption'])) : '';
                    if ($url !== '') {
                        self::insert_photo($tid, $url, $caption);
                        $redirectArgs['cp_msg'] = 'photo_added';
                    }
                } elseif (!empty($_FILES['photo_file']['tmp_name']) && is_uploaded_file($_FILES['photo_file']['tmp_name'])) {
                    $attachId = self::handle_photo_upload($tid, $_FILES['photo_file']);
                    if ($attachId > 0) {
                        $caption = isset($_POST['photo_caption']) ? sanitize_text_field(wp_unslash($_POST['photo_caption'])) : '';
                        $url = wp_get_attachment_url($attachId);
                        self::insert_photo($tid, $url, $caption);
                        $redirectArgs['cp_msg'] = 'photo_added';
                    } else {
                        $redirectArgs['cp_msg'] = 'photo_upload_error';
                    }
                } elseif (!empty($_FILES['photo_files']['tmp_name']) && is_array($_FILES['photo_files']['tmp_name'])) {
                    $caption = isset($_POST['photo_caption']) ? sanitize_text_field(wp_unslash($_POST['photo_caption'])) : '';
                    $count = self::handle_bulk_photo_upload($tid, $_FILES['photo_files'], $caption);
                    $redirectArgs['cp_msg'] = $count > 0 ? 'photo_added' : 'photo_upload_error';
                    if ($count > 0) {
                        $redirectArgs['cp_imported'] = $count;
                    }
                }
                break;

            case 'delete_photo':
                $photoId = isset($_POST['photo_id']) ? (int) $_POST['photo_id'] : 0;
                if ($tid > 0 && $photoId > 0) {
                    self::delete_photo($tid, $photoId);
                    $redirectArgs['cp_msg'] = 'photo_deleted';
                }
                break;

            case 'add_pgn':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'pgn_error';
                } elseif (!empty($_POST['pgn_content'])) {
                    $content = wp_unslash($_POST['pgn_content']);
                    $round = isset($_POST['pgn_round']) ? max(1, (int) $_POST['pgn_round']) : 1;
                    $white = isset($_POST['pgn_white']) ? sanitize_text_field(wp_unslash($_POST['pgn_white'])) : null;
                    $black = isset($_POST['pgn_black']) ? sanitize_text_field(wp_unslash($_POST['pgn_black'])) : null;
                    $result = isset($_POST['pgn_result']) ? sanitize_text_field(wp_unslash($_POST['pgn_result'])) : null;
                    if (trim($content) !== '') {
                        self::insert_pgn($tid, $round, $content, $white, $black, $result);
                        $redirectArgs['cp_msg'] = 'pgn_added';
                    }
                } elseif (!empty($_FILES['pgn_file']['tmp_name']) && is_uploaded_file($_FILES['pgn_file']['tmp_name'])) {
                    $round = isset($_POST['pgn_round']) ? max(1, (int) $_POST['pgn_round']) : 1;
                    $imported = self::import_pgn_file($tid, $_FILES['pgn_file']['tmp_name'], $round);
                    $redirectArgs['cp_msg'] = $imported > 0 ? 'pgn_added' : 'pgn_error';
                    $redirectArgs['cp_imported'] = $imported;
                }
                break;

            case 'delete_pgn':
                $pgnId = isset($_POST['pgn_id']) ? (int) $_POST['pgn_id'] : 0;
                if ($tid > 0 && $pgnId > 0) {
                    self::delete_pgn($tid, $pgnId);
                    $redirectArgs['cp_msg'] = 'pgn_deleted';
                }
                break;

            case 'delete_tournament':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'delete_tournament_error';
                } else {
                    $deleted = self::delete_tournament($tid);
                    $redirectArgs['cp_msg'] = $deleted ? 'delete_tournament_success' : 'delete_tournament_error';
                    if ($deleted) {
                        $redirectToTournament = false;
                    }
                }
                break;

            case 'update_tournament_name':
                $newName = isset($_POST['tournament_name']) ? trim(sanitize_text_field(wp_unslash($_POST['tournament_name']))) : '';
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'update_name_error';
                } elseif ($newName === '') {
                    $redirectArgs['cp_msg'] = 'update_name_empty';
                } else {
                    $updated = self::update_tournament_name($tid, $newName);
                    $redirectArgs['cp_msg'] = $updated ? 'update_name_success' : 'update_name_error';
                }
                break;

            case 'bulk_import_csv':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'add_player_error_tournament';
                } elseif (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                    $redirectArgs['cp_msg'] = 'bulk_import_no_file';
                } else {
                    $result = self::bulk_import_players_csv($tid, $_FILES['csv_file']['tmp_name']);
                    $redirectArgs['cp_msg'] = $result['success'] ? 'bulk_import_success' : 'bulk_import_error';
                    if (isset($result['count'])) {
                        $redirectArgs['cp_imported'] = $result['count'];
                    }
                }
                break;

            case 'save_results':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'save_results_error';
                } elseif (empty($_POST['results']) || !is_array($_POST['results'])) {
                    $redirectArgs['cp_msg'] = 'save_results_no_data';
                } else {
                    $saved = self::save_results_for_tournament($tid, $_POST['results']);
                    $redirectArgs['cp_msg'] = $saved ? 'save_results_success' : 'save_results_error';
                }
                $editRound = isset($_POST['edit_round']) ? (int) $_POST['edit_round'] : 0;
                if ($editRound > 0) {
                    $redirectArgs['edit_round'] = $editRound;
                }
                break;

            case 'add_external_tournament':
                $extName = isset($_POST['ext_name']) ? trim(sanitize_text_field(wp_unslash($_POST['ext_name']))) : '';
                $extUrl = isset($_POST['ext_url']) ? esc_url_raw(trim(wp_unslash($_POST['ext_url']))) : '';
                $extPreview = self::get_external_preview_url_from_post();
                $extRounds = isset($_POST['ext_rounds']) ? max(0, (int) $_POST['ext_rounds']) : 0;
                if ($extName !== '' && $extUrl !== '') {
                    self::add_external_tournament($extName, $extUrl, $extPreview ?: null, $extRounds);
                    $redirectArgs['cp_msg'] = 'ext_tournament_added';
                } else {
                    $redirectArgs['cp_msg'] = 'ext_tournament_error';
                }
                $redirect = admin_url('admin.php?page=chess-podium-external-tournaments');
                if (!empty($redirectArgs)) {
                    $redirect = add_query_arg($redirectArgs, $redirect);
                }
                wp_safe_redirect($redirect);
                exit;

            case 'update_external_tournament':
                $extId = isset($_POST['ext_id']) ? sanitize_key(wp_unslash($_POST['ext_id'])) : '';
                $extName = isset($_POST['ext_name']) ? trim(sanitize_text_field(wp_unslash($_POST['ext_name']))) : '';
                $extUrl = isset($_POST['ext_url']) ? esc_url_raw(trim(wp_unslash($_POST['ext_url']))) : '';
                $extPreview = self::get_external_preview_url_from_post();
                $extRounds = isset($_POST['ext_rounds']) ? max(0, (int) $_POST['ext_rounds']) : 0;
                if ($extId !== '' && $extName !== '' && $extUrl !== '') {
                    self::update_external_tournament($extId, $extName, $extUrl, $extPreview ?: null, $extRounds);
                    $redirectArgs['cp_msg'] = 'ext_tournament_updated';
                } else {
                    $redirectArgs['cp_msg'] = 'ext_tournament_error';
                }
                $redirect = admin_url('admin.php?page=chess-podium-external-tournaments');
                if (!empty($redirectArgs)) {
                    $redirect = add_query_arg($redirectArgs, $redirect);
                }
                wp_safe_redirect($redirect);
                exit;

            case 'delete_external_tournament':
                $extId = isset($_POST['ext_id']) ? sanitize_key(wp_unslash($_POST['ext_id'])) : '';
                if ($extId !== '') {
                    self::delete_external_tournament($extId);
                    $redirectArgs['cp_msg'] = 'ext_tournament_deleted';
                }
                $redirect = admin_url('admin.php?page=chess-podium-external-tournaments');
                if (!empty($redirectArgs)) {
                    $redirect = add_query_arg($redirectArgs, $redirect);
                }
                wp_safe_redirect($redirect);
                exit;
        }

        $redirect = admin_url('admin.php?page=' . self::MENU_SLUG);
        if ($redirectToTournament) {
            $tidToAdd = isset($redirectArgs['tournament_id']) ? (int) $redirectArgs['tournament_id'] : $tid;
            if ($tidToAdd > 0) {
                $redirect = add_query_arg('tournament_id', $tidToAdd, $redirect);
            }
        }
        if (!empty($redirectArgs)) {
            $redirect = add_query_arg($redirectArgs, $redirect);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    public static function render_admin_page(): void
    {
        $selectedTournamentId = isset($_GET['tournament_id']) ? (int) $_GET['tournament_id'] : 0;
        $printRound = isset($_GET['print_round']) ? (int) $_GET['print_round'] : 0;
        $printStandings = isset($_GET['print_standings']) ? (int) $_GET['print_standings'] : 0;
        $msg = isset($_GET['cp_msg']) ? sanitize_key(wp_unslash($_GET['cp_msg'])) : '';
        $importedCount = isset($_GET['cp_imported']) ? (int) $_GET['cp_imported'] : 0;
        $tournaments = self::get_tournaments();
        $selectedTournament = $selectedTournamentId > 0 ? self::get_tournament($selectedTournamentId) : null;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Chess Podium - Tournament Manager', 'chess-podium'); ?></h1>
            <p><?php echo esc_html__('Create tournament, add players, generate rounds automatically and enter results.', 'chess-podium'); ?></p>
            <?php if ($msg !== ''): ?>
                <?php self::render_admin_notice($msg, $importedCount); ?>
            <?php endif; ?>

            <hr>
            <h2><?php echo esc_html__('Create new tournament', 'chess-podium'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="create_tournament">
                <table class="form-table">
                    <tr>
                        <th><label for="name"><?php echo esc_html__('Tournament name', 'chess-podium'); ?></label></th>
                        <td><input id="name" name="name" type="text" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="rounds_total"><?php echo esc_html__('Rounds', 'chess-podium'); ?></label></th>
                        <td><input id="rounds_total" name="rounds_total" type="number" min="1" max="15" value="5"></td>
                    </tr>
                    <tr>
                        <th><label for="tournament_type"><?php echo esc_html__('Tournament type', 'chess-podium'); ?></label></th>
                        <td>
                            <select id="tournament_type" name="tournament_type">
                                <option value="swiss"><?php echo esc_html__('Swiss', 'chess-podium'); ?></option>
                                <option value="round_robin"><?php echo esc_html__('Round Robin', 'chess-podium'); ?></option>
                            </select>
                            <p class="description"><?php echo esc_html__('Swiss: pairing by score. Round Robin: everyone plays everyone.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bye_points"><?php echo esc_html__('BYE points', 'chess-podium'); ?></label></th>
                        <td>
                            <select id="bye_points" name="bye_points">
                                <option value="0">0</option>
                                <option value="0.5">0.5</option>
                                <option value="1" selected>1</option>
                            </select>
                            <p class="description"><?php echo esc_html__('Points awarded to player when they have a bye.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tiebreakers"><?php echo esc_html__('Tiebreakers', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="tiebreakers" name="tiebreakers" type="text" value="buchholz,sb,rating" class="regular-text" placeholder="buchholz,sb,rating">
                            <p class="description"><?php echo esc_html__('Criteria order: buchholz, sb, rating, direct (comma-separated).', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                </table>
                <p><button class="button button-primary" type="submit"><?php echo esc_html__('Create tournament', 'chess-podium'); ?></button></p>
            </form>

            <hr>
            <h2><?php echo esc_html__('Existing tournaments', 'chess-podium'); ?></h2>
            <?php if (empty($tournaments)): ?>
                <p><?php echo esc_html__('No tournaments created.', 'chess-podium'); ?></p>
            <?php else: ?>
                <ul>
                    <?php foreach ($tournaments as $t): ?>
                        <li>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tournament_id=' . (int) $t->id)); ?>">
                                <?php echo esc_html($t->name); ?>
                            </a>
                            (<?php echo esc_html__('Current round', 'chess-podium'); ?>: <?php echo (int) $t->current_round; ?> / <?php echo (int) $t->rounds_total; ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($selectedTournament): ?>
                <?php if ($printRound > 0): ?>
                    <?php self::render_print_pairings_block($selectedTournament, $printRound); ?>
                <?php endif; ?>
                <?php if ($printStandings > 0): ?>
                    <?php self::render_print_standings_block($selectedTournament); ?>
                <?php endif; ?>
                <?php self::render_tournament_admin_block($selectedTournament); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_tournament_admin_block(object $tournament): void
    {
        $tid = (int) $tournament->id;
        $players = self::get_players($tid);
        $standings = self::compute_standings($tid);
        $roundPairings = self::get_pairings_for_round($tid, (int) $tournament->current_round);
        $exportTurnsUrl = self::build_export_url($tid, 'turns');
        $exportStandingsUrl = self::build_export_url($tid, 'standings');
        $photos = self::get_photos($tid);
        $pgns = self::get_pgns($tid);
        $publishUrl = ChessPodium_ExportEngine::get_export_url_base() . '/' . ChessPodium_ExportEngine::get_tournament_slug($tournament) . '/index.html';
        ?>
        <hr>
        <h2>
            <form method="post" style="display:inline-flex;align-items:center;gap:0.5rem;">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="update_tournament_name">
                <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                <input type="text" name="tournament_name" value="<?php echo esc_attr($tournament->name); ?>" class="regular-text" style="max-width:400px;">
                <button type="submit" class="button"><?php echo esc_html__('Rename', 'chess-podium'); ?></button>
            </form>
            - <?php echo esc_html__('Tournament details', 'chess-podium'); ?>
        </h2>
        <p style="margin-bottom:1rem;">
            <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Permanently delete this tournament? All players, pairings, photos and PGN games will be removed.', 'chess-podium')); ?>');">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="delete_tournament">
                <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                <button type="submit" class="button button-link-delete"><?php echo esc_html__('Delete tournament', 'chess-podium'); ?></button>
            </form>
        </p>
        <p>
            <strong><?php echo esc_html__('Current round', 'chess-podium'); ?>:</strong> <?php echo (int) $tournament->current_round; ?> / <?php echo (int) $tournament->rounds_total; ?>
            | <strong><?php echo esc_html__('Type', 'chess-podium'); ?>:</strong> <?php echo (isset($tournament->tournament_type) && $tournament->tournament_type === 'round_robin') ? esc_html__('Round Robin', 'chess-podium') : esc_html__('Swiss', 'chess-podium'); ?>
            | <strong>BYE:</strong> <?php echo esc_html((string) (isset($tournament->bye_points) ? $tournament->bye_points : '1')); ?> pt
        </p>
        <p><strong><?php echo esc_html__('Live tournament page (for players)', 'chess-podium'); ?>:</strong><br>
            <code><?php echo esc_html(home_url('/torneo/')); ?></code> / <code><?php echo esc_html(home_url('/currenttournament/')); ?></code><br>
            <span class="description"><?php echo esc_html__('Share these URLs with players to follow pairings and standings during the tournament. Shows "No tournament in progress" when no tournament is being played.', 'chess-podium'); ?></span>
        </p>

        <h3><?php echo esc_html__('Generate round', 'chess-podium'); ?></h3>
        <form method="post">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="generate_round">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <p>
                <button class="button button-primary" type="submit"
                    <?php disabled((int) $tournament->current_round >= (int) $tournament->rounds_total || count($players) < 2); ?>>
                    <?php echo esc_html__('Generate next round', 'chess-podium'); ?>
                </button>
            </p>
            <p class="description"><?php echo esc_html__('Round 1: pairing by rating. Later rounds: basic Swiss (avoids rematch when possible).', 'chess-podium'); ?></p>
        </form>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="generate_round_print">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <p>
                <button class="button button-primary" type="submit"
                    <?php disabled((int) $tournament->current_round >= (int) $tournament->rounds_total || count($players) < 2); ?>>
                    <?php echo esc_html__('Generate round and print pairings', 'chess-podium'); ?>
                </button>
            </p>
            <p class="description"><?php echo esc_html__('Generate next round and open printable pairings in the same panel.', 'chess-podium'); ?></p>
        </form>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="rollback_round">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <p>
                <button class="button button-secondary" type="submit"
                        onclick="return confirm('<?php echo esc_js(__('Cancel current round and remove all pairings/results for this round?', 'chess-podium')); ?>');"
                    <?php disabled((int) $tournament->current_round <= 0); ?>>
                    <?php echo esc_html__('Rollback current round', 'chess-podium'); ?>
                </button>
            </p>
            <p class="description"><?php echo esc_html__('Useful for corrections: completely removes the last generated round.', 'chess-podium'); ?></p>
        </form>

        <h3><?php echo esc_html__('Current round results', 'chess-podium'); ?></h3>
        <?php
        $editRound = isset($_GET['edit_round']) ? (int) $_GET['edit_round'] : (int) $tournament->current_round;
        $editRoundPairings = $editRound > 0 ? self::get_pairings_for_round((int) $tournament->id, $editRound) : [];
        ?>
        <?php if ((int) $tournament->current_round > 0): ?>
        <p>
            <strong><?php echo esc_html__('Edit round results', 'chess-podium'); ?>:</strong>
            <?php for ($r = 1; $r <= (int) $tournament->current_round; $r++): ?>
                <?php
                $editUrl = add_query_arg(['edit_round' => $r], admin_url('admin.php?page=' . self::MENU_SLUG . '&tournament_id=' . (int) $tournament->id));
                ?>
                <a class="button button-small <?php echo $editRound === $r ? 'button-primary' : ''; ?>" href="<?php echo esc_url($editUrl); ?>"><?php echo esc_html(sprintf(__('Round %d', 'chess-podium'), (int) $r)); ?></a>
            <?php endfor; ?>
        </p>
        <?php endif; ?>
        <?php if (empty($editRoundPairings)): ?>
            <p><?php echo esc_html__('No pairings for the selected round.', 'chess-podium'); ?></p>
        <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="save_results">
                <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
                <input type="hidden" name="edit_round" value="<?php echo (int) $editRound; ?>">
                <p class="description"><?php echo esc_html(sprintf(__('Edit results for round %d.', 'chess-podium'), (int) $editRound)); ?></p>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Board', 'chess-podium'); ?></th><th><?php echo esc_html__('White', 'chess-podium'); ?></th><th><?php echo esc_html__('Black', 'chess-podium'); ?></th><th><?php echo esc_html__('Result', 'chess-podium'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($editRoundPairings as $tableNum => $pairing): ?>
                        <tr>
                            <td><?php echo (int) ($tableNum + 1); ?></td>
                            <td><?php echo ChessPodium_CountryHelper::render_flag($pairing->white_country ?? '', $pairing->white_country ?? ''); ?> <?php echo esc_html($pairing->white_name); ?></td>
                            <td><?php echo $pairing->is_bye ? '-' : (ChessPodium_CountryHelper::render_flag($pairing->black_country ?? '', $pairing->black_country ?? '') . ' ' . esc_html($pairing->black_name)); ?></td>
                            <td>
                                <?php if ((int) $pairing->is_bye === 1): ?>
                                    BYE
                                <?php else: ?>
                                    <select name="results[<?php echo (int) $pairing->id; ?>]">
                                        <?php
                                        $options = ['', '1-0', '0-1', '1/2-1/2'];
                                        foreach ($options as $opt):
                                            ?>
                                            <option value="<?php echo esc_attr($opt); ?>" <?php selected((string) $pairing->result, $opt); ?>>
                                                <?php echo $opt === '' ? esc_html__('Not entered', 'chess-podium') : esc_html($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button class="button button-primary" type="submit"><?php echo esc_html__('Save results', 'chess-podium'); ?></button></p>
            </form>
        <?php endif; ?>

        <h3><?php echo esc_html__('Standings', 'chess-podium'); ?></h3>
        <?php if (empty($standings)): ?>
            <p><?php echo esc_html__('No standings available.', 'chess-podium'); ?></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead><tr><th><?php echo esc_html__('Pos', 'chess-podium'); ?></th><th><?php echo esc_html__('Player', 'chess-podium'); ?></th><th><?php echo esc_html__('Pts', 'chess-podium'); ?></th><th><?php echo esc_html__('Buchholz', 'chess-podium'); ?></th><th><?php echo esc_html__('SB', 'chess-podium'); ?></th><th><?php echo esc_html__('Rating', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($standings as $pos => $s): ?>
                    <tr>
                        <td><?php echo (int) ($pos + 1); ?></td>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($s['country'] ?? '', $s['country'] ?? ''); ?> <?php echo esc_html($s['name']); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['points'], 1)); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['buchholz'], 1)); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['sb'], 2)); ?></td>
                        <td><?php echo (int) $s['rating']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3><?php echo esc_html__('Print', 'chess-podium'); ?></h3>
        <p>
            <?php
            $printStandingsUrl = add_query_arg(['print_standings' => 1], admin_url('admin.php?page=' . self::MENU_SLUG . '&tournament_id=' . (int) $tournament->id));
            ?>
            <a class="button" href="<?php echo esc_url($printStandingsUrl); ?>"><?php echo esc_html__('Print standings', 'chess-podium'); ?></a>
        </p>
        <p class="description"><?php echo esc_html__('Opens standings in printable format.', 'chess-podium'); ?></p>

        <?php if ((int) $tournament->current_round > 0): ?>
        <p>
            <strong><?php echo esc_html__('Print round pairings', 'chess-podium'); ?>:</strong>
            <?php for ($r = 1; $r <= (int) $tournament->current_round; $r++): ?>
                <?php
                $printRoundUrl = add_query_arg(['print_round' => $r], admin_url('admin.php?page=' . self::MENU_SLUG . '&tournament_id=' . (int) $tournament->id));
                ?>
                <a class="button button-small" href="<?php echo esc_url($printRoundUrl); ?>"><?php echo esc_html(sprintf(__('Round %d', 'chess-podium'), (int) $r)); ?></a>
            <?php endfor; ?>
        </p>
        <?php endif; ?>

        <hr style="margin:2rem 0;">
        <h3><?php echo esc_html__('Publish tournament (static export)', 'chess-podium'); ?></h3>
        <p><?php echo esc_html__('Generate a linkable HTML folder in wp-content/uploads/chess-podium/ with standings, pairings, PGN games and photo gallery.', 'chess-podium'); ?></p>
        <form method="post">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="publish_tournament">
            <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
            <button class="button button-primary" type="submit"><?php echo esc_html__('Publish tournament', 'chess-podium'); ?></button>
        </form>
        <?php if (file_exists(ChessPodium_ExportEngine::get_export_dir($tournament) . '/index.html')): ?>
        <p><a href="<?php echo esc_url($publishUrl); ?>" target="_blank" class="button"><?php echo esc_html__('Open published page', 'chess-podium'); ?></a></p>
        <?php endif; ?>

        <h3><?php echo esc_html__('Photo gallery', 'chess-podium'); ?></h3>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="add_photo">
            <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
            <p>
                <strong><?php echo esc_html__('Single upload', 'chess-podium'); ?>:</strong>
                <input type="file" name="photo_file" accept="image/*">
                <strong><?php echo esc_html__('or bulk (multiple files)', 'chess-podium'); ?>:</strong>
                <input type="file" name="photo_files[]" accept="image/*" multiple>
                <strong><?php echo esc_html__('or URL', 'chess-podium'); ?>:</strong>
                <input type="url" name="photo_url" placeholder="https://..." class="regular-text" style="max-width:300px;">
                <input type="text" name="photo_caption" placeholder="<?php echo esc_attr__('Caption (optional)', 'chess-podium'); ?>" class="regular-text">
                <button class="button" type="submit"><?php echo esc_html__('Add photo', 'chess-podium'); ?></button>
            </p>
            <p class="description"><?php echo esc_html__('Select multiple images with Ctrl+click (or Cmd+click on Mac). Caption applies to all photos in bulk upload.', 'chess-podium'); ?></p>
        </form>
        <?php if (!empty($photos)): ?>
        <p class="description"><?php echo esc_html__('Click on a photo to enlarge and browse.', 'chess-podium'); ?></p>
        <div class="cp-admin-gallery" style="display:flex;flex-wrap:wrap;gap:12px;margin-top:12px;">
            <?php foreach ($photos as $i => $ph): ?>
            <div style="border:1px solid #ddd;padding:8px;max-width:200px;">
                <img src="<?php echo esc_url($ph->photo_url); ?>" alt="<?php echo esc_attr($ph->caption ?? ''); ?>" class="cp-gallery-thumb" data-index="<?php echo $i; ?>" data-src="<?php echo esc_url($ph->photo_url); ?>" data-caption="<?php echo esc_attr($ph->caption ?? ''); ?>" style="max-width:100%;height:120px;object-fit:cover;cursor:pointer;">
                <p style="font-size:12px;margin:4px 0;"><?php echo esc_html($ph->caption ?? ''); ?></p>
                <form method="post" style="margin:0;">
                    <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                    <input type="hidden" name="regina_action" value="delete_photo">
                    <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                    <input type="hidden" name="photo_id" value="<?php echo (int) $ph->id; ?>">
                    <button class="button button-small button-link-delete" type="submit"><?php echo esc_html__('Remove', 'chess-podium'); ?></button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <div id="cp-admin-lightbox" class="cp-lightbox" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,0.9);align-items:center;justify-content:center;">
            <button type="button" class="cp-lightbox-close" style="position:absolute;top:1rem;right:1rem;background:rgba(255,255,255,0.2);color:#fff;border:none;font-size:2rem;cursor:pointer;padding:0.5rem 1rem;">&times;</button>
            <button type="button" class="cp-lightbox-prev" style="position:absolute;left:1rem;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.2);color:#fff;border:none;font-size:2rem;cursor:pointer;padding:0.5rem 1rem;">&larr;</button>
            <button type="button" class="cp-lightbox-next" style="position:absolute;right:1rem;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.2);color:#fff;border:none;font-size:2rem;cursor:pointer;padding:0.5rem 1rem;">&rarr;</button>
            <div style="max-width:95vw;max-height:95vh;text-align:center;">
                <img id="cp-admin-lightbox-img" src="" alt="" style="max-width:95vw;max-height:85vh;object-fit:contain;">
                <p id="cp-admin-lightbox-caption" style="color:#fff;padding:1rem;"></p>
            </div>
        </div>
        <script>
        (function(){
            var lb=document.getElementById("cp-admin-lightbox");
            var img=document.getElementById("cp-admin-lightbox-img");
            var cap=document.getElementById("cp-admin-lightbox-caption");
            var thumbs=document.querySelectorAll(".cp-admin-gallery .cp-gallery-thumb");
            var items=[];
            thumbs.forEach(function(el,i){items.push({src:el.dataset.src||el.src,caption:el.dataset.caption||el.alt});el.onclick=function(){open(i);};});
            var idx=0;
            function open(i){idx=i;img.src=items[idx].src;cap.textContent=items[idx].caption;lb.style.display="flex";document.body.style.overflow="hidden";}
            function close(){lb.style.display="none";document.body.style.overflow="";}
            function prev(){idx=(idx-1+items.length)%items.length;open(idx);}
            function next(){idx=(idx+1)%items.length;open(idx);}
            lb.querySelector(".cp-lightbox-close").onclick=close;
            lb.querySelector(".cp-lightbox-prev").onclick=prev;
            lb.querySelector(".cp-lightbox-next").onclick=next;
            lb.onclick=function(e){if(e.target===lb)close();};
            document.addEventListener("keydown",function(e){if(lb.style.display!=="flex")return;if(e.key==="Escape")close();if(e.key==="ArrowLeft")prev();if(e.key==="ArrowRight")next();});
        })();
        </script>
        <?php endif; ?>

        <h3><?php echo esc_html__('PGN games', 'chess-podium'); ?></h3>
        <p><?php echo esc_html__('Upload PGN file or paste content. Games will be viewable on the published page.', 'chess-podium'); ?></p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="add_pgn">
            <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
            <p>
                <label><?php echo esc_html__('Round', 'chess-podium'); ?>: <input type="number" name="pgn_round" min="1" value="1" style="width:60px;"></label>
                <input type="file" name="pgn_file" accept=".pgn">
                <button class="button" type="submit"><?php echo esc_html__('Upload PGN', 'chess-podium'); ?></button>
            </p>
        </form>
        <form method="post">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="add_pgn">
            <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
            <p>
                <label><?php echo esc_html__('Round', 'chess-podium'); ?>: <input type="number" name="pgn_round" min="1" value="1" style="width:60px;"></label>
                <label><?php echo esc_html__('White', 'chess-podium'); ?>: <input type="text" name="pgn_white" placeholder="<?php echo esc_attr__('Name', 'chess-podium'); ?>"></label>
                <label><?php echo esc_html__('Black', 'chess-podium'); ?>: <input type="text" name="pgn_black" placeholder="<?php echo esc_attr__('Name', 'chess-podium'); ?>"></label>
                <label><?php echo esc_html__('Result', 'chess-podium'); ?>: <select name="pgn_result"><option value="">-</option><option value="1-0">1-0</option><option value="0-1">0-1</option><option value="1/2-1/2">1/2-1/2</option></select></label>
            </p>
            <p><textarea name="pgn_content" rows="6" class="large-text" placeholder="<?php echo esc_attr__('Paste PGN here...', 'chess-podium'); ?>"></textarea></p>
            <p><button class="button" type="submit"><?php echo esc_html__('Add game', 'chess-podium'); ?></button></p>
        </form>
        <?php if (!empty($pgns)): ?>
        <p><strong><?php echo esc_html__('Loaded games', 'chess-podium'); ?>:</strong></p>
        <ul>
            <?php foreach ($pgns as $pgn): ?>
            <li>
                <?php echo esc_html(sprintf(__('Round %d', 'chess-podium'), (int) $pgn->round_no)); ?>: <?php echo esc_html($pgn->white_name ?? '?'); ?> - <?php echo esc_html($pgn->black_name ?? '?'); ?> (<?php echo esc_html($pgn->result ?? '-'); ?>)
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                    <input type="hidden" name="regina_action" value="delete_pgn">
                    <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                    <input type="hidden" name="pgn_id" value="<?php echo (int) $pgn->id; ?>">
                    <button class="button button-small button-link-delete" type="submit"><?php echo esc_html__('Remove', 'chess-podium'); ?></button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h3><?php echo esc_html__('Add player', 'chess-podium'); ?><?php
            if (!ChessPodium_License::is_pro()) {
                $pc = count(self::get_players($tournament->id));
                $lim = ChessPodium_License::get_free_player_limit();
                echo ' <span class="description">(' . (int) $pc . '/' . $lim . ' ' . esc_html__('players', 'chess-podium') . ')</span>';
            }
        ?></h3>
        <?php
        $canAdd = ChessPodium_License::can_add_players(count(self::get_players($tournament->id)), 1);
        if (!$canAdd): ?>
            <p class="notice notice-warning inline" style="margin:0.5rem 0;padding:0.5rem 1rem;"><?php echo esc_html__('Player limit reached. Upgrade to Chess Podium Pro for unlimited players.', 'chess-podium'); ?></p>
        <?php else: ?>
        <form method="post">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="add_player">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <table class="form-table">
                <tr>
                    <th><label for="player_name"><?php echo esc_html__('Name', 'chess-podium'); ?></label></th>
                    <td><input id="player_name" name="player_name" type="text" required class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="player_country"><?php echo esc_html__('Nationality', 'chess-podium'); ?></label></th>
                    <td>
                        <select id="player_country" name="player_country" required>
                            <?php foreach (ChessPodium_CountryHelper::get_countries() as $code => $label): ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php echo esc_html__('Required for manual entry. Shown as flag next to player name.', 'chess-podium'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="player_rating"><?php echo esc_html__('Rating', 'chess-podium'); ?></label></th>
                    <td><input id="player_rating" name="player_rating" type="number" min="0" max="3500" value="0"></td>
                </tr>
            </table>
            <p><button class="button" type="submit"><?php echo esc_html__('Add player', 'chess-podium'); ?></button></p>
        </form>
        <?php endif; ?>

        <h3><?php echo esc_html__('Import players from CSV', 'chess-podium'); ?></h3>
        <?php if (!$canAdd): ?>
            <p class="description"><?php echo esc_html__('Import disabled: player limit reached.', 'chess-podium'); ?></p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="bulk_import_csv">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <p>
                <input type="file" name="csv_file" accept=".csv">
                <button class="button" type="submit"><?php echo esc_html__('Import CSV', 'chess-podium'); ?></button>
            </p>
            <p class="description"><?php echo esc_html__('Format: name;rating or name;rating;fide_id;country. First row: optional header. Country: ISO 2-letter (e.g. IT, DE).', 'chess-podium'); ?></p>
        </form>
        <?php endif; ?>

        <h3><?php echo esc_html__('Import player from FIDE ID', 'chess-podium'); ?></h3>
        <?php if (!$canAdd): ?>
            <p class="description"><?php echo esc_html__('Import disabled: player limit reached.', 'chess-podium'); ?></p>
        <?php else: ?>
        <form method="post">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="import_fide_player">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <table class="form-table">
                <tr>
                    <th><label for="fide_id"><?php echo esc_html__('FIDE ID', 'chess-podium'); ?></label></th>
                    <td>
                        <input id="fide_id" name="fide_id" type="text" inputmode="numeric" pattern="[0-9]+" class="regular-text" placeholder="<?php echo esc_attr__('e.g. 1503014', 'chess-podium'); ?>" required>
                        <p class="description"><?php echo esc_html__('Automatic import from official FIDE source: name, FIDE ID, standard Elo, nationality (when available).', 'chess-podium'); ?></p>
                    </td>
                </tr>
            </table>
            <p><button class="button" type="submit"><?php echo esc_html__('Import from FIDE', 'chess-podium'); ?></button></p>
        </form>
        <?php endif; ?>

        <h3><?php echo esc_html(sprintf(__('Registered players (%d)', 'chess-podium'), count($players))); ?></h3>
        <?php if (empty($players)): ?>
            <p><?php echo esc_html__('No players added yet.', 'chess-podium'); ?></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead><tr><th>#</th><th><?php echo esc_html__('Name', 'chess-podium'); ?></th><th><?php echo esc_html__('FIDE ID', 'chess-podium'); ?></th><th><?php echo esc_html__('Rating', 'chess-podium'); ?></th><th><?php echo esc_html__('Actions', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($players as $idx => $p): ?>
                    <tr>
                        <td><?php echo (int) ($idx + 1); ?></td>
                        <td>
                            <?php echo ChessPodium_CountryHelper::render_flag($p->country ?? '', (string) ($p->country ?? '')); ?>
                            <form method="post" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                                <input type="hidden" name="regina_action" value="update_player">
                                <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
                                <input type="hidden" name="player_id" value="<?php echo (int) $p->id; ?>">
                                <input name="player_name" type="text" value="<?php echo esc_attr($p->name); ?>" required>
                                <select name="player_country" style="width:140px;">
                                    <?php foreach (ChessPodium_CountryHelper::get_countries() as $code => $label): ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected(($p->country ?? ''), $code); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td><?php echo !empty($p->fide_id) ? esc_html((string) $p->fide_id) : '-'; ?></td>
                        <td>
                                <input name="player_rating" type="number" min="0" max="3500" value="<?php echo (int) $p->rating; ?>" style="width:90px;">
                        </td>
                        <td>
                                <button class="button" type="submit"><?php echo esc_html__('Save', 'chess-podium'); ?></button>
                            </form>

                            <?php $canDelete = !self::player_used_in_pairings((int) $tournament->id, (int) $p->id); ?>
                            <form method="post" style="display:inline-block;margin-top:6px;">
                                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                                <input type="hidden" name="regina_action" value="delete_player">
                                <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
                                <input type="hidden" name="player_id" value="<?php echo (int) $p->id; ?>">
                                <button class="button button-link-delete" type="submit"
                                        onclick="return confirm('<?php echo esc_js(__('Permanently delete this player?', 'chess-podium')); ?>');"
                                    <?php disabled(!$canDelete); ?>>
                                    <?php echo esc_html__('Delete', 'chess-podium'); ?>
                                </button>
                            </form>
                            <?php if (!$canDelete): ?>
                                <div class="description"><?php echo esc_html__('Cannot delete: already paired in one or more rounds.', 'chess-podium'); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3><?php echo esc_html__('Export tournament data', 'chess-podium'); ?></h3>
        <p>
            <a class="button" href="<?php echo esc_url($exportTurnsUrl); ?>"><?php echo esc_html__('Export rounds (CSV)', 'chess-podium'); ?></a>
            <a class="button" href="<?php echo esc_url($exportStandingsUrl); ?>"><?php echo esc_html__('Export standings (CSV)', 'chess-podium'); ?></a>
        </p>
        <p class="description"><?php echo esc_html__('Use these files to share tournament data and debug pairing.', 'chess-podium'); ?></p>
        <?php
    }

    public static function render_tornei_page_shortcode(array $atts = []): string
    {
        $published = self::get_published_tournaments();
        if (empty($published)) {
            return '<div class="checkmate-tornei-list"><p>' . esc_html__('No published tournaments. Publish a tournament from Chess Podium to see it here.', 'chess-podium') . '</p></div>';
        }
        $logoUrl = self::get_default_logo_url();
        ob_start();
        ?>
        <div class="checkmate-tornei-list">
            <div class="checkmate-tornei-grid">
                <?php foreach ($published as $item): ?>
                <a href="<?php echo esc_url($item['url']); ?>" class="checkmate-torneo-card" target="_blank" rel="noopener">
                    <div class="checkmate-torneo-preview">
                        <img src="<?php echo esc_url($item['preview_url']); ?>" alt="<?php echo esc_attr($item['name']); ?>">
                    </div>
                    <div class="checkmate-torneo-info">
                        <h3><?php echo esc_html($item['name']); ?></h3>
                        <span class="checkmate-torneo-meta"><?php echo esc_html(sprintf(_n('%d round', '%d rounds', (int) $item['rounds'], 'chess-podium'), (int) $item['rounds'])); ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .checkmate-tornei-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem; }
            .checkmate-torneo-card { display: block; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; text-decoration: none; color: inherit; transition: box-shadow 0.2s; }
            .checkmate-torneo-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
            .checkmate-torneo-preview { aspect-ratio: 16/10; background: #2c3e50; overflow: hidden; }
            .checkmate-torneo-preview img { width: 100%; height: 100%; object-fit: cover; }
            .checkmate-torneo-info { padding: 1rem; }
            .checkmate-torneo-info h3 { margin: 0 0 0.25rem; font-size: 1.1em; }
            .checkmate-torneo-meta { font-size: 0.85em; color: #666; }
        </style>
        <?php
        return (string) ob_get_clean();
    }

    public static function render_tournament_shortcode(array $atts = []): string
    {
        $atts = shortcode_atts(['id' => 0], $atts, 'regina_torneo');
        $tid = (int) $atts['id'];
        $tournament = $tid > 0 ? self::get_tournament($tid) : self::get_latest_tournament();
        if (!$tournament) {
            return '<p>Nessun torneo disponibile.</p>';
        }
        return self::render_public_block($tournament);
    }

    private static function render_public_block(object $tournament): string
    {
        $standings = self::compute_standings((int) $tournament->id);
        $pairings = self::get_pairings_for_round((int) $tournament->id, (int) $tournament->current_round);

        ob_start();
        ?>
        <h1><?php echo esc_html($tournament->name); ?></h1>
        <p><strong><?php echo esc_html__('Current round', 'chess-podium'); ?>:</strong> <?php echo (int) $tournament->current_round; ?> / <?php echo (int) $tournament->rounds_total; ?></p>

        <h2><?php echo esc_html__('Current round pairings', 'chess-podium'); ?></h2>
        <?php if (empty($pairings)): ?>
            <p><?php echo esc_html__('No pairings generated yet.', 'chess-podium'); ?></p>
        <?php else: ?>
            <table>
                <thead><tr><th><?php echo esc_html__('Board', 'chess-podium'); ?></th><th><?php echo esc_html__('White', 'chess-podium'); ?></th><th><?php echo esc_html__('Black', 'chess-podium'); ?></th><th><?php echo esc_html__('Result', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($pairings as $i => $p): ?>
                    <tr>
                        <td><?php echo (int) ($i + 1); ?></td>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($p->white_country ?? '', $p->white_country ?? ''); ?> <?php echo esc_html($p->white_name); ?></td>
                        <td><?php echo (int) $p->is_bye === 1 ? '-' : (ChessPodium_CountryHelper::render_flag($p->black_country ?? '', $p->black_country ?? '') . ' ' . esc_html($p->black_name)); ?></td>
                        <td><?php echo esc_html($p->result ?: ((int) $p->is_bye === 1 ? 'BYE' : __('in progress', 'chess-podium'))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?php echo esc_html__('Standings', 'chess-podium'); ?></h2>
        <?php if (empty($standings)): ?>
            <p><?php echo esc_html__('Standings not available.', 'chess-podium'); ?></p>
        <?php else: ?>
            <table>
                <thead><tr><th><?php echo esc_html__('Pos', 'chess-podium'); ?></th><th><?php echo esc_html__('Player', 'chess-podium'); ?></th><th><?php echo esc_html__('Pts', 'chess-podium'); ?></th><th><?php echo esc_html__('Buchholz', 'chess-podium'); ?></th><th><?php echo esc_html__('SB', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($standings as $pos => $s): ?>
                    <tr>
                        <td><?php echo (int) ($pos + 1); ?></td>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($s['country'] ?? '', $s['country'] ?? ''); ?> <?php echo esc_html($s['name']); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['points'], 1)); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['buchholz'], 1)); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['sb'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_print_pairings_block(object $tournament, int $round): void
    {
        $pairings = self::get_pairings_for_round((int) $tournament->id, $round);
        if (empty($pairings)) {
            return;
        }
        ?>
        <div id="checkmate-print-block" class="checkmate-print-block" style="background:#fffbe6;border:1px solid #e6d58b;padding:16px;margin:16px 0;">
            <h2><?php echo esc_html(sprintf(__('Printable pairings - %s - Round %d', 'chess-podium'), $tournament->name, (int) $round)); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php echo esc_html__('Board', 'chess-podium'); ?></th><th><?php echo esc_html__('White', 'chess-podium'); ?></th><th><?php echo esc_html__('Black', 'chess-podium'); ?></th><th><?php echo esc_html__('Result', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($pairings as $i => $p): ?>
                    <tr>
                        <td><?php echo (int) ($i + 1); ?></td>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($p->white_country ?? '', $p->white_country ?? ''); ?> <?php echo esc_html($p->white_name); ?></td>
                        <td><?php echo (int) $p->is_bye === 1 ? '-' : (ChessPodium_CountryHelper::render_flag($p->black_country ?? '', $p->black_country ?? '') . ' ' . esc_html($p->black_name)); ?></td>
                        <td><?php echo (int) $p->is_bye === 1 ? 'BYE' : '__________'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button class="button button-secondary" type="button" onclick="window.print();"><?php echo esc_html__('Print now', 'chess-podium'); ?></button>
            </p>
        </div>
        <?php
    }

    private static function render_print_standings_block(object $tournament): void
    {
        $standings = self::compute_standings((int) $tournament->id);
        if (empty($standings)) {
            return;
        }
        ?>
        <div id="checkmate-print-standings" class="checkmate-print-block" style="background:#e7f5e9;border:1px solid #7cb87c;padding:16px;margin:16px 0;">
            <h2><?php echo esc_html(sprintf(__('Printable standings - %s', 'chess-podium'), $tournament->name)); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php echo esc_html__('Pos', 'chess-podium'); ?></th><th><?php echo esc_html__('Player', 'chess-podium'); ?></th><th><?php echo esc_html__('Pts', 'chess-podium'); ?></th><th><?php echo esc_html__('Buchholz', 'chess-podium'); ?></th><th><?php echo esc_html__('SB', 'chess-podium'); ?></th><th><?php echo esc_html__('Rating', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($standings as $pos => $s): ?>
                    <tr>
                        <td><?php echo (int) ($pos + 1); ?></td>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($s['country'] ?? '', $s['country'] ?? ''); ?> <?php echo esc_html($s['name']); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['points'], 1)); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['buchholz'], 1)); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['sb'], 2)); ?></td>
                        <td><?php echo (int) $s['rating']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
            <p>
                <button class="button button-secondary" type="button" onclick="window.print();"><?php echo esc_html__('Print now', 'chess-podium'); ?></button>
            </p>
        </div>
        <?php
    }

    private static function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charsetCollate = $wpdb->get_charset_collate();
        $tournaments = $wpdb->prefix . 'regina_tournaments';
        $players = $wpdb->prefix . 'regina_players';
        $pairings = $wpdb->prefix . 'regina_pairings';

        $sql = "
        CREATE TABLE $tournaments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            rounds_total INT NOT NULL DEFAULT 5,
            tournament_type VARCHAR(20) NOT NULL DEFAULT 'swiss',
            bye_points DECIMAL(3,2) NOT NULL DEFAULT 1.0,
            tiebreakers VARCHAR(100) NOT NULL DEFAULT 'buchholz,sb,rating',
            current_round INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charsetCollate;

        CREATE TABLE $players (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tournament_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            fide_id VARCHAR(20) NULL,
            country VARCHAR(2) NULL,
            rating INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tournament_id (tournament_id),
            KEY fide_id (fide_id)
        ) $charsetCollate;

        CREATE TABLE $pairings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tournament_id BIGINT UNSIGNED NOT NULL,
            round_no INT NOT NULL,
            white_player_id BIGINT UNSIGNED NOT NULL,
            black_player_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_bye TINYINT(1) NOT NULL DEFAULT 0,
            result VARCHAR(20) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tournament_round (tournament_id, round_no)
        ) $charsetCollate;

        CREATE TABLE {$wpdb->prefix}regina_tournament_photos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tournament_id BIGINT UNSIGNED NOT NULL,
            photo_url VARCHAR(500) NOT NULL,
            caption VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tournament_id (tournament_id)
        ) $charsetCollate;

        CREATE TABLE {$wpdb->prefix}regina_tournament_pgns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tournament_id BIGINT UNSIGNED NOT NULL,
            round_no INT NOT NULL,
            pairing_id BIGINT UNSIGNED NULL,
            white_name VARCHAR(191) NULL,
            black_name VARCHAR(191) NULL,
            result VARCHAR(20) NULL,
            pgn_content LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tournament_id (tournament_id)
        ) $charsetCollate;
        ";

        dbDelta($sql);
    }

    private static function db_table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private static function maybe_run_schema_updates(): void
    {
        global $wpdb;
        $playersTable = self::db_table('regina_players');
        $tournamentsTable = self::db_table('regina_tournaments');
        $column = $wpdb->get_var("SHOW COLUMNS FROM $playersTable LIKE 'fide_id'"); // phpcs:ignore
        if ($column === null) {
            $wpdb->query("ALTER TABLE $playersTable ADD COLUMN fide_id VARCHAR(20) NULL AFTER name"); // phpcs:ignore
            $wpdb->query("ALTER TABLE $playersTable ADD INDEX fide_id (fide_id)"); // phpcs:ignore
        }
        $countryCol = $wpdb->get_var("SHOW COLUMNS FROM $playersTable LIKE 'country'"); // phpcs:ignore
        if ($countryCol === null) {
            $wpdb->query("ALTER TABLE $playersTable ADD COLUMN country VARCHAR(2) NULL AFTER fide_id"); // phpcs:ignore
        }
        $typeCol = $wpdb->get_var("SHOW COLUMNS FROM $tournamentsTable LIKE 'tournament_type'"); // phpcs:ignore
        if ($typeCol === null) {
            $wpdb->query("ALTER TABLE $tournamentsTable ADD COLUMN tournament_type VARCHAR(20) NOT NULL DEFAULT 'swiss' AFTER rounds_total"); // phpcs:ignore
            $wpdb->query("ALTER TABLE $tournamentsTable ADD COLUMN bye_points DECIMAL(3,2) NOT NULL DEFAULT 1.0 AFTER tournament_type"); // phpcs:ignore
            $wpdb->query("ALTER TABLE $tournamentsTable ADD COLUMN tiebreakers VARCHAR(100) NOT NULL DEFAULT 'buchholz,sb,rating' AFTER bye_points"); // phpcs:ignore
        }
        $photosTable = self::db_table('regina_tournament_photos');
        if ($wpdb->get_var("SHOW TABLES LIKE '$photosTable'") !== $photosTable) { // phpcs:ignore
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $photosTable (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tournament_id BIGINT UNSIGNED NOT NULL,
                photo_url VARCHAR(500) NOT NULL,
                caption VARCHAR(500) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY tournament_id (tournament_id)
            ) $charset"); // phpcs:ignore
        }
        $pgnsTable = self::db_table('regina_tournament_pgns');
        if ($wpdb->get_var("SHOW TABLES LIKE '$pgnsTable'") !== $pgnsTable) { // phpcs:ignore
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $pgnsTable (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tournament_id BIGINT UNSIGNED NOT NULL,
                round_no INT NOT NULL,
                pairing_id BIGINT UNSIGNED NULL,
                white_name VARCHAR(191) NULL,
                black_name VARCHAR(191) NULL,
                result VARCHAR(20) NULL,
                pgn_content LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY tournament_id (tournament_id)
            ) $charset"); // phpcs:ignore
        }
        $savedVersion = get_option('chess_podium_db_version', '0');
        if (version_compare($savedVersion, self::DB_VERSION, '<')) {
            update_option('chess_podium_db_version', self::DB_VERSION);
            flush_rewrite_rules();
        }
    }

    private static function insert_tournament(string $name, int $rounds, string $type = 'swiss', float $byePoints = 1.0, string $tiebreakers = 'buchholz,sb,rating'): int
    {
        global $wpdb;
        $type = in_array($type, ['swiss', 'round_robin'], true) ? $type : 'swiss';
        $byePoints = max(0.0, min(1.0, (float) $byePoints));
        $tiebreakers = self::sanitize_tiebreakers($tiebreakers);
        $wpdb->insert(
            self::db_table('regina_tournaments'),
            [
                'name' => $name,
                'rounds_total' => $rounds,
                'tournament_type' => $type,
                'bye_points' => $byePoints,
                'tiebreakers' => $tiebreakers,
                'current_round' => 0,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%f', '%s', '%d', '%s']
        );
        return (int) $wpdb->insert_id;
    }

    private static function sanitize_tiebreakers(string $raw): string
    {
        $allowed = ['buchholz', 'sb', 'rating', 'direct'];
        $parts = array_map('trim', explode(',', strtolower($raw)));
        $valid = array_filter($parts, static fn ($p) => in_array($p, $allowed, true));
        return implode(',', array_slice(array_unique($valid), 0, 5)) ?: 'buchholz,sb,rating';
    }

    private static function insert_player(int $tournamentId, string $name, int $rating, ?string $fideId = null, ?string $country = null): void
    {
        global $wpdb;
        $country = self::sanitize_country_code($country);
        $wpdb->insert(
            self::db_table('regina_players'),
            [
                'tournament_id' => $tournamentId,
                'name' => $name,
                'fide_id' => $fideId,
                'country' => $country ?: null,
                'rating' => $rating,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
    }

    private static function sanitize_country_code(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $code = strtoupper(substr(trim($code), 0, 2));
        return (strlen($code) === 2 && ctype_alpha($code)) ? $code : null;
    }

    private static function update_player(int $tournamentId, int $playerId, string $name, int $rating, ?string $country = null): void
    {
        global $wpdb;
        $country = self::sanitize_country_code($country);
        $wpdb->update(
            self::db_table('regina_players'),
            [
                'name' => $name,
                'rating' => $rating,
                'country' => $country ?: null,
            ],
            [
                'id' => $playerId,
                'tournament_id' => $tournamentId,
            ],
            ['%s', '%d', '%s'],
            ['%d', '%d']
        );
    }

    private static function delete_player(int $tournamentId, int $playerId): bool
    {
        global $wpdb;
        if (self::player_used_in_pairings($tournamentId, $playerId)) {
            return false;
        }
        $deleted = $wpdb->delete(
            self::db_table('regina_players'),
            [
                'id' => $playerId,
                'tournament_id' => $tournamentId,
            ],
            ['%d', '%d']
        );
        return $deleted > 0;
    }

    private static function delete_tournament(int $tournamentId): bool
    {
        global $wpdb;
        $tournament = self::get_tournament($tournamentId);
        if (!$tournament) {
            return false;
        }
        $wpdb->delete(self::db_table('regina_pairings'), ['tournament_id' => $tournamentId], ['%d']);
        $wpdb->delete(self::db_table('regina_players'), ['tournament_id' => $tournamentId], ['%d']);
        $wpdb->delete(self::db_table('regina_tournament_photos'), ['tournament_id' => $tournamentId], ['%d']);
        $wpdb->delete(self::db_table('regina_tournament_pgns'), ['tournament_id' => $tournamentId], ['%d']);
        $deleted = $wpdb->delete(self::db_table('regina_tournaments'), ['id' => $tournamentId], ['%d']);
        return $deleted > 0;
    }

    private static function update_tournament_name(int $tournamentId, string $name): bool
    {
        global $wpdb;
        $updated = $wpdb->update(
            self::db_table('regina_tournaments'),
            ['name' => $name],
            ['id' => $tournamentId],
            ['%s'],
            ['%d']
        );
        return $updated !== false;
    }

    private static function update_pairing_result(int $pairingId, string $result): void
    {
        global $wpdb;
        $valid = ['', '1-0', '0-1', '1/2-1/2'];
        if (!in_array($result, $valid, true)) {
            return;
        }
        $wpdb->update(
            self::db_table('regina_pairings'),
            ['result' => $result === '' ? null : $result],
            ['id' => $pairingId],
            ['%s'],
            ['%d']
        );
    }

    private static function get_tournaments(): array
    {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . self::db_table('regina_tournaments') . ' ORDER BY id DESC'); // phpcs:ignore
    }

    private static function get_tournament(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::db_table('regina_tournaments') . ' WHERE id = %d', $id));
        return $row ?: null;
    }

    private static function get_latest_tournament(): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . self::db_table('regina_tournaments') . ' ORDER BY id DESC LIMIT 1'); // phpcs:ignore
        return $row ?: null;
    }

    /** Returns the latest tournament that is still in progress (current_round < rounds_total). */
    private static function get_tournament_in_progress(): ?object
    {
        global $wpdb;
        $table = self::db_table('regina_tournaments');
        $row = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE current_round < rounds_total ORDER BY id DESC LIMIT 1" // phpcs:ignore
        );
        return $row ?: null;
    }

    private static function get_players(int $tournamentId): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::db_table('regina_players') . ' WHERE tournament_id = %d ORDER BY rating DESC, name ASC',
                $tournamentId
            )
        );
    }

    private static function get_player_by_fide_id(int $tournamentId, string $fideId): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::db_table('regina_players') . ' WHERE tournament_id = %d AND fide_id = %s LIMIT 1',
                $tournamentId,
                $fideId
            )
        );
        return $row ?: null;
    }

    private static function get_pairings_for_round(int $tournamentId, int $round): array
    {
        global $wpdb;
        if ($round <= 0) {
            return [];
        }
        $pairings = self::db_table('regina_pairings');
        $players = self::db_table('regina_players');
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, wp.name AS white_name, wp.country AS white_country, bp.name AS black_name, bp.country AS black_country
                FROM $pairings p
                LEFT JOIN $players wp ON p.white_player_id = wp.id
                LEFT JOIN $players bp ON p.black_player_id = bp.id
                WHERE p.tournament_id = %d AND p.round_no = %d
                ORDER BY p.id ASC",
                $tournamentId,
                $round
            )
        );
    }

    private static function get_all_pairings(int $tournamentId): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::db_table('regina_pairings') . ' WHERE tournament_id = %d ORDER BY round_no ASC, id ASC',
                $tournamentId
            )
        );
    }

    private static function get_all_pairings_detailed(int $tournamentId): array
    {
        global $wpdb;
        $pairings = self::db_table('regina_pairings');
        $players = self::db_table('regina_players');
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, wp.name AS white_name, wp.country AS white_country, bp.name AS black_name, bp.country AS black_country
                FROM $pairings p
                LEFT JOIN $players wp ON p.white_player_id = wp.id
                LEFT JOIN $players bp ON p.black_player_id = bp.id
                WHERE p.tournament_id = %d
                ORDER BY p.round_no ASC, p.id ASC",
                $tournamentId
            )
        );
    }

    private static function generate_next_round(int $tournamentId): bool
    {
        global $wpdb;
        $tournament = self::get_tournament($tournamentId);
        if (!$tournament) {
            return false;
        }
        if ((int) $tournament->current_round >= (int) $tournament->rounds_total) {
            return false;
        }

        $players = self::get_players($tournamentId);
        if (count($players) < 2) {
            return false;
        }

        $nextRound = (int) $tournament->current_round + 1;
        $pairings = [];
        $type = isset($tournament->tournament_type) ? (string) $tournament->tournament_type : 'swiss';

        if ($type === 'round_robin') {
            $pairings = self::pair_round_robin($players, $nextRound);
        } elseif ($nextRound === 1) {
            $pairings = self::pair_round_one($players);
        } else {
            $standings = self::compute_standings($tournamentId);
            $pairings = self::pair_swiss($players, $standings, $tournamentId);
        }

        foreach ($pairings as $pair) {
            $wpdb->insert(
                self::db_table('regina_pairings'),
                [
                    'tournament_id' => $tournamentId,
                    'round_no' => $nextRound,
                    'white_player_id' => (int) $pair['white'],
                    'black_player_id' => (int) $pair['black'],
                    'is_bye' => (int) $pair['is_bye'],
                    'result' => $pair['is_bye'] ? 'BYE' : null,
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%d', '%d', '%s', '%s']
            );
        }

        $wpdb->update(
            self::db_table('regina_tournaments'),
            ['current_round' => $nextRound],
            ['id' => $tournamentId],
            ['%d'],
            ['%d']
        );
        return true;
    }

    private static function rollback_current_round(int $tournamentId): bool
    {
        global $wpdb;
        $tournament = self::get_tournament($tournamentId);
        if (!$tournament) {
            return false;
        }

        $currentRound = (int) $tournament->current_round;
        if ($currentRound <= 0) {
            return false;
        }

        $wpdb->delete(
            self::db_table('regina_pairings'),
            [
                'tournament_id' => $tournamentId,
                'round_no' => $currentRound,
            ],
            ['%d', '%d']
        );

        $wpdb->update(
            self::db_table('regina_tournaments'),
            ['current_round' => $currentRound - 1],
            ['id' => $tournamentId],
            ['%d'],
            ['%d']
        );
        return true;
    }

    private static function save_results_for_tournament(int $tournamentId, array $results): bool
    {
        $validResults = ['', '1-0', '0-1', '1/2-1/2'];
        $pairingIds = self::get_pairing_ids_for_tournament($tournamentId);
        $validPairingIds = array_flip($pairingIds);

        foreach ($results as $pairingId => $result) {
            $pairingId = (int) $pairingId;
            $result = sanitize_text_field((string) $result);
            if (!isset($validPairingIds[$pairingId]) || !in_array($result, $validResults, true)) {
                continue;
            }
            self::update_pairing_result($pairingId, $result);
        }
        return true;
    }

    private static function bulk_import_players_csv(int $tournamentId, string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ['success' => false];
        }
        $count = 0;
        $delimiter = ';';
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) === 1 && strpos($row[0], ',') !== false) {
                $row = str_getcsv($row[0], ',');
            }
            if (empty($row)) {
                continue;
            }
            $name = trim((string) $row[0]);
            if ($name === '' || in_array(strtolower($name), ['nome', 'name', 'player'], true)) {
                continue;
            }
            if (!ChessPodium_License::can_add_players(count(self::get_players($tournamentId)), 1)) {
                break;
            }
            $rating = isset($row[1]) ? max(0, min(3500, (int) $row[1])) : 0;
            $fideId = isset($row[2]) ? self::sanitize_fide_id((string) $row[2]) : null;
            if ($fideId === '') {
                $fideId = null;
            }
            $country = isset($row[3]) ? self::sanitize_country_code(trim((string) $row[3])) : null;
            self::insert_player($tournamentId, $name, $rating, $fideId, $country);
            $count++;
        }
        fclose($handle);
        return ['success' => true, 'count' => $count];
    }

    private static function get_pairing_ids_for_tournament(int $tournamentId): array
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM ' . self::db_table('regina_pairings') . ' WHERE tournament_id = %d',
                $tournamentId
            )
        );
        return array_map('intval', (array) $ids);
    }

    private static function publish_tournament_to_folder(int $tournamentId): array
    {
        $tournament = self::get_tournament($tournamentId);
        if (!$tournament) {
            return ['success' => false];
        }
        $players = self::get_players($tournamentId);
        $standings = self::compute_standings($tournamentId);
        $allPairings = self::get_all_pairings_detailed($tournamentId);
        $photos = self::get_photos($tournamentId);
        $pgns = self::get_pgns($tournamentId);
        return ChessPodium_ExportEngine::generate_export($tournament, $players, $standings, $allPairings, $photos, $pgns);
    }

    private static function insert_photo(int $tournamentId, string $url, string $caption): void
    {
        global $wpdb;
        $maxOrder = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM ' . self::db_table('regina_tournament_photos') . ' WHERE tournament_id = %d', $tournamentId));
        $wpdb->insert(self::db_table('regina_tournament_photos'), ['tournament_id' => $tournamentId, 'photo_url' => $url, 'caption' => $caption, 'sort_order' => $maxOrder + 1, 'created_at' => current_time('mysql')], ['%d', '%s', '%s', '%d', '%s']);
    }

    private static function handle_bulk_photo_upload(int $tournamentId, array $files, string $caption): int
    {
        $count = 0;
        $tmpNames = $files['tmp_name'] ?? [];
        $names = $files['name'] ?? [];
        $errors = $files['error'] ?? [];
        $types = $files['type'] ?? [];
        $sizes = $files['size'] ?? [];

        if (!is_array($tmpNames)) {
            return 0;
        }

        foreach ($tmpNames as $i => $tmpName) {
            if (empty($tmpName) || !is_uploaded_file($tmpName)) {
                continue;
            }
            if (isset($errors[$i]) && $errors[$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $file = [
                'name' => $names[$i] ?? '',
                'type' => $types[$i] ?? '',
                'tmp_name' => $tmpName,
                'error' => $errors[$i] ?? 0,
                'size' => $sizes[$i] ?? 0,
            ];
            $attachId = self::handle_photo_upload($tournamentId, $file);
            if ($attachId > 0) {
                $url = wp_get_attachment_url($attachId);
                self::insert_photo($tournamentId, $url, $caption);
                $count++;
            }
        }
        return $count;
    }

    private static function handle_photo_upload(int $tournamentId, array $file): int
    {
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $upload = wp_handle_upload($file, ['test_form' => false]);
        if (isset($upload['error'])) {
            return 0;
        }
        $attachId = wp_insert_attachment(['post_mime_type' => $upload['type'], 'post_title' => sanitize_file_name(basename($upload['file'])), 'post_content' => '', 'post_status' => 'inherit'], $upload['file']);
        if (is_wp_error($attachId)) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_generate_attachment_metadata($attachId, $upload['file']);
        return (int) $attachId;
    }

    private static function delete_photo(int $tournamentId, int $photoId): void
    {
        global $wpdb;
        $wpdb->delete(self::db_table('regina_tournament_photos'), ['id' => $photoId, 'tournament_id' => $tournamentId], ['%d', '%d']);
    }

    private static function get_photos(int $tournamentId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::db_table('regina_tournament_photos') . ' WHERE tournament_id = %d ORDER BY sort_order ASC, id ASC', $tournamentId));
    }

    private static function insert_pgn(int $tournamentId, int $round, string $content, ?string $white = null, ?string $black = null, ?string $result = null): void
    {
        global $wpdb;
        $wpdb->insert(self::db_table('regina_tournament_pgns'), ['tournament_id' => $tournamentId, 'round_no' => $round, 'white_name' => $white, 'black_name' => $black, 'result' => $result, 'pgn_content' => $content, 'created_at' => current_time('mysql')], ['%d', '%d', '%s', '%s', '%s', '%s', '%s']);
    }

    private static function import_pgn_file(int $tournamentId, string $filePath, int $round): int
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return 0;
        }
        $games = self::split_pgn_games($content);
        $count = 0;
        foreach ($games as $game) {
            $meta = self::parse_pgn_headers($game);
            self::insert_pgn($tournamentId, $round, $game, $meta['white'] ?? null, $meta['black'] ?? null, $meta['result'] ?? null);
            $count++;
        }
        return $count;
    }

    private static function split_pgn_games(string $content): array
    {
        $games = [];
        $parts = preg_split('/\n\s*\n\s*\n/', trim($content));
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && preg_match('/\[Event/', $p)) {
                $games[] = $p;
            }
        }
        if (empty($games) && trim($content) !== '') {
            $games[] = $content;
        }
        return $games;
    }

    private static function parse_pgn_headers(string $pgn): array
    {
        $out = [];
        if (preg_match('/\[White\s+"([^"]*)"\]/', $pgn, $m)) {
            $out['white'] = $m[1];
        }
        if (preg_match('/\[Black\s+"([^"]*)"\]/', $pgn, $m)) {
            $out['black'] = $m[1];
        }
        if (preg_match('/\[Result\s+"([^"]*)"\]/', $pgn, $m)) {
            $out['result'] = $m[1];
        }
        return $out;
    }

    private static function delete_pgn(int $tournamentId, int $pgnId): void
    {
        global $wpdb;
        $wpdb->delete(self::db_table('regina_tournament_pgns'), ['id' => $pgnId, 'tournament_id' => $tournamentId], ['%d', '%d']);
    }

    private static function get_pgns(int $tournamentId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::db_table('regina_tournament_pgns') . ' WHERE tournament_id = %d ORDER BY round_no ASC, id ASC', $tournamentId));
    }

    public static function get_published_tournaments(): array
    {
        $tournaments = self::get_tournaments();
        $published = [];
        $logoUrl = self::get_default_logo_url();
        foreach ($tournaments as $t) {
            $dir = ChessPodium_ExportEngine::get_export_dir($t);
            if (!file_exists($dir . '/index.html')) {
                continue;
            }
            $url = ChessPodium_ExportEngine::get_export_url($t) . '/index.html';
            $photos = self::get_photos((int) $t->id);
            $previewUrl = !empty($photos) ? $photos[0]->photo_url : $logoUrl;
            $published[] = [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'url' => $url,
                'preview_url' => $previewUrl,
                'rounds' => (int) $t->current_round,
                'external' => false,
            ];
        }
        $external = self::get_external_tournaments();
        foreach ($external as $e) {
            $published[] = [
                'id' => $e['id'],
                'name' => $e['name'],
                'url' => $e['url'],
                'preview_url' => $e['preview_url'] ?: $logoUrl,
                'rounds' => (int) ($e['rounds'] ?? 0),
                'external' => true,
            ];
        }
        return $published;
    }

    private static function get_external_tournaments(): array
    {
        $data = get_option('chess_podium_external_tournaments', []);
        return is_array($data) ? $data : [];
    }

    private static function get_external_preview_url_from_post(): ?string
    {
        if (!empty($_FILES['ext_preview_file']['tmp_name']) && is_uploaded_file($_FILES['ext_preview_file']['tmp_name'])) {
            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $upload = wp_handle_upload($_FILES['ext_preview_file'], ['test_form' => false]);
            if (!isset($upload['error']) && !empty($upload['url'])) {
                $attachId = wp_insert_attachment([
                    'post_mime_type' => $upload['type'],
                    'post_title' => sanitize_file_name(basename($upload['file'])),
                    'post_content' => '',
                    'post_status' => 'inherit',
                ], $upload['file']);
                if (!is_wp_error($attachId)) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    wp_generate_attachment_metadata($attachId, $upload['file']);
                }
                return $upload['url'];
            }
        }
        $url = isset($_POST['ext_preview']) ? esc_url_raw(trim(wp_unslash($_POST['ext_preview']))) : '';
        return $url !== '' ? $url : null;
    }

    private static function add_external_tournament(string $name, string $url, ?string $previewUrl = null, int $rounds = 0): void
    {
        $list = self::get_external_tournaments();
        $id = 'ext_' . (string) (time() . '_' . wp_rand(100, 999));
        $list[] = [
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'preview_url' => $previewUrl ?? '',
            'rounds' => $rounds,
        ];
        update_option('chess_podium_external_tournaments', $list);
    }

    private static function update_external_tournament(string $id, string $name, string $url, ?string $previewUrl = null, int $rounds = 0): void
    {
        $list = self::get_external_tournaments();
        foreach ($list as $i => $e) {
            if (($e['id'] ?? '') === $id) {
                $list[$i] = [
                    'id' => $id,
                    'name' => $name,
                    'url' => $url,
                    'preview_url' => $previewUrl ?? ($e['preview_url'] ?? ''),
                    'rounds' => $rounds,
                ];
                update_option('chess_podium_external_tournaments', $list);
                return;
            }
        }
    }

    private static function delete_external_tournament(string $id): void
    {
        $list = self::get_external_tournaments();
        $list = array_filter($list, static fn ($e) => ($e['id'] ?? '') !== $id);
        update_option('chess_podium_external_tournaments', array_values($list));
    }

    public static function get_default_logo_url(): string
    {
        return plugins_url('assets/logo-chess-podium.svg', __FILE__);
    }

    private static function maybe_create_tornei_page(): void
    {
        $optionKey = 'chess_podium_tornei_page_id';
        $pageId = (int) get_option($optionKey, 0);
        if ($pageId <= 0) {
            $legacyId = (int) get_option('checkmate_manager_tornei_page_id', 0);
            if ($legacyId > 0) {
                update_option($optionKey, $legacyId);
                delete_option('checkmate_manager_tornei_page_id');
                $pageId = $legacyId;
            }
        }
        if ($pageId > 0) {
            $page = get_post($pageId);
            if ($page && $page->post_status === 'publish') {
                return;
            }
        }
        $authorId = 1;
        $admin = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
        if (!empty($admin)) {
            $authorId = (int) $admin[0]->ID;
        }
        $pageId = wp_insert_post([
            'post_title' => __('Tournaments', 'chess-podium'),
            'post_name' => 'tornei',
            'post_content' => '<!-- wp:shortcode -->[chess_podium_tornei]<!-- /wp:shortcode -->',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => $authorId,
            'comment_status' => 'closed',
        ]);
        if (is_wp_error($pageId)) {
            return;
        }
        update_option($optionKey, (int) $pageId);
        self::add_tornei_page_to_menu((int) $pageId);
    }

    private static function add_tornei_page_to_menu(int $pageId): void
    {
        $menus = wp_get_nav_menus();
        if (empty($menus)) {
            return;
        }
        $menu = reset($menus);
        $menuId = (int) $menu->term_id;
        $existing = wp_get_nav_menu_items($menuId);
        foreach ((array) $existing as $item) {
            if ((int) $item->object_id === $pageId && $item->object === 'page') {
                return;
            }
        }
        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => $pageId,
            'menu-item-status' => 'publish',
            'menu-item-position' => 5,
        ]);
    }

    private static function pair_round_robin(array $players, int $roundNo): array
    {
        usort($players, static function ($a, $b) {
            return ((int) $b->rating <=> (int) $a->rating);
        });
        $count = count($players);
        $ids = array_map(static fn ($p) => (int) $p->id, $players);
        $isOdd = $count % 2 !== 0;
        if ($isOdd) {
            $ids[] = 0;
        }
        $n = count($ids);
        $fixed = $ids[0];
        $rotating = $isOdd ? array_slice($ids, 1, -1) : array_slice($ids, 1);
        $roundIndex = ($roundNo - 1) % max(1, count($rotating));
        $rotated = array_merge(
            array_slice($rotating, $roundIndex),
            array_slice($rotating, 0, $roundIndex)
        );
        $order = $isOdd ? array_merge([$fixed], $rotated, [0]) : array_merge([$fixed], $rotated);
        $half = (int) ($n / 2);

        $pairs = [];
        for ($i = 0; $i < $half; $i++) {
            $white = $order[$i];
            $black = $order[$half + $i];
            if ($black === 0) {
                $pairs[] = ['white' => $white, 'black' => 0, 'is_bye' => 1];
            } else {
                $pairs[] = ['white' => $white, 'black' => $black, 'is_bye' => 0];
            }
        }
        return $pairs;
    }

    private static function pair_round_one(array $players): array
    {
        usort($players, static function ($a, $b) {
            return ((int) $b->rating <=> (int) $a->rating);
        });

        $pairs = [];
        $count = count($players);
        $half = intdiv($count, 2);

        for ($i = 0; $i < $half; $i++) {
            $white = $players[$i];
            $black = $players[$i + $half];
            $pairs[] = [
                'white' => (int) $white->id,
                'black' => (int) $black->id,
                'is_bye' => 0,
            ];
        }

        if ($count % 2 !== 0) {
            $byePlayer = $players[$count - 1];
            $pairs[] = [
                'white' => (int) $byePlayer->id,
                'black' => 0,
                'is_bye' => 1,
            ];
        }

        return $pairs;
    }

    private static function pair_swiss(array $players, array $standings, int $tournamentId): array
    {
        $ordered = [];
        $pointsMap = [];
        foreach ($standings as $s) {
            $pid = (int) $s['player_id'];
            $ordered[] = $pid;
            $pointsMap[$pid] = (float) $s['points'];
        }

        // Ensure every player is present in ordering.
        foreach ($players as $p) {
            $pid = (int) $p->id;
            if (!in_array($pid, $ordered, true)) {
                $ordered[] = $pid;
                $pointsMap[$pid] = 0.0;
            }
        }

        $allPairings = self::get_all_pairings($tournamentId);
        $playedMap = array();
        $hadBye = array();
        $colorBalances = array();

        foreach ($allPairings as $match) {
            $w = (int) $match->white_player_id;
            $b = (int) $match->black_player_id;
            $isBye = (int) $match->is_bye === 1;

            if (!isset($colorBalances[$w])) {
                $colorBalances[$w] = 0;
            }

            if ($isBye) {
                $hadBye[$w] = true;
                continue;
            }

            if (!isset($colorBalances[$b])) {
                $colorBalances[$b] = 0;
            }
            $colorBalances[$w]++;
            $colorBalances[$b]--;
            $playedMap[self::pair_key($w, $b)] = true;
        }

        $pairs = [];
        $remaining = $ordered;

        // Odd players: choose BYE among the lowest ranked, preferring one who never had BYE.
        if (count($remaining) % 2 !== 0) {
            $byeCandidate = null;
            for ($i = count($remaining) - 1; $i >= 0; $i--) {
                $pid = (int) $remaining[$i];
                if (!isset($hadBye[$pid])) {
                    $byeCandidate = $pid;
                    break;
                }
            }
            if ($byeCandidate === null && !empty($remaining)) {
                $byeCandidate = (int) $remaining[count($remaining) - 1];
            }
            if ($byeCandidate !== null) {
                $pairs[] = array('white' => $byeCandidate, 'black' => 0, 'is_bye' => 1);
                $remaining = array_values(array_filter(
                    $remaining,
                    static function ($id) use ($byeCandidate) {
                        return (int) $id !== (int) $byeCandidate;
                    }
                ));
            }
        }

        // Backtracking is exponential: with 20+ players it can take minutes or timeout.
        // Use greedy algorithm directly for larger tournaments.
        $n = count($remaining);
        if ($n > 20) {
            $searchedPairs = self::pair_swiss_greedy_fallback($remaining, $pointsMap, $playedMap, $colorBalances);
        } else {
            $searchedPairs = self::search_pairings_backtracking($remaining, $pointsMap, $playedMap, $colorBalances);
            if (count($searchedPairs) * 2 !== $n) {
                $searchedPairs = self::pair_swiss_greedy_fallback($remaining, $pointsMap, $playedMap, $colorBalances);
            }
        }

        return array_merge($pairs, $searchedPairs);
    }

    private static function search_pairings_backtracking(
        array $remaining,
        array $pointsMap,
        array $playedMap,
        array $colorBalances
    ): array {
        $bestPairs = array();
        $bestPenalty = PHP_INT_MAX;
        $maxCandidates = 8;

        $walk = function (array $todo, array $currentPairs, int $currentPenalty) use (
            &$walk,
            &$bestPairs,
            &$bestPenalty,
            $pointsMap,
            $playedMap,
            $colorBalances,
            $maxCandidates
        ) {
            if ($currentPenalty >= $bestPenalty) {
                return;
            }

            if (empty($todo)) {
                $bestPenalty = $currentPenalty;
                $bestPairs = $currentPairs;
                return;
            }

            $a = (int) $todo[0];
            $others = array_slice($todo, 1);
            $candidates = array();

            foreach ($others as $b) {
                $b = (int) $b;
                $hasRematch = isset($playedMap[self::pair_key($a, $b)]);
                $rematchPenalty = $hasRematch ? 1000 : 0;
                $scoreDiff = abs((float) ($pointsMap[$a] ?? 0.0) - (float) ($pointsMap[$b] ?? 0.0));
                $scorePenalty = (int) round($scoreDiff * 10);
                $orientation = self::best_orientation($a, $b, $colorBalances);
                $totalPenalty = $rematchPenalty + $scorePenalty + (int) $orientation['penalty'];

                $candidates[] = array(
                    'opp' => $b,
                    'white' => (int) $orientation['white'],
                    'black' => (int) $orientation['black'],
                    'penalty' => $totalPenalty,
                );
            }

            usort($candidates, static function ($x, $y) {
                return (int) $x['penalty'] <=> (int) $y['penalty'];
            });

            $candidates = array_slice($candidates, 0, $maxCandidates);

            foreach ($candidates as $candidate) {
                $newPenalty = $currentPenalty + (int) $candidate['penalty'];
                if ($newPenalty >= $bestPenalty) {
                    continue;
                }

                $opp = (int) $candidate['opp'];
                $nextTodo = array();
                foreach ($others as $id) {
                    if ((int) $id !== $opp) {
                        $nextTodo[] = (int) $id;
                    }
                }

                $nextPairs = $currentPairs;
                $nextPairs[] = array(
                    'white' => (int) $candidate['white'],
                    'black' => (int) $candidate['black'],
                    'is_bye' => 0,
                );

                $walk($nextTodo, $nextPairs, $newPenalty);
            }
        };

        $walk($remaining, array(), 0);
        return $bestPairs;
    }

    private static function pair_swiss_greedy_fallback(array $remaining, array $pointsMap, array $playedMap, array $colorBalances): array
    {
        $pairs = array();
        $todo = array_values($remaining);

        while (count($todo) > 1) {
            $a = (int) array_shift($todo);
            $bestIdx = null;
            $bestPenalty = PHP_INT_MAX;
            $bestOrientation = array('white' => $a, 'black' => 0, 'penalty' => 0);

            $scoreA = (float) ($pointsMap[$a] ?? 0.0);
            foreach ($todo as $idx => $rawB) {
                $b = (int) $rawB;
                $hasRematch = isset($playedMap[self::pair_key($a, $b)]);
                $scoreDiff = abs($scoreA - (float) ($pointsMap[$b] ?? 0.0));
                $orientation = self::best_orientation($a, $b, $colorBalances);
                $penalty = ($hasRematch ? 1000 : 0) + (int) round($scoreDiff * 10) + (int) $orientation['penalty'];
                if ($penalty < $bestPenalty) {
                    $bestPenalty = $penalty;
                    $bestIdx = (int) $idx;
                    $bestOrientation = $orientation;
                }
            }

            if ($bestIdx === null) {
                break;
            }

            $b = (int) $todo[$bestIdx];
            unset($todo[$bestIdx]);
            $todo = array_values($todo);

            $pairs[] = array(
                'white' => (int) $bestOrientation['white'],
                'black' => (int) $bestOrientation['black'],
                'is_bye' => 0,
            );
        }

        return $pairs;
    }

    private static function best_orientation(int $a, int $b, array $colorBalances): array
    {
        $balA = (int) ($colorBalances[$a] ?? 0);
        $balB = (int) ($colorBalances[$b] ?? 0);

        // Option 1: a as white, b as black
        $p1 = abs($balA + 1) + abs($balB - 1);
        // Option 2: b as white, a as black
        $p2 = abs($balB + 1) + abs($balA - 1);

        if ($p1 <= $p2) {
            return array('white' => $a, 'black' => $b, 'penalty' => $p1);
        }

        return array('white' => $b, 'black' => $a, 'penalty' => $p2);
    }

    private static function pair_key(int $a, int $b): string
    {
        $min = min($a, $b);
        $max = max($a, $b);
        return $min . '-' . $max;
    }

    private static function color_balance(int $tournamentId, int $playerId): int
    {
        global $wpdb;
        $pairings = self::db_table('regina_pairings');
        $white = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pairings WHERE tournament_id=%d AND white_player_id=%d", $tournamentId, $playerId));
        $black = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pairings WHERE tournament_id=%d AND black_player_id=%d", $tournamentId, $playerId));
        return $white - $black;
    }

    private static function already_played(int $tournamentId, int $a, int $b): bool
    {
        global $wpdb;
        $pairings = self::db_table('regina_pairings');
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $pairings
                 WHERE tournament_id=%d
                 AND ((white_player_id=%d AND black_player_id=%d) OR (white_player_id=%d AND black_player_id=%d))",
                $tournamentId,
                $a,
                $b,
                $b,
                $a
            )
        );
        return $count > 0;
    }

    private static function player_used_in_pairings(int $tournamentId, int $playerId): bool
    {
        global $wpdb;
        $pairings = self::db_table('regina_pairings');
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $pairings
                 WHERE tournament_id = %d
                 AND (white_player_id = %d OR black_player_id = %d)",
                $tournamentId,
                $playerId,
                $playerId
            )
        );
        return $count > 0;
    }

    private static function sanitize_fide_id(string $raw): string
    {
        return preg_replace('/[^0-9]/', '', $raw) ?? '';
    }

    private static function fetch_fide_player_data(string $fideId): ?array
    {
        $url = 'https://ratings.fide.com/profile/' . rawurlencode($fideId);
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'user-agent' => 'ChessPodium/0.1 (+WordPress)',
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }

        $name = '';
        if (preg_match('/<title>\s*([^<]+?)\s+FIDE\s+Profile\s*<\/title>/i', $body, $m)) {
            $name = trim(wp_strip_all_tags($m[1]));
        }

        $plain = preg_replace('/\s+/', ' ', wp_strip_all_tags($body));
        $rating = 0;
        if (is_string($plain) && preg_match('/\b([0-9]{3,4})\s*STANDARD\b/i', $plain, $m2)) {
            $rating = (int) $m2[1];
        }

        if ($name === '') {
            return null;
        }

        $country = null;
        if (preg_match('/topfed\.phtml\?country=([A-Z]{3})\b/i', $body, $m)) {
            $country = ChessPodium_CountryHelper::fide_to_iso($m[1]);
        } elseif (preg_match('/\bfederation[:\s]+[A-Za-z\s]+\(([A-Z]{3})\)/i', $body, $m)) {
            $country = ChessPodium_CountryHelper::fide_to_iso($m[1]);
        } elseif (preg_match('/\b(ITA|GER|USA|RUS|FRA|ENG|ESP|CHN|IND|NED|POL|UKR|HUN|AZE|ARM|ROU|CZE|GRE|CRO|SRB|BUL|SWE|NOR|DEN|FIN|ISR|BRA|ARG|MEX|CAN|AUS|EGY|IRI|UZB|KAZ|GEO|LTU|LAT|EST|SLO|SUI|AUT|BLR|MDA|PHI|VIE|THA|INA|SGP|MAL)\b/i', $body, $m)) {
            $country = ChessPodium_CountryHelper::fide_to_iso($m[1]);
        }

        return array(
            'name' => $name,
            'rating' => $rating,
            'fide_id' => $fideId,
            'country' => $country,
        );
    }

    private static function render_admin_notice(string $code, int $importedCount = 0): void
    {
        $map = array(
            'create_success' => array('success', __('Tournament created successfully.', 'chess-podium')),
            'create_error_name' => array('error', __('Enter a valid tournament name.', 'chess-podium')),
            'add_player_success' => array('success', __('Player added successfully.', 'chess-podium')),
            'add_player_error_tournament' => array('error', __('Select a valid tournament.', 'chess-podium')),
            'add_player_error_name' => array('error', __('Enter a valid player name.', 'chess-podium')),
            'add_player_error_country' => array('error', __('Select a nationality for the player.', 'chess-podium')),
            'player_limit_reached' => array('error', __('Player limit reached. Upgrade to Chess Podium Pro for unlimited players.', 'chess-podium')),
            'update_player_success' => array('success', __('Player updated successfully.', 'chess-podium')),
            'update_player_error' => array('error', __('Unable to update: invalid data.', 'chess-podium')),
            'update_player_error_name' => array('error', __('Player name cannot be empty.', 'chess-podium')),
            'delete_player_success' => array('success', __('Player deleted successfully.', 'chess-podium')),
            'delete_player_error' => array('error', __('Unable to delete: invalid data.', 'chess-podium')),
            'delete_player_in_use' => array('warning', __('Unable to delete: player is already paired in one or more rounds.', 'chess-podium')),
            'generate_success' => array('success', __('Round generated successfully.', 'chess-podium')),
            'generate_failed' => array('error', __('Unable to generate round. Ensure you have at least 2 players and rounds available.', 'chess-podium')),
            'generate_error' => array('error', __('Select a valid tournament.', 'chess-podium')),
            'rollback_success' => array('success', __('Round rolled back successfully.', 'chess-podium')),
            'rollback_failed' => array('error', __('Unable to rollback: no round to rollback.', 'chess-podium')),
            'rollback_error' => array('error', __('Select a valid tournament.', 'chess-podium')),
            'save_results_success' => array('success', __('Results saved successfully.', 'chess-podium')),
            'save_results_error' => array('error', __('Unable to save results.', 'chess-podium')),
            'save_results_no_data' => array('warning', __('No results to save.', 'chess-podium')),
            'fide_imported' => array('success', __('Player imported from FIDE successfully.', 'chess-podium')),
            'fide_exists' => array('warning', __('This FIDE ID is already in the tournament.', 'chess-podium')),
            'fide_not_found' => array('error', __('FIDE player not found or unreachable.', 'chess-podium')),
            'fide_invalid' => array('error', __('Invalid FIDE ID.', 'chess-podium')),
            'bulk_import_success' => array('success', __('Players imported successfully.', 'chess-podium')),
            'bulk_import_error' => array('error', __('CSV import error. Check format (name;rating or name,rating).', 'chess-podium')),
            'bulk_import_no_file' => array('error', __('Select a CSV file to upload.', 'chess-podium')),
            'publish_success' => array('success', __('Tournament published successfully.', 'chess-podium')),
            'publish_error' => array('error', __('Publishing error.', 'chess-podium')),
            'photo_added' => array('success', __('Photo added to gallery.', 'chess-podium')),
            'photo_deleted' => array('success', __('Photo removed.', 'chess-podium')),
            'photo_error' => array('error', __('Error: select a tournament.', 'chess-podium')),
            'photo_upload_error' => array('error', __('Photo upload error.', 'chess-podium')),
            'pgn_added' => array('success', __('PGN game added.', 'chess-podium')),
            'pgn_deleted' => array('success', __('PGN game removed.', 'chess-podium')),
            'pgn_error' => array('error', __('Error: enter valid PGN content or upload a file.', 'chess-podium')),
            'delete_tournament_success' => array('success', __('Tournament permanently deleted.', 'chess-podium')),
            'delete_tournament_error' => array('error', __('Unable to delete tournament.', 'chess-podium')),
            'update_name_success' => array('success', __('Tournament name updated.', 'chess-podium')),
            'update_name_empty' => array('error', __('Tournament name cannot be empty.', 'chess-podium')),
            'update_name_error' => array('error', __('Unable to update tournament name.', 'chess-podium')),
        );

        if (!isset($map[$code])) {
            return;
        }

        [$type, $text] = $map[$code];
        if ($code === 'bulk_import_success' && $importedCount > 0) {
            $text = sprintf(/* translators: %d: number of players */ _n('%d player imported successfully.', '%d players imported successfully.', $importedCount, 'chess-podium'), $importedCount);
        }
        if ($code === 'pgn_added' && $importedCount > 1) {
            $text = sprintf(/* translators: %d: number of games */ __('%d PGN games imported.', 'chess-podium'), $importedCount);
        }
        if ($code === 'photo_added' && $importedCount > 1) {
            $text = sprintf(/* translators: %d: number of photos */ __('%d photos added to gallery.', 'chess-podium'), $importedCount);
        }
        $allowHtml = false;
        if ($code === 'publish_success' && isset($_GET['cp_publish_url'])) {
            $url = esc_url_raw(wp_unslash($_GET['cp_publish_url']));
            if ($url !== '') {
                $text = sprintf(
                    /* translators: %s: link to published page */
                    __('Tournament published. <a href="%s" target="_blank">Open published page</a>', 'chess-podium'),
                    esc_url($url)
                );
                $allowHtml = true;
            }
        }
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . ($allowHtml ? wp_kses_post($text) : esc_html($text)) . '</p></div>';
    }

    private static function compute_standings(int $tournamentId): array
    {
        $tournament = self::get_tournament($tournamentId);
        $byePoints = ($tournament && isset($tournament->bye_points)) ? (float) $tournament->bye_points : 1.0;
        $players = self::get_players($tournamentId);
        $pairings = self::get_all_pairings($tournamentId);

        $scores = [];
        $opponents = [];
        $resultsVsOpponent = [];

        foreach ($players as $p) {
            $pid = (int) $p->id;
            $scores[$pid] = 0.0;
            $opponents[$pid] = [];
            $resultsVsOpponent[$pid] = [];
        }

        foreach ($pairings as $match) {
            $w = (int) $match->white_player_id;
            $b = (int) $match->black_player_id;
            $res = (string) $match->result;
            $isBye = (int) $match->is_bye === 1;

            if ($isBye) {
                $scores[$w] += $byePoints;
                continue;
            }

            $opponents[$w][] = $b;
            $opponents[$b][] = $w;

            if ($res === '1-0') {
                $scores[$w] += 1.0;
                $resultsVsOpponent[$w][$b] = 1.0;
                $resultsVsOpponent[$b][$w] = 0.0;
            } elseif ($res === '0-1') {
                $scores[$b] += 1.0;
                $resultsVsOpponent[$w][$b] = 0.0;
                $resultsVsOpponent[$b][$w] = 1.0;
            } elseif ($res === '1/2-1/2') {
                $scores[$w] += 0.5;
                $scores[$b] += 0.5;
                $resultsVsOpponent[$w][$b] = 0.5;
                $resultsVsOpponent[$b][$w] = 0.5;
            }
        }

        $rows = [];
        foreach ($players as $p) {
            $pid = (int) $p->id;
            $buchholz = 0.0;
            $sb = 0.0;
            foreach ($opponents[$pid] as $opp) {
                $oppScore = $scores[$opp] ?? 0.0;
                $buchholz += $oppScore;
                $gameScore = $resultsVsOpponent[$pid][$opp] ?? 0.0;
                $sb += $oppScore * $gameScore;
            }
            $rows[] = [
                'player_id' => $pid,
                'name' => $p->name,
                'country' => isset($p->country) ? (string) $p->country : '',
                'fide_id' => isset($p->fide_id) ? (string) $p->fide_id : '',
                'rating' => (int) $p->rating,
                'points' => $scores[$pid] ?? 0.0,
                'buchholz' => $buchholz,
                'sb' => $sb,
            ];
        }

        $tiebreakers = ($tournament && isset($tournament->tiebreakers)) ? explode(',', (string) $tournament->tiebreakers) : ['buchholz', 'sb', 'rating'];
        $tiebreakers = array_map('trim', array_map('strtolower', $tiebreakers));

        usort($rows, static function ($a, $b) use ($tiebreakers) {
            if ($b['points'] !== $a['points']) {
                return $b['points'] <=> $a['points'];
            }
            foreach ($tiebreakers as $tb) {
                if ($tb === 'buchholz' && $b['buchholz'] !== $a['buchholz']) {
                    return $b['buchholz'] <=> $a['buchholz'];
                }
                if ($tb === 'sb' && $b['sb'] !== $a['sb']) {
                    return $b['sb'] <=> $a['sb'];
                }
                if ($tb === 'rating' && $b['rating'] !== $a['rating']) {
                    return $b['rating'] <=> $a['rating'];
                }
            }
            return $b['rating'] <=> $a['rating'];
        });

        return $rows;
    }

    public static function handle_export_requests(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (empty($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== self::MENU_SLUG) {
            return;
        }
        if (empty($_GET['regina_export'])) {
            return;
        }

        $exportType = sanitize_key(wp_unslash($_GET['regina_export']));
        $tournamentId = isset($_GET['tournament_id']) ? (int) $_GET['tournament_id'] : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if ($tournamentId <= 0 || !in_array($exportType, array('turns', 'standings'), true)) {
            wp_die('Richiesta export non valida.');
        }
        if (!wp_verify_nonce($nonce, 'regina_export_' . $tournamentId . '_' . $exportType)) {
            wp_die('Nonce non valido.');
        }

        $tournament = self::get_tournament($tournamentId);
        if (!$tournament) {
            wp_die('Torneo non trovato.');
        }

        if ($exportType === 'turns') {
            self::output_turns_csv($tournament);
            return;
        }

        self::output_standings_csv($tournament);
    }

    private static function build_export_url(int $tournamentId, string $exportType): string
    {
        $url = add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'tournament_id' => $tournamentId,
                'regina_export' => $exportType,
            ),
            admin_url('admin.php')
        );

        return wp_nonce_url($url, 'regina_export_' . $tournamentId . '_' . $exportType);
    }

    private static function output_turns_csv(object $tournament): void
    {
        $rows = self::get_all_pairings_detailed((int) $tournament->id);
        $filename = 'chess-podium-turns-' . sanitize_title((string) $tournament->name) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fputcsv($out, array('round', 'board', 'white', 'black', 'result', 'is_bye'));
        $boardByRound = array();
        foreach ($rows as $row) {
            $roundNo = (int) $row->round_no;
            if (!isset($boardByRound[$roundNo])) {
                $boardByRound[$roundNo] = 0;
            }
            $boardByRound[$roundNo]++;
            fputcsv($out, array(
                $roundNo,
                $boardByRound[$roundNo],
                (string) $row->white_name,
                (int) $row->is_bye === 1 ? '' : (string) $row->black_name,
                (string) ($row->result ?? ''),
                (int) $row->is_bye,
            ));
        }

        fclose($out);
        exit;
    }

    private static function output_standings_csv(object $tournament): void
    {
        $standings = self::compute_standings((int) $tournament->id);
        $filename = 'chess-podium-standings-' . sanitize_title((string) $tournament->name) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fputcsv($out, array('position', 'player', 'country', 'fide_id', 'rating', 'points', 'buchholz', 'sonneborn_berger'));
        foreach ($standings as $pos => $row) {
            fputcsv($out, array(
                (int) $pos + 1,
                (string) $row['name'],
                (string) ($row['country'] ?? ''),
                (string) ($row['fide_id'] ?? ''),
                (int) $row['rating'],
                (float) $row['points'],
                (float) $row['buchholz'],
                (float) $row['sb'],
            ));
        }

        fclose($out);
        exit;
    }
}

register_activation_hook(__FILE__, ['ChessPodiumPlugin', 'activate']);
register_deactivation_hook(__FILE__, ['ChessPodiumPlugin', 'deactivate']);
ChessPodiumPlugin::init();

