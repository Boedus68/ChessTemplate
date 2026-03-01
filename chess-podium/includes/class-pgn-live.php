<?php
/**
 * PGN Live Stream - URL/FTP sources for automatic PGN fetch
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_PgnLive
{
    public const CRON_HOOK = 'chess_podium_fetch_pgn_sources';

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'fetch_all_sources']);
        add_action('init', [self::class, 'schedule_cron']);
        add_filter('cron_schedules', [self::class, 'add_cron_interval']);
    }

    public static function add_cron_interval(array $schedules): array
    {
        $schedules['chess_podium_pgn_interval'] = [
            'interval' => 60,
            'display' => __('Every minute (PGN Live)', 'chess-podium'),
        ];
        return $schedules;
    }

    public static function schedule_cron(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }
        wp_schedule_event(time(), 'chess_podium_pgn_interval', self::CRON_HOOK);
    }

    public static function fetch_all_sources(): void
    {
        $sources = self::get_all_sources();
        foreach ($sources as $src) {
            self::fetch_source($src);
        }
    }

    public static function fetch_source(object $source): int
    {
        $url = (string) ($source->source_url ?? '');
        if ($url === '') {
            return 0;
        }
        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            return 0;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return 0;
        }
        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return 0;
        }
        $tid = (int) $source->tournament_id;
        $round = (int) ($source->round_no ?? 1);
        $count = self::import_pgn_content($tid, $body, $round);
        if ($count > 0) {
            self::update_last_fetched((int) $source->id);
        }
        return $count;
    }

    private static function import_pgn_content(int $tournamentId, string $content, int $round): int
    {
        if (!class_exists('ChessPodiumPlugin')) {
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

    private static function insert_pgn(int $tid, int $round, string $content, ?string $white, ?string $black, ?string $result): void
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'regina_tournament_pgns',
            [
                'tournament_id' => $tid,
                'round_no' => $round,
                'white_name' => $white,
                'black_name' => $black,
                'result' => $result,
                'pgn_content' => $content,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private static function update_last_fetched(int $sourceId): void
    {
        global $wpdb;
        $table = self::db_table();
        $wpdb->update($table, ['last_fetched' => current_time('mysql')], ['id' => $sourceId], ['%s'], ['%d']);
    }

    public static function get_all_sources(): array
    {
        global $wpdb;
        $table = self::db_table();
        return $wpdb->get_results("SELECT * FROM $table WHERE source_url != '' AND source_url IS NOT NULL ORDER BY id ASC");
    }

    public static function get_sources_for_tournament(int $tournamentId): array
    {
        global $wpdb;
        $table = self::db_table();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE tournament_id = %d ORDER BY id ASC", $tournamentId));
    }

    public static function add_source(int $tournamentId, string $url, int $round = 1, int $intervalSec = 60): int
    {
        global $wpdb;
        $wpdb->insert(self::db_table(), [
            'tournament_id' => $tournamentId,
            'source_type' => 'url',
            'source_url' => $url,
            'round_no' => $round,
            'fetch_interval_sec' => $intervalSec,
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%d', '%d', '%s']);
        return (int) $wpdb->insert_id;
    }

    public static function delete_source(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(self::db_table(), ['id' => $id], ['%d']);
    }

    private static function db_table(): string
    {
        return $GLOBALS['wpdb']->prefix . 'regina_pgn_sources';
    }

    public static function create_table(): void
    {
        global $wpdb;
        $table = self::db_table();
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return;
        }
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tournament_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(20) NOT NULL DEFAULT 'url',
            source_url VARCHAR(500) NULL,
            round_no INT NOT NULL DEFAULT 1,
            pairing_id BIGINT UNSIGNED NULL,
            last_fetched DATETIME NULL,
            fetch_interval_sec INT NOT NULL DEFAULT 60,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tournament_id (tournament_id)
        ) $charset";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
