<?php
/**
 * Player Status - Bye & Withdrawals management
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_PlayerStatus
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_BYE_REQUESTED = 'bye_requested';
    public const STATUS_BYE_FULL = 'bye_full';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public static function get_status(int $tournamentId, int $playerId, int $roundNo): ?string
    {
        global $wpdb;
        $table = self::db_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM $table WHERE tournament_id = %d AND player_id = %d AND round_no = %d",
            $tournamentId,
            $playerId,
            $roundNo
        ));
        return $row ? (string) $row->status : null;
    }

    public static function set_status(int $tournamentId, int $playerId, int $roundNo, string $status): bool
    {
        global $wpdb;
        $table = self::db_table();
        $valid = [self::STATUS_PRESENT, self::STATUS_BYE_REQUESTED, self::STATUS_BYE_FULL, self::STATUS_WITHDRAWN];
        if (!in_array($status, $valid, true)) {
            return false;
        }
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE tournament_id = %d AND player_id = %d AND round_no = %d",
            $tournamentId,
            $playerId,
            $roundNo
        ));
        $data = [
            'tournament_id' => $tournamentId,
            'player_id' => $playerId,
            'round_no' => $roundNo,
            'status' => $status,
        ];
        if ($existing) {
            return (bool) $wpdb->update($table, $data, ['id' => (int) $existing], ['%d', '%d', '%d', '%s'], ['%d']);
        }
        $data['created_at'] = current_time('mysql');
        return (bool) $wpdb->insert($table, $data, ['%d', '%d', '%d', '%s', '%s']);
    }

    public static function get_all_for_round(int $tournamentId, int $roundNo): array
    {
        global $wpdb;
        $table = self::db_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT player_id, status FROM $table WHERE tournament_id = %d AND round_no = %d",
            $tournamentId,
            $roundNo
        ));
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->player_id] = (string) $r->status;
        }
        return $map;
    }

    public static function get_withdrawn_before_round(int $tournamentId, int $roundNo): array
    {
        global $wpdb;
        $table = self::db_table();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT player_id FROM $table WHERE tournament_id = %d AND round_no <= %d AND status = %s",
            $tournamentId,
            $roundNo,
            self::STATUS_WITHDRAWN
        ));
        return array_map('intval', (array) $rows);
    }

    public static function get_bye_requested_for_round(int $tournamentId, int $roundNo): array
    {
        global $wpdb;
        $table = self::db_table();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT player_id FROM $table WHERE tournament_id = %d AND round_no = %d AND status = %s",
            $tournamentId,
            $roundNo,
            self::STATUS_BYE_REQUESTED
        ));
        return array_map('intval', (array) $rows);
    }

    public static function save_bulk_statuses(int $tournamentId, int $roundNo, array $statuses): void
    {
        foreach ($statuses as $playerId => $status) {
            $playerId = (int) $playerId;
            if ($playerId > 0 && is_string($status)) {
                self::set_status($tournamentId, $playerId, $roundNo, $status);
            }
        }
    }

    private static function db_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'regina_player_status';
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
            player_id BIGINT UNSIGNED NOT NULL,
            round_no INT NOT NULL,
            status VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY tournament_player_round (tournament_id, player_id, round_no),
            KEY tournament_round (tournament_id, round_no)
        ) $charset";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
