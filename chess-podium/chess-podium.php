<?php
/**
 * Plugin Name: Chess Podium
 * Plugin URI: https://chesspodium.com
 * Description: Chess tournament manager for WordPress: players, rounds, Swiss pairings, results, standings, and exports. Free plan: up to 10 players per tournament. Upgrade to Pro for unlimited players.
 * Version: 0.4.0
 * Author: Chess Podium
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
require_once __DIR__ . '/includes/class-registration.php';
require_once __DIR__ . '/includes/class-locale.php';
require_once __DIR__ . '/includes/class-player-status.php';
require_once __DIR__ . '/includes/class-trf-export.php';
require_once __DIR__ . '/includes/class-pdf-brochure.php';
require_once __DIR__ . '/includes/class-club-ranking.php';
require_once __DIR__ . '/includes/class-pgn-live.php';
require_once __DIR__ . '/includes/class-max-weight-matching.php';

if (!defined('CHESS_PODIUM_PLUGIN_DIR')) {
    define('CHESS_PODIUM_PLUGIN_DIR', __DIR__);
}

// Must run before plugins_loaded so load_plugin_textdomain sees our locale
add_filter('determine_locale', 'chess_podium_determine_locale', 1);
add_filter('locale', 'chess_podium_determine_locale', 1);

final class ChessPodiumPlugin
{
    private const DB_VERSION = '0.3.0';
    private const MENU_SLUG = 'chess-podium';

    public static function init(): void
    {
        add_action('plugins_loaded', [self::class, 'load_textdomain']);
        add_action('admin_menu', [self::class, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_external_tournaments_scripts']);
        add_action('admin_init', [self::class, 'handle_admin_actions']);
        add_action('admin_init', [self::class, 'handle_export_requests']);
        add_shortcode('chess_podium_tournament', [self::class, 'render_tournament_shortcode']);
        add_shortcode('chess_podium_brochure', [self::class, 'render_brochure_shortcode']);
        add_shortcode('chess_podium_club_ranking', [self::class, 'render_club_ranking_shortcode']);
        add_shortcode('chess_podium_player_profile', [self::class, 'render_player_profile_shortcode']);
        add_shortcode('chess_podium_tornei', [self::class, 'render_tornei_page_shortcode']);
        add_shortcode('checkmate_manager_tornei', [self::class, 'render_tornei_page_shortcode']);
        add_shortcode('storico_tornei', [self::class, 'render_tornei_page_shortcode']);
        // Backward compatibility with previous shortcode.
        add_shortcode('regina_torneo', [self::class, 'render_tournament_shortcode']);
        add_action('init', [self::class, 'register_rewrite']);
        add_action('chess_podium_license_check', [ChessPodium_License::class, 'force_revalidate']);
        add_action('widgets_init', [self::class, 'register_widgets']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_widget_styles']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_public_styles']);
        add_filter('query_vars', [self::class, 'register_query_vars']);
        add_action('template_redirect', [self::class, 'handle_public_route']);
        add_action('rest_api_init', [self::class, 'register_live_rest_routes']);
        add_filter('chess_podium_tournament_slug', [self::class, 'filter_slug_by_locale'], 5);
        add_filter('chess_podium_live_slug', [self::class, 'filter_slug_by_locale'], 5);
        ChessPodium_Registration::init();
        ChessPodium_PgnLive::init();
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

    public static function get_tournament_url(): string
    {
        $slug = apply_filters('chess_podium_tournament_slug', 'torneo');
        $slug = sanitize_title($slug) ?: 'torneo';
        return home_url('/' . $slug . '/');
    }

    public static function get_live_url(int $tournamentId = 0): string
    {
        $liveSlug = apply_filters('chess_podium_live_slug', 'livetournament');
        $liveSlug = sanitize_title($liveSlug) ?: 'livetournament';
        $slug = apply_filters('chess_podium_tournament_slug', 'torneo');
        $slug = sanitize_title($slug) ?: 'torneo';
        $base = home_url('/' . $slug . '/' . $liveSlug . '/');
        return $tournamentId > 0 ? add_query_arg('tournament_id', $tournamentId, $base) : $base;
    }

    public static function filter_slug_by_locale(string $slug): string
    {
        $which = current_filter() === 'chess_podium_live_slug' ? 'live' : 'main';
        $locale = function_exists('get_locale') ? get_locale() : '';
        if (strpos($locale, 'it') === 0) {
            return $which === 'live' ? 'livetorneo' : 'torneo';
        }
        if (strpos($locale, 'en') === 0 || strpos($locale, 'de') === 0 || strpos($locale, 'fr') === 0 || strpos($locale, 'es') === 0) {
            return $which === 'live' ? 'livetournament' : 'tournament';
        }
        return $slug;
    }

    public static function register_rewrite(): void
    {
        $slug = apply_filters('chess_podium_tournament_slug', 'torneo');
        $slug = sanitize_title($slug) ?: 'torneo';
        $liveSlug = apply_filters('chess_podium_live_slug', 'livetournament');
        $liveSlug = sanitize_title($liveSlug) ?: 'livetournament';
        add_rewrite_rule('^' . preg_quote($slug, '/') . '/?$', 'index.php?chess_podium_torneo_page=1', 'top');
        add_rewrite_rule('^currenttournament/?$', 'index.php?chess_podium_torneo_page=1', 'top');
        add_rewrite_rule('^' . preg_quote($slug, '/') . '/' . preg_quote($liveSlug, '/') . '/?$', 'index.php?chess_podium_live=1', 'top');
        add_rewrite_rule('^' . preg_quote($liveSlug, '/') . '/?$', 'index.php?chess_podium_live=1', 'top');
        if ($slug === 'torneo') {
            add_rewrite_rule('^torneo/live/?$', 'index.php?chess_podium_live=1', 'top');
        }
    }

    public static function register_query_vars(array $vars): array
    {
        $vars[] = 'chess_podium_torneo_page';
        $vars[] = 'chess_podium_live';
        return $vars;
    }

    public static function register_live_rest_routes(): void
    {
        register_rest_route('chess-podium/v1', '/live/current', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_live_current'],
            'permission_callback' => '__return_true',
            'args' => [
                'tournament_id' => ['required' => false, 'type' => 'integer', 'default' => 0],
            ],
        ]);
    }

    public static function rest_live_current(\WP_REST_Request $request): \WP_REST_Response
    {
        $tid = (int) $request->get_param('tournament_id');
        $tournament = $tid > 0 ? self::get_tournament($tid) : self::get_tournament_in_progress();
        if (!$tournament) {
            return new \WP_REST_Response(['tournament' => null, 'standings' => [], 'pairings' => []], 200);
        }
        $tid = (int) $tournament->id;
        $standings = self::compute_standings($tid);
        $currentRound = (int) $tournament->current_round;
        $pairings = $currentRound > 0 ? self::get_pairings_for_round($tid, $currentRound) : [];
        $startingRanks = self::get_starting_ranks($tid);
        $pointsBeforeRound = $currentRound > 0 ? self::compute_standings_before_round($tid, $currentRound) : [];
        $pairingsData = [];
        foreach ($pairings as $i => $p) {
            $whiteId = (int) $p->white_player_id;
            $blackId = (int) $p->black_player_id;
            $pairingsData[] = [
                'board' => $i + 1,
                'white' => $p->white_name ?? '',
                'white_title' => isset($p->white_title) && $p->white_title !== '' ? (string) $p->white_title : '',
                'white_country' => $p->white_country ?? '',
                'white_pts' => $pointsBeforeRound[$whiteId] ?? 0,
                'white_nr' => $startingRanks[$whiteId] ?? 0,
                'black' => (int) $p->is_bye === 1 ? '-' : ($p->black_name ?? ''),
                'black_title' => (int) $p->is_bye === 1 ? '' : (isset($p->black_title) && $p->black_title !== '' ? (string) $p->black_title : ''),
                'black_country' => (int) $p->is_bye === 1 ? '' : ($p->black_country ?? ''),
                'black_pts' => (int) $p->is_bye === 1 ? null : ($pointsBeforeRound[$blackId] ?? 0),
                'black_nr' => (int) $p->is_bye === 1 ? null : ($startingRanks[$blackId] ?? 0),
                'result' => (string) ($p->result ?? ''),
                'is_bye' => (int) $p->is_bye === 1,
            ];
        }
        return new \WP_REST_Response([
            'tournament' => [
                'id' => (int) $tournament->id,
                'name' => (string) $tournament->name,
                'current_round' => $currentRound,
                'rounds_total' => (int) $tournament->rounds_total,
            ],
            'standings' => array_map(static function ($s, $pos) use ($startingRanks) {
                $pid = (int) ($s['player_id'] ?? 0);
                return [
                    'pos' => $pos + 1,
                    'nr' => $startingRanks[$pid] ?? 0,
                    'name' => $s['name'] ?? '',
                    'title' => $s['title'] ?? '',
                    'country' => $s['country'] ?? '',
                    'points' => (float) ($s['points'] ?? 0),
                    'buchholz' => (float) ($s['buchholz'] ?? 0),
                ];
            }, $standings, array_keys($standings)),
            'pairings' => $pairingsData,
        ], 200);
    }

    public static function handle_public_route(): void
    {
        if ((int) get_query_var('chess_podium_live') === 1) {
            self::render_live_dashboard();
            exit;
        }

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
                .cp-flag-img { display: inline-block; vertical-align: middle; margin-right: 4px; }
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

    private static function render_live_dashboard(): void
    {
        $restUrl = rest_url('chess-podium/v1/live/current');
        $tid = isset($_GET['tournament_id']) ? (int) $_GET['tournament_id'] : 0;
        if ($tid > 0) {
            $restUrl = add_query_arg('tournament_id', $tid, $restUrl);
        }
        status_header(200);
        nocache_headers();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e('Live - Tournament', 'chess-podium'); ?></title>
            <style>
                * { box-sizing: border-box; }
                body { margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: #0d1117; color: #e6edf3; min-height: 100vh; overflow-x: hidden; }
                .cp-live { max-width: 100%; padding: 2rem; }
                .cp-live h1 { font-size: 2.5rem; margin: 0 0 1rem; color: #58a6ff; }
                .cp-live-section { margin-bottom: 2rem; }
                .cp-live-section h2 { font-size: 1.5rem; margin: 0 0 1rem; color: #8b949e; border-bottom: 2px solid #30363d; padding-bottom: 0.5rem; }
                .cp-live-table { width: 100%; border-collapse: collapse; font-size: 1.25rem; }
                .cp-live-table th, .cp-live-table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid #21262d; }
                .cp-live-table th { color: #8b949e; font-weight: 600; }
                .cp-live-table tr:hover { background: rgba(88, 166, 255, 0.08); }
                .cp-live-round { font-size: 1.1rem; color: #58a6ff; margin-bottom: 0.5rem; }
                .cp-live-no-data { text-align: center; padding: 3rem; color: #8b949e; font-size: 1.2rem; }
                .cp-live-scroll { max-height: 50vh; overflow-y: auto; }
                .cp-live-scroll::-webkit-scrollbar { width: 8px; }
                .cp-live-scroll::-webkit-scrollbar-track { background: #21262d; }
                .cp-live-scroll::-webkit-scrollbar-thumb { background: #484f58; border-radius: 4px; }
            </style>
        </head>
        <body>
        <div class="cp-live">
            <h1 id="cp-live-title"><?php esc_html_e('Live Tournament', 'chess-podium'); ?></h1>
            <p id="cp-live-round" class="cp-live-round"></p>
            <div class="cp-live-section">
                <h2><?php esc_html_e('Standings', 'chess-podium'); ?></h2>
                <div class="cp-live-scroll">
                    <table class="cp-live-table" id="cp-live-standings">
                        <thead><tr><th><?php esc_html_e('Pos', 'chess-podium'); ?></th><th><?php esc_html_e('Nr', 'chess-podium'); ?></th><th><?php esc_html_e('Player', 'chess-podium'); ?></th><th><?php esc_html_e('Pts', 'chess-podium'); ?></th><th><?php esc_html_e('Buchholz', 'chess-podium'); ?></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="cp-live-section">
                <h2><?php esc_html_e('Pairings', 'chess-podium'); ?></h2>
                <div class="cp-live-scroll">
                    <table class="cp-live-table" id="cp-live-pairings">
                        <thead><tr><th><?php esc_html_e('Board', 'chess-podium'); ?></th><th><?php esc_html_e('White', 'chess-podium'); ?></th><th><?php esc_html_e('Pts', 'chess-podium'); ?></th><th><?php esc_html_e('Nr', 'chess-podium'); ?></th><th><?php esc_html_e('Result', 'chess-podium'); ?></th><th><?php esc_html_e('Nr', 'chess-podium'); ?></th><th><?php esc_html_e('Pts', 'chess-podium'); ?></th><th><?php esc_html_e('Black', 'chess-podium'); ?></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var url = <?php echo json_encode(esc_url($restUrl)); ?>;
            function fetchData(){
                fetch(url).then(function(r){return r.json();}).then(function(data){
                    var t=data.tournament, st=data.standings||[], pr=data.pairings||[];
                    document.getElementById('cp-live-title').textContent=t?t.name:'<?php echo esc_js(__('No tournament in progress', 'chess-podium')); ?>';
                    document.getElementById('cp-live-round').textContent=t?('<?php echo esc_js(__('Round', 'chess-podium')); ?> '+t.current_round+' / '+t.rounds_total):'';
                    var stBody=document.querySelector('#cp-live-standings tbody');
                    stBody.innerHTML='';
                    if(st.length===0){stBody.innerHTML='<tr><td colspan="5" class="cp-live-no-data"><?php echo esc_js(__('No standings', 'chess-podium')); ?></td></tr>';}
                    else{st.forEach(function(s){var f=s.country?'<img src="https://flagcdn.com/w20/'+s.country.toLowerCase()+'.png" alt="" width="20" height="15" style="vertical-align:middle;margin-right:4px;">':'';
                        stBody.innerHTML+='<tr><td>'+s.pos+'</td><td>'+s.nr+'</td><td>'+f+(s.title?s.title+' ':'')+s.name+'</td><td>'+s.points.toFixed(1)+'</td><td>'+s.buchholz.toFixed(1)+'</td></tr>';});}
                    var prBody=document.querySelector('#cp-live-pairings tbody');
                    prBody.innerHTML='';
                    if(pr.length===0){prBody.innerHTML='<tr><td colspan="8" class="cp-live-no-data"><?php echo esc_js(__('No pairings', 'chess-podium')); ?></td></tr>';}
                    else{pr.forEach(function(p){var fw=p.white_country?'<img src="https://flagcdn.com/w20/'+p.white_country.toLowerCase()+'.png" alt="" width="20" height="15" style="vertical-align:middle;margin-right:4px;">':'';
                        var w=fw+(p.white_title?p.white_title+' ':'')+p.white;var fb=p.is_bye?'':(p.black_country?'<img src="https://flagcdn.com/w20/'+p.black_country.toLowerCase()+'.png" alt="" width="20" height="15" style="vertical-align:middle;margin-right:4px;">':'');
                        var b=p.is_bye?'-':fb+(p.black_title?p.black_title+' ':'')+p.black;
                        prBody.innerHTML+='<tr><td>'+p.board+'</td><td>'+w+'</td><td>'+(p.white_pts!==null?'('+p.white_pts+')':'-')+'</td><td>'+p.white_nr+'</td><td>'+p.result+'</td><td>'+(p.black_nr!==null?p.black_nr:'-')+'</td><td>'+(p.black_pts!==null?'('+p.black_pts+')':'-')+'</td><td>'+b+'</td></tr>';});}
                }).catch(function(){});
            }
            fetchData();
            setInterval(fetchData, 10000);
        })();
        </script>
        </body>
        </html>
        <?php
    }

    public static function enqueue_external_tournaments_scripts(string $hook): void
    {
        if ($hook !== 'chess-podium_page_chess-podium-external-tournaments') {
            return;
        }
        wp_enqueue_media();
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, self::MENU_SLUG) === false) {
            return;
        }
        wp_enqueue_style(
            'chess-podium-admin',
            plugins_url('assets/admin.css', __FILE__),
            [],
            '0.4.0'
        );
        if (!empty($_GET['tournament_id'])) {
            wp_enqueue_media();
        }
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
        // Load explicitly by path so we don't rely on get_locale() (which may be cached by other plugins)
        if (file_exists($moFile)) {
            load_textdomain('chess-podium', $moFile);
        } else {
            load_plugin_textdomain('chess-podium', false, dirname(plugin_basename(__FILE__)) . '/languages');
        }
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Add tournaments to a page', 'chess-podium'); ?></h1>
            <p><?php echo esc_html__('Use these shortcodes to display content on any page or post:', 'chess-podium'); ?></p>
            <table class="widefat striped" style="max-width:700px;">
                <thead><tr><th><?php esc_html_e('Shortcode', 'chess-podium'); ?></th><th><?php esc_html_e('Description', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                    <tr><td><code>[chess_podium_tornei]</code></td><td><?php esc_html_e('List of published tournaments', 'chess-podium'); ?></td></tr>
                    <tr><td><code>[chess_podium_tournament id="X"]</code></td><td><?php esc_html_e('Single tournament (omit id for current)', 'chess-podium'); ?></td></tr>
                    <tr><td><code>[chess_podium_brochure id="X"]</code></td><td><?php esc_html_e('Tournament brochure / announcement', 'chess-podium'); ?></td></tr>
                    <tr><td><code>[chess_podium_club_ranking]</code></td><td><?php esc_html_e('Club ranking from all published tournaments', 'chess-podium'); ?></td></tr>
                    <tr><td><code>[chess_podium_player_profile fide_id="12345"]</code></td><td><?php esc_html_e('Player profile and history', 'chess-podium'); ?></td></tr>
                    <tr><td><code>[chess_podium_player_profile name="Player Name"]</code></td><td><?php esc_html_e('Player profile by name', 'chess-podium'); ?></td></tr>
                </tbody>
            </table>
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

    public static function render_pairing_settings_page(): void
    {
        $savedMsg = '';
        if (isset($_POST['chess_podium_pairing_save']) && check_admin_referer('chess_podium_pairing_settings')) {
            $newPath = sanitize_text_field(wp_unslash($_POST['bbp_pairings_path'] ?? ''));
            if (!empty($_FILES['bbp_pairings_file']['tmp_name']) && is_uploaded_file($_FILES['bbp_pairings_file']['tmp_name'])) {
                $uploadDir = wp_upload_dir();
                $baseDir = $uploadDir['basedir'] . '/chess-podium-bbp';
                if (!file_exists($baseDir)) {
                    wp_mkdir_p($baseDir);
                }
                $ext = strtolower(pathinfo($_FILES['bbp_pairings_file']['name'], PATHINFO_EXTENSION));
                $destName = ($ext === 'exe') ? 'bbpPairings.exe' : 'bbpPairings';
                $destPath = $baseDir . '/' . $destName;
                if (move_uploaded_file($_FILES['bbp_pairings_file']['tmp_name'], $destPath)) {
                    if (PHP_OS_FAMILY !== 'Windows') {
                        @chmod($destPath, 0755);
                    }
                    $newPath = $destPath;
                }
            }
            update_option('chess_podium_bbp_pairings_path', $newPath);
            $apiUrl = trim(sanitize_text_field(wp_unslash($_POST['bbp_pairings_api_url'] ?? '')));
            if ($apiUrl !== '' && !preg_match('#^https?://#i', $apiUrl)) {
                $apiUrl = '';
            }
            update_option('chess_podium_bbp_pairings_api_url', $apiUrl);
            $savedMsg = '<div class="notice notice-success"><p>' . esc_html__('Pairing settings saved.', 'chess-podium') . '</p></div>';
        }
        $bbpPath = get_option('chess_podium_bbp_pairings_path', '');
        $apiUrl = get_option('chess_podium_bbp_pairings_api_url', '');
        $pathExamples = PHP_OS_FAMILY === 'Windows'
            ? __('Examples: C:\\laragon\\bin\\bbpPairings\\bbpPairings.exe or C:\\xampp\\htdocs\\bbpPairings.exe', 'chess-podium')
            : __('Examples: /usr/local/bin/bbpPairings or /home/user/bbpPairings', 'chess-podium');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pairing (bbpPairings)', 'chess-podium'); ?></h1>
            <p><?php esc_html_e('bbpPairings is the FIDE Dutch Swiss pairing engine. Same engine used by Vega. Single Linux executable, no Java or other dependencies. Output identical to JaVaFo.', 'chess-podium'); ?></p>
            <p><a href="https://github.com/BieremaBoyzProgramming/bbpPairings/releases" target="_blank" rel="noopener"><?php esc_html_e('bbpPairings releases on GitHub', 'chess-podium'); ?></a></p>

            <?php if (!function_exists('exec')): ?>
                <div class="notice notice-info"><p><?php esc_html_e('exec() is disabled on this server. Configure a Pairing API URL below to use bbpPairings remotely. Otherwise the built-in pairing engine will be used.', 'chess-podium'); ?></p></div>
            <?php endif; ?>

            <?php echo $savedMsg; ?>

            <form method="post" enctype="multipart/form-data" style="max-width:700px;">
                <?php wp_nonce_field('chess_podium_pairing_settings'); ?>
                <input type="hidden" name="chess_podium_pairing_save" value="1">
                <table class="form-table">
                    <tr>
                        <th><label for="bbp_pairings_path"><?php esc_html_e('Path to bbpPairings', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="bbp_pairings_path" name="bbp_pairings_path" type="text" class="large-text" value="<?php echo esc_attr($bbpPath); ?>" placeholder="<?php echo esc_attr(PHP_OS_FAMILY === 'Windows' ? 'C:\\path\\to\\bbpPairings.exe' : '/usr/local/bin/bbpPairings'); ?>">
                            <p class="description"><?php echo esc_html($pathExamples); ?></p>
                            <p class="description"><?php esc_html_e('Or upload the executable below. Leave empty to use built-in pairing.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bbp_pairings_file"><?php esc_html_e('Upload executable', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="bbp_pairings_file" name="bbp_pairings_file" type="file">
                            <p class="description"><?php esc_html_e('Download bbpPairings from GitHub, then select the file here. It will be saved and the path set automatically.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bbp_pairings_api_url"><?php esc_html_e('Pairing API URL', 'chess-podium'); ?></label></th>
                        <td>
                            <input id="bbp_pairings_api_url" name="bbp_pairings_api_url" type="url" class="large-text" value="<?php echo esc_attr($apiUrl); ?>" placeholder="https://your-server.com/chess-podium-pairing/pair.php">
                            <p class="description"><?php esc_html_e('When exec() is disabled, use a remote pairing API. Deploy the pairing-api folder on a server with bbpPairings and exec() enabled, then enter the full URL to pair.php here.', 'chess-podium'); ?></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'chess-podium'); ?></button>
                    <?php if ($apiUrl !== ''): ?>
                        <button type="submit" name="chess_podium_test_pairing_api" value="1" class="button"><?php esc_html_e('Test API', 'chess-podium'); ?></button>
                    <?php endif; ?>
                </p>
            </form>

            <?php
            $testResult = get_transient('chess_podium_pairing_api_test');
            if (is_array($testResult)):
                delete_transient('chess_podium_pairing_api_test');
                $isOk = !empty($testResult['ok']);
                $msg = $testResult['msg'] ?? '';
            ?>
                <div class="notice notice-<?php echo $isOk ? 'success' : 'error'; ?>"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function get_license_error_message(array $result): string
    {
        $reason = $result['reason'] ?? 'invalid';
        $messages = [
            'invalid_key' => __('Invalid license key. Check the key and try again.', 'chess-podium'),
            'email_mismatch' => __('Email does not match the license. Use the exact email used when purchasing.', 'chess-podium'),
            'domain_mismatch' => __('This license is already activated for another site. One license per domain.', 'chess-podium') . (isset($result['allowed_domain']) ? ' ' . sprintf(__('Allowed domain: %s', 'chess-podium'), $result['allowed_domain']) : ''),
            'expired' => __('License has expired. Renew your subscription.', 'chess-podium'),
            'inactive' => __('License is inactive or revoked.', 'chess-podium'),
            'network_error' => __('Could not reach the license server. Check your connection and try again.', 'chess-podium') . ' ' . ($result['message'] ?? ''),
            'api_error' => __('License server error.', 'chess-podium') . ' ' . ($result['message'] ?? ''),
            'invalid_response' => __('Invalid response from license server.', 'chess-podium'),
        ];
        return $messages[$reason] ?? __('Validation failed. Please check your license key and email.', 'chess-podium');
    }

    public static function render_license_page(): void
    {
        $msg = isset($_GET['cp_msg']) ? sanitize_key(wp_unslash($_GET['cp_msg'])) : '';
        $data = ChessPodium_License::get_license_data();
        $isPro = ChessPodium_License::is_pro();

        $validationError = null;
        if ($msg === 'license_saved' && !$isPro && !empty($data['key']) && !empty($data['email'])) {
            $result = ChessPodium_License::validate_remote_full(trim((string) $data['key']), (string) $data['email']);
            if (!$result['valid'] && isset($result['reason'])) {
                $validationError = self::get_license_error_message($result);
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Chess Podium License', 'chess-podium'); ?></h1>

            <?php if ($msg === 'license_saved'): ?>
                <?php if ($isPro): ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('License saved and validated. Pro active.', 'chess-podium'); ?></p></div>
                <?php elseif ($validationError): ?>
                    <div class="notice notice-error is-dismissible"><p><?php echo esc_html($validationError); ?></p></div>
                <?php else: ?>
                    <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('License saved. Validating...', 'chess-podium'); ?></p></div>
                <?php endif; ?>
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
                <p class="description"><?php echo esc_html__('Enter the license key you received after purchasing Chess Podium Pro. One license per domain—it will be locked to this site on first activation.', 'chess-podium'); ?></p>
                <p class="description"><strong><?php echo esc_html__('This site\'s domain:', 'chess-podium'); ?></strong> <code><?php echo esc_html(parse_url(home_url(), PHP_URL_HOST) ?: '-'); ?></code></p>
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
                '0.4.0'
            );
        }
    }

    public static function enqueue_public_styles(): void
    {
        global $post;
        if (!$post || !is_singular()) {
            return;
        }
        $content = (string) $post->post_content;
        $shortcodes = ['chess_podium_club_ranking', 'chess_podium_player_profile', 'chess_podium_brochure'];
        foreach ($shortcodes as $sc) {
            if (has_shortcode($content, $sc)) {
                wp_enqueue_style(
                    'chess-podium-public',
                    plugins_url('assets/public.css', __FILE__),
                    [],
                    '0.4.0'
                );
                break;
            }
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
        if (ChessPodium_License::is_pro()) {
            add_submenu_page(
                self::MENU_SLUG,
                __('Registration payments', 'chess-podium'),
                __('Registration payments', 'chess-podium'),
                'manage_options',
                'chess-podium-registration',
                [ChessPodium_Registration::class, 'render_settings_page']
            );
        }
        add_submenu_page(
            self::MENU_SLUG,
            __('Pairing (bbpPairings)', 'chess-podium'),
            __('Pairing (bbpPairings)', 'chess-podium'),
            'manage_options',
            'chess-podium-pairing',
            [self::class, 'render_pairing_settings_page']
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

        if (isset($_POST['chess_podium_test_pairing_api']) && check_admin_referer('chess_podium_pairing_settings')) {
            $apiUrl = trim((string) get_option('chess_podium_bbp_pairings_api_url', ''));
            $result = ['ok' => false, 'msg' => ''];
            if ($apiUrl === '' || !preg_match('#^https?://#i', $apiUrl)) {
                $result['msg'] = __('No API URL configured.', 'chess-podium');
            } else {
                $name = str_pad('Test Tournament', 90, ' ');
                $minimalTrf = "012 {$name} " . str_pad('', 25) . "   " . date('Y/m/d') . " " . date('Y/m/d') . "    4    4    0 " . str_pad('', 40) . " " . str_pad('', 40) . str_pad('', 20) . "\r\n";
                $minimalTrf .= "001    1      " . str_pad('Player One', 33) . "1500 ITA          0 0.0    1     -\r\n";
                $minimalTrf .= "001    2      " . str_pad('Player Two', 33) . "1400 ITA          0 0.0    2     -\r\n";
                $minimalTrf .= "001    3      " . str_pad('Player Three', 33) . "1300 ITA          0 0.0    3     -\r\n";
                $minimalTrf .= "001    4      " . str_pad('Player Four', 33) . "1200 ITA          0 0.0    4     -\r\n";
                $minimalTrf .= "XXR 1\r\n";
                $response = wp_remote_post($apiUrl, [
                    'timeout' => 15,
                    'body' => ['trf' => base64_encode($minimalTrf)],
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                ]);
                if (is_wp_error($response)) {
                    $result['msg'] = __('Connection failed:', 'chess-podium') . ' ' . $response->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    $json = json_decode($body, true);
                    if ($code !== 200) {
                        $result['msg'] = sprintf(__('HTTP %d. Response: %s', 'chess-podium'), $code, esc_html(substr($body, 0, 200)));
                    } elseif (!is_array($json)) {
                        $result['msg'] = __('Invalid JSON response:', 'chess-podium') . ' ' . esc_html(substr($body, 0, 150));
                    } elseif (empty($json['ok'])) {
                        $result['msg'] = __('API error:', 'chess-podium') . ' ' . esc_html($json['error'] ?? $body);
                    } elseif (empty($json['pairs']) || !is_array($json['pairs'])) {
                        $result['msg'] = __('API returned no pairings.', 'chess-podium');
                    } else {
                        $result['ok'] = true;
                        $result['msg'] = sprintf(__('API OK. Received %d pairings.', 'chess-podium'), count($json['pairs']));
                    }
                }
            }
            set_transient('chess_podium_pairing_api_test', $result, 60);
            wp_safe_redirect(admin_url('admin.php?page=chess-podium-pairing'));
            exit;
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

            case 'save_registration_settings':
                if ($tid > 0 && ChessPodium_License::is_pro()) {
                    global $wpdb;
                    $enabled = isset($_POST['registration_enabled']) ? 1 : 0;
                    $fee = isset($_POST['registration_fee']) ? max(0.0, (float) $_POST['registration_fee']) : 0.0;
                    $currency = isset($_POST['registration_currency']) ? sanitize_text_field(wp_unslash($_POST['registration_currency'])) : 'EUR';
                    $currency = in_array($currency, ['EUR', 'USD', 'GBP'], true) ? $currency : 'EUR';
                    $wpdb->update(
                        self::db_table('regina_tournaments'),
                        ['registration_enabled' => $enabled, 'registration_fee' => $fee, 'registration_currency' => $currency],
                        ['id' => $tid],
                        ['%d', '%f', '%s'],
                        ['%d']
                    );
                    $redirectArgs['cp_msg'] = 'registration_settings_saved';
                }
                break;

            case 'add_player':
                $playerName = isset($_POST['player_name']) ? trim(sanitize_text_field(wp_unslash($_POST['player_name']))) : '';
                $rating = isset($_POST['player_rating']) ? (int) $_POST['player_rating'] : 0;
                $rating = max(0, min(3500, $rating));
                $country = isset($_POST['player_country']) ? self::sanitize_country_code(sanitize_text_field(wp_unslash($_POST['player_country']))) : null;
                $title = isset($_POST['player_title']) ? self::sanitize_fide_title(sanitize_text_field(wp_unslash($_POST['player_title']))) : null;
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'add_player_error_tournament';
                } elseif ($playerName === '') {
                    $redirectArgs['cp_msg'] = 'add_player_error_name';
                } elseif ($country === null || $country === '') {
                    $redirectArgs['cp_msg'] = 'add_player_error_country';
                } elseif (!ChessPodium_License::can_add_players(count(self::get_players($tid)), 1)) {
                    $redirectArgs['cp_msg'] = 'player_limit_reached';
                } else {
                    self::insert_player($tid, $playerName, $rating, null, $country, $title);
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
                            $title = $fideData['title'] ?? null;
                            self::insert_player($tid, $fideData['name'], $fideData['rating'], $fideId, $country, $title);
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
                $title = isset($_POST['player_title']) ? self::sanitize_fide_title(sanitize_text_field(wp_unslash($_POST['player_title']))) : null;
                if ($tid <= 0 || $playerId <= 0) {
                    $redirectArgs['cp_msg'] = 'update_player_error';
                } elseif ($playerName === '') {
                    $redirectArgs['cp_msg'] = 'update_player_error_name';
                } else {
                    self::update_player($tid, $playerId, $playerName, $rating, $country, $title);
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

            case 'save_fixed_boards':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'fixed_boards_error';
                } elseif (isset($_POST['remove_fixed_board'])) {
                    $pid = (int) $_POST['remove_fixed_board'];
                    self::remove_fixed_board($tid, $pid);
                    $redirectArgs['cp_msg'] = 'fixed_boards_removed';
                } elseif (!empty($_POST['add_fixed_board']) && !empty($_POST['fixed_board_player_id'])) {
                    $pid = (int) $_POST['fixed_board_player_id'];
                    $board = max(1, (int) ($_POST['fixed_board_number'] ?? 1));
                    $fromRound = max(2, (int) ($_POST['fixed_board_from_round'] ?? 2));
                    $tournament = self::get_tournament($tid);
                    $fromRound = $tournament ? min($fromRound, (int) $tournament->rounds_total) : $fromRound;
                    self::save_fixed_board($tid, $pid, $board, $fromRound);
                    $redirectArgs['cp_msg'] = 'fixed_boards_updated';
                }
                break;

            case 'save_brochure':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'brochure_error';
                } else {
                    $data = [
                        'venue_name' => isset($_POST['brochure_venue_name']) ? sanitize_text_field(wp_unslash($_POST['brochure_venue_name'])) : '',
                        'venue_address' => isset($_POST['brochure_venue_address']) ? sanitize_textarea_field(wp_unslash($_POST['brochure_venue_address'])) : '',
                        'venue_map_url' => isset($_POST['brochure_venue_map_url']) ? esc_url_raw(wp_unslash($_POST['brochure_venue_map_url'])) : '',
                        'start_date' => isset($_POST['brochure_start_date']) ? sanitize_text_field(wp_unslash($_POST['brochure_start_date'])) : '',
                        'tournament_rules' => isset($_POST['brochure_tournament_rules']) ? sanitize_textarea_field(wp_unslash($_POST['brochure_tournament_rules'])) : '',
                        'tournament_description' => isset($_POST['brochure_tournament_description']) ? sanitize_textarea_field(wp_unslash($_POST['brochure_tournament_description'])) : '',
                        'prize_table' => [],
                        'contacts' => [],
                    ];
                    $tournament = self::get_tournament($tid);
                    $roundsTotal = $tournament ? (int) $tournament->rounds_total : 0;
                    if ($roundsTotal > 0 && !empty($_POST['brochure_round_date']) && is_array($_POST['brochure_round_date'])) {
                        $roundDates = [];
                        for ($r = 1; $r <= $roundsTotal; $r++) {
                            $d = isset($_POST['brochure_round_date'][$r]) ? sanitize_text_field(wp_unslash($_POST['brochure_round_date'][$r])) : '';
                            $roundDates[$r] = $d;
                        }
                        $data['round_dates'] = $roundDates;
                    }
                    if ($roundsTotal > 0 && !empty($_POST['brochure_round_time']) && is_array($_POST['brochure_round_time'])) {
                        $roundTimes = [];
                        for ($r = 1; $r <= $roundsTotal; $r++) {
                            $t = isset($_POST['brochure_round_time'][$r]) ? sanitize_text_field(wp_unslash($_POST['brochure_round_time'][$r])) : '';
                            $roundTimes[$r] = $t;
                        }
                        $data['round_times'] = $roundTimes;
                    }
                    $data['time_control'] = isset($_POST['brochure_time_control']) ? sanitize_text_field(wp_unslash($_POST['brochure_time_control'])) : '';
                    $data['fee_titled'] = isset($_POST['brochure_fee_titled']) ? sanitize_text_field(wp_unslash($_POST['brochure_fee_titled'])) : '';
                    $data['fee_normal'] = isset($_POST['brochure_fee_normal']) ? sanitize_text_field(wp_unslash($_POST['brochure_fee_normal'])) : '';
                    $data['fee_under18'] = isset($_POST['brochure_fee_under18']) ? sanitize_text_field(wp_unslash($_POST['brochure_fee_under18'])) : '';
                    $data['brochure_logo'] = isset($_POST['brochure_logo_url']) ? esc_url_raw(wp_unslash($_POST['brochure_logo_url'])) : '';
                    if (!empty($_FILES['brochure_logo_file']['tmp_name']) && is_uploaded_file($_FILES['brochure_logo_file']['tmp_name'])) {
                        $attachId = self::handle_photo_upload($tid, $_FILES['brochure_logo_file']);
                        if ($attachId > 0) {
                            $data['brochure_logo'] = wp_get_attachment_url($attachId);
                        }
                    }
                    $venuePhotos = [];
                    if (!empty($_POST['brochure_venue_photos_urls']) && is_array($_POST['brochure_venue_photos_urls'])) {
                        foreach ($_POST['brochure_venue_photos_urls'] as $url) {
                            $u = esc_url_raw(wp_unslash($url));
                            if ($u !== '') {
                                $venuePhotos[] = $u;
                            }
                        }
                    }
                    if (!empty($_FILES['brochure_venue_photos']['tmp_name']) && is_array($_FILES['brochure_venue_photos']['tmp_name'])) {
                        foreach ($_FILES['brochure_venue_photos']['tmp_name'] as $i => $tmpName) {
                            if (empty($tmpName) || !is_uploaded_file($tmpName)) {
                                continue;
                            }
                            $file = [
                                'name' => $_FILES['brochure_venue_photos']['name'][$i] ?? '',
                                'type' => $_FILES['brochure_venue_photos']['type'][$i] ?? '',
                                'tmp_name' => $tmpName,
                                'error' => $_FILES['brochure_venue_photos']['error'][$i] ?? 0,
                                'size' => $_FILES['brochure_venue_photos']['size'][$i] ?? 0,
                            ];
                            $attachId = self::handle_photo_upload($tid, $file);
                            if ($attachId > 0) {
                                $venuePhotos[] = wp_get_attachment_url($attachId);
                            }
                        }
                    }
                    $data['brochure_venue_photos'] = $venuePhotos;
                    if (!empty($_POST['brochure_prize']) && is_array($_POST['brochure_prize'])) {
                        foreach ($_POST['brochure_prize'] as $row) {
                            if (!empty($row['position']) || !empty($row['prize'])) {
                                $data['prize_table'][] = [
                                    'position' => sanitize_text_field($row['position'] ?? ''),
                                    'prize' => sanitize_text_field($row['prize'] ?? ''),
                                ];
                            }
                        }
                    }
                    if (!empty($_POST['brochure_contacts']) && is_array($_POST['brochure_contacts'])) {
                        foreach ($_POST['brochure_contacts'] as $row) {
                            if (!empty($row['name']) || !empty($row['email']) || !empty($row['phone'])) {
                                $data['contacts'][] = [
                                    'name' => sanitize_text_field($row['name'] ?? ''),
                                    'email' => sanitize_email($row['email'] ?? ''),
                                    'phone' => sanitize_text_field($row['phone'] ?? ''),
                                ];
                            }
                        }
                    }
                    ChessPodium_PdfBrochure::save_brochure($tid, $data);
                    $redirectArgs['cp_msg'] = 'brochure_saved';
                }
                break;

            case 'save_player_statuses':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'status_save_error';
                } else {
                    $roundNo = isset($_POST['status_round']) ? max(1, (int) $_POST['status_round']) : 0;
                    $statuses = isset($_POST['player_status']) && is_array($_POST['player_status']) ? $_POST['player_status'] : [];
                    if ($roundNo > 0 && !empty($statuses)) {
                        ChessPodium_PlayerStatus::save_bulk_statuses($tid, $roundNo, $statuses);
                        $redirectArgs['cp_msg'] = 'status_save_success';
                    }
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

            case 'add_pgn_source':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'pgn_source_error';
                } elseif (!empty($_POST['pgn_source_url'])) {
                    $url = esc_url_raw(wp_unslash($_POST['pgn_source_url']));
                    $round = isset($_POST['pgn_source_round']) ? max(1, (int) $_POST['pgn_source_round']) : 1;
                    $interval = isset($_POST['pgn_source_interval']) ? max(30, min(300, (int) $_POST['pgn_source_interval'])) : 60;
                    if ($url !== '') {
                        ChessPodium_PgnLive::add_source($tid, $url, $round, $interval);
                        $redirectArgs['cp_msg'] = 'pgn_source_added';
                    }
                }
                break;

            case 'delete_pgn_source':
                $srcId = isset($_POST['pgn_source_id']) ? (int) $_POST['pgn_source_id'] : 0;
                if ($srcId > 0) {
                    ChessPodium_PgnLive::delete_source($srcId);
                    $redirectArgs['cp_msg'] = 'pgn_source_deleted';
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

            case 'save_pairings':
                if ($tid <= 0) {
                    $redirectArgs['cp_msg'] = 'save_pairings_error';
                } elseif (!isset($_POST['pairings']) || !is_array($_POST['pairings'])) {
                    $redirectArgs['cp_msg'] = 'save_pairings_no_data';
                } else {
                    $editRound = isset($_POST['edit_round']) ? (int) $_POST['edit_round'] : 0;
                    $saved = self::save_pairings_for_round($tid, $editRound, $_POST['pairings']);
                    $redirectArgs['cp_msg'] = $saved ? 'save_pairings_success' : 'save_pairings_error';
                    if ($editRound > 0) {
                        $redirectArgs['edit_round'] = $editRound;
                        $redirectArgs['edit_pairings'] = 1;
                    }
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
        <div class="wrap cp-admin-wrap">
            <h1><?php echo esc_html__('Chess Podium', 'chess-podium'); ?></h1>
            <p class="cp-subtitle"><?php echo esc_html__('Create tournament, add players, generate rounds and enter results.', 'chess-podium'); ?></p>
            <?php if ($msg !== ''): ?>
                <?php self::render_admin_notice($msg, $importedCount); ?>
            <?php endif; ?>

            <div class="cp-create-tournament">
                <h2 style="margin-top:0;"><?php echo esc_html__('Create new tournament', 'chess-podium'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                    <input type="hidden" name="regina_action" value="create_tournament">
                    <table class="form-table">
                        <tr>
                            <th><label for="name"><?php echo esc_html__('Name', 'chess-podium'); ?></label></th>
                            <td><input id="name" name="name" type="text" required class="regular-text" placeholder="<?php echo esc_attr__('e.g. Campionato 2025', 'chess-podium'); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="rounds_total"><?php echo esc_html__('Rounds', 'chess-podium'); ?></label></th>
                            <td><input id="rounds_total" name="rounds_total" type="number" min="1" max="15" value="5" style="width:70px;"> <span class="description"><?php echo esc_html__('Swiss or Round Robin', 'chess-podium'); ?></span></td>
                        </tr>
                        <tr>
                            <th><label for="tournament_type"><?php echo esc_html__('Type', 'chess-podium'); ?></label></th>
                            <td>
                                <select id="tournament_type" name="tournament_type" style="width:140px;">
                                    <option value="swiss"><?php echo esc_html__('Swiss', 'chess-podium'); ?></option>
                                    <option value="round_robin"><?php echo esc_html__('Round Robin', 'chess-podium'); ?></option>
                                </select>
                                <select id="bye_points" name="bye_points" style="width:80px;">
                                    <option value="0">0</option>
                                    <option value="0.5">0.5</option>
                                    <option value="1" selected>1</option>
                                </select> <?php echo esc_html__('BYE pts', 'chess-podium'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="tiebreakers"><?php echo esc_html__('Tiebreakers', 'chess-podium'); ?></label></th>
                            <td><input id="tiebreakers" name="tiebreakers" type="text" value="buchholz,sb,rating" class="regular-text" style="max-width:280px;"></td>
                        </tr>
                    </table>
                    <p><button class="button button-primary" type="submit"><?php echo esc_html__('Create tournament', 'chess-podium'); ?></button></p>
                </form>
            </div>

            <?php if (!empty($tournaments)): ?>
            <p><strong><?php echo esc_html__('Select tournament', 'chess-podium'); ?>:</strong></p>
            <div class="cp-tournament-list">
                <?php foreach ($tournaments as $t): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tournament_id=' . (int) $t->id)); ?>" class="<?php echo $selectedTournamentId === (int) $t->id ? 'cp-current' : ''; ?>">
                        <?php echo esc_html($t->name); ?> (<?php echo (int) $t->current_round; ?>/<?php echo (int) $t->rounds_total; ?>)
                    </a>
                <?php endforeach; ?>
            </div>
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
        $exportTrfUrl = self::build_export_url($tid, 'trf');
        $photos = self::get_photos($tid);
        $pgns = self::get_pgns($tid);
        $publishUrl = ChessPodium_ExportEngine::get_export_url_base() . '/' . ChessPodium_ExportEngine::get_tournament_slug($tournament) . '/index.html';
        $activeTab = isset($_GET['cp_tab']) ? sanitize_key($_GET['cp_tab']) : 'overview';
        $validTabs = ['overview', 'players', 'rounds', 'settings', 'content', 'publish'];
        if (!in_array($activeTab, $validTabs, true)) {
            $activeTab = 'overview';
        }
        $baseUrl = admin_url('admin.php?page=' . self::MENU_SLUG . '&tournament_id=' . $tid);
        ?>
        <div class="cp-tournament-header">
            <form method="post" class="cp-inline-form" style="flex:1;min-width:200px;">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="update_tournament_name">
                <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                <input type="text" name="tournament_name" value="<?php echo esc_attr($tournament->name); ?>" class="regular-text" style="max-width:300px;">
                <button type="submit" class="button"><?php echo esc_html__('Rename', 'chess-podium'); ?></button>
            </form>
            <div class="cp-tournament-meta">
                <span><?php echo esc_html__('Round', 'chess-podium'); ?> <?php echo (int) $tournament->current_round; ?>/<?php echo (int) $tournament->rounds_total; ?></span>
                <span><?php echo (isset($tournament->tournament_type) && $tournament->tournament_type === 'round_robin') ? esc_html__('Round Robin', 'chess-podium') : esc_html__('Swiss', 'chess-podium'); ?></span>
                <span><?php echo count($players); ?> <?php echo esc_html__('players', 'chess-podium'); ?></span>
            </div>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Permanently delete this tournament?', 'chess-podium')); ?>');">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="delete_tournament">
                <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                <button type="submit" class="button button-link-delete"><?php echo esc_html__('Delete', 'chess-podium'); ?></button>
            </form>
        </div>

        <div class="cp-admin-tabs">
            <nav class="cp-tab-nav" role="tablist">
                <button type="button" class="cp-tab-btn <?php echo $activeTab === 'overview' ? 'cp-tab-active' : ''; ?>" data-tab="overview"><?php echo esc_html__('Overview', 'chess-podium'); ?></button>
                <button type="button" class="cp-tab-btn <?php echo $activeTab === 'players' ? 'cp-tab-active' : ''; ?>" data-tab="players"><?php echo esc_html__('Players', 'chess-podium'); ?></button>
                <button type="button" class="cp-tab-btn <?php echo $activeTab === 'rounds' ? 'cp-tab-active' : ''; ?>" data-tab="rounds"><?php echo esc_html__('Rounds', 'chess-podium'); ?></button>
                <button type="button" class="cp-tab-btn <?php echo $activeTab === 'settings' ? 'cp-tab-active' : ''; ?>" data-tab="settings"><?php echo esc_html__('Settings', 'chess-podium'); ?></button>
                <button type="button" class="cp-tab-btn <?php echo $activeTab === 'content' ? 'cp-tab-active' : ''; ?>" data-tab="content"><?php echo esc_html__('Content', 'chess-podium'); ?></button>
                <button type="button" class="cp-tab-btn <?php echo $activeTab === 'publish' ? 'cp-tab-active' : ''; ?>" data-tab="publish"><?php echo esc_html__('Publish', 'chess-podium'); ?></button>
            </nav>
            <div class="cp-tab-panels">

        <div class="cp-tab-panel <?php echo $activeTab === 'overview' ? 'cp-tab-active' : ''; ?>" data-panel="overview">
        <h3><?php echo esc_html__('Overview', 'chess-podium'); ?></h3>
        <p class="description"><a href="<?php echo esc_url(self::get_tournament_url()); ?>" target="_blank"><?php echo esc_html(self::get_tournament_url()); ?></a> &ndash; <?php echo esc_html__('Share with players for live standings.', 'chess-podium'); ?></p>
        <div class="cp-quick-actions">
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="generate_round">
                <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                <button class="button button-primary" type="submit" <?php disabled((int) $tournament->current_round >= (int) $tournament->rounds_total || count($players) < 2); ?>>
                    <?php echo esc_html__('Generate next round', 'chess-podium'); ?>
                </button>
            </form>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="generate_round_print">
                <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                <button class="button" type="submit" <?php disabled((int) $tournament->current_round >= (int) $tournament->rounds_total || count($players) < 2); ?>>
                    <?php echo esc_html__('Generate & print', 'chess-podium'); ?>
                </button>
            </form>
            <a class="button" href="<?php echo esc_url(add_query_arg('print_standings', 1, $baseUrl)); ?>"><?php echo esc_html__('Print standings', 'chess-podium'); ?></a>
            <?php if ((int) $tournament->current_round > 0): ?>
                <?php for ($r = 1; $r <= (int) $tournament->current_round; $r++): ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('print_round', $r, $baseUrl)); ?>"><?php echo esc_html(sprintf(__('Round %d', 'chess-podium'), $r)); ?></a>
                <?php endfor; ?>
            <?php endif; ?>
        </div>

        <?php
        $editRound = isset($_GET['edit_round']) ? (int) $_GET['edit_round'] : (int) $tournament->current_round;
        $editPairingsMode = isset($_GET['edit_pairings']) && (int) $_GET['edit_pairings'] === 1;
        $editRoundPairings = $editRound > 0 ? self::get_pairings_for_round((int) $tournament->id, $editRound) : [];
        ?>
        <?php if ((int) $tournament->current_round > 0 && !empty($editRoundPairings)): ?>
        <h3><?php echo esc_html__('Enter results', 'chess-podium'); ?></h3>
        <p>
            <?php for ($r = 1; $r <= (int) $tournament->current_round; $r++):
                $roundUrl = add_query_arg(['edit_round' => $r, 'cp_tab' => 'overview'], $baseUrl);
                if ($editPairingsMode) {
                    $roundUrl = add_query_arg('edit_pairings', 1, $roundUrl);
                } else {
                    $roundUrl = remove_query_arg('edit_pairings', $roundUrl);
                }
            ?>
                <a class="button button-small <?php echo $editRound === $r ? 'button-primary' : ''; ?>" href="<?php echo esc_url($roundUrl); ?>"><?php echo esc_html(sprintf(__('Round %d', 'chess-podium'), $r)); ?></a>
            <?php endfor; ?>
            <?php if ($editPairingsMode): ?>
                <a class="button button-primary" href="<?php echo esc_url(remove_query_arg('edit_pairings', add_query_arg(['edit_round' => $editRound, 'cp_tab' => 'overview'], $baseUrl))); ?>"><?php echo esc_html__('View only', 'chess-podium'); ?></a>
            <?php else: ?>
                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['edit_round' => $editRound, 'cp_tab' => 'overview', 'edit_pairings' => 1], $baseUrl)); ?>"><?php echo esc_html__('Edit pairings', 'chess-podium'); ?></a>
            <?php endif; ?>
        </p>
        <?php if ($editPairingsMode): ?>
        <form method="post" class="cp-edit-pairings-form">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="save_pairings">
            <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
            <input type="hidden" name="edit_round" value="<?php echo (int) $editRound; ?>">
            <p class="description"><?php echo esc_html__('Change White/Black players to match the tournament played. Each player must appear only once per round.', 'chess-podium'); ?></p>
            <table class="widefat striped" style="max-width:800px;">
                <thead><tr><th><?php echo esc_html__('Board', 'chess-podium'); ?></th><th><?php echo esc_html__('White', 'chess-podium'); ?></th><th><?php echo esc_html__('Black', 'chess-podium'); ?></th><th><?php echo esc_html__('Result', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($editRoundPairings as $tableNum => $pairing):
                    $whiteId = (int) $pairing->white_player_id;
                    $blackId = (int) $pairing->black_player_id;
                    $isBye = (int) $pairing->is_bye === 1;
                ?>
                    <tr>
                        <td><?php echo (int) ($tableNum + 1); ?></td>
                        <td>
                            <?php if ($isBye): ?>
                                <select name="pairings[<?php echo (int) $pairing->id; ?>][white]">
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?php echo (int) $p->id; ?>" <?php selected($whiteId, (int) $p->id); ?>><?php echo esc_html($p->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <select name="pairings[<?php echo (int) $pairing->id; ?>][white]">
                                    <option value="">—</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?php echo (int) $p->id; ?>" <?php selected($whiteId, (int) $p->id); ?>><?php echo esc_html($p->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isBye): ?>
                                <span class="description">BYE</span>
                                <input type="hidden" name="pairings[<?php echo (int) $pairing->id; ?>][black]" value="0">
                            <?php else: ?>
                                <select name="pairings[<?php echo (int) $pairing->id; ?>][black]">
                                    <option value="">—</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?php echo (int) $p->id; ?>" <?php selected($blackId, (int) $p->id); ?>><?php echo esc_html($p->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isBye): ?>BYE
                            <?php else:
                                $dispRes = self::normalize_result((string) ($pairing->result ?? ''));
                            ?>
                                <select name="pairings[<?php echo (int) $pairing->id; ?>][result]">
                                    <?php foreach (['', '1-0', '0-1', '1/2-1/2'] as $opt): ?>
                                        <option value="<?php echo esc_attr($opt); ?>" <?php selected($dispRes, $opt); ?>><?php echo $opt === '' ? esc_html__('—', 'chess-podium') : esc_html($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><button class="button button-primary" type="submit"><?php echo esc_html__('Save pairings', 'chess-podium'); ?></button></p>
        </form>
        <?php else: ?>
        <form method="post">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="save_results">
            <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
            <input type="hidden" name="edit_round" value="<?php echo (int) $editRound; ?>">
            <table class="widefat striped" style="max-width:700px;">
                <thead><tr><th><?php echo esc_html__('Board', 'chess-podium'); ?></th><th><?php echo esc_html__('White', 'chess-podium'); ?></th><th><?php echo esc_html__('Black', 'chess-podium'); ?></th><th><?php echo esc_html__('Result', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($editRoundPairings as $tableNum => $pairing): ?>
                    <tr>
                        <td><?php echo (int) ($tableNum + 1); ?></td>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($pairing->white_country ?? '', $pairing->white_country ?? ''); ?> <?php echo esc_html($pairing->white_name); ?></td>
                        <td><?php echo $pairing->is_bye ? '-' : (ChessPodium_CountryHelper::render_flag($pairing->black_country ?? '', $pairing->black_country ?? '') . ' ' . esc_html($pairing->black_name)); ?></td>
                        <td>
                            <?php if ((int) $pairing->is_bye === 1): ?>BYE
                            <?php else: ?>
                            <select name="results[<?php echo (int) $pairing->id; ?>]">
                                <?php
                                $dispRes = self::normalize_result((string) ($pairing->result ?? ''));
                                foreach (['', '1-0', '0-1', '1/2-1/2'] as $opt): ?>
                                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($dispRes, $opt); ?>><?php echo $opt === '' ? esc_html__('—', 'chess-podium') : esc_html($opt); ?></option>
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
        <?php endif; ?>

        <h3><?php echo esc_html__('Standings', 'chess-podium'); ?></h3>
        <?php if (empty($standings)): ?>
            <p><?php echo esc_html__('No standings yet.', 'chess-podium'); ?></p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:600px;">
                <thead><tr><th><?php echo esc_html__('Pos', 'chess-podium'); ?></th><th><?php echo esc_html__('Player', 'chess-podium'); ?></th><th><?php echo esc_html__('Pts', 'chess-podium'); ?></th><th><?php echo esc_html__('Buchholz', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($standings, 0, 15) as $pos => $s): ?>
                    <tr>
                        <td><?php echo (int) ($pos + 1); ?></td>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($s['country'] ?? '', $s['country'] ?? ''); ?> <?php echo esc_html($s['name']); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['points'], 1)); ?></td>
                        <td><?php echo esc_html(number_format((float) $s['buchholz'], 1)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($standings) > 15): ?><p class="description"><?php echo esc_html(sprintf(__('%d players total. See Players tab for full list.', 'chess-podium'), count($standings))); ?></p><?php endif; ?>
        <?php endif; ?>

        <h3><?php echo esc_html__('Fixed board assignments', 'chess-podium'); ?></h3>
        <p class="description"><?php echo esc_html__('Assign a fixed board to a player from a round (e.g. for wheelchair access). The pairing will place that player at the specified board.', 'chess-podium'); ?></p>
        <?php
        $fixedBoards = self::get_all_fixed_board_assignments((int) $tournament->id);
        $fixedBoardsByPlayer = [];
        foreach ($fixedBoards as $fb) {
            $fixedBoardsByPlayer[(int) $fb['player_id']] = true;
        }
        ?>
        <form method="post" class="cp-fixed-boards-form">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="save_fixed_boards">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <table class="widefat striped" style="max-width:600px;">
                <thead><tr><th><?php echo esc_html__('Player', 'chess-podium'); ?></th><th><?php echo esc_html__('Board', 'chess-podium'); ?></th><th><?php echo esc_html__('From round', 'chess-podium'); ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($fixedBoards as $fb): ?>
                    <tr>
                        <td><?php echo esc_html($fb['player_name'] ?? ''); ?></td>
                        <td><?php echo (int) $fb['board_number']; ?></td>
                        <td><?php echo (int) $fb['from_round']; ?></td>
                        <td><button type="submit" name="remove_fixed_board" value="<?php echo (int) $fb['player_id']; ?>" class="button button-small"><?php echo esc_html__('Remove', 'chess-podium'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td>
                        <select name="fixed_board_player_id">
                            <option value=""><?php echo esc_html__('— Select player —', 'chess-podium'); ?></option>
                            <?php foreach ($players as $p): ?>
                                <?php if (!isset($fixedBoardsByPlayer[(int) $p->id])): ?>
                                    <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" name="fixed_board_number" min="1" max="200" value="1" style="width:60px;"></td>
                    <td><input type="number" name="fixed_board_from_round" min="2" max="<?php echo (int) $tournament->rounds_total; ?>" value="2" style="width:60px;"></td>
                    <td><button type="submit" name="add_fixed_board" value="1" class="button"><?php echo esc_html__('Add', 'chess-podium'); ?></button></td>
                </tr>
                </tbody>
            </table>
        </form>
        </div>

        <div class="cp-tab-panel <?php echo $activeTab === 'settings' ? 'cp-tab-active' : ''; ?>" data-panel="settings">
        <h3><?php echo esc_html__('Settings', 'chess-podium'); ?></h3>
        <h4><?php echo esc_html__('Online registration', 'chess-podium'); ?></h4>
        <?php if (!ChessPodium_License::is_pro()): ?>
            <p class="notice notice-info inline"><?php echo esc_html__('Online registration with Stripe and PayPal is a Pro feature. Upgrade to Chess Podium Pro to enable it.', 'chess-podium'); ?></p>
        <?php else:
        $regEnabled = isset($tournament->registration_enabled) && (int) $tournament->registration_enabled === 1;
        $regFee = isset($tournament->registration_fee) ? (float) $tournament->registration_fee : 0.0;
        $regCurrency = isset($tournament->registration_currency) ? (string) $tournament->registration_currency : 'EUR';
        ?>
        <form method="post">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="save_registration_settings">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <p>
                <label><input type="checkbox" name="registration_enabled" value="1" <?php checked($regEnabled); ?>>
                    <?php esc_html_e('Enable online registration', 'chess-podium'); ?>
                </label>
            </p>
            <p>
                <label><?php esc_html_e('Registration fee', 'chess-podium'); ?></label><br>
                <input type="number" name="registration_fee" step="0.01" min="0" value="<?php echo esc_attr($regFee); ?>" style="width:100px;"> 
                <select name="registration_currency">
                    <option value="EUR" <?php selected($regCurrency, 'EUR'); ?>>EUR</option>
                    <option value="USD" <?php selected($regCurrency, 'USD'); ?>>USD</option>
                    <option value="GBP" <?php selected($regCurrency, 'GBP'); ?>>GBP</option>
                </select>
                <span class="description"><?php esc_html_e('Set fee to 0 for free registration (no Stripe/PayPal needed).', 'chess-podium'); ?></span>
            </p>
            <p><button type="submit" class="button"><?php esc_html_e('Save registration settings', 'chess-podium'); ?></button></p>
        </form>
        <?php if ($regEnabled): ?>
            <p class="description">
                <?php esc_html_e('Registration page shortcode:', 'chess-podium'); ?>
                <code>[chess_podium_registration id="<?php echo (int) $tournament->id; ?>"]</code><br>
                <?php esc_html_e('Create a page and add this shortcode. Configure Stripe and/or PayPal in Chess Podium → Registration payments.', 'chess-podium'); ?>
            </p>
        <?php endif; ?>
        <?php endif; ?>
        </div>

        <div class="cp-tab-panel <?php echo $activeTab === 'rounds' ? 'cp-tab-active' : ''; ?>" data-panel="rounds">
        <h3><?php echo esc_html__('Rounds', 'chess-podium'); ?></h3>
        <?php
        $nextRoundForStatus = (int) $tournament->current_round + 1;
        $canSetStatus = $nextRoundForStatus <= (int) $tournament->rounds_total && count($players) >= 2;
        if ($canSetStatus):
            $statusForRound = ChessPodium_PlayerStatus::get_all_for_round((int) $tournament->id, $nextRoundForStatus);
        ?>
        <h3><?php echo esc_html__('Bye & Withdrawals (next round)', 'chess-podium'); ?></h3>
        <p class="description"><?php echo esc_html__('Set player availability for round', 'chess-podium'); ?> <?php echo (int) $nextRoundForStatus; ?>. <?php echo esc_html__('Bye requested = 0.5 pts. Withdrawn = excluded from pairings.', 'chess-podium'); ?></p>
        <form method="post" class="cp-status-form">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="save_player_statuses">
            <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
            <input type="hidden" name="status_round" value="<?php echo (int) $nextRoundForStatus; ?>">
            <table class="widefat striped" style="max-width:600px;">
                <thead><tr><th><?php echo esc_html__('Player', 'chess-podium'); ?></th><th><?php echo esc_html__('Status for round', 'chess-podium'); ?> <?php echo (int) $nextRoundForStatus; ?></th></tr></thead>
                <tbody>
                <?php foreach ($players as $p): ?>
                    <?php
                    $currentStatus = $statusForRound[(int) $p->id] ?? ChessPodium_PlayerStatus::STATUS_PRESENT;
                    ?>
                    <tr>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($p->country ?? '', $p->country ?? ''); ?> <?php echo esc_html($p->name); ?></td>
                        <td>
                            <select name="player_status[<?php echo (int) $p->id; ?>]">
                                <option value="<?php echo esc_attr(ChessPodium_PlayerStatus::STATUS_PRESENT); ?>" <?php selected($currentStatus, ChessPodium_PlayerStatus::STATUS_PRESENT); ?>><?php echo esc_html__('Present', 'chess-podium'); ?></option>
                                <option value="<?php echo esc_attr(ChessPodium_PlayerStatus::STATUS_BYE_REQUESTED); ?>" <?php selected($currentStatus, ChessPodium_PlayerStatus::STATUS_BYE_REQUESTED); ?>><?php echo esc_html__('Bye 0.5 pts', 'chess-podium'); ?></option>
                                <option value="<?php echo esc_attr(ChessPodium_PlayerStatus::STATUS_WITHDRAWN); ?>" <?php selected($currentStatus, ChessPodium_PlayerStatus::STATUS_WITHDRAWN); ?>><?php echo esc_html__('Withdrawn', 'chess-podium'); ?></option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:8px;"><button class="button" type="submit"><?php echo esc_html__('Save statuses', 'chess-podium'); ?></button></p>
        </form>
        <?php endif; ?>

        <div class="cp-card">
            <h4><?php echo esc_html__('Generate & rollback', 'chess-podium'); ?></h4>
            <p class="description"><?php echo esc_html__('Generate pairings and print from Overview tab.', 'chess-podium'); ?></p>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                <input type="hidden" name="regina_action" value="rollback_round">
                <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
                <button class="button button-secondary" type="submit"
                        onclick="return confirm('<?php echo esc_js(__('Cancel current round and remove all pairings/results for this round?', 'chess-podium')); ?>');"
                    <?php disabled((int) $tournament->current_round <= 0); ?>>
                    <?php echo esc_html__('Rollback current round', 'chess-podium'); ?>
                </button>
            </form>
        </div>
        </div>

        <div class="cp-tab-panel <?php echo $activeTab === 'content' ? 'cp-tab-active' : ''; ?>" data-panel="content">
        <h3><?php echo esc_html__('Content', 'chess-podium'); ?></h3>
        <h4><?php echo esc_html__('Tournament brochure (Bando)', 'chess-podium'); ?></h4>
        <?php
        $brochureMeta = ChessPodium_PdfBrochure::get_all_meta($tid);
        $brochurePdfUrl = self::build_export_url($tid, 'brochure_pdf');
        $brochureHtmlUrl = self::build_export_url($tid, 'brochure_html');
        ?>
        <form method="post" class="cp-brochure-form" enctype="multipart/form-data">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="save_brochure">
            <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
            <table class="form-table">
                <tr>
                    <th><label for="brochure_tournament_description"><?php esc_html_e('Tournament description and highlights', 'chess-podium'); ?></label></th>
                    <td><textarea id="brochure_tournament_description" name="brochure_tournament_description" rows="5" class="large-text"><?php echo esc_textarea($brochureMeta['tournament_description'] ?? ''); ?></textarea>
                    <p class="description"><?php esc_html_e('Describe the tournament and its main attractions.', 'chess-podium'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="brochure_tournament_rules"><?php esc_html_e('Tournament rules', 'chess-podium'); ?></label></th>
                    <td><textarea id="brochure_tournament_rules" name="brochure_tournament_rules" rows="6" class="large-text"><?php echo esc_textarea($brochureMeta['tournament_rules'] ?? ''); ?></textarea>
                    <p class="description"><?php esc_html_e('Rules and regulations for the tournament.', 'chess-podium'); ?></p></td>
                </tr>
                <tr>
                    <th><label for="brochure_logo_url"><?php esc_html_e('Tournament logo', 'chess-podium'); ?></label></th>
                    <td>
                        <input id="brochure_logo_url" name="brochure_logo_url" type="url" class="large-text" placeholder="https://..." value="<?php echo esc_attr($brochureMeta['brochure_logo'] ?? ''); ?>">
                        <p class="description"><?php esc_html_e('URL or upload below. Shown next to the tournament title.', 'chess-podium'); ?></p>
                        <p><input type="file" name="brochure_logo_file" accept="image/*"> <?php esc_html_e('or upload new image', 'chess-podium'); ?></p>
                        <?php if (!empty($brochureMeta['brochure_logo'])): ?>
                        <p><img src="<?php echo esc_url($brochureMeta['brochure_logo']); ?>" alt="" style="max-width:120px;max-height:80px;object-fit:contain;border:1px solid #ccc;"></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="brochure_venue_name"><?php esc_html_e('Venue name', 'chess-podium'); ?></label></th>
                    <td><input id="brochure_venue_name" name="brochure_venue_name" type="text" class="regular-text" value="<?php echo esc_attr($brochureMeta['venue_name'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="brochure_venue_address"><?php esc_html_e('Venue address', 'chess-podium'); ?></label></th>
                    <td><textarea id="brochure_venue_address" name="brochure_venue_address" rows="3" class="large-text"><?php echo esc_textarea($brochureMeta['venue_address'] ?? ''); ?></textarea></td>
                </tr>
                <tr>
                    <th><label for="brochure_venue_map_url"><?php esc_html_e('Map URL (embed)', 'chess-podium'); ?></label></th>
                    <td><input id="brochure_venue_map_url" name="brochure_venue_map_url" type="url" class="large-text" placeholder="https://www.openstreetmap.org/..." value="<?php echo esc_attr($brochureMeta['venue_map_url'] ?? ''); ?>">
                    <p class="description"><?php esc_html_e('OpenStreetMap or Google Maps embed URL.', 'chess-podium'); ?></p></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Venue photos', 'chess-podium'); ?></th>
                    <td>
                        <div id="cp-brochure-venue-photos">
                            <?php
                            $venuePhotos = isset($brochureMeta['brochure_venue_photos']) ? json_decode($brochureMeta['brochure_venue_photos'], true) : [];
                            if (!is_array($venuePhotos)) {
                                $venuePhotos = [];
                            }
                            foreach ($venuePhotos as $url):
                                if (empty($url) || !is_string($url)) {
                                    continue;
                                }
                            ?>
                            <p class="cp-venue-photo-row" style="display:flex;align-items:center;gap:8px;margin:4px 0;">
                                <img src="<?php echo esc_url($url); ?>" alt="" style="max-width:80px;max-height:60px;object-fit:cover;">
                                <input type="hidden" name="brochure_venue_photos_urls[]" value="<?php echo esc_attr($url); ?>">
                                <button type="button" class="button button-small cp-remove-venue-photo"><?php esc_html_e('Remove', 'chess-podium'); ?></button>
                            </p>
                            <?php endforeach; ?>
                        </div>
                        <p><input type="file" name="brochure_venue_photos[]" accept="image/*" multiple> <?php esc_html_e('Add venue photos', 'chess-podium'); ?></p>
                        <p class="description"><?php esc_html_e('Photos of the playing venue for the brochure.', 'chess-podium'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="brochure_start_date"><?php esc_html_e('Start date', 'chess-podium'); ?></label></th>
                    <td><input id="brochure_start_date" name="brochure_start_date" type="date" value="<?php echo esc_attr($brochureMeta['start_date'] ?? ''); ?>">
                    <p class="description"><?php esc_html_e('Optional. Used as fallback if round dates are not set manually.', 'chess-podium'); ?></p></td>
                </tr>
            </table>
            <?php
            $roundsTotal = (int) $tournament->rounds_total;
            $roundDatesMeta = isset($brochureMeta['round_dates']) ? json_decode($brochureMeta['round_dates'], true) : [];
            $roundTimesMeta = isset($brochureMeta['round_times']) ? json_decode($brochureMeta['round_times'], true) : [];
            if (!is_array($roundDatesMeta)) {
                $roundDatesMeta = [];
            }
            if (!is_array($roundTimesMeta)) {
                $roundTimesMeta = [];
            }
            if ($roundsTotal > 0):
            ?>
            <h4><?php esc_html_e('Round dates and start times (manual)', 'chess-podium'); ?></h4>
            <p class="description"><?php esc_html_e('Enter the date and start time for each round. Each tournament may have different schedules.', 'chess-podium'); ?></p>
            <table class="form-table" style="max-width:500px;">
                <thead><tr><th><?php esc_html_e('Round', 'chess-podium'); ?></th><th><?php esc_html_e('Date', 'chess-podium'); ?></th><th><?php esc_html_e('Start time', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php for ($r = 1; $r <= $roundsTotal; $r++): ?>
                <tr>
                    <td><?php echo esc_html(sprintf(__('Round %d', 'chess-podium'), $r)); ?></td>
                    <td><input id="brochure_round_date_<?php echo $r; ?>" name="brochure_round_date[<?php echo $r; ?>]" type="date" value="<?php echo esc_attr($roundDatesMeta[$r] ?? ''); ?>"></td>
                    <td><input id="brochure_round_time_<?php echo $r; ?>" name="brochure_round_time[<?php echo $r; ?>]" type="time" value="<?php echo esc_attr($roundTimesMeta[$r] ?? ''); ?>" placeholder="20:00"></td>
                </tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <h4><?php esc_html_e('Time control', 'chess-podium'); ?></h4>
            <table class="form-table">
                <tr>
                    <th><label for="brochure_time_control"><?php esc_html_e('Reflection time / Time control', 'chess-podium'); ?></label></th>
                    <td><input id="brochure_time_control" name="brochure_time_control" type="text" class="regular-text" placeholder="<?php esc_attr_e('e.g. 90+30, 60 min + 30 sec/move', 'chess-podium'); ?>" value="<?php echo esc_attr($brochureMeta['time_control'] ?? ''); ?>"></td>
                </tr>
            </table>
            <h4><?php esc_html_e('Registration fees', 'chess-podium'); ?></h4>
            <table class="form-table">
                <tr>
                    <th><label for="brochure_fee_titled"><?php esc_html_e('Titled players', 'chess-podium'); ?></label></th>
                    <td><input id="brochure_fee_titled" name="brochure_fee_titled" type="text" class="regular-text" placeholder="<?php esc_attr_e('e.g. €15', 'chess-podium'); ?>" value="<?php echo esc_attr($brochureMeta['fee_titled'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="brochure_fee_normal"><?php esc_html_e('Standard fee', 'chess-podium'); ?></label></th>
                    <td><input id="brochure_fee_normal" name="brochure_fee_normal" type="text" class="regular-text" placeholder="<?php esc_attr_e('e.g. €20', 'chess-podium'); ?>" value="<?php echo esc_attr($brochureMeta['fee_normal'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="brochure_fee_under18"><?php esc_html_e('Under 18', 'chess-podium'); ?></label></th>
                    <td><input id="brochure_fee_under18" name="brochure_fee_under18" type="text" class="regular-text" placeholder="<?php esc_attr_e('e.g. €10', 'chess-podium'); ?>" value="<?php echo esc_attr($brochureMeta['fee_under18'] ?? ''); ?>"></td>
                </tr>
            </table>
            <h4><?php esc_html_e('Prize table', 'chess-podium'); ?></h4>
            <div id="cp-brochure-prizes">
                <?php
                $prizes = isset($brochureMeta['prize_table']) ? json_decode($brochureMeta['prize_table'], true) : [];
                if (empty($prizes)) {
                    $prizes = [['position' => '1°', 'prize' => ''], ['position' => '2°', 'prize' => ''], ['position' => '3°', 'prize' => '']];
                }
                foreach ($prizes as $i => $p):
                ?>
                <p><input type="text" name="brochure_prize[<?php echo $i; ?>][position]" placeholder="<?php esc_attr_e('Position', 'chess-podium'); ?>" value="<?php echo esc_attr($p['position'] ?? ''); ?>" style="width:80px;">
                <input type="text" name="brochure_prize[<?php echo $i; ?>][prize]" placeholder="<?php esc_attr_e('Prize', 'chess-podium'); ?>" value="<?php echo esc_attr($p['prize'] ?? ''); ?>" style="width:200px;"></p>
                <?php endforeach; ?>
                <p><button type="button" class="button button-small cp-add-prize"><?php esc_html_e('Add row', 'chess-podium'); ?></button></p>
            </div>
            <h4><?php esc_html_e('Contacts', 'chess-podium'); ?></h4>
            <div id="cp-brochure-contacts">
                <?php
                $contacts = isset($brochureMeta['contacts']) ? json_decode($brochureMeta['contacts'], true) : [];
                if (empty($contacts)) {
                    $contacts = [['name' => '', 'email' => '', 'phone' => '']];
                }
                foreach ($contacts as $i => $c):
                ?>
                <p><input type="text" name="brochure_contacts[<?php echo $i; ?>][name]" placeholder="<?php esc_attr_e('Name', 'chess-podium'); ?>" value="<?php echo esc_attr($c['name'] ?? ''); ?>" style="width:150px;">
                <input type="email" name="brochure_contacts[<?php echo $i; ?>][email]" placeholder="<?php esc_attr_e('Email', 'chess-podium'); ?>" value="<?php echo esc_attr($c['email'] ?? ''); ?>" style="width:180px;">
                <input type="text" name="brochure_contacts[<?php echo $i; ?>][phone]" placeholder="<?php esc_attr_e('Phone', 'chess-podium'); ?>" value="<?php echo esc_attr($c['phone'] ?? ''); ?>" style="width:120px;"></p>
                <?php endforeach; ?>
                <p><button type="button" class="button button-small cp-add-contact"><?php esc_html_e('Add contact', 'chess-podium'); ?></button></p>
            </div>
            <p><button class="button button-primary" type="submit"><?php esc_html_e('Save brochure', 'chess-podium'); ?></button></p>
        </form>
        <p>
            <a class="button" href="<?php echo esc_url($brochureHtmlUrl); ?>"><?php esc_html_e('Download HTML', 'chess-podium'); ?></a>
            <a class="button" href="<?php echo esc_url($brochurePdfUrl); ?>"><?php esc_html_e('Download PDF', 'chess-podium'); ?></a>
        </p>
        <script>
        (function(){
            var pi=document.querySelectorAll('#cp-brochure-prizes p').length-1, ci=document.querySelectorAll('#cp-brochure-contacts p').length-1;
            document.querySelectorAll('.cp-remove-venue-photo').forEach(function(btn){
                btn.addEventListener('click',function(){ this.closest('.cp-venue-photo-row').remove(); });
            });
            document.querySelector('.cp-add-prize')&&document.querySelector('.cp-add-prize').addEventListener('click',function(){
                pi++; var c=document.getElementById('cp-brochure-prizes'); var p=document.createElement('p');
                p.innerHTML='<input type="text" name="brochure_prize['+pi+'][position]" placeholder="<?php echo esc_js(__('Position', 'chess-podium')); ?>" style="width:80px;"> <input type="text" name="brochure_prize['+pi+'][prize]" placeholder="<?php echo esc_js(__('Prize', 'chess-podium')); ?>" style="width:200px;">';
                c.insertBefore(p,c.lastElementChild);
            });
            document.querySelector('.cp-add-contact')&&document.querySelector('.cp-add-contact').addEventListener('click',function(){
                ci++; var c=document.getElementById('cp-brochure-contacts'); var p=document.createElement('p');
                p.innerHTML='<input type="text" name="brochure_contacts['+ci+'][name]" placeholder="<?php echo esc_js(__('Name', 'chess-podium')); ?>" style="width:150px;"> <input type="email" name="brochure_contacts['+ci+'][email]" placeholder="<?php echo esc_js(__('Email', 'chess-podium')); ?>" style="width:180px;"> <input type="text" name="brochure_contacts['+ci+'][phone]" placeholder="<?php echo esc_js(__('Phone', 'chess-podium')); ?>" style="width:120px;">';
                c.insertBefore(p,c.lastElementChild);
            });
        })();
        </script>

        <h3><?php echo esc_html__('Live mode (projection)', 'chess-podium'); ?></h3>
        <?php
        $liveUrl = self::get_live_url($tid);
        ?>
        <p><a class="button" href="<?php echo esc_url($liveUrl); ?>" target="_blank"><?php esc_html_e('Open Live Dashboard', 'chess-podium'); ?></a></p>
        <p class="description"><?php esc_html_e('Full-screen page for projection in the playing hall. Auto-refreshes every 10 seconds when results are updated.', 'chess-podium'); ?></p>

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

        <h4><?php echo esc_html__('PGN Live (URL)', 'chess-podium'); ?></h4>
        <p class="description"><?php echo esc_html__('Add a URL that returns PGN content. Fetched automatically every minute. Useful for DGT boards or external feeds.', 'chess-podium'); ?></p>
        <form method="post">
            <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
            <input type="hidden" name="regina_action" value="add_pgn_source">
            <input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
            <p>
                <input type="url" name="pgn_source_url" placeholder="https://..." class="regular-text" style="min-width:300px;" required>
                <label><?php echo esc_html__('Round', 'chess-podium'); ?>: <input type="number" name="pgn_source_round" min="1" value="1" style="width:60px;"></label>
                <label><?php echo esc_html__('Interval (sec)', 'chess-podium'); ?>: <input type="number" name="pgn_source_interval" min="30" max="300" value="60" style="width:70px;"></label>
                <button class="button" type="submit"><?php echo esc_html__('Add source', 'chess-podium'); ?></button>
            </p>
        </form>
        <?php
        $pgnSources = ChessPodium_PgnLive::get_sources_for_tournament($tid);
        if (!empty($pgnSources)):
        ?>
        <ul>
            <?php foreach ($pgnSources as $src): ?>
            <li>
                <code><?php echo esc_html($src->source_url); ?></code> (<?php echo esc_html(sprintf(__('Round %d', 'chess-podium'), (int) $src->round_no)); ?>)
                <?php if ($src->last_fetched): ?><span class="description"><?php echo esc_html(sprintf(__('Last fetch: %s', 'chess-podium'), $src->last_fetched)); ?></span><?php endif; ?>
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('regina_torneo_action', 'regina_nonce'); ?>
                    <input type="hidden" name="regina_action" value="delete_pgn_source">
                    <input type="hidden" name="pgn_source_id" value="<?php echo (int) $src->id; ?>">
                    <button class="button button-small button-link-delete" type="submit"><?php echo esc_html__('Remove', 'chess-podium'); ?></button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        </div>

        <div class="cp-tab-panel <?php echo $activeTab === 'publish' ? 'cp-tab-active' : ''; ?>" data-panel="publish">
        <h3><?php echo esc_html__('Publish & export', 'chess-podium'); ?></h3>
        <h4><?php echo esc_html__('Publish tournament (static export)', 'chess-podium'); ?></h4>
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

        <h4><?php echo esc_html__('Export tournament data', 'chess-podium'); ?></h4>
        <p>
            <a class="button" href="<?php echo esc_url($exportTurnsUrl); ?>"><?php echo esc_html__('Export rounds (CSV)', 'chess-podium'); ?></a>
            <a class="button" href="<?php echo esc_url($exportStandingsUrl); ?>"><?php echo esc_html__('Export standings (CSV)', 'chess-podium'); ?></a>
            <a class="button" href="<?php echo esc_url($exportTrfUrl); ?>"><?php echo esc_html__('Export FIDE TRF', 'chess-podium'); ?></a>
        </p>
        <p class="description"><?php echo esc_html__('Use these files to share tournament data and debug pairing. TRF format for FIDE rating submission.', 'chess-podium'); ?></p>
        </div>

        <div class="cp-tab-panel <?php echo $activeTab === 'players' ? 'cp-tab-active' : ''; ?>" data-panel="players">
        <h3><?php echo esc_html__('Players', 'chess-podium'); ?></h3>
        <h4><?php echo esc_html__('Add player', 'chess-podium'); ?></h4><?php
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
                <tr>
                    <th><label for="player_title"><?php echo esc_html__('FIDE title', 'chess-podium'); ?></label></th>
                    <td>
                        <select id="player_title" name="player_title">
                            <option value=""><?php echo esc_html__('— None —', 'chess-podium'); ?></option>
                            <option value="GM">GM</option>
                            <option value="IM">IM</option>
                            <option value="FM">FM</option>
                            <option value="CM">CM</option>
                            <option value="WGM">WGM</option>
                            <option value="WIM">WIM</option>
                            <option value="WFM">WFM</option>
                            <option value="WCM">WCM</option>
                        </select>
                        <p class="description"><?php echo esc_html__('Optional. Shown before player name in tables.', 'chess-podium'); ?></p>
                    </td>
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
            <p class="description"><?php echo esc_html__('Format: name;rating;fide_id;country;title. First row: optional header. Country: ISO 2-letter. Title: GM, IM, FM, WGM, etc.', 'chess-podium'); ?></p>
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
                                <select name="player_title" style="width:70px;">
                                    <option value="">—</option>
                                    <option value="GM" <?php selected(($p->title ?? ''), 'GM'); ?>>GM</option>
                                    <option value="IM" <?php selected(($p->title ?? ''), 'IM'); ?>>IM</option>
                                    <option value="FM" <?php selected(($p->title ?? ''), 'FM'); ?>>FM</option>
                                    <option value="CM" <?php selected(($p->title ?? ''), 'CM'); ?>>CM</option>
                                    <option value="WGM" <?php selected(($p->title ?? ''), 'WGM'); ?>>WGM</option>
                                    <option value="WIM" <?php selected(($p->title ?? ''), 'WIM'); ?>>WIM</option>
                                    <option value="WFM" <?php selected(($p->title ?? ''), 'WFM'); ?>>WFM</option>
                                    <option value="WCM" <?php selected(($p->title ?? ''), 'WCM'); ?>>WCM</option>
                                </select>
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
        </div>

            </div><!-- .cp-tab-panels -->
        </div><!-- .cp-admin-tabs -->
        <script>
        (function(){
            var c=document.querySelector('.cp-admin-tabs');
            if(!c)return;
            var btns=c.querySelectorAll('.cp-tab-btn');
            var panels=c.querySelectorAll('.cp-tab-panel');
            btns.forEach(function(b){
                b.addEventListener('click',function(){
                    var t=this.getAttribute('data-tab');
                    btns.forEach(function(x){x.classList.remove('cp-tab-active');});
                    panels.forEach(function(p){
                        p.classList.toggle('cp-tab-active',p.getAttribute('data-panel')===t);
                    });
                    this.classList.add('cp-tab-active');
                    if(window.history&&window.history.replaceState){
                        var u=new URL(window.location.href);
                        u.searchParams.set('cp_tab',t);
                        window.history.replaceState({},'',u.toString());
                    }
                });
            });
        })();
        </script>
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
        $atts = shortcode_atts(['id' => 0, 'round' => 0], $atts, 'regina_torneo');
        $tid = (int) $atts['id'];
        $roundFromUrl = isset($_GET['round']) ? max(1, (int) $_GET['round']) : 0;
        $roundOverride = (int) $atts['round'] ?: $roundFromUrl;
        $tournament = $tid > 0 ? self::get_tournament($tid) : self::get_latest_tournament();
        if (!$tournament) {
            return '<p>Nessun torneo disponibile.</p>';
        }
        return self::render_public_block($tournament, $roundOverride > 0 ? $roundOverride : 0);
    }

    public static function render_brochure_shortcode(array $atts = []): string
    {
        $atts = shortcode_atts(['id' => 0], $atts, 'chess_podium_brochure');
        $tid = (int) $atts['id'];
        $tournament = $tid > 0 ? self::get_tournament($tid) : self::get_latest_tournament();
        if (!$tournament) {
            return '<p>' . esc_html__('No tournament available.', 'chess-podium') . '</p>';
        }
        return ChessPodium_PdfBrochure::generate_html($tournament);
    }

    public static function render_club_ranking_shortcode(array $atts = []): string
    {
        $standings = ChessPodium_ClubRanking::get_club_standings();
        if (empty($standings)) {
            return '<div class="cp-club-ranking"><p>' . esc_html__('No club ranking data. Publish tournaments to build the ranking.', 'chess-podium') . '</p></div>';
        }
        ob_start();
        ?>
        <div class="cp-club-ranking">
            <h2><?php esc_html_e('Club ranking', 'chess-podium'); ?></h2>
            <table class="cp-club-table">
                <thead><tr><th><?php esc_html_e('Pos', 'chess-podium'); ?></th><th><?php esc_html_e('Player', 'chess-podium'); ?></th><th><?php esc_html_e('Tournaments', 'chess-podium'); ?></th><th><?php esc_html_e('Total points', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($standings as $pos => $s): ?>
                    <tr>
                        <td><?php echo (int) ($pos + 1); ?></td>
                        <td><?php echo ChessPodium_CountryHelper::render_flag($s['country'] ?? '', $s['country'] ?? ''); ?> <?php echo esc_html($s['name']); ?></td>
                        <td><?php echo (int) $s['tournaments']; ?></td>
                        <td><?php echo esc_html(number_format((float) $s['total_points'], 1)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_player_profile_shortcode(array $atts = []): string
    {
        $atts = shortcode_atts(['fide_id' => '', 'name' => ''], $atts, 'chess_podium_player_profile');
        $identifier = trim((string) $atts['fide_id']) ?: trim((string) $atts['name']);
        if ($identifier === '') {
            return '<p>' . esc_html__('Specify fide_id or name attribute.', 'chess-podium') . '</p>';
        }
        $profile = ChessPodium_ClubRanking::get_player_profile($identifier);
        if (!$profile) {
            return '<p>' . esc_html__('Player not found.', 'chess-podium') . '</p>';
        }
        ob_start();
        ?>
        <div class="cp-player-profile">
            <h2><?php echo esc_html($profile['name']); ?></h2>
            <?php if (!empty($profile['fide_id'])): ?><p><?php esc_html_e('FIDE ID', 'chess-podium'); ?>: <?php echo esc_html($profile['fide_id']); ?></p><?php endif; ?>
            <p><?php echo esc_html(sprintf(__('%d tournaments', 'chess-podium'), $profile['total_tournaments'])); ?> &ndash; <?php echo esc_html(number_format($profile['total_points'], 1)); ?> <?php esc_html_e('points', 'chess-podium'); ?></p>
            <h3><?php esc_html_e('Tournament history', 'chess-podium'); ?></h3>
            <table class="cp-profile-table">
                <thead><tr><th><?php esc_html_e('Tournament', 'chess-podium'); ?></th><th><?php esc_html_e('Position', 'chess-podium'); ?></th><th><?php esc_html_e('Points', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($profile['history'] as $h): ?>
                    <tr>
                        <td><?php echo esc_html($h['tournament_name']); ?></td>
                        <td><?php echo (int) $h['position']; ?>°</td>
                        <td><?php echo esc_html(number_format((float) $h['points'], 1)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function compute_standings_before_round(int $tournamentId, int $roundNo): array
    {
        $tournament = self::get_tournament($tournamentId);
        $byePoints = ($tournament && isset($tournament->bye_points)) ? (float) $tournament->bye_points : 1.0;
        $players = self::get_players($tournamentId);
        $pairings = self::get_all_pairings($tournamentId);
        $pairings = array_filter($pairings, static function ($m) use ($roundNo) {
            return (int) $m->round_no < $roundNo;
        });

        $scores = [];
        $opponents = [];
        foreach ($players as $p) {
            $scores[(int) $p->id] = 0.0;
            $opponents[(int) $p->id] = [];
        }
        foreach ($pairings as $match) {
            $w = (int) $match->white_player_id;
            $b = (int) $match->black_player_id;
            $res = self::normalize_result((string) ($match->result ?? ''));
            $isBye = (int) $match->is_bye === 1;
            if ($isBye) {
                $pts = isset($match->bye_points) && $match->bye_points !== null ? (float) $match->bye_points : $byePoints;
                $scores[$w] = ($scores[$w] ?? 0) + $pts;
                continue;
            }
            $opponents[$w][] = $b;
            $opponents[$b][] = $w;
            if ($res === '1-0') {
                $scores[$w] = ($scores[$w] ?? 0) + 1.0;
            } elseif ($res === '0-1') {
                $scores[$b] = ($scores[$b] ?? 0) + 1.0;
            } elseif ($res === '1/2-1/2') {
                $scores[$w] = ($scores[$w] ?? 0) + 0.5;
                $scores[$b] = ($scores[$b] ?? 0) + 0.5;
            }
        }
        return $scores;
    }

    private static function get_starting_ranks(int $tournamentId): array
    {
        $players = self::get_players($tournamentId);
        usort($players, static function ($a, $b) {
            $r = ((int) $b->rating <=> (int) $a->rating);
            return $r !== 0 ? $r : strcasecmp((string) $a->name, (string) $b->name);
        });
        $ranks = [];
        foreach ($players as $i => $p) {
            $ranks[(int) $p->id] = $i + 1;
        }
        return $ranks;
    }

    private static function format_player_display(string $name, ?string $title, ?string $country, bool $useImg = true): string
    {
        $flag = $country ? ($useImg ? ChessPodium_CountryHelper::render_flag_img($country, $country) : ChessPodium_CountryHelper::render_flag($country, $country)) . ' ' : '';
        $titleStr = $title ? esc_html(trim($title)) . ' ' : '';
        return $flag . $titleStr . esc_html($name);
    }

    private static function render_public_block(object $tournament, int $roundOverride = 0): string
    {
        $tid = (int) $tournament->id;
        $displayRound = $roundOverride > 0 ? $roundOverride : (int) $tournament->current_round;
        $standings = self::compute_standings($tid);
        $pairings = self::get_pairings_for_round($tid, $displayRound);
        $pointsBeforeRound = $displayRound > 0 ? self::compute_standings_before_round($tid, $displayRound) : [];
        $startingRanks = self::get_starting_ranks($tid);

        ob_start();
        ?>
        <h1><?php echo esc_html($tournament->name); ?></h1>
        <p><strong><?php echo esc_html__('Current round', 'chess-podium'); ?>:</strong> <?php echo (int) $displayRound; ?> / <?php echo (int) $tournament->rounds_total; ?></p>

        <h2><?php echo $roundOverride > 0 ? esc_html(sprintf(__('Round %d pairings', 'chess-podium'), (int) $displayRound)) : esc_html__('Current round pairings', 'chess-podium'); ?></h2>
        <?php if (empty($pairings)): ?>
            <p><?php echo esc_html__('No pairings generated yet.', 'chess-podium'); ?></p>
        <?php else: ?>
            <table>
                <thead><tr><th><?php echo esc_html__('Board', 'chess-podium'); ?></th><th><?php echo esc_html__('White', 'chess-podium'); ?></th><th><?php echo esc_html__('Pts', 'chess-podium'); ?></th><th><?php echo esc_html__('Nr', 'chess-podium'); ?></th><th><?php echo esc_html__('Result', 'chess-podium'); ?></th><th><?php echo esc_html__('Nr', 'chess-podium'); ?></th><th><?php echo esc_html__('Pts', 'chess-podium'); ?></th><th><?php echo esc_html__('Black', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($pairings as $i => $p):
                    $whiteId = (int) $p->white_player_id;
                    $blackId = (int) $p->black_player_id;
                    $whitePts = $pointsBeforeRound[$whiteId] ?? 0;
                    $blackPts = (int) $p->is_bye === 1 ? null : ($pointsBeforeRound[$blackId] ?? 0);
                    $whiteRank = $startingRanks[$whiteId] ?? '-';
                    $blackRank = (int) $p->is_bye === 1 ? null : ($startingRanks[$blackId] ?? '-');
                ?>
                    <tr>
                        <td><?php echo (int) ($i + 1); ?></td>
                        <td><?php echo self::format_player_display($p->white_name ?? '', $p->white_title ?? null, $p->white_country ?? null, true); ?></td>
                        <td><?php echo $whitePts !== null ? '(' . number_format((float) $whitePts, 1) . ')' : '-'; ?></td>
                        <td><?php echo esc_html((string) $whiteRank); ?></td>
                        <td><?php echo esc_html($p->result ?: ((int) $p->is_bye === 1 ? 'BYE' : __('in progress', 'chess-podium'))); ?></td>
                        <td><?php echo $blackRank !== null ? esc_html((string) $blackRank) : '-'; ?></td>
                        <td><?php echo $blackPts !== null ? '(' . number_format((float) $blackPts, 1) . ')' : '-'; ?></td>
                        <td><?php echo (int) $p->is_bye === 1 ? '-' : self::format_player_display($p->black_name ?? '', $p->black_title ?? null, $p->black_country ?? null, true); ?></td>
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
                <thead><tr><th><?php echo esc_html__('Pos', 'chess-podium'); ?></th><th><?php echo esc_html__('Nr', 'chess-podium'); ?></th><th><?php echo esc_html__('Player', 'chess-podium'); ?></th><th><?php echo esc_html__('Pts', 'chess-podium'); ?></th><th><?php echo esc_html__('Buchholz', 'chess-podium'); ?></th><th><?php echo esc_html__('SB', 'chess-podium'); ?></th></tr></thead>
                <tbody>
                <?php
                $standingsWithRank = [];
                foreach ($standings as $pos => $s) {
                    $pid = (int) $s['player_id'];
                    $standingsWithRank[] = array_merge($s, ['pos' => $pos + 1, 'start_rank' => $startingRanks[$pid] ?? '-']);
                }
                foreach ($standingsWithRank as $s):
                ?>
                    <tr>
                        <td><?php echo (int) $s['pos']; ?></td>
                        <td><?php echo esc_html((string) $s['start_rank']); ?></td>
                        <td><?php echo self::format_player_display($s['name'] ?? '', $s['title'] ?? null, $s['country'] ?? null, true); ?></td>
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
        $fixedBoardsTable = self::db_table('regina_fixed_boards');
        if ($wpdb->get_var("SHOW TABLES LIKE '$fixedBoardsTable'") !== $fixedBoardsTable) { // phpcs:ignore
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $fixedBoardsTable (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tournament_id BIGINT UNSIGNED NOT NULL,
                player_id BIGINT UNSIGNED NOT NULL,
                board_number INT NOT NULL,
                from_round INT NOT NULL DEFAULT 2,
                PRIMARY KEY (id),
                UNIQUE KEY tournament_player (tournament_id, player_id),
                KEY tournament_round (tournament_id, from_round)
            ) $charset"); // phpcs:ignore
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
        $regCol = $wpdb->get_var("SHOW COLUMNS FROM $tournamentsTable LIKE 'registration_enabled'"); // phpcs:ignore
        if ($regCol === null) {
            $wpdb->query("ALTER TABLE $tournamentsTable ADD COLUMN registration_enabled TINYINT(1) NOT NULL DEFAULT 0"); // phpcs:ignore
            $wpdb->query("ALTER TABLE $tournamentsTable ADD COLUMN registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0"); // phpcs:ignore
            $wpdb->query("ALTER TABLE $tournamentsTable ADD COLUMN registration_currency VARCHAR(3) NOT NULL DEFAULT 'EUR'"); // phpcs:ignore
        }
        foreach (['city' => 'VARCHAR(100) NULL', 'federation' => 'VARCHAR(3) NULL', 'start_date' => 'DATE NULL', 'end_date' => 'DATE NULL', 'chief_arbiter' => 'VARCHAR(100) NULL', 'deputy_arbiter' => 'VARCHAR(100) NULL', 'time_control' => 'VARCHAR(50) NULL'] as $col => $def) {
            if ($wpdb->get_var("SHOW COLUMNS FROM $tournamentsTable LIKE '$col'") === null) {
                $wpdb->query("ALTER TABLE $tournamentsTable ADD COLUMN $col $def"); // phpcs:ignore
            }
        }
        foreach (['birth_date' => 'DATE NULL', 'sex' => 'CHAR(1) NULL', 'title' => 'VARCHAR(10) NULL'] as $col => $def) {
            if ($wpdb->get_var("SHOW COLUMNS FROM $playersTable LIKE '$col'") === null) {
                $wpdb->query("ALTER TABLE $playersTable ADD COLUMN $col $def"); // phpcs:ignore
            }
        }
        ChessPodium_Registration::create_tables();
        ChessPodium_PlayerStatus::create_table();
        ChessPodium_PdfBrochure::create_table();
        ChessPodium_PgnLive::create_table();
        $pairingsTable = self::db_table('regina_pairings');
        $byePointsCol = $wpdb->get_var("SHOW COLUMNS FROM $pairingsTable LIKE 'bye_points'"); // phpcs:ignore
        if ($byePointsCol === null) {
            $wpdb->query("ALTER TABLE $pairingsTable ADD COLUMN bye_points DECIMAL(3,2) NULL AFTER result"); // phpcs:ignore
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

    public static function insert_player(int $tournamentId, string $name, int $rating, ?string $fideId = null, ?string $country = null, ?string $title = null): void
    {
        global $wpdb;
        $country = self::sanitize_country_code($country);
        $title = $title ? self::sanitize_fide_title($title) : null;
        $cols = ['tournament_id', 'name', 'fide_id', 'country', 'rating', 'created_at'];
        $vals = [$tournamentId, $name, $fideId, $country ?: null, $rating, current_time('mysql')];
        $fmts = ['%d', '%s', '%s', '%s', '%d', '%s'];
        if ($title !== null) {
            $cols[] = 'title';
            $vals[] = $title;
            $fmts[] = '%s';
        }
        $wpdb->insert(self::db_table('regina_players'), array_combine($cols, $vals), $fmts);
    }

    public static function sanitize_fide_title(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $t = strtoupper(trim(substr($raw, 0, 10)));
        $valid = ['GM', 'IM', 'FM', 'CM', 'WGM', 'WIM', 'WFM', 'WCM'];
        return in_array($t, $valid, true) ? $t : null;
    }

    public static function sanitize_country_code(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $code = strtoupper(substr(trim($code), 0, 2));
        return (strlen($code) === 2 && ctype_alpha($code)) ? $code : null;
    }

    private static function update_player(int $tournamentId, int $playerId, string $name, int $rating, ?string $country = null, ?string $title = null): void
    {
        global $wpdb;
        $country = self::sanitize_country_code($country);
        $data = [
            'name' => $name,
            'rating' => $rating,
            'country' => $country ?: null,
        ];
        $formats = ['%s', '%d', '%s'];
        if ($title !== null) {
            $data['title'] = $title;
            $formats[] = '%s';
        } else {
            $data['title'] = null;
            $formats[] = '%s';
        }
        $wpdb->update(
            self::db_table('regina_players'),
            $data,
            [
                'id' => $playerId,
                'tournament_id' => $tournamentId,
            ],
            $formats,
            ['%d', '%d']
        );
    }

    private static function delete_player(int $tournamentId, int $playerId): bool
    {
        global $wpdb;
        if (self::player_used_in_pairings($tournamentId, $playerId)) {
            return false;
        }
        self::remove_fixed_board($tournamentId, $playerId);
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
        $wpdb->delete(self::db_table('regina_fixed_boards'), ['tournament_id' => $tournamentId], ['%d']);
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

    /**
     * Normalize result string for consistent comparison (handles ½-½, 0.5-0.5, =)
     */
    public static function normalize_result(string $res): string
    {
        $res = trim($res);
        if ($res === '') {
            return '';
        }
        if ($res === '=' || $res === '½-½' || $res === '0.5-0.5' || preg_match('/^0\.5\s*-\s*0\.5$/i', $res)) {
            return '1/2-1/2';
        }
        return $res;
    }

    private static function update_pairing_result(int $pairingId, string $result): void
    {
        global $wpdb;
        $result = self::normalize_result($result);
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

    /**
     * Save manually edited pairings for a round.
     * Validates that each player appears at most once (white or black) per round.
     *
     * @param array<int, array{white: int, black: int, result?: string}> $pairings pairing_id => {white, black, result?}
     */
    private static function save_pairings_for_round(int $tournamentId, int $roundNo, array $pairings): bool
    {
        global $wpdb;
        $table = self::db_table('regina_pairings');
        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT id, white_player_id, black_player_id, is_bye FROM $table WHERE tournament_id = %d AND round_no = %d",
            $tournamentId,
            $roundNo
        ));
        $validIds = [];
        foreach ($existing as $row) {
            $validIds[(int) $row->id] = true;
        }

        $usedWhite = [];
        $usedBlack = [];
        $updates = [];

        foreach ($pairings as $pairingId => $data) {
            $pairingId = (int) $pairingId;
            if (!isset($validIds[$pairingId])) {
                continue;
            }
            $white = isset($data['white']) ? (int) $data['white'] : 0;
            $black = isset($data['black']) ? (int) $data['black'] : 0;
            $result = isset($data['result']) ? self::normalize_result(sanitize_text_field((string) $data['result'])) : null;

            $row = null;
            foreach ($existing as $r) {
                if ((int) $r->id === $pairingId) {
                    $row = $r;
                    break;
                }
            }
            if (!$row) {
                continue;
            }
            $isBye = (int) $row->is_bye === 1;

            if ($isBye) {
                if ($white > 0 && $black === 0) {
                    $updates[$pairingId] = ['white' => $white, 'black' => 0, 'result' => $result];
                    $usedWhite[$white] = true;
                }
                continue;
            }

            if ($white <= 0 || $black <= 0) {
                return false;
            }
            if ($white === $black) {
                return false;
            }
            if (isset($usedWhite[$white]) || isset($usedBlack[$white])) {
                return false;
            }
            if (isset($usedWhite[$black]) || isset($usedBlack[$black])) {
                return false;
            }
            $usedWhite[$white] = true;
            $usedBlack[$black] = true;
            $updates[$pairingId] = ['white' => $white, 'black' => $black, 'result' => $result];
        }

        foreach ($updates as $pairingId => $u) {
            $data = [
                'white_player_id' => $u['white'],
                'black_player_id' => $u['black'],
            ];
            if ($u['result'] !== null) {
                $validRes = ['', '1-0', '0-1', '1/2-1/2'];
                if (in_array($u['result'], $validRes, true)) {
                    $data['result'] = $u['result'] === '' ? null : $u['result'];
                }
            }
            $wpdb->update($table, $data, ['id' => $pairingId], ['%d', '%d', '%s'], ['%d']);
        }
        return true;
    }

    private static function get_tournaments(): array
    {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . self::db_table('regina_tournaments') . ' ORDER BY id DESC'); // phpcs:ignore
    }

    public static function get_tournament(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::db_table('regina_tournaments') . ' WHERE id = %d', $id));
        return $row ?: null;
    }

    /** Returns the tournament in progress, or the latest tournament. For registration shortcode when no ID is specified. */
    public static function get_current_tournament(): ?object
    {
        $t = self::get_tournament_in_progress();
        return $t ?: self::get_latest_tournament();
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

    public static function get_players(int $tournamentId): array
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
                "SELECT p.*, wp.name AS white_name, wp.country AS white_country, wp.title AS white_title,
                bp.name AS black_name, bp.country AS black_country, bp.title AS black_title
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
                "SELECT p.*, wp.name AS white_name, wp.country AS white_country, wp.title AS white_title,
                bp.name AS black_name, bp.country AS black_country, bp.title AS black_title
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

        $allPlayers = self::get_players($tournamentId);
        if (count($allPlayers) < 2) {
            return false;
        }

        $nextRound = (int) $tournament->current_round + 1;
        $byePointsDefault = isset($tournament->bye_points) ? (float) $tournament->bye_points : 1.0;

        $statusMap = ChessPodium_PlayerStatus::get_all_for_round($tournamentId, $nextRound);
        $withdrawn = [];
        $byeRequested = [];
        foreach ($statusMap as $pid => $status) {
            if ($status === ChessPodium_PlayerStatus::STATUS_WITHDRAWN) {
                $withdrawn[$pid] = true;
            } elseif ($status === ChessPodium_PlayerStatus::STATUS_BYE_REQUESTED) {
                $byeRequested[$pid] = true;
            }
        }

        $players = array_values(array_filter($allPlayers, static function ($p) use ($withdrawn) {
            return !isset($withdrawn[(int) $p->id]);
        }));
        if (count($players) < 2) {
            return false;
        }

        $pairings = [];
        foreach (array_keys($byeRequested) as $pid) {
            $pairings[] = [
                'white' => $pid,
                'black' => 0,
                'is_bye' => 1,
                'bye_points' => 0.5,
            ];
        }
        $remainingPlayers = array_values(array_filter($players, static function ($p) use ($byeRequested) {
            return !isset($byeRequested[(int) $p->id]);
        }));

        $type = isset($tournament->tournament_type) ? (string) $tournament->tournament_type : 'swiss';
        if ($type === 'round_robin') {
            $roundPairs = self::pair_round_robin($remainingPlayers, $nextRound);
        } elseif ($nextRound === 1) {
            $roundPairs = self::pair_round_one($remainingPlayers);
        } else {
            $standings = self::compute_standings($tournamentId);
            $roundPairs = self::pair_swiss($remainingPlayers, $standings, $tournamentId, $nextRound, $withdrawn, $byeRequested);
        }

        foreach ($roundPairs as $pair) {
            $byePts = null;
            if (!empty($pair['is_bye'])) {
                $byePts = $byePointsDefault;
            }
            $pairings[] = [
                'white' => (int) $pair['white'],
                'black' => (int) $pair['black'],
                'is_bye' => (int) $pair['is_bye'],
                'bye_points' => $byePts,
            ];
        }

        $pairings = self::apply_fixed_board_assignments($pairings, $tournamentId, $nextRound);

        foreach ($pairings as $pair) {
            $insertData = [
                'tournament_id' => $tournamentId,
                'round_no' => $nextRound,
                'white_player_id' => (int) $pair['white'],
                'black_player_id' => (int) $pair['black'],
                'is_bye' => (int) $pair['is_bye'],
                'result' => $pair['is_bye'] ? 'BYE' : null,
                'created_at' => current_time('mysql'),
            ];
            $insertFmt = ['%d', '%d', '%d', '%d', '%d', '%s', '%s'];
            if (isset($pair['bye_points'])) {
                $insertData['bye_points'] = (float) $pair['bye_points'];
                $insertFmt[] = '%f';
            }
            $wpdb->insert(self::db_table('regina_pairings'), $insertData, $insertFmt);
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
            $result = self::normalize_result(sanitize_text_field((string) $result));
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
            $title = isset($row[4]) ? self::sanitize_fide_title(trim((string) $row[4])) : null;
            self::insert_player($tournamentId, $name, $rating, $fideId, $country, $title);
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
            $r = ((int) $b->rating <=> (int) $a->rating);
            if ($r !== 0) {
                return $r;
            }
            return strcasecmp((string) $b->name, (string) $a->name);
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
            $r = ((int) $b->rating <=> (int) $a->rating);
            if ($r !== 0) {
                return $r;
            }
            return strcasecmp((string) $b->name, (string) $a->name);
        });

        $pairs = [];
        $count = count($players);
        $half = intdiv($count, 2);

        for ($i = 0; $i < $half; $i++) {
            $top = $players[$i];
            $bottom = $players[$i + $half];
            // FIDE standard: alternate colors by board - odd boards (2,4,6...) bottom gets White
            if ($i % 2 === 0) {
                $white = $top;
                $black = $bottom;
            } else {
                $white = $bottom;
                $black = $top;
            }
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

    /**
     * @param array<int> $withdrawn Player IDs withdrawn (excluded from pairing).
     * @param array<int> $byeRequested Player IDs with bye requested (excluded from pairing).
     */
    private static function pair_swiss(array $players, array $standings, int $tournamentId, int $nextRound = 0, array $withdrawn = [], array $byeRequested = []): array
    {
        if ($nextRound > 0) {
            $bbpPairs = self::pair_with_bbp($tournamentId, $nextRound, $withdrawn, $byeRequested);
            if ($bbpPairs !== null) {
                return $bbpPairs;
            }
        }

        // FIDE Dutch: pairing order = Score then TPN (start_rank) only - no Buchholz/SB.
        $startRankMap = [];
        $ordered = [];
        $pointsMap = [];
        $playerIdsToPair = [];
        foreach ($players as $p) {
            $playerIdsToPair[(int) $p->id] = true;
        }
        foreach ($standings as $s) {
            $pid = (int) $s['player_id'];
            $startRankMap[$pid] = (int) ($s['start_rank'] ?? 999);
            $pointsMap[$pid] = (float) $s['points'];
            if (isset($playerIdsToPair[$pid])) {
                $ordered[] = $pid;
            }
        }
        // Sort by points DESC, then TPN for pairing. Vega uses player ID (Nr) as pairing number.
        usort($ordered, static function ($a, $b) use ($pointsMap, $startRankMap) {
            $pa = $pointsMap[$a] ?? 0.0;
            $pb = $pointsMap[$b] ?? 0.0;
            if (abs($pb - $pa) >= 0.001) {
                return $pb <=> $pa;
            }
            // TPN: player ID ascending (matches Vega Nr / JaVaFo pairing-id when IDs = rating order)
            return $a <=> $b;
        });

        // Ensure every player is present in ordering.
        foreach ($players as $p) {
            $pid = (int) $p->id;
            if (!in_array($pid, $ordered, true)) {
                $ordered[] = $pid;
                $pointsMap[$pid] = 0.0;
                $startRankMap[$pid] = $startRankMap[$pid] ?? 999;
            }
        }

        $allPairings = self::get_all_pairings($tournamentId);
        $playedMap = array();
        $hadBye = array();
        $colorBalances = array();

        $lastColors = [];
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
            $lastColors[$w] = array_slice(array_merge($lastColors[$w] ?? [], ['w']), -2);
            $lastColors[$b] = array_slice(array_merge($lastColors[$b] ?? [], ['b']), -2);
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

        // FIDE Dutch System: within each score group, pair top half vs bottom half
        // (rank 1 vs rank 1+size/2, rank 2 vs rank 2+size/2, etc.)
        $dutchData = self::build_dutch_rank_data($remaining, $pointsMap);

        // Strict Dutch first (matches JaVaFo/Vega): sequential pairing within each score group.
        $searchedPairs = self::pair_strict_dutch($remaining, $pointsMap, $playedMap, $colorBalances, $dutchData, $lastColors, $startRankMap);
        if (count($searchedPairs) * 2 !== count($remaining)) {
            // Fallback: MWM engine per arxiv.org/html/2112.10522v2
            $searchedPairs = self::pair_swiss_mwm($remaining, $pointsMap, $playedMap, $colorBalances, $dutchData, $lastColors);
        }
        if (count($searchedPairs) * 2 !== count($remaining)) {
            $searchedPairs = self::pair_swiss_greedy_fallback($remaining, $pointsMap, $playedMap, $colorBalances, $dutchData, $lastColors);
        }

        return array_merge($pairs, $searchedPairs);
    }

    /**
     * Strict FIDE Dutch pairing: within each score group, pair rank k with rank k+size/2.
     * Matches JaVaFo/Vega deterministic behavior. Returns [] if any constraint is violated.
     *
     * @param array<int> $remaining Player IDs in standings order.
     * @param array<int,float> $pointsMap Player ID => points.
     * @param array<string,bool> $playedMap pair_key => true if already played.
     * @param array<int,int> $colorBalances Player ID => color diff (white-black).
     * @param array{rankInGroup: array<int,int>, groupSize: array<int,int>} $dutchData
     * @param array<int, array<string>> $lastColors Player ID => last 2 colors.
     * @param array<int,int> $startRankMap Player ID => start rank (rating order).
     * @return array<int, array{white: int, black: int, is_bye: int}>
     */
    /**
     * FIDE Dutch System pairing with full backtracking (JaVaFo/Vega compatible).
     *
     * Algorithm (FIDE Dutch System rules B1-B6 + C1-C5):
     * 1. Split players into score groups ordered by TPN (start rank = rating order).
     * 2. For each group, split into top half S1 and bottom half S2.
     * 3. Try S1[k] vs S2[k] for all k (natural Dutch pairing).
     * 4. On conflict (rematch or absolute color violation):
     *    a. Transpose S2 (shift bottom-half candidate right, cyclically).
     *    b. If all S2 transpositions exhausted, try S1-S2 single exchanges.
     *    c. If all fail, float the last player of this group down to the next group.
     * 5. Floater prepended to next score group (retains priority, pairs first).
     * 6. Color: lower signed CD gets White; CD tie broken by TPN (lower = White).
     *
     * Returns [] if no valid pairing found (caller falls back to MWM engine).
     */
    private static function pair_strict_dutch(
        array $remaining,
        array $pointsMap,
        array $playedMap,
        array $colorBalances,
        array $dutchData,
        array $lastColors,
        array $startRankMap = []
    ): array {
        $n = count($remaining);
        if ($n < 2) {
            return [];
        }

        // Build score groups in TPN order (already sorted by pair_swiss).
        $groups = [];
        $i = 0;
        while ($i < $n) {
            $score = (float) ($pointsMap[$remaining[$i]] ?? 0.0);
            $group = [];
            while ($i < $n && abs((float) ($pointsMap[$remaining[$i]] ?? 0) - $score) < 0.001) {
                $group[] = (int) $remaining[$i];
                $i++;
            }
            $groups[] = $group;
        }

        $allPairs = [];
        $floater = null;

        foreach ($groups as $group) {
            // Prepend floater from previous odd group.
            if ($floater !== null) {
                array_unshift($group, $floater);
                $floater = null;
            }

            $groupPairs = self::dutch_pair_group(
                $group,
                $playedMap,
                $colorBalances,
                $lastColors,
                $startRankMap,
                $floater
            );

            if ($groupPairs === null) {
                // Complete failure — fall back to MWM.
                return [];
            }

            foreach ($groupPairs as $p) {
                $allPairs[] = $p;
            }
        }

        // Leftover floater after last group means incomplete pairing.
        if ($floater !== null) {
            return [];
        }

        return $allPairs;
    }

    /**
     * Pair a single score group with FIDE Dutch backtracking.
     * $floater (passed by ref) is set to the player who must float to the next group.
     *
     * @param array<int> $group Players in TPN order.
     * @return array<int,array{white:int,black:int,is_bye:int}>|null  null = complete failure.
     */
    private static function dutch_pair_group(
        array $group,
        array $playedMap,
        array $colorBalances,
        array $lastColors,
        array $startRankMap,
        ?int &$floater
    ): ?array {
        $floater = null;
        $size = count($group);

        if ($size === 0) {
            return [];
        }
        if ($size === 1) {
            // Single player — must float down.
            $floater = $group[0];
            return [];
        }

        // For odd groups, we will try floating each candidate (starting from last = lowest TPN).
        $candidates = [$size % 2 !== 0 ? [$group[$size - 1], array_slice($group, 0, $size - 1)] : [null, $group]];

        if ($size % 2 !== 0) {
            // Build list of all possible floaters (last player tried first per FIDE).
            $candidates = [];
            for ($fi = $size - 1; $fi >= 0; $fi--) {
                $fc = $group[$fi];
                $reduced = array_values(array_filter($group, static fn ($x) => $x !== $fc));
                $candidates[] = [$fc, $reduced];
            }
        }

        foreach ($candidates as [$fc, $even]) {
            $half = count($even) / 2;
            $s1 = array_slice($even, 0, $half);
            $s2 = array_slice($even, $half);

            $s2perms = self::dutch_s2_permutations($s1, $s2, $startRankMap);

            foreach ($s2perms as $s2perm) {
                $valid = true;
                $pairs = [];
                for ($k = 0; $k < $half; $k++) {
                    $a = (int) $s1[$k];
                    $b = (int) $s2perm[$k];
                    if (!self::dutch_pair_is_allowed($a, $b, $playedMap, $colorBalances, $lastColors)) {
                        $valid = false;
                        break;
                    }
                    $pairs[] = self::dutch_assign_color($a, $b, $colorBalances, $lastColors, $startRankMap);
                }
                if ($valid) {
                    $floater = $fc;
                    return $pairs;
                }
            }
        }

        // Complete failure for this group.
        return null;
    }

    /**
     * Generate S2 permutations in Javafo/Vega priority order:
     * 1. Natural order (no shift).
     * 2. Cyclic right-shifts of S2 (transpositions).
     * 3. Single S1-S2 exchanges (one S1 player swaps with one S2 player).
     */
    private static function dutch_s2_permutations(array $s1, array $s2, array $startRankMap): array
    {
        $perms = [];
        $half = count($s2);

        if ($half === 0) {
            return [[]];
        }

        // 1. Natural order.
        $perms[] = $s2;

        // 2. Cyclic shifts of S2.
        for ($shift = 1; $shift < $half; $shift++) {
            $perms[] = array_merge(array_slice($s2, $shift), array_slice($s2, 0, $shift));
        }

        // 3. Single S1-S2 exchanges: swap s1[i] <-> s2[j].
        for ($i = count($s1) - 1; $i >= 0; $i--) {
            for ($j = 0; $j < $half; $j++) {
                $newS2 = $s2;
                $newS2[$j] = $s1[$i];
                // Re-sort to maintain TPN order within S2 as Javafo does.
                usort($newS2, static fn ($a, $b) => ($startRankMap[$a] ?? $a) <=> ($startRankMap[$b] ?? $b));
                if (!in_array($newS2, $perms, true)) {
                    $perms[] = $newS2;
                }
            }
        }

        return $perms;
    }

    /**
     * Check if pairing (a, b) is allowed under FIDE Dutch rules B2, C1, C5.
     * B2: no rematch.
     * C1: no 3 consecutive same color.
     * C5: no pair if |cdA + cdB| >= 4 (both have extreme same preference).
     */
    private static function dutch_pair_is_allowed(
        int $a,
        int $b,
        array $playedMap,
        array $colorBalances,
        array $lastColors
    ): bool {
        // B2: rematch check.
        if (isset($playedMap[self::pair_key($a, $b)])) {
            return false;
        }

        $lastA = $lastColors[$a] ?? [];
        $lastB = $lastColors[$b] ?? [];
        $lenA = count($lastA);
        $lenB = count($lastB);

        // C1: absolute color prohibition — 2 consecutive same color = must play opposite.
        $mustABlack = $lenA >= 2 && $lastA[$lenA - 2] === 'w' && $lastA[$lenA - 1] === 'w';
        $mustAWhite = $lenA >= 2 && $lastA[$lenA - 2] === 'b' && $lastA[$lenA - 1] === 'b';
        $mustBBlack = $lenB >= 2 && $lastB[$lenB - 2] === 'w' && $lastB[$lenB - 1] === 'w';
        $mustBWhite = $lenB >= 2 && $lastB[$lenB - 2] === 'b' && $lastB[$lenB - 1] === 'b';

        // Both must play the same color — impossible.
        if ($mustAWhite && $mustBWhite) {
            return false;
        }
        if ($mustABlack && $mustBBlack) {
            return false;
        }

        // C5: extreme CD clash.
        $cdA = (int) ($colorBalances[$a] ?? 0);
        $cdB = (int) ($colorBalances[$b] ?? 0);
        if (abs($cdA + $cdB) >= 4) {
            return false;
        }

        return true;
    }

    /**
     * Assign colors for a valid pair (a, b) per FIDE Dutch System C4/C5.
     * Lower signed CD gets White; CD tie broken by TPN (lower start_rank = White).
     */
    private static function dutch_assign_color(
        int $a,
        int $b,
        array $colorBalances,
        array $lastColors,
        array $startRankMap
    ): array {
        $lastA = $lastColors[$a] ?? [];
        $lastB = $lastColors[$b] ?? [];
        $lenA = count($lastA);
        $lenB = count($lastB);

        // C1 absolute constraints take highest priority.
        $mustAWhite = $lenA >= 2 && $lastA[$lenA - 2] === 'b' && $lastA[$lenA - 1] === 'b';
        $mustBWhite = $lenB >= 2 && $lastB[$lenB - 2] === 'b' && $lastB[$lenB - 1] === 'b';
        $mustABlack = $lenA >= 2 && $lastA[$lenA - 2] === 'w' && $lastA[$lenA - 1] === 'w';
        $mustBBlack = $lenB >= 2 && $lastB[$lenB - 2] === 'w' && $lastB[$lenB - 1] === 'w';

        if ($mustAWhite || $mustBBlack) {
            return ['white' => $a, 'black' => $b, 'is_bye' => 0];
        }
        if ($mustBWhite || $mustABlack) {
            return ['white' => $b, 'black' => $a, 'is_bye' => 0];
        }

        // C4: lower signed CD gets White.
        $cdA = (int) ($colorBalances[$a] ?? 0);
        $cdB = (int) ($colorBalances[$b] ?? 0);
        if ($cdA < $cdB) {
            return ['white' => $a, 'black' => $b, 'is_bye' => 0];
        }
        if ($cdB < $cdA) {
            return ['white' => $b, 'black' => $a, 'is_bye' => 0];
        }

        // CD equal: lower TPN (start_rank) gets White (FIDE tiebreaker).
        $srA = $startRankMap[$a] ?? $a;
        $srB = $startRankMap[$b] ?? $b;
        return $srA <= $srB
            ? ['white' => $a, 'black' => $b, 'is_bye' => 0]
            : ['white' => $b, 'black' => $a, 'is_bye' => 0];
    }

    /**
     * Pair via bbpPairings (FIDE Dutch Swiss). Same engine as Vega. Returns null if disabled or fails.
     * bbpPairings is a single C++ executable, no Java/dependencies. Output identical to JaVaFo.
     *
     * @param int $tournamentId Tournament ID.
     * @param int $roundToPair Round number to pair.
     * @param array<int> $withdrawn Player IDs withdrawn.
     * @param array<int> $byeRequested Player IDs with bye requested.
     * @return array<int, array{white: int, black: int, is_bye: int}>|null Pairings or null.
     */
    private static function pair_with_bbp(int $tournamentId, int $roundToPair, array $withdrawn, array $byeRequested): ?array
    {
        $trfData = ChessPodium_TrfExport::generate_trf_for_pairing($tournamentId, $roundToPair, $withdrawn, $byeRequested);
        if ($trfData['trf'] === '') {
            return null;
        }

        if (function_exists('exec')) {
            $bbpPath = trim((string) get_option('chess_podium_bbp_pairings_path', ''));
            if ($bbpPath !== '' && is_readable($bbpPath)) {
                $result = self::pair_with_bbp_local($trfData, $bbpPath, $tournamentId, $withdrawn, $byeRequested);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        $apiUrl = trim((string) get_option('chess_podium_bbp_pairings_api_url', ''));
        if ($apiUrl !== '' && preg_match('#^https?://#i', $apiUrl)) {
            $result = self::pair_with_bbp_api($trfData, $apiUrl, $tournamentId, $withdrawn, $byeRequested);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private static function pair_with_bbp_local(array $trfData, string $bbpPath, int $tournamentId, array $withdrawn, array $byeRequested): ?array
    {
        $tmpDir = get_temp_dir();
        $trfFile = $tmpDir . 'cp_trf_' . wp_rand(10000, 99999) . '.trfx';
        $outFile = $tmpDir . 'cp_pair_' . wp_rand(10000, 99999) . '.txt';

        if (file_put_contents($trfFile, $trfData['trf']) === false) {
            return null;
        }

        $bbpEsc = escapeshellarg($bbpPath);
        $trfEsc = escapeshellarg($trfFile);
        $outEsc = escapeshellarg($outFile);
        $cmd = $bbpEsc . ' --dutch ' . $trfEsc . ' -p ' . $outEsc . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null');

        $output = [];
        $ret = 0;
        exec($cmd, $output, $ret);

        $pairs = self::parse_bbp_output($outFile, $trfData['startRankToPlayerId']);

        @unlink($trfFile);
        @unlink($outFile);

        return self::validate_pairs($pairs, $tournamentId, $withdrawn, $byeRequested) ? $pairs : null;
    }

    private static function pair_with_bbp_api(array $trfData, string $apiUrl, int $tournamentId, array $withdrawn, array $byeRequested): ?array
    {
        $response = wp_remote_post($apiUrl, [
            'timeout' => 30,
            'body' => ['trf' => base64_encode($trfData['trf'])],
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200 || $body === '') {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['ok']) || !isset($json['pairs'])) {
            return null;
        }

        $map = $trfData['startRankToPlayerId'];
        $pairs = [];
        foreach ($json['pairs'] as $pair) {
            $wTpn = (int) ($pair[0] ?? 0);
            $bTpn = (int) ($pair[1] ?? 0);
            $whitePid = $map[$wTpn] ?? 0;
            $blackPid = $bTpn === 0 ? 0 : ($map[$bTpn] ?? 0);
            if ($whitePid > 0) {
                $pairs[] = [
                    'white' => $whitePid,
                    'black' => $blackPid,
                    'is_bye' => $blackPid === 0 ? 1 : 0,
                ];
            }
        }

        return self::validate_pairs($pairs, $tournamentId, $withdrawn, $byeRequested) ? $pairs : null;
    }

    private static function parse_bbp_output(string $outFile, array $map): array
    {
        $pairs = [];
        if (!is_readable($outFile)) {
            return $pairs;
        }
        $lines = array_map('trim', (array) file($outFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $pairCount = (int) ($lines[0] ?? 0);
        if ($pairCount > 0) {
            for ($i = 1; $i <= $pairCount && isset($lines[$i]); $i++) {
                $parts = preg_split('/\s+/', $lines[$i], 2);
                $wId = isset($parts[0]) ? (int) $parts[0] : 0;
                $bId = isset($parts[1]) ? (int) $parts[1] : 0;
                $whitePid = $map[$wId] ?? 0;
                $blackPid = $bId === 0 ? 0 : ($map[$bId] ?? 0);
                if ($whitePid > 0) {
                    $pairs[] = [
                        'white' => $whitePid,
                        'black' => $blackPid,
                        'is_bye' => $blackPid === 0 ? 1 : 0,
                    ];
                }
            }
        }
        return $pairs;
    }

    private static function validate_pairs(array $pairs, int $tournamentId, array $withdrawn, array $byeRequested): bool
    {
        $playingCount = count(self::get_players($tournamentId)) - count($withdrawn) - count($byeRequested);
        $expectedPairs = (int) ceil($playingCount / 2);
        return count($pairs) === $expectedPairs && !empty($pairs);
    }

    /**
     * Build Dutch pairing data: rank within score group and group size.
     * FIDE Dutch pairs rank k with rank k+size/2 (top half vs bottom half).
     *
     * @return array{rankInGroup: array<int,int>, groupSize: array<int,int>}
     */
    private static function build_dutch_rank_data(array $ordered, array $pointsMap): array
    {
        $rankInGroup = [];
        $groupSize = [];
        $i = 0;
        while ($i < count($ordered)) {
            $score = (float) ($pointsMap[$ordered[$i]] ?? 0.0);
            $group = [];
            while ($i < count($ordered) && abs((float) ($pointsMap[$ordered[$i]] ?? 0) - $score) < 0.001) {
                $group[] = (int) $ordered[$i];
                $i++;
            }
            $size = count($group);
            foreach ($group as $rank => $pid) {
                $rankInGroup[$pid] = $rank + 1;
                $groupSize[$pid] = $size;
            }
        }
        return ['rankInGroup' => $rankInGroup, 'groupSize' => $groupSize];
    }

    /**
     * Swiss pairing via Maximum Weight Matching (MWM) per arxiv.org/html/2112.10522v2.
     * Weight: w = 10000*(-|s(pi)-s(pj)|) + 100*(-|cd(pi)+cd(pj)|) + π(pi,pj)
     * Dutch π = -|sg_size/2 - |r(pi)-r(pj)||^1.01 (sg_size=0 if different score groups)
     * Color: player with lower |cd| plays White; if equal, higher rank in group plays White.
     */
    private static function pair_swiss_mwm(
        array $remaining,
        array $pointsMap,
        array $playedMap,
        array $colorBalances,
        array $dutchData,
        array $lastColors
    ): array {
        $n = count($remaining);
        if ($n < 2) {
            return [];
        }

        $rankInGroup = $dutchData['rankInGroup'] ?? [];
        $groupSize = $dutchData['groupSize'] ?? [];
        $beta = 2;

        // Map: vertex index i -> player id
        $idxToPid = array_values($remaining);
        $pidToIdx = array_flip($idxToPid);

        $edges = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = (int) $idxToPid[$i];
                $b = (int) $idxToPid[$j];

                if (isset($playedMap[self::pair_key($a, $b)])) {
                    continue;
                }

                $cdA = (int) ($colorBalances[$a] ?? 0);
                $cdB = (int) ($colorBalances[$b] ?? 0);
                if (abs($cdA + $cdB) >= 2 * $beta) {
                    continue;
                }

                $scoreDiff = abs((float) ($pointsMap[$a] ?? 0) - (float) ($pointsMap[$b] ?? 0));
                $colorSum = abs($cdA + $cdB);
                $pi = self::mwm_dutch_pi($a, $b, $rankInGroup, $groupSize);

                $w = 10000 * (-$scoreDiff) + 100 * (-$colorSum) + $pi;
                $wInt = (int) round($w * 100);
                $edges[] = [$i, $j, $wInt];
            }
        }

        if (empty($edges)) {
            return [];
        }

        $maxWeight = 0;
        foreach ($edges as $e) {
            $maxWeight = max($maxWeight, abs($e[2]));
        }
        $M = (int) ($maxWeight * $n * 2 + 1000000);
        foreach ($edges as $k => $e) {
            $edges[$k][2] = $e[2] + $M;
        }

        try {
            $matching = new ChessPodium_MaxWeightMatching($edges);
            $mate = $matching->main();
        } catch (Throwable $e) {
            return [];
        }

        if (!is_array($mate)) {
            return [];
        }

        $seen = [];
        $pairs = [];
        foreach ($mate as $i => $j) {
            if ($j === -1 || isset($seen[$i]) || isset($seen[$j])) {
                continue;
            }
            $seen[$i] = true;
            $seen[$j] = true;
            $a = (int) $idxToPid[$i];
            $b = (int) $idxToPid[$j];
            $orientation = self::mwm_color_orientation($a, $b, $colorBalances, $dutchData);
            $pairs[] = [
                'white' => (int) $orientation['white'],
                'black' => (int) $orientation['black'],
                'is_bye' => 0,
            ];
        }

        return $pairs;
    }

    /**
     * Dutch π(pi,pj) = -|sg_size/2 - |r(pi)-r(pj)||^1.01
     * sg_size = 0 if different score groups (article 2.2).
     */
    private static function mwm_dutch_pi(int $a, int $b, array $rankInGroup, array $groupSize): float
    {
        $ra = $rankInGroup[$a] ?? 0;
        $rb = $rankInGroup[$b] ?? 0;
        $sz = $groupSize[$a] ?? 0;
        $szB = $groupSize[$b] ?? 0;
        if ($ra === 0 || $rb === 0) {
            return 0.0;
        }
        $actualDist = (float) abs($ra - $rb);
        if ($sz !== $szB || $sz < 2) {
            $idealDist = 0.0;
        } else {
            $idealDist = $sz / 2.0;
        }
        $deviation = abs($idealDist - $actualDist);
        return -pow($deviation, 2.0);
    }

    /**
     * FIDE: "the respective player with the lower color difference will play white."
     * CD = white games - black games (signed); lower CD = has played more Black = right to White.
     * Tiebreaker when CD equal: higher-ranked player (lower rank number) gets White.
     * NOTE: Uses CD with sign, NOT abs() — critical for correct color assignment.
     */
    private static function mwm_color_orientation(int $a, int $b, array $colorBalances, array $dutchData): array
    {
        $cdA = (int) ($colorBalances[$a] ?? 0);
        $cdB = (int) ($colorBalances[$b] ?? 0);
        $rankInGroup = $dutchData['rankInGroup'] ?? [];
        $ra = $rankInGroup[$a] ?? 0;
        $rb = $rankInGroup[$b] ?? 0;

        if ($cdA < $cdB) {
            return ['white' => $a, 'black' => $b];
        }
        if ($cdB < $cdA) {
            return ['white' => $b, 'black' => $a];
        }
        if ($ra !== $rb) {
            return $ra < $rb ? ['white' => $a, 'black' => $b] : ['white' => $b, 'black' => $a];
        }
        return ['white' => $a, 'black' => $b];
    }

    private static function search_pairings_backtracking(
        array $remaining,
        array $pointsMap,
        array $playedMap,
        array $colorBalances,
        array $dutchData = [],
        array $lastColors = []
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
            $dutchData,
            $lastColors,
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
                $dutchPenalty = self::dutch_pairing_penalty($a, $b, $pointsMap, $dutchData);
                $orientation = self::best_orientation($a, $b, $colorBalances, $lastColors, $dutchData);
                $totalPenalty = $rematchPenalty + $scorePenalty + $dutchPenalty + (int) $orientation['penalty'];

                $candidates[] = array(
                    'opp' => $b,
                    'white' => (int) $orientation['white'],
                    'black' => (int) $orientation['black'],
                    'penalty' => $totalPenalty,
                    'dutchPenalty' => $dutchPenalty,
                );
            }

            usort($candidates, static function ($x, $y) {
                $c = (int) $x['penalty'] <=> (int) $y['penalty'];
                return $c !== 0 ? $c : ((int) $x['dutchPenalty'] <=> (int) $y['dutchPenalty']);
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

    private static function pair_swiss_greedy_fallback(array $remaining, array $pointsMap, array $playedMap, array $colorBalances, array $dutchData = [], array $lastColors = []): array
    {
        $pairs = array();
        $todo = array_values($remaining);

        while (count($todo) > 1) {
            $a = (int) array_shift($todo);
            $bestIdx = null;
            $bestPenalty = PHP_INT_MAX;
            $bestDutchPenalty = PHP_INT_MAX;
            $bestOrientation = array('white' => $a, 'black' => 0, 'penalty' => 0);

            $scoreA = (float) ($pointsMap[$a] ?? 0.0);
            foreach ($todo as $idx => $rawB) {
                $b = (int) $rawB;
                $hasRematch = isset($playedMap[self::pair_key($a, $b)]);
                $scoreDiff = abs($scoreA - (float) ($pointsMap[$b] ?? 0.0));
                $dutchPenalty = self::dutch_pairing_penalty($a, $b, $pointsMap, $dutchData);
                $orientation = self::best_orientation($a, $b, $colorBalances, $lastColors, $dutchData);
                $penalty = ($hasRematch ? 1000 : 0) + (int) round($scoreDiff * 10) + $dutchPenalty + (int) $orientation['penalty'];
                if ($penalty < $bestPenalty || ($penalty === $bestPenalty && $dutchPenalty < $bestDutchPenalty)) {
                    $bestPenalty = $penalty;
                    $bestDutchPenalty = $dutchPenalty;
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

    /**
     * Dutch pairing penalty: prefer pairing rank k with rank k+size/2 (top vs bottom half).
     * Based on arxiv.org/html/2112.10522v2 - π(pi,pj) = -|sg_size/2 - |r(pi)-r(pj)||^1.01
     *
     * @param array{rankInGroup?: array<int,int>, groupSize?: array<int,int>} $dutchData
     */
    private static function dutch_pairing_penalty(int $a, int $b, array $pointsMap, array $dutchData): int
    {
        $rankInGroup = $dutchData['rankInGroup'] ?? [];
        $groupSize = $dutchData['groupSize'] ?? [];
        if (empty($rankInGroup) || empty($groupSize)) {
            return 0;
        }
        $ra = $rankInGroup[$a] ?? 0;
        $rb = $rankInGroup[$b] ?? 0;
        $sz = $groupSize[$a] ?? 0;
        if ($ra === 0 || $rb === 0 || $sz < 2) {
            return 0;
        }
        // Same score group only (different groups already penalized by scoreDiff)
        $szB = $groupSize[$b] ?? 0;
        if ($sz !== $szB) {
            return 0;
        }
        $idealDist = $sz / 2.0;
        $actualDist = (float) abs($ra - $rb);
        $deviation = abs($idealDist - $actualDist);
        return (int) round(12.0 * pow($deviation, 1.01));
    }

    /**
     * FIDE: no more than 2 consecutive same color; color diff ≤ 2.
     * When color balance is equal: bottom-half player (higher rank in group) gets White.
     *
     * @param array<int, array<string>> $lastColors pid => last 2 colors ['w','b']
     * @param array{rankInGroup?: array<int,int>} $dutchData For tiebreaker when balance equal.
     */
    private static function best_orientation(int $a, int $b, array $colorBalances, array $lastColors = [], array $dutchData = []): array
    {
        $balA = (int) ($colorBalances[$a] ?? 0);
        $balB = (int) ($colorBalances[$b] ?? 0);

        $lastA = $lastColors[$a] ?? [];
        $lastB = $lastColors[$b] ?? [];
        $forbidAWhite = count($lastA) === 2 && $lastA[0] === 'w' && $lastA[1] === 'w';
        $forbidABlack = count($lastA) === 2 && $lastA[0] === 'b' && $lastA[1] === 'b';
        $forbidBWhite = count($lastB) === 2 && $lastB[0] === 'w' && $lastB[1] === 'w';
        $forbidBBlack = count($lastB) === 2 && $lastB[0] === 'b' && $lastB[1] === 'b';

        $p1 = 500;
        $p2 = 500;
        if (!$forbidAWhite && !$forbidBBlack && abs($balA + 1) <= 2 && abs($balB - 1) <= 2) {
            $p1 = abs($balA + 1) + abs($balB - 1);
        }
        if (!$forbidBWhite && !$forbidABlack && abs($balB + 1) <= 2 && abs($balA - 1) <= 2) {
            $p2 = abs($balB + 1) + abs($balA - 1);
        }

        if ($p1 < $p2) {
            return array('white' => $a, 'black' => $b, 'penalty' => $p1);
        }
        if ($p2 < $p1) {
            return array('white' => $b, 'black' => $a, 'penalty' => $p2);
        }

        // Equal penalty: FIDE tiebreaker - higher-ranked player (lower rank number) gets White
        $rankInGroup = $dutchData['rankInGroup'] ?? [];
        $ra = $rankInGroup[$a] ?? 0;
        $rb = $rankInGroup[$b] ?? 0;
        if ($ra > 0 && $rb > 0 && $ra !== $rb) {
            return $ra < $rb
                ? array('white' => $a, 'black' => $b, 'penalty' => $p1)
                : array('white' => $b, 'black' => $a, 'penalty' => $p2);
        }

        if ($p1 <= $p2) {
            return array('white' => $a, 'black' => $b, 'penalty' => $p1);
        }
        return array('white' => $b, 'black' => $a, 'penalty' => $p2);
    }

    /**
     * Reorder pairings so players with fixed board assignments get their assigned board.
     *
     * @param array<int, array{white: int, black: int, is_bye: int, bye_points?: float}> $pairings
     * @return array<int, array{white: int, black: int, is_bye: int, bye_points?: float}>
     */
    private static function apply_fixed_board_assignments(array $pairings, int $tournamentId, int $round): array
    {
        $result = $pairings;
        $assignments = self::get_fixed_board_assignments($tournamentId, $round);
        if (!empty($assignments)) {
            asort($assignments, SORT_NUMERIC);
            $n = count($pairings);
            foreach ($assignments as $playerId => $boardNumber) {
                $targetIdx = (int) $boardNumber - 1;
                if ($targetIdx < 0 || $targetIdx >= $n) {
                    continue;
                }
                $currentIdx = null;
                foreach ($result as $idx => $pair) {
                    $w = (int) $pair['white'];
                    $b = (int) $pair['black'];
                    if ($w === $playerId || $b === $playerId) {
                        $currentIdx = $idx;
                        break;
                    }
                }
                if ($currentIdx !== null && $currentIdx !== $targetIdx) {
                    $temp = $result[$currentIdx];
                    $result[$currentIdx] = $result[$targetIdx];
                    $result[$targetIdx] = $temp;
                }
            }
        }
        return $result;
    }

    /**
     * @return array<int, array{player_id: int, player_name: string, board_number: int, from_round: int}>
     */
    private static function get_all_fixed_board_assignments(int $tournamentId): array
    {
        global $wpdb;
        $fbTable = self::db_table('regina_fixed_boards');
        $pTable = self::db_table('regina_players');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT fb.player_id, fb.board_number, fb.from_round, p.name AS player_name
             FROM $fbTable fb
             LEFT JOIN $pTable p ON fb.player_id = p.id AND fb.tournament_id = p.tournament_id
             WHERE fb.tournament_id = %d
             ORDER BY fb.board_number ASC",
            $tournamentId
        ));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'player_id' => (int) $r->player_id,
                'player_name' => (string) ($r->player_name ?? ''),
                'board_number' => (int) $r->board_number,
                'from_round' => (int) $r->from_round,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, int> player_id => board_number for players with fixed board from this round
     */
    private static function get_fixed_board_assignments(int $tournamentId, int $round): array
    {
        global $wpdb;
        $table = self::db_table('regina_fixed_boards');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT player_id, board_number FROM $table WHERE tournament_id = %d AND from_round <= %d",
            $tournamentId,
            $round
        ));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->player_id] = (int) $r->board_number;
        }
        return $out;
    }

    private static function save_fixed_board(int $tournamentId, int $playerId, int $boardNumber, int $fromRound): void
    {
        global $wpdb;
        $table = self::db_table('regina_fixed_boards');
        $wpdb->replace(
            $table,
            [
                'tournament_id' => $tournamentId,
                'player_id' => $playerId,
                'board_number' => $boardNumber,
                'from_round' => $fromRound,
            ],
            ['%d', '%d', '%d', '%d']
        );
    }

    private static function remove_fixed_board(int $tournamentId, int $playerId): void
    {
        global $wpdb;
        $wpdb->delete(
            self::db_table('regina_fixed_boards'),
            ['tournament_id' => $tournamentId, 'player_id' => $playerId],
            ['%d', '%d']
        );
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

    public static function sanitize_fide_id(string $raw): string
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

        $title = null;
        if (preg_match('/\b(GM|IM|FM|CM|WGM|WIM|WFM|WCM)\b/i', $body, $m)) {
            $title = strtoupper($m[1]);
        }

        return array(
            'name' => $name,
            'rating' => $rating,
            'fide_id' => $fideId,
            'country' => $country,
            'title' => $title,
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
            'fixed_boards_updated' => array('success', __('Fixed board assignment saved.', 'chess-podium')),
            'fixed_boards_removed' => array('success', __('Fixed board assignment removed.', 'chess-podium')),
            'fixed_boards_error' => array('error', __('Select a valid tournament.', 'chess-podium')),
            'save_results_success' => array('success', __('Results saved successfully.', 'chess-podium')),
            'save_results_error' => array('error', __('Unable to save results.', 'chess-podium')),
            'save_results_no_data' => array('warning', __('No results to save.', 'chess-podium')),
            'save_pairings_success' => array('success', __('Pairings updated successfully.', 'chess-podium')),
            'save_pairings_error' => array('error', __('Unable to save pairings. Check that each player appears only once per round.', 'chess-podium')),
            'save_pairings_no_data' => array('warning', __('No pairings to save.', 'chess-podium')),
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
            'registration_settings_saved' => array('success', __('Registration settings saved.', 'chess-podium')),
            'status_save_success' => array('success', __('Player statuses saved.', 'chess-podium')),
            'status_save_error' => array('error', __('Unable to save player statuses.', 'chess-podium')),
            'brochure_saved' => array('success', __('Tournament brochure saved.', 'chess-podium')),
            'brochure_error' => array('error', __('Unable to save brochure.', 'chess-podium')),
            'pgn_source_added' => array('success', __('PGN live source added. Fetch runs every minute.', 'chess-podium')),
            'pgn_source_deleted' => array('success', __('PGN source removed.', 'chess-podium')),
            'pgn_source_error' => array('error', __('Enter a valid URL for PGN source.', 'chess-podium')),
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

    public static function compute_standings(int $tournamentId): array
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
            $res = self::normalize_result((string) ($match->result ?? ''));
            $isBye = (int) $match->is_bye === 1;

            if ($isBye) {
                $pts = isset($match->bye_points) && $match->bye_points !== null ? (float) $match->bye_points : $byePoints;
                $scores[$w] += $pts;
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
        $startRank = 0;
        foreach ($players as $p) {
            $pid = (int) $p->id;
            $startRank++;
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
                'title' => isset($p->title) && $p->title !== '' ? (string) $p->title : null,
                'country' => isset($p->country) ? (string) $p->country : '',
                'fide_id' => isset($p->fide_id) ? (string) $p->fide_id : '',
                'rating' => (int) $p->rating,
                'points' => $scores[$pid] ?? 0.0,
                'buchholz' => $buchholz,
                'sb' => $sb,
                'start_rank' => $startRank,
            ];
        }

        $tiebreakers = ($tournament && isset($tournament->tiebreakers)) ? explode(',', (string) $tournament->tiebreakers) : ['buchholz', 'sb', 'rating'];
        $tiebreakers = array_map('trim', array_map('strtolower', $tiebreakers));

        usort($rows, static function ($a, $b) use ($tiebreakers) {
            if ($b['points'] !== $a['points']) {
                return $b['points'] <=> $a['points'];
            }
            // FIDE Dutch: within score group, use starting rank first for pairing
            $srA = $a['start_rank'] ?? PHP_INT_MAX;
            $srB = $b['start_rank'] ?? PHP_INT_MAX;
            if ($srA !== $srB) {
                return $srA <=> $srB;
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
            return $srA <=> $srB;
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

        if ($tournamentId <= 0 || !in_array($exportType, array('turns', 'standings', 'trf', 'brochure_pdf', 'brochure_html'), true)) {
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

        if ($exportType === 'trf') {
            self::output_trf($tournament);
            return;
        }

        if ($exportType === 'brochure_pdf' || $exportType === 'brochure_html') {
            self::output_brochure($tournament, $exportType === 'brochure_pdf');
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

    private static function output_brochure(object $tournament, bool $asPdf): void
    {
        if ($asPdf) {
            $pdf = ChessPodium_PdfBrochure::generate_pdf($tournament);
            if ($pdf && strlen($pdf) > 0) {
                $filename = 'bando-' . sanitize_title((string) $tournament->name) . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Pragma: no-cache');
                header('Expires: 0');
                header('Content-Length: ' . strlen($pdf));
                echo $pdf;
                exit;
            }
        }
        $html = ChessPodium_PdfBrochure::generate_html($tournament);
        if ($asPdf) {
            $notice = '<div style="background:#fff3cd;border:1px solid #ffc107;padding:12px;margin-bottom:20px;border-radius:4px;"><strong>' . esc_html__('PDF not available', 'chess-podium') . '</strong> ' . esc_html__('Run "composer install" in the plugin directory to enable PDF download. HTML file provided instead.', 'chess-podium') . '</div>';
            $html = preg_replace('/(<body[^>]*>)/', '$1' . $notice, $html, 1);
        }
        $filename = 'bando-' . sanitize_title((string) $tournament->name) . '.html';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $html;
        exit;
    }

    private static function output_trf(object $tournament): void
    {
        $content = ChessPodium_TrfExport::generate_trf((int) $tournament->id);
        $filename = 'chess-podium-' . sanitize_title((string) $tournament->name) . '.trf';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $content;
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