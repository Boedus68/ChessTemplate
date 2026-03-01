<?php
/**
 * License validation for Chess Podium Pro
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_License
{
    private const OPTION_KEY = 'chess_podium_license';
    private const CACHE_KEY = 'chess_podium_license_valid';
    private const CACHE_HOURS = 24;
    /** Free tier: max players per tournament. Pro removes this limit. */
    private const FREE_PLAYER_LIMIT = 10;
    private const API_URL = 'https://www.chesspodium.com/wp-json/chess-podium/v1/validate-license';

    public static function is_pro(): bool
    {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached === '1') {
            return true;
        }
        if ($cached === '0') {
            return false;
        }

        $data = get_option(self::OPTION_KEY, []);
        $key = isset($data['key']) ? trim((string) $data['key']) : '';
        if ($key === '') {
            set_transient(self::CACHE_KEY, '0', HOUR_IN_SECONDS);
            return false;
        }

        $valid = self::validate_remote($key, isset($data['email']) ? (string) $data['email'] : '');
        set_transient(self::CACHE_KEY, $valid ? '1' : '0', $valid ? self::CACHE_HOURS * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return $valid;
    }

    public static function get_free_player_limit(): int
    {
        return self::FREE_PLAYER_LIMIT;
    }

    public static function can_add_players(int $currentCount, int $countToAdd = 1): bool
    {
        if (self::is_pro()) {
            return true;
        }
        return ($currentCount + $countToAdd) <= self::FREE_PLAYER_LIMIT;
    }

    public static function get_remaining_slots(int $currentCount): int
    {
        if (self::is_pro()) {
            return PHP_INT_MAX;
        }
        return max(0, self::FREE_PLAYER_LIMIT - $currentCount);
    }

    private static function validate_remote(string $key, string $email): bool
    {
        $result = self::validate_remote_full($key, $email);
        return $result['valid'];
    }

    /**
     * Validate license and return full result with reason on failure.
     *
     * @return array{valid: bool, reason?: string}
     */
    public static function validate_remote_full(string $key, string $email): array
    {
        $url = apply_filters('chess_podium_license_api_url', self::API_URL);
        $response = wp_remote_post($url, [
            'timeout' => 15,
            'body' => [
                'license_key' => $key,
                'email' => $email,
                'domain' => parse_url(home_url(), PHP_URL_HOST) ?: '',
                'action' => 'validate',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['valid' => false, 'reason' => 'network_error', 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['valid' => false, 'reason' => 'api_error', 'message' => sprintf(__('API returned HTTP %d', 'chess-podium'), $code)];
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            return ['valid' => false, 'reason' => 'invalid_response'];
        }
        if (isset($decoded['valid']) && $decoded['valid'] === true) {
            return ['valid' => true];
        }
        $result = ['valid' => false, 'reason' => $decoded['reason'] ?? 'invalid'];
        if (isset($decoded['allowed_domain'])) {
            $result['allowed_domain'] = $decoded['allowed_domain'];
        }
        return $result;
    }

    public static function save_license(string $key, string $email = ''): bool
    {
        update_option(self::OPTION_KEY, [
            'key' => $key,
            'email' => $email,
            'saved_at' => time(),
        ]);
        delete_transient(self::CACHE_KEY);
        return true;
    }

    public static function remove_license(): void
    {
        delete_option(self::OPTION_KEY);
        delete_transient(self::CACHE_KEY);
    }

    public static function get_license_data(): array
    {
        return get_option(self::OPTION_KEY, []);
    }

    /** Called by daily cron to force revalidation. */
    public static function force_revalidate(): void
    {
        delete_transient(self::CACHE_KEY);
    }
}
