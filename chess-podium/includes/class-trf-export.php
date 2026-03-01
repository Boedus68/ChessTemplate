<?php
/**
 * FIDE TRF Export - Tournament Report File (TRF16 format)
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_TrfExport
{
    /**
     * Generate TRF16 format content for tournament.
     *
     * @param int $tournamentId Tournament ID.
     * @return string TRF file content.
     */
    public static function generate_trf(int $tournamentId): string
    {
        $tournament = ChessPodium_TrfExport::get_tournament($tournamentId);
        if (!$tournament) {
            return '';
        }

        $players = ChessPodium_TrfExport::get_players($tournamentId);
        $standings = ChessPodium_TrfExport::compute_standings($tournamentId);
        $pairings = ChessPodium_TrfExport::get_all_pairings($tournamentId);
        $roundsTotal = (int) $tournament->current_round ?: (int) $tournament->rounds_total;

        $lines = [];

        $playerRankMap = [];
        foreach ($standings as $idx => $s) {
            $playerRankMap[(int) $s['player_id']] = $idx + 1;
        }

        $playerIdToStartRank = [];
        $startRank = 1;
        foreach ($players as $p) {
            $playerIdToStartRank[(int) $p->id] = $startRank++;
        }

        $roundResults = self::build_round_results($players, $pairings, $playerIdToStartRank);

        foreach ($standings as $idx => $s) {
            $pid = (int) $s['player_id'];
            $player = self::find_player($players, $pid);
            if (!$player) {
                continue;
            }
            $line = self::format_player_record(
                $player,
                $idx + 1,
                (float) $s['points'],
                $roundsTotal,
                $roundResults[$pid] ?? [],
                $playerIdToStartRank
            );
            $lines[] = $line;
        }

        $tournamentLine = self::format_tournament_record($tournament, count($players), $roundsTotal);
        array_unshift($lines, $tournamentLine);

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Generate TRF(x) for bbpPairings/JaVaFo pairing input.
     * Current round: blank for playing, "0000 - -" for absent/withdrawn, "0000 - =" for bye.
     * XXR extension added for total rounds.
     *
     * @param int $tournamentId Tournament ID.
     * @param int $roundToPair Round number to pair (e.g. 2 for pairing round 2).
     * @param array<int> $absentForRound Player IDs not playing (withdrawn).
     * @param array<int> $byeForRound Player IDs with half-point bye.
     * @return array{trf: string, startRankToPlayerId: array<int,int>} TRF content and ID mapping.
     */
    public static function generate_trf_for_pairing(int $tournamentId, int $roundToPair, array $absentForRound = [], array $byeForRound = []): array
    {
        $tournament = self::get_tournament($tournamentId);
        if (!$tournament) {
            return ['trf' => '', 'startRankToPlayerId' => []];
        }

        $players = self::get_players($tournamentId);
        $standings = self::compute_standings($tournamentId);
        $pairings = self::get_all_pairings($tournamentId);
        $roundsTotal = (int) ($tournament->rounds_total ?: 9);

        usort($players, static function ($a, $b) {
            return ((int) ($b->rating ?? 0)) <=> ((int) ($a->rating ?? 0));
        });

        $playerIdToStartRank = [];
        foreach ($players as $i => $p) {
            $playerIdToStartRank[(int) $p->id] = $i + 1;
        }

        $roundResults = self::build_round_results($players, $pairings, $playerIdToStartRank);

        foreach ($absentForRound as $pid) {
            $roundResults[$pid] = $roundResults[$pid] ?? [];
            $roundResults[$pid][$roundToPair] = ['opp' => 0, 'color' => '-', 'result' => '-'];
        }
        foreach ($byeForRound as $pid) {
            $roundResults[$pid] = $roundResults[$pid] ?? [];
            $roundResults[$pid][$roundToPair] = ['opp' => 0, 'color' => '-', 'result' => '='];
        }

        $lines = [];
        foreach ($standings as $idx => $s) {
            $pid = (int) $s['player_id'];
            $player = self::find_player($players, $pid);
            if (!$player) {
                continue;
            }
            $line = self::format_player_record(
                $player,
                $idx + 1,
                (float) $s['points'],
                $roundsTotal,
                $roundResults[$pid] ?? [],
                $playerIdToStartRank
            );
            $lines[] = $line;
        }

        $tournamentLine = self::format_tournament_record($tournament, count($players), $roundsTotal);
        array_unshift($lines, $tournamentLine);
        $lines[] = 'XXR ' . $roundsTotal;

        $startRankToPlayerId = [];
        foreach ($standings as $idx => $s) {
            $pid = (int) $s['player_id'];
            $pairingId = $playerIdToStartRank[$pid] ?? ($idx + 1);
            $startRankToPlayerId[$pairingId] = $pid;
        }

        return ['trf' => implode("\r\n", $lines) . "\r\n", 'startRankToPlayerId' => $startRankToPlayerId];
    }

    private static function get_tournament(int $id): ?object
    {
        if (!class_exists('ChessPodiumPlugin')) {
            return null;
        }
        return ChessPodiumPlugin::get_tournament($id);
    }

    private static function get_players(int $tournamentId): array
    {
        if (!class_exists('ChessPodiumPlugin')) {
            return [];
        }
        return ChessPodiumPlugin::get_players($tournamentId);
    }

    private static function compute_standings(int $tournamentId): array
    {
        if (!class_exists('ChessPodiumPlugin')) {
            return [];
        }
        return ChessPodiumPlugin::compute_standings($tournamentId);
    }

    private static function get_all_pairings(int $tournamentId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'regina_pairings';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE tournament_id = %d ORDER BY round_no ASC, id ASC",
            $tournamentId
        ));
    }

    private static function find_player(array $players, int $id): ?object
    {
        foreach ($players as $p) {
            if ((int) $p->id === $id) {
                return $p;
            }
        }
        return null;
    }

    private static function build_round_results(array $players, array $pairings, array $playerIdToStartRank): array
    {
        $results = [];
        foreach ($players as $p) {
            $results[(int) $p->id] = [];
        }

        foreach ($pairings as $m) {
            $wId = (int) $m->white_player_id;
            $bId = (int) $m->black_player_id;
            $round = (int) $m->round_no;
            $isBye = (int) $m->is_bye === 1;
            $res = class_exists('ChessPodiumPlugin') ? ChessPodiumPlugin::normalize_result((string) ($m->result ?? '')) : trim((string) ($m->result ?? ''));

            if ($isBye) {
                $results[$wId][$round] = ['opp' => 0, 'color' => 'w', 'result' => '1'];
                continue;
            }

            $wRank = $playerIdToStartRank[$wId] ?? 0;
            $bRank = $playerIdToStartRank[$bId] ?? 0;

            if ($res === '1-0') {
                $results[$wId][$round] = ['opp' => $bRank, 'color' => 'w', 'result' => '1'];
                $results[$bId][$round] = ['opp' => $wRank, 'color' => 'b', 'result' => '0'];
            } elseif ($res === '0-1') {
                $results[$wId][$round] = ['opp' => $bRank, 'color' => 'w', 'result' => '0'];
                $results[$bId][$round] = ['opp' => $wRank, 'color' => 'b', 'result' => '1'];
            } elseif ($res === '1/2-1/2') {
                $results[$wId][$round] = ['opp' => $bRank, 'color' => 'w', 'result' => '='];
                $results[$bId][$round] = ['opp' => $wRank, 'color' => 'b', 'result' => '='];
            } else {
                $results[$wId][$round] = ['opp' => $bRank, 'color' => 'w', 'result' => '-'];
                $results[$bId][$round] = ['opp' => $wRank, 'color' => 'b', 'result' => '-'];
            }
        }
        return $results;
    }

    private static function format_player_record(
        object $player,
        int $rank,
        float $points,
        int $roundsTotal,
        array $roundResults,
        array $playerIdToStartRank
    ): string {
        $pid = (int) $player->id;
        $startRank = $playerIdToStartRank[$pid] ?? $rank;
        $sex = isset($player->sex) && in_array((string) $player->sex, ['m', 'w'], true) ? (string) $player->sex : ' ';
        $title = isset($player->title) ? self::pad_str((string) $player->title, 3) : '   ';
        $name = self::format_trf_name((string) $player->name);
        $name = self::pad_str($name, 33);
        $rating = isset($player->rating) ? sprintf('%4d', min(9999, max(0, (int) $player->rating))) : '   0';
        $fed = isset($player->country) ? self::pad_str(strtoupper(substr((string) $player->country, 0, 3)), 3) : '   ';
        $fideId = isset($player->fide_id) && (string) $player->fide_id !== '' ? self::pad_str((string) $player->fide_id, 11) : '          0';
        $birth = isset($player->birth_date) && (string) $player->birth_date !== '' ? self::format_birth_date((string) $player->birth_date) : '          ';
        $ptsStr = sprintf('%4.1f', min(99.9, max(0, $points)));
        $rankStr = sprintf('%4d', $rank);

        $roundPart = '';
        for ($r = 1; $r <= $roundsTotal; $r++) {
            $res = $roundResults[$r] ?? null;
            if ($res) {
                if ($res['opp'] === 0 && ($res['color'] ?? '') === '-') {
                    $roundPart .= '0000 - ' . $res['result'];
                } else {
                    $opp = sprintf('%4d', $res['opp']);
                    $color = $res['color'] === 'w' ? 'w' : 'b';
                    $roundPart .= $opp . $color . $res['result'];
                }
            } else {
                $roundPart .= '     -';
            }
        }

        return '001 ' . sprintf('%4d', $startRank) . ' ' . $sex . $title . ' ' . $name . ' ' . $rating . ' ' . $fed . ' ' . $fideId . ' ' . $birth . ' ' . $ptsStr . ' ' . $rankStr . '  ' . $roundPart;
    }

    private static function format_tournament_record(object $tournament, int $playerCount, int $roundsTotal): string
    {
        $name = self::pad_str((string) $tournament->name, 90);
        $city = isset($tournament->city) ? self::pad_str((string) $tournament->city, 25) : self::pad_str('', 25);
        $fed = isset($tournament->federation) ? self::pad_str(strtoupper(substr((string) $tournament->federation, 0, 3)), 3) : '   ';
        $startDate = isset($tournament->start_date) ? self::format_trf_date((string) $tournament->start_date) : date('Y/m/d');
        $endDate = isset($tournament->end_date) ? self::format_trf_date((string) $tournament->end_date) : $startDate;
        $arbiter = isset($tournament->chief_arbiter) ? self::pad_str((string) $tournament->chief_arbiter, 40) : self::pad_str('', 40);
        $deputy = isset($tournament->deputy_arbiter) ? self::pad_str((string) $tournament->deputy_arbiter, 40) : self::pad_str('', 40);
        $timeControl = isset($tournament->time_control) ? self::pad_str((string) $tournament->time_control, 20) : self::pad_str('', 20);

        return '012 ' . $name . ' ' . $city . ' ' . $fed . ' ' . $startDate . ' ' . $endDate . ' ' .
            sprintf('%4d', $playerCount) . ' ' . sprintf('%4d', $playerCount) . '    0 ' .
            '  ' . $arbiter . ' ' . $deputy . ' ' . $timeControl;
    }

    private static function format_trf_name(string $name): string
    {
        $parts = array_map('trim', explode(',', $name, 2));
        if (count($parts) === 2) {
            return $parts[0] . ', ' . $parts[1];
        }
        $words = preg_split('/\s+/', trim($name), 2);
        if (count($words) >= 2) {
            return $words[1] . ', ' . $words[0];
        }
        return $name;
    }

    private static function format_birth_date(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return '          ';
        }
        return date('Y/m/d', $ts);
    }

    private static function format_trf_date(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return date('Y/m/d');
        }
        return date('Y/m/d', $ts);
    }

    private static function pad_str(string $s, int $len): string
    {
        $s = mb_substr($s, 0, $len);
        return str_pad($s, $len, ' ', STR_PAD_RIGHT);
    }
}
