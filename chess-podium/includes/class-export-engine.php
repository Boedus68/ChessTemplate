<?php
/**
 * Export Engine - Generates static HTML for tournament publication
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_ExportEngine
{
    public static function get_export_base_dir(): string
    {
        $uploadDir = wp_upload_dir();
        $base = $uploadDir['basedir'] . '/chess-podium';
        if (!file_exists($base)) {
            wp_mkdir_p($base);
        }
        return $base;
    }

    public static function get_export_url_base(): string
    {
        $uploadDir = wp_upload_dir();
        return $uploadDir['baseurl'] . '/chess-podium';
    }

    public static function get_tournament_slug(object $tournament): string
    {
        $base = sanitize_title((string) $tournament->name);
        return $base ?: 'torneo-' . (int) $tournament->id;
    }

    public static function get_export_dir(object $tournament): string
    {
        return self::get_export_base_dir() . '/' . self::get_tournament_slug($tournament);
    }

    public static function get_export_url(object $tournament): string
    {
        return self::get_export_url_base() . '/' . self::get_tournament_slug($tournament);
    }

    public static function generate_export(object $tournament, array $players, array $standings, array $allPairings, array $photos, array $pgns): array
    {
        $dir = self::get_export_dir($tournament);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $slug = self::get_tournament_slug($tournament);
        $baseUrl = self::get_export_url($tournament);
        $roundsTotal = (int) $tournament->current_round;

        $brochurePdf = class_exists('ChessPodium_PdfBrochure') ? ChessPodium_PdfBrochure::generate_pdf($tournament) : null;
        $hasBrochure = $brochurePdf && strlen($brochurePdf) > 0;
        if ($hasBrochure) {
            self::write_file($dir . '/bando.pdf', $brochurePdf);
        }

        $playerStats = self::compute_player_stats($players, $allPairings);
        $navHtml = self::build_nav_html($slug, $roundsTotal, $hasBrochure);

        $indexHtml = self::build_index_html($tournament, $players, $standings, $navHtml, $playerStats, $hasBrochure);
        self::write_file($dir . '/index.html', $indexHtml);

        $standingHtml = self::build_standing_html($tournament, $standings, $navHtml, $playerStats);
        self::write_file($dir . '/standing.html', $standingHtml);

        for ($r = 1; $r <= $roundsTotal; $r++) {
            $roundPairings = array_filter($allPairings, static fn ($p) => (int) $p->round_no === $r);
            $pairsHtml = self::build_pairs_html($tournament, $roundPairings, $r, $navHtml, $playerStats);
            self::write_file($dir . '/pairs' . $r . '.html', $pairsHtml);
        }

        $gamesHtml = self::build_games_html($tournament, $allPairings, $pgns, $navHtml);
        self::write_file($dir . '/games.html', $gamesHtml);

        $galleryHtml = self::build_gallery_html($tournament, $photos, $navHtml);
        self::write_file($dir . '/galleria.html', $galleryHtml);

        self::write_css($dir . '/style.css');

        return [
            'success' => true,
            'url' => $baseUrl . '/index.html',
            'dir' => $dir,
        ];
    }

    private static function compute_player_stats(array $players, array $allPairings): array
    {
        $playerMap = [];
        foreach ($players as $p) {
            $playerMap[(int) $p->id] = [
                'name' => (string) $p->name,
                'country' => isset($p->country) ? (string) $p->country : '',
                'rating' => (int) $p->rating,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'byes' => 0,
                'bye_points_sum' => 0.0,
                'opponents' => [],
            ];
        }

        $byePointsDefault = 1.0;
        foreach ($allPairings as $m) {
            $wId = (int) $m->white_player_id;
            $bId = (int) $m->black_player_id;
            $res = (string) ($m->result ?? '');
            $isBye = (int) $m->is_bye === 1;
            $round = (int) $m->round_no;

            if ($isBye) {
                if (isset($playerMap[$wId])) {
                    $playerMap[$wId]['byes']++;
                    $pts = isset($m->bye_points) && $m->bye_points !== null ? (float) $m->bye_points : $byePointsDefault;
                    $playerMap[$wId]['bye_points_sum'] += $pts;
                    $playerMap[$wId]['opponents'][] = [
                        'round' => $round,
                        'opponent' => 'BYE',
                        'color' => 'white',
                        'result' => '1',
                        'opponent_rating' => 0,
                    ];
                }
                continue;
            }

            $wName = (string) ($m->white_name ?? '');
            $bName = (string) ($m->black_name ?? '');
            $bRating = $playerMap[$bId]['rating'] ?? 0;
            $wRating = $playerMap[$wId]['rating'] ?? 0;

            $wResult = ($res === '1-0') ? '1' : (($res === '0-1') ? '0' : (($res === '1/2-1/2') ? '½' : '-'));
            $bResult = ($res === '0-1') ? '1' : (($res === '1-0') ? '0' : (($res === '1/2-1/2') ? '½' : '-'));

            if (isset($playerMap[$wId])) {
                $playerMap[$wId]['opponents'][] = [
                    'round' => $round,
                    'opponent' => $bName,
                    'color' => 'white',
                    'result' => $wResult,
                    'opponent_rating' => $bRating,
                ];
                if ($res === '1-0') {
                    $playerMap[$wId]['wins']++;
                } elseif ($res === '0-1') {
                    $playerMap[$wId]['losses']++;
                } elseif ($res === '1/2-1/2') {
                    $playerMap[$wId]['draws']++;
                }
            }
            if (isset($playerMap[$bId])) {
                $playerMap[$bId]['opponents'][] = [
                    'round' => $round,
                    'opponent' => $wName,
                    'color' => 'black',
                    'result' => $bResult,
                    'opponent_rating' => $wRating,
                ];
                if ($res === '0-1') {
                    $playerMap[$bId]['wins']++;
                } elseif ($res === '1-0') {
                    $playerMap[$bId]['losses']++;
                } elseif ($res === '1/2-1/2') {
                    $playerMap[$bId]['draws']++;
                }
            }
        }

        $result = [];
        foreach ($playerMap as $pid => $stats) {
            $n = $stats['wins'] + $stats['draws'] + $stats['losses'];
            $byePts = ($stats['byes'] > 0 && isset($stats['bye_points_sum'])) ? $stats['bye_points_sum'] : ($byePointsDefault * $stats['byes']);
            $points = $stats['wins'] + 0.5 * $stats['draws'] + $byePts;
            $perf = $stats['rating'];
            $eloVar = self::compute_elo_variation_fide($stats);
            if ($n > 0) {
                $perf = $stats['rating'] + 400 * ($stats['wins'] - $stats['losses']) / $n;
            }
            $result[$pid] = [
                'name' => $stats['name'],
                'rating' => $stats['rating'],
                'wins' => $stats['wins'],
                'draws' => $stats['draws'],
                'losses' => $stats['losses'],
                'byes' => $stats['byes'],
                'points' => $points,
                'opponents' => $stats['opponents'],
                'performance' => (int) round($perf),
                'elo_variation' => $eloVar,
            ];
        }
        return $result;
    }

    /** FIDE Elo variation: sum of K*(S-E) per game. K=40 (<2300), K=20 (2300-2399), K=10 (>=2400). */
    private static function compute_elo_variation_fide(array $stats): int
    {
        $rating = (int) ($stats['rating'] ?? 0);
        $k = $rating >= 2400 ? 10 : ($rating < 2300 ? 40 : 20);
        $totalDelta = 0.0;

        foreach ($stats['opponents'] ?? [] as $opp) {
            if (($opp['opponent'] ?? '') === 'BYE') {
                continue;
            }
            $oppRating = (int) ($opp['opponent_rating'] ?? 0);
            $res = $opp['result'] ?? '-';
            if ($res === '-' || $res === '') {
                continue;
            }
            $s = ($res === '1' || $res === 1) ? 1.0 : (($res === '½' || $res === 0.5) ? 0.5 : 0.0);
            $e = 1.0 / (1.0 + pow(10, ($oppRating - $rating) / 400.0));
            $totalDelta += $k * ($s - $e);
        }

        return (int) round($totalDelta);
    }

    private static function player_link(string $name, int $playerId, array $playerStats, string $country = '', ?string $title = null): string
    {
        $flag = $country ? ChessPodium_CountryHelper::render_flag_img($country, $country) . ' ' : '';
        $titleStr = $title ? esc_html(trim($title)) . ' ' : '';
        if (!isset($playerStats[$playerId])) {
            return $flag . $titleStr . esc_html($name);
        }
        $slug = 'cp-player-' . $playerId;
        return $flag . $titleStr . '<a href="#' . esc_attr($slug) . '" class="cp-player-link" data-player-id="' . (int) $playerId . '">' . esc_html($name) . '</a>';
    }

    private static function build_player_modal(array $playerStats): string
    {
        $json = json_encode($playerStats);
        $wins = esc_html__('Wins', 'chess-podium');
        $draws = esc_html__('Draws', 'chess-podium');
        $losses = esc_html__('Losses', 'chess-podium');
        $opponents = esc_html__('Opponents', 'chess-podium');
        $round = esc_html__('Round', 'chess-podium');
        $eloVar = esc_html__('Elo variation', 'chess-podium');
        $performance = esc_html__('Performance', 'chess-podium');
        $rating = esc_html__('Rating', 'chess-podium');
        $color = esc_html__('Color', 'chess-podium');
        $result = esc_html__('Result', 'chess-podium');
        $close = esc_attr__('Close', 'chess-podium');

        $html = '
<div id="cp-player-modal" class="cp-player-modal" aria-hidden="true">
    <div class="cp-player-modal-backdrop"></div>
    <div class="cp-player-modal-content">
        <button type="button" class="cp-player-modal-close" aria-label="' . $close . '">&times;</button>
        <div id="cp-player-modal-body"></div>
    </div>
</div>
<script>
(function() {
    var stats = ' . $json . ';
    var modal = document.getElementById("cp-player-modal");
    var body = document.getElementById("cp-player-modal-body");
    var backdrop = modal ? modal.querySelector(".cp-player-modal-backdrop") : null;
    var closeBtn = modal ? modal.querySelector(".cp-player-modal-close") : null;

    function showPlayer(pid) {
        var s = stats[pid];
        if (!s || !body) return;
        var oppRows = "";
        for (var i = 0; i < s.opponents.length; i++) {
            var o = s.opponents[i];
            oppRows += "<tr><td>" + o.round + "</td><td>" + (o.opponent || "-") + "</td><td>" + (o.color || "-") + "</td><td>" + (o.result || "-") + "</td><td>" + (o.opponent_rating || "-") + "</td></tr>";
        }
        var eloStr = s.elo_variation > 0 ? "+" + s.elo_variation : String(s.elo_variation);
        body.innerHTML = "<h3>" + (s.name || "") + "</h3>" +
            "<p><strong>' . $rating . ':</strong> " + (s.rating || 0) + " | <strong>' . $performance . ':</strong> " + (s.performance || s.rating) + " | <strong>' . $eloVar . ':</strong> " + eloStr + "</p>" +
            "<p><strong>' . $wins . ':</strong> " + (s.wins || 0) + " | <strong>' . $draws . ':</strong> " + (s.draws || 0) + " | <strong>' . $losses . ':</strong> " + (s.losses || 0) + (s.byes ? " | BYE: " + s.byes : "") + "</p>" +
            "<h4>' . $opponents . '</h4><table class=\"table\"><thead><tr><th>' . $round . '</th><th>' . $opponents . '</th><th>' . $color . '</th><th>' . $result . '</th><th>Elo</th></tr></thead><tbody>" + oppRows + "</tbody></table>";
        modal.setAttribute("aria-hidden", "false");
        modal.classList.add("open");
        document.body.style.overflow = "hidden";
    }

    function hide() {
        if (modal) {
            modal.setAttribute("aria-hidden", "true");
            modal.classList.remove("open");
            document.body.style.overflow = "";
            history.replaceState(null, "", location.pathname + location.search);
        }
    }

    document.querySelectorAll(".cp-player-link").forEach(function(a) {
        a.addEventListener("click", function(e) {
            e.preventDefault();
            var pid = parseInt(a.getAttribute("data-player-id"), 10);
            if (pid) {
                history.replaceState(null, "", a.getAttribute("href") || "#");
                showPlayer(pid);
            }
        });
    });

    if (backdrop) backdrop.addEventListener("click", hide);
    if (closeBtn) closeBtn.addEventListener("click", hide);
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape" && modal && modal.classList.contains("open")) hide();
    });

    var hash = location.hash;
    if (hash && hash.indexOf("cp-player-") === 1) {
        var pid = parseInt(hash.replace("cp-player-", ""), 10);
        if (pid && stats[pid]) showPlayer(pid);
    }
})();
</script>';
        return $html;
    }

    private static function write_file(string $path, string $content): bool
    {
        return file_put_contents($path, $content) !== false;
    }

    private static function build_nav_html(string $slug, int $roundsTotal, bool $hasBrochure = false): string
    {
        $links = [
            ['index.html', __('Home', 'chess-podium')],
            ['standing.html', __('Standings', 'chess-podium')],
        ];
        if ($hasBrochure) {
            $links[] = ['bando.pdf', __('Brochure', 'chess-podium')];
        }
        for ($r = 1; $r <= $roundsTotal; $r++) {
            $links[] = ['pairs' . $r . '.html', sprintf(__('Round %d', 'chess-podium'), $r)];
        }
        $links[] = ['games.html', __('Games', 'chess-podium')];
        $links[] = ['galleria.html', __('Gallery', 'chess-podium')];

        $items = '';
        foreach ($links as $l) {
            $items .= '<li><a href="' . esc_attr($l[0]) . '"' . (strpos($l[0], '.pdf') !== false ? ' target="_blank" rel="noopener"' : '') . '>' . esc_html($l[1]) . '</a></li>';
        }
        return '<nav class="nav"><ul>' . $items . '</ul></nav>';
    }

    private static function build_index_html(object $tournament, array $players, array $standings, string $navHtml, array $playerStats, bool $hasBrochure = false): string
    {
        $name = esc_html((string) $tournament->name);
        $rows = '';
        foreach ($players as $i => $p) {
            $pid = (int) $p->id;
            $country = isset($p->country) ? (string) $p->country : '';
            $title = isset($p->title) && $p->title !== '' ? (string) $p->title : null;
            $link = self::player_link((string) $p->name, $pid, $playerStats, $country, $title);
            $rows .= '<tr><td>' . ((int) $i + 1) . '</td><td>' . $link . '</td><td>' . ((int) $p->rating) . '</td></tr>';
        }
        $top3 = array_slice($standings, 0, 3);
        $top3Html = '';
        foreach ($top3 as $i => $s) {
            $pid = (int) ($s['player_id'] ?? 0);
            $country = (string) ($s['country'] ?? '');
            $title = isset($s['title']) && $s['title'] !== '' ? (string) $s['title'] : null;
            $link = self::player_link((string) $s['name'], $pid, $playerStats, $country, $title);
            $top3Html .= '<tr><td>' . ((int) $i + 1) . '</td><td>' . $link . '</td><td>' . number_format((float) $s['points'], 1) . '</td></tr>';
        }
        $quickLinks = '<a href="standing.html">' . esc_html__('View full standings', 'chess-podium') . '</a> | <a href="games.html">' . esc_html__('PGN games', 'chess-podium') . '</a> | <a href="galleria.html">' . esc_html__('Photo gallery', 'chess-podium') . '</a>';
        if ($hasBrochure) {
            $quickLinks .= ' | <a href="bando.pdf" target="_blank" rel="noopener"><strong>' . esc_html__('Download brochure (PDF)', 'chess-podium') . '</strong></a>';
        }
        $content = '<h2>' . esc_html__('Players', 'chess-podium') . '</h2><table class="table"><thead><tr><th>#</th><th>' . esc_html__('Name', 'chess-podium') . '</th><th>' . esc_html__('Rating', 'chess-podium') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<h2>Top 3</h2><table class="table"><thead><tr><th>' . esc_html__('Pos', 'chess-podium') . '</th><th>' . esc_html__('Name', 'chess-podium') . '</th><th>' . esc_html__('Pts', 'chess-podium') . '</th></tr></thead><tbody>' . $top3Html . '</tbody></table>' .
            '<p>' . $quickLinks . '</p>';
        $content .= self::build_player_modal($playerStats);
        return self::wrap_html($name, $navHtml, $content);
    }

    private static function build_standing_html(object $tournament, array $standings, string $navHtml, array $playerStats): string
    {
        $name = esc_html((string) $tournament->name);
        $rows = '';
        foreach ($standings as $i => $s) {
            $pid = (int) ($s['player_id'] ?? 0);
            $country = (string) ($s['country'] ?? '');
            $title = isset($s['title']) && $s['title'] !== '' ? (string) $s['title'] : null;
            $link = self::player_link((string) $s['name'], $pid, $playerStats, $country, $title);
            $rows .= '<tr><td>' . ((int) $i + 1) . '</td><td>' . $link . '</td><td>' . number_format((float) $s['points'], 1) . '</td><td>' . number_format((float) $s['buchholz'], 1) . '</td><td>' . number_format((float) $s['sb'], 2) . '</td></tr>';
        }
        $content = '<h2>' . esc_html__('Standings', 'chess-podium') . '</h2><table class="table"><thead><tr><th>' . esc_html__('Pos', 'chess-podium') . '</th><th>' . esc_html__('Player', 'chess-podium') . '</th><th>' . esc_html__('Pts', 'chess-podium') . '</th><th>' . esc_html__('Buchholz', 'chess-podium') . '</th><th>' . esc_html__('SB', 'chess-podium') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $content .= self::build_player_modal($playerStats);
        return self::wrap_html($name . ' - ' . __('Standings', 'chess-podium'), $navHtml, $content);
    }

    private static function build_pairs_html(object $tournament, array $pairings, int $round, string $navHtml, array $playerStats): string
    {
        $name = esc_html((string) $tournament->name);
        $rows = '';
        $idx = 0;
        foreach ($pairings as $p) {
            $idx++;
            $wId = (int) $p->white_player_id;
            $bId = (int) $p->black_player_id;
            $isBye = (int) $p->is_bye === 1;
            $whiteCountry = isset($p->white_country) ? (string) $p->white_country : '';
            $blackCountry = isset($p->black_country) ? (string) $p->black_country : '';
            $whiteTitle = isset($p->white_title) && $p->white_title !== '' ? (string) $p->white_title : null;
            $blackTitle = $isBye ? null : (isset($p->black_title) && $p->black_title !== '' ? (string) $p->black_title : null);
            $whiteLink = self::player_link((string) $p->white_name, $wId, $playerStats, $whiteCountry, $whiteTitle);
            $blackLink = $isBye ? '-' : self::player_link((string) $p->black_name, $bId, $playerStats, $blackCountry, $blackTitle);
            $result = (int) $p->is_bye === 1 ? 'BYE' : esc_html((string) ($p->result ?: '-'));
            $rows .= '<tr><td>' . $idx . '</td><td>' . $whiteLink . '</td><td>' . $blackLink . '</td><td>' . $result . '</td></tr>';
        }
        $content = '<h2>' . sprintf(esc_html__('Pairings round %d', 'chess-podium'), $round) . '</h2><table class="table"><thead><tr><th>' . esc_html__('Board', 'chess-podium') . '</th><th>' . esc_html__('White', 'chess-podium') . '</th><th>' . esc_html__('Black', 'chess-podium') . '</th><th>' . esc_html__('Result', 'chess-podium') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $content .= self::build_player_modal($playerStats);
        return self::wrap_html($name . ' - ' . sprintf(__('Round %d', 'chess-podium'), $round), $navHtml, $content);
    }

    private static function build_games_html(object $tournament, array $allPairings, array $pgns, string $navHtml): string
    {
        $name = esc_html((string) $tournament->name);
        $gamesList = '';
        $gameId = 0;
        foreach ($pgns as $pgn) {
            $gameId++;
            $round = (int) $pgn->round_no;
            $white = esc_html((string) ($pgn->white_name ?? __('White', 'chess-podium')));
            $black = esc_html((string) ($pgn->black_name ?? __('Black', 'chess-podium')));
            $result = esc_html((string) ($pgn->result ?? ''));
            $pgnContent = $pgn->pgn_content;
            $gamesList .= '<div class="game-block" id="game-wrap-' . $gameId . '">';
            $gamesList .= '<h4 class="cp-game-header">' . sprintf(__('Round %d', 'chess-podium'), $round) . ': ' . $white . ' – ' . $black . ' ' . $result . '</h4>';
            $gamesList .= '<script type="text/plain" id="pgn-' . $gameId . '">' . preg_replace('/<\/script>/', '<\\/script>', $pgnContent) . '</script>';
            $gamesList .= '<div class="cp-game-layout">';
            $gamesList .= '<div class="cp-game-board-wrap"><div class="board-container" id="board-' . $gameId . '"></div><div class="pgn-controls"></div></div>';
            $gamesList .= '<div class="cp-game-moves-panel"><div class="cp-moves-label">' . esc_html__('Moves', 'chess-podium') . '</div><div class="pgn-moves"></div></div>';
            $gamesList .= '</div></div>';
        }
        if ($gamesList === '') {
            $gamesList = '<p>' . esc_html__('No PGN games loaded.', 'chess-podium') . '</p>';
        }
        $pgnViewerJs = self::get_pgn_viewer_script();
        return self::wrap_html(
            $name . ' - ' . __('Games', 'chess-podium'),
            $navHtml,
            '<h2>' . esc_html__('PGN games', 'chess-podium') . '</h2><p class="cp-games-hint">' . esc_html__('Use the controls or click moves to navigate. Keyboard: ← → previous/next, Home/End start/end.', 'chess-podium') . '</p>' . $gamesList . $pgnViewerJs,
            true
        );
    }

    private static function build_gallery_html(object $tournament, array $photos, string $navHtml): string
    {
        $name = esc_html((string) $tournament->name);
        $items = '';
        foreach ($photos as $i => $ph) {
            $url = esc_url($ph->photo_url);
            $caption = esc_html((string) ($ph->caption ?? ''));
            $items .= '<figure class="gallery-item"><img src="' . $url . '" alt="' . $caption . '" data-index="' . $i . '" data-src="' . $url . '" data-caption="' . $caption . '" loading="lazy"><figcaption>' . $caption . '</figcaption></figure>';
        }
        $content = '<h2>' . esc_html__('Photo gallery', 'chess-podium') . '</h2>';
        $content .= '<p class="gallery-hint">' . esc_html__('Click on a photo to enlarge and browse.', 'chess-podium') . '</p>';
        $content .= !empty($items) ? '<div class="gallery">' . $items . '</div>' : '<p>' . esc_html__('No photos loaded.', 'chess-podium') . '</p>';
        $content .= self::get_lightbox_html();
        $content .= self::get_lightbox_script();
        return self::wrap_html($name . ' - ' . __('Gallery', 'chess-podium'), $navHtml, $content);
    }

    private static function get_lightbox_html(): string
    {
        $close = esc_attr__('Close', 'chess-podium');
        $prev = esc_attr__('Previous', 'chess-podium');
        $next = esc_attr__('Next', 'chess-podium');
        return '
<div id="cp-lightbox" class="cp-lightbox" aria-hidden="true">
    <button type="button" class="cp-lightbox-close" aria-label="' . $close . '">&times;</button>
    <button type="button" class="cp-lightbox-prev" aria-label="' . $prev . '">&larr;</button>
    <button type="button" class="cp-lightbox-next" aria-label="' . $next . '">&rarr;</button>
    <div class="cp-lightbox-content">
        <img src="" alt="" id="cp-lightbox-img">
        <p class="cp-lightbox-caption" id="cp-lightbox-caption"></p>
    </div>
</div>';
    }

    private static function get_lightbox_script(): string
    {
        return '
<script>
(function() {
    var lb = document.getElementById("cp-lightbox");
    var img = document.getElementById("cp-lightbox-img");
    var cap = document.getElementById("cp-lightbox-caption");
    var items = [];
    var idx = 0;
    function open(i) {
        idx = i;
        if (items.length === 0) return;
        img.src = items[idx].src;
        img.alt = items[idx].caption;
        cap.textContent = items[idx].caption;
        lb.setAttribute("aria-hidden", "false");
        lb.classList.add("open");
        document.body.style.overflow = "hidden";
    }
    function close() {
        lb.setAttribute("aria-hidden", "true");
        lb.classList.remove("open");
        document.body.style.overflow = "";
    }
    function prev() { idx = (idx - 1 + items.length) % items.length; open(idx); }
    function next() { idx = (idx + 1) % items.length; open(idx); }
    document.querySelectorAll(".gallery-item img").forEach(function(el, i) {
        items.push({ src: el.dataset.src || el.src, caption: el.dataset.caption || el.alt });
        el.style.cursor = "pointer";
        el.onclick = function() { open(i); };
    });
    if (lb) {
        lb.querySelector(".cp-lightbox-close").onclick = close;
        lb.querySelector(".cp-lightbox-prev").onclick = prev;
        lb.querySelector(".cp-lightbox-next").onclick = next;
        lb.onclick = function(e) { if (e.target === lb) close(); };
        document.addEventListener("keydown", function(e) {
            if (!lb.classList.contains("open")) return;
            if (e.key === "Escape") close();
            if (e.key === "ArrowLeft") prev();
            if (e.key === "ArrowRight") next();
        });
    }
})();
</script>';
    }

    private static function get_pgn_viewer_script(): string
    {
        $start = esc_js(__('Start', 'chess-podium'));
        $prev = esc_js(__('Prev', 'chess-podium'));
        $next = esc_js(__('Next', 'chess-podium'));
        $end = esc_js(__('End', 'chess-podium'));
        $play = esc_js(__('Play', 'chess-podium'));
        $pause = esc_js(__('Pause', 'chess-podium'));
        $noMoves = esc_js(__('(no moves)', 'chess-podium'));
        return '
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chess.js/0.10.3/chess.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chessboard-js/1.0.0/chessboard-1.0.0.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/chessboard-js/1.0.0/chessboard-1.0.0.min.css">
<script>
document.addEventListener("DOMContentLoaded", function() {
    function initBoards() {
    var blocks = document.querySelectorAll(".game-block");
    blocks.forEach(function(block, idx) {
        var pgnEl = document.getElementById("pgn-" + (idx + 1));
        var boardId = "board-" + (idx + 1);
        var boardEl = document.getElementById(boardId);
        if (!boardEl || !pgnEl) return;
        var pgn = pgnEl.textContent.trim();
        if (!pgn) return;
        try {
            var game = new Chess();
            if (!game.load_pgn(pgn)) return;
            var moves = game.history();
            var cfg = {
                pieceTheme: "https://chessboardjs.com/img/chesspieces/wikipedia/{piece}.png",
                position: "start",
                draggable: false
            };
            var board = ChessBoard(boardId, cfg);
            var moveIdx = 0;
            var autoPlayTimer = null;

            function goToMove(n) {
                moveIdx = Math.max(0, Math.min(n, moves.length));
                game.reset();
                for (var j = 0; j < moveIdx; j++) game.move(moves[j]);
                board.position(game.fen());
                updateMovesHighlight();
            }

            function updateMovesHighlight() {
                block.querySelectorAll(".cp-move").forEach(function(el, i) {
                    el.classList.toggle("cp-move-current", i === moveIdx - 1);
                });
            }

            var movesEl = block.querySelector(".pgn-moves");
            if (movesEl) {
                var html = "";
                var num = 1;
                for (var i = 0; i < moves.length; i++) {
                    if (i % 2 === 0) { html += "<span class=\"cp-move-num\">" + num + ".</span> "; num++; }
                    html += "<span class=\"cp-move\" data-idx=\"" + i + "\">" + moves[i] + "</span> ";
                }
                movesEl.innerHTML = html || "<span class=\"cp-no-moves\">' . $noMoves . '</span>";
                movesEl.querySelectorAll(".cp-move").forEach(function(el) {
                    el.onclick = function() { goToMove(parseInt(el.dataset.idx, 10) + 1); };
                });
            }

            var ctrlEl = block.querySelector(".pgn-controls");
            if (ctrlEl) {
                var btnStart = document.createElement("button");
                btnStart.className = "cp-ctrl-btn";
                btnStart.textContent = "' . $start . '";
                btnStart.onclick = function() { if (autoPlayTimer) clearInterval(autoPlayTimer); autoPlayTimer = null; goToMove(0); };
                var btnPrev = document.createElement("button");
                btnPrev.className = "cp-ctrl-btn";
                btnPrev.textContent = "' . $prev . '";
                btnPrev.onclick = function() { goToMove(moveIdx - 1); };
                var btnNext = document.createElement("button");
                btnNext.className = "cp-ctrl-btn";
                btnNext.textContent = "' . $next . '";
                btnNext.onclick = function() { goToMove(moveIdx + 1); };
                var btnEnd = document.createElement("button");
                btnEnd.className = "cp-ctrl-btn";
                btnEnd.textContent = "' . $end . '";
                btnEnd.onclick = function() { if (autoPlayTimer) clearInterval(autoPlayTimer); autoPlayTimer = null; goToMove(moves.length); };
                var btnPlay = document.createElement("button");
                btnPlay.className = "cp-ctrl-btn cp-ctrl-play";
                btnPlay.textContent = "' . $play . '";
                btnPlay.onclick = function() {
                    if (autoPlayTimer) { clearInterval(autoPlayTimer); autoPlayTimer = null; btnPlay.textContent = "' . $play . '"; return; }
                    btnPlay.textContent = "' . $pause . '";
                    autoPlayTimer = setInterval(function() {
                        if (moveIdx >= moves.length) { clearInterval(autoPlayTimer); autoPlayTimer = null; btnPlay.textContent = "' . $play . '"; return; }
                        goToMove(moveIdx + 1);
                    }, 1500);
                };
                ctrlEl.appendChild(btnStart);
                ctrlEl.appendChild(btnPrev);
                ctrlEl.appendChild(btnNext);
                ctrlEl.appendChild(btnEnd);
                ctrlEl.appendChild(btnPlay);
            }

            block.addEventListener("click", function() { block.focus(); });
            block.addEventListener("keydown", function(e) {
                if (e.key === "ArrowLeft") { e.preventDefault(); goToMove(moveIdx - 1); }
                if (e.key === "ArrowRight") { e.preventDefault(); goToMove(moveIdx + 1); }
                if (e.key === "Home") { e.preventDefault(); goToMove(0); }
                if (e.key === "End") { e.preventDefault(); goToMove(moves.length); }
            });
            block.setAttribute("tabindex", "0");

            goToMove(0);

            function doResize() { if (board && typeof board.resize === "function") board.resize(); }
            setTimeout(doResize, 150);
            window.addEventListener("resize", doResize);
            if (typeof ResizeObserver !== "undefined") {
                var ro = new ResizeObserver(function() { doResize(); });
                if (boardEl && boardEl.parentElement) ro.observe(boardEl.parentElement);
            }
        } catch (e) { console.error(e); }
    });
    }
    requestAnimationFrame(function() { setTimeout(initBoards, 50); });
});
</script>';
    }

    private static function wrap_html(string $title, string $navHtml, string $content, bool $extraScripts = false): string
    {
        $css = '<link rel="stylesheet" href="style.css">';
        $scripts = $extraScripts ? '' : '';
        $navToggleLabel = esc_attr__('Menu', 'chess-podium');
        $navScript = '<script>(function(){var t=document.getElementById("cp-nav-toggle"),h=document.getElementById("cp-site-header");if(t&&h){t.onclick=function(){h.classList.toggle("nav-open");t.setAttribute("aria-expanded",h.classList.contains("nav-open"));};}})();</script>';
        $footer = sprintf(
            __('Generated by %s', 'chess-podium'),
            '<a href="https://www.chesspodium.com/" target="_blank" rel="noopener">' . esc_html__('Chess Podium', 'chess-podium') . '</a>'
        );
        $lang = get_locale();
        $header = '<header id="cp-site-header"><button id="cp-nav-toggle" class="cp-nav-toggle" type="button" aria-label="' . $navToggleLabel . '" aria-expanded="false">&#9776;</button>' . $navHtml . '</header>';
        return '<!DOCTYPE html><html lang="' . esc_attr(substr($lang, 0, 2)) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title>' . $css . '</head><body>' . $header . '<main class="container"><h1>' . $title . '</h1>' . $content . '</main><footer><p>' . $footer . '</p></footer>' . $scripts . $navScript . '</body></html>';
    }

    private static function write_css(string $path): void
    {
        $css = '
* { box-sizing: border-box; }
body { font-family: system-ui, sans-serif; margin: 0; background: #f5f5f5; color: #222; }
header { background: #1a365d; color: #fff; padding: 1rem; display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; border-bottom: 3px solid #c9a227; }
.cp-nav-toggle { display: none; background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; padding: 0.5rem 0.75rem; line-height: 1; -webkit-tap-highlight-color: transparent; }
.cp-nav-toggle:hover { background: rgba(255,255,255,0.1); border-radius: 4px; }
.nav { flex: 1; }
.nav ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 1rem; }
.nav a { color: #ecf0f1; text-decoration: none; padding: 0.35em 0; display: block; }
.nav a:hover { text-decoration: underline; }
@media (max-width: 768px) {
  .cp-nav-toggle { display: block; margin-left: auto; }
  .nav { flex: 1 1 100%; order: 3; }
  header:not(.nav-open) .nav { display: none; }
  header.nav-open .nav { display: block; }
  .nav ul { flex-direction: column; gap: 0; padding-top: 0.5rem; }
  .nav ul li { border-top: 1px solid rgba(255,255,255,0.2); }
  .nav ul li a { padding: 0.75rem 0; }
}
.container { max-width: 900px; margin: 0 auto; padding: 1.5rem; background: #fff; min-height: 60vh; }
table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
th, td { border: 1px solid #ddd; padding: 0.5rem; text-align: left; }
th { background: #1a365d; color: #fff; }
tr:nth-child(even) { background: #f9f9f9; }
.gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; }
.gallery figure { margin: 0; }
.gallery img { width: 100%; height: 200px; object-fit: cover; cursor: pointer; transition: opacity 0.2s; }
.gallery img:hover { opacity: 0.9; }
.gallery figcaption { padding: 0.5rem; font-size: 0.9em; color: #666; }
.gallery-hint { color: #666; font-size: 0.9em; margin-bottom: 1rem; }
.cp-lightbox { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.9); align-items: center; justify-content: center; }
.cp-lightbox.open { display: flex; }
.cp-lightbox-content { max-width: 95vw; max-height: 95vh; text-align: center; position: relative; }
.cp-lightbox-content img { max-width: 95vw; max-height: 85vh; object-fit: contain; }
.cp-lightbox-caption { color: #fff; padding: 1rem; font-size: 1rem; }
.cp-lightbox-close, .cp-lightbox-prev, .cp-lightbox-next { position: absolute; background: rgba(255,255,255,0.2); color: #fff; border: none; font-size: 2rem; cursor: pointer; padding: 0.5rem 1rem; border-radius: 4px; }
.cp-lightbox-close:hover, .cp-lightbox-prev:hover, .cp-lightbox-next:hover { background: rgba(255,255,255,0.3); }
.cp-lightbox-close { top: 1rem; right: 1rem; }
.cp-lightbox-prev { left: 1rem; top: 50%; transform: translateY(-50%); }
.cp-lightbox-next { right: 1rem; top: 50%; transform: translateY(-50%); }
.cp-games-hint { color: #666; font-size: 0.9em; margin-bottom: 1.5rem; }
.game-block { margin: 2.5rem 0; padding: 1.25rem; border: 1px solid #ddd; border-radius: 8px; background: #fafafa; }
.cp-game-header { margin: 0 0 1rem; font-size: 1.1em; color: #1a365d; }
.cp-game-layout { display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start; }
.cp-game-board-wrap { flex-shrink: 0; }
.board-container { width: 400px; max-width: 100%; min-width: 280px; }
.pgn-controls { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; align-items: center; }
.cp-ctrl-btn { margin: 0; padding: 0.4rem 0.75rem; cursor: pointer; font-size: 0.9em; border: 1px solid #ccc; border-radius: 4px; background: #fff; touch-action: manipulation; -webkit-tap-highlight-color: transparent; }
.cp-ctrl-btn:hover { background: #f0f0f0; }
.cp-ctrl-play { background: #2c5282; color: #fff; border-color: #1a365d; }
.cp-ctrl-play:hover { background: #1a365d; }
.cp-game-moves-panel { flex: 1; min-width: 220px; }
.cp-moves-label { font-weight: 600; margin-bottom: 0.5rem; font-size: 0.95em; }
.pgn-moves { font-family: "Georgia", serif; font-size: 1rem; line-height: 1.8; color: #333; }
.pgn-moves .cp-move-num { color: #666; margin-right: 0.25em; }
.pgn-moves .cp-move { cursor: pointer; padding: 0.1em 0.2em; border-radius: 3px; touch-action: manipulation; -webkit-tap-highlight-color: transparent; }
.pgn-moves .cp-move:hover { background: #e8e8e8; }
.pgn-moves .cp-move.cp-move-current { background: #2c5282; color: #fff; }
.pgn-moves .cp-no-moves { color: #999; }
@media (max-width: 600px) { .cp-game-layout { flex-direction: column; } .board-container { width: 100%; max-width: 400px; min-width: 280px; } }
footer { text-align: center; padding: 1rem; color: #666; font-size: 0.9em; }
button { margin: 0.25rem; padding: 0.5rem 1rem; cursor: pointer; }
.cp-player-link { color: #2c5282; text-decoration: none; }
.cp-player-link:hover { text-decoration: underline; }
.cp-flag-img { display: inline-block; margin-right: 4px; }
.cp-player-modal { display: none; position: fixed; inset: 0; z-index: 9998; align-items: center; justify-content: center; padding: 1rem; }
.cp-player-modal.open { display: flex; }
.cp-player-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.5); cursor: pointer; }
.cp-player-modal-content { position: relative; background: #fff; border-radius: 8px; max-width: 600px; max-height: 90vh; overflow-y: auto; padding: 1.5rem; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
.cp-player-modal-close { position: absolute; top: 0.5rem; right: 0.5rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 0.25rem; line-height: 1; }
.cp-player-modal-close:hover { color: #333; }
.cp-player-modal-content h3 { margin-top: 0; }
.cp-player-modal-content h4 { margin: 1rem 0 0.5rem; }
';
        self::write_file($path, trim($css));
    }
}
