<?php
/**
 * Club Ranking & Hall of Fame - Aggregate tournament data
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_ClubRanking
{
    /**
     * Get club standings aggregated from all published tournaments.
     *
     * @return array List of [player_key, name, fide_id, total_points, tournaments, wins, ...]
     */
    public static function get_club_standings(): array
    {
        $tournaments = self::get_published_tournaments();
        $playerData = [];

        foreach ($tournaments as $t) {
            if (!empty($t['external'])) {
                continue;
            }
            $tid = (int) ($t['id'] ?? $t->id ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $standings = ChessPodiumPlugin::compute_standings($tid);
            foreach ($standings as $s) {
                $key = self::player_key($s['name'] ?? '', $s['fide_id'] ?? '');
                if (!isset($playerData[$key])) {
                    $playerData[$key] = [
                        'player_key' => $key,
                        'name' => $s['name'] ?? '',
                        'fide_id' => $s['fide_id'] ?? '',
                        'country' => $s['country'] ?? '',
                        'total_points' => 0.0,
                        'tournaments' => 0,
                        'wins' => 0,
                        'draws' => 0,
                        'losses' => 0,
                    ];
                }
                $playerData[$key]['total_points'] += (float) ($s['points'] ?? 0);
                $playerData[$key]['tournaments']++;
            }
        }

        $players = array_values($playerData);
        usort($players, static function ($a, $b) {
            return $b['total_points'] <=> $a['total_points'];
        });

        return $players;
    }

    /**
     * Get player profile with tournament history.
     *
     * @param string $identifier FIDE ID or player name.
     * @return array|null Profile data or null.
     */
    public static function get_player_profile(string $identifier): ?array
    {
        $tournaments = self::get_published_tournaments();
        $profile = null;
        $history = [];

        foreach ($tournaments as $t) {
            if (!empty($t['external'])) {
                continue;
            }
            $tid = (int) ($t['id'] ?? $t->id ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $standings = ChessPodiumPlugin::compute_standings($tid);
            $tName = (string) ($t['name'] ?? $t->name ?? '');
            foreach ($standings as $pos => $s) {
                $match = false;
                if (!empty($s['fide_id']) && (string) $s['fide_id'] === $identifier) {
                    $match = true;
                } elseif (strcasecmp(trim((string) $s['name']), trim($identifier)) === 0) {
                    $match = true;
                }
                if ($match) {
                    if (!$profile) {
                        $profile = [
                            'name' => $s['name'] ?? '',
                            'fide_id' => $s['fide_id'] ?? '',
                            'country' => $s['country'] ?? '',
                        ];
                    }
                    $history[] = [
                        'tournament_id' => $tid,
                        'tournament_name' => $tName,
                        'position' => $pos + 1,
                        'points' => (float) ($s['points'] ?? 0),
                    ];
                }
            }
        }

        if (!$profile) {
            return null;
        }

        $profile['history'] = $history;
        $profile['total_tournaments'] = count($history);
        $profile['total_points'] = array_sum(array_column($history, 'points'));
        return $profile;
    }

    private static function player_key(string $name, string $fideId): string
    {
        $name = trim($name);
        $fideId = trim($fideId);
        if ($fideId !== '') {
            return 'fide:' . $fideId;
        }
        return 'name:' . mb_strtolower($name);
    }

    private static function get_published_tournaments(): array
    {
        if (!class_exists('ChessPodiumPlugin')) {
            return [];
        }
        return ChessPodiumPlugin::get_published_tournaments();
    }
}
