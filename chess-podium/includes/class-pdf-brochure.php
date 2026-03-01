<?php
/**
 * PDF Brochure Builder - Tournament announcement (Bando Torneo)
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_PdfBrochure
{
    private const META_KEYS = [
        'venue_name',
        'venue_address',
        'venue_map_url',
        'start_date',
        'prize_table',
        'contacts',
        'tournament_rules',
        'tournament_description',
        'brochure_logo',
        'brochure_venue_photos',
        'round_dates',
        'round_times',
        'time_control',
        'fee_titled',
        'fee_normal',
        'fee_under18',
    ];

    public static function get_meta(int $tournamentId, string $key): ?string
    {
        global $wpdb;
        $table = self::db_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT meta_value FROM $table WHERE tournament_id = %d AND meta_key = %s",
            $tournamentId,
            $key
        ));
        return $row ? (string) $row->meta_value : null;
    }

    public static function set_meta(int $tournamentId, string $key, string $value): bool
    {
        global $wpdb;
        $table = self::db_table();
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE tournament_id = %d AND meta_key = %s",
            $tournamentId,
            $key
        ));
        if ($existing) {
            return (bool) $wpdb->update($table, ['meta_value' => $value], ['id' => (int) $existing], ['%s'], ['%d']);
        }
        return (bool) $wpdb->insert($table, [
            'tournament_id' => $tournamentId,
            'meta_key' => $key,
            'meta_value' => $value,
        ], ['%d', '%s', '%s']);
    }

    public static function get_all_meta(int $tournamentId): array
    {
        global $wpdb;
        $table = self::db_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM $table WHERE tournament_id = %d",
            $tournamentId
        ));
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->meta_key] = (string) $r->meta_value;
        }
        return $out;
    }

    public static function save_brochure(int $tournamentId, array $data): void
    {
        foreach (self::META_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                self::set_meta($tournamentId, $key, is_string($data[$key]) ? $data[$key] : wp_json_encode($data[$key]));
            }
        }
    }

    public static function get_round_times(object $tournament): array
    {
        $tid = (int) $tournament->id;
        $rounds = (int) $tournament->rounds_total;
        $manual = self::get_meta($tid, 'round_times');
        if (!$manual) {
            return array_fill(1, max(0, $rounds), '');
        }
        $decoded = json_decode($manual, true);
        if (!is_array($decoded)) {
            return array_fill(1, max(0, $rounds), '');
        }
        $times = [];
        for ($r = 1; $r <= $rounds; $r++) {
            $times[$r] = $decoded[$r] ?? '';
        }
        return $times;
    }

    public static function get_round_dates(object $tournament): array
    {
        $tid = (int) $tournament->id;
        $rounds = (int) $tournament->rounds_total;
        $manual = self::get_meta($tid, 'round_dates');
        if ($manual) {
            $decoded = json_decode($manual, true);
            if (is_array($decoded)) {
                $dates = [];
                for ($r = 1; $r <= $rounds; $r++) {
                    $date = $decoded[$r] ?? '';
                    $dates[$r] = $date ? date('d/m/Y', strtotime($date)) : '-';
                }
                return $dates;
            }
        }
        $start = self::get_meta($tid, 'start_date');
        if (!$start) {
            return array_fill(1, max(0, $rounds), '-');
        }
        $dates = [];
        $ts = strtotime($start);
        for ($r = 1; $r <= $rounds; $r++) {
            $dates[$r] = date('d/m/Y', $ts);
            $ts = strtotime('+1 week', $ts);
        }
        return $dates;
    }

    public static function generate_html(object $tournament): string
    {
        $meta = self::get_all_meta((int) $tournament->id);
        $venueName = $meta['venue_name'] ?? '';
        $venueAddress = $meta['venue_address'] ?? '';
        $venueMapUrl = $meta['venue_map_url'] ?? '';
        $startDate = $meta['start_date'] ?? '';
        $prizeTable = isset($meta['prize_table']) ? json_decode($meta['prize_table'], true) : [];
        $contacts = isset($meta['contacts']) ? json_decode($meta['contacts'], true) : [];
        $tournamentRules = $meta['tournament_rules'] ?? '';
        $tournamentDescription = $meta['tournament_description'] ?? '';
        $brochureLogo = $meta['brochure_logo'] ?? '';
        $venuePhotos = isset($meta['brochure_venue_photos']) ? json_decode($meta['brochure_venue_photos'], true) : [];
        if (!is_array($venuePhotos)) {
            $venuePhotos = [];
        }
        $timeControl = $meta['time_control'] ?? '';
        $feeTitled = $meta['fee_titled'] ?? '';
        $feeNormal = $meta['fee_normal'] ?? '';
        $feeUnder18 = $meta['fee_under18'] ?? '';

        $roundDates = self::get_round_dates($tournament);
        $roundTimes = self::get_round_times($tournament);
        $roundsTotal = (int) $tournament->rounds_total;

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html((string) $tournament->name); ?> - <?php esc_html_e('Tournament announcement', 'chess-podium'); ?></title>
            <style>
                @page { margin: 1.5cm; }
                body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; font-size: 10pt; line-height: 1.5; color: #2d3748; margin: 0; padding: 0; }
                .cp-brochure-wrap { max-width: 100%; }
                .cp-brochure-header { background: #1a365d; color: #fff; padding: 24px 28px; margin: 0 0 24px 0; border-bottom: 4px solid #c9a227; }
                .cp-brochure-header-inner { overflow: hidden; }
                .cp-brochure-logo-cell { float: left; padding-right: 20px; }
                .cp-brochure-logo { max-height: 72px; max-width: 140px; }
                .cp-brochure-title-cell { overflow: hidden; }
                .cp-brochure-title { font-family: "DejaVu Serif", Georgia, serif; font-size: 22pt; font-weight: bold; margin: 0 0 4px 0; letter-spacing: 0.5px; }
                .cp-brochure-subtitle { font-size: 9pt; opacity: 0.95; margin: 0; }
                .cp-brochure-section { margin-bottom: 22px; page-break-inside: avoid; }
                .cp-brochure-section-title { font-family: "DejaVu Serif", Georgia, serif; font-size: 12pt; font-weight: bold; color: #1a365d; margin: 0 0 10px 0; padding-bottom: 6px; border-bottom: 2px solid #c9a227; display: inline-block; }
                .cp-brochure-box { background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #2c5282; padding: 14px 18px; margin: 10px 0; }
                table.cp-brochure-table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 9.5pt; }
                table.cp-brochure-table th, table.cp-brochure-table td { border: 1px solid #cbd5e0; padding: 8px 12px; text-align: left; }
                table.cp-brochure-table th { background: #1a365d; color: #fff; font-weight: bold; }
                table.cp-brochure-table tr:nth-child(even) { background: #f7fafc; }
                .cp-brochure-venue { background: #fff; border: 1px solid #e2e8f0; padding: 16px; margin: 10px 0; }
                .cp-brochure-venue-name { font-size: 11pt; font-weight: bold; color: #1a365d; margin-bottom: 6px; }
                .venue-map { margin: 12px 0; }
                .venue-map iframe { width: 100%; height: 220px; border: 1px solid #e2e8f0; }
                .cp-venue-photos { margin: 12px 0; }
                .cp-venue-photos img { max-width: 280px; max-height: 200px; object-fit: cover; border: 1px solid #e2e8f0; margin: 4px; }
                .contact-item { padding: 6px 0; border-bottom: 1px dotted #e2e8f0; }
                .contact-item:last-child { border-bottom: none; }
                .cp-brochure-footer { margin-top: 28px; padding-top: 14px; border-top: 1px solid #e2e8f0; font-size: 8pt; color: #718096; text-align: center; }
            </style>
        </head>
        <body>
        <div class="cp-brochure-wrap">
            <div class="cp-brochure-header">
                <div class="cp-brochure-header-inner">
                    <?php if ($brochureLogo): ?>
                    <div class="cp-brochure-logo-cell">
                        <img src="<?php echo esc_url($brochureLogo); ?>" alt="" class="cp-brochure-logo">
                    </div>
                    <?php endif; ?>
                    <div class="cp-brochure-title-cell">
                        <h1 class="cp-brochure-title"><?php echo esc_html((string) $tournament->name); ?></h1>
                        <p class="cp-brochure-subtitle"><?php esc_html_e('Tournament announcement', 'chess-podium'); ?></p>
                    </div>
                </div>
            </div>

            <?php if ($tournamentDescription): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Description and highlights', 'chess-podium'); ?></h2>
                <div class="cp-brochure-box">
                    <div class="cp-tournament-description"><?php echo nl2br(esc_html($tournamentDescription)); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($tournamentRules): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Tournament rules', 'chess-podium'); ?></h2>
                <div class="cp-brochure-box">
                    <div class="cp-tournament-rules"><?php echo nl2br(esc_html($tournamentRules)); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($venueName || $venueAddress): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Venue', 'chess-podium'); ?></h2>
                <div class="cp-brochure-venue">
                    <?php if ($venueName): ?><div class="cp-brochure-venue-name"><?php echo esc_html($venueName); ?></div><?php endif; ?>
                    <?php if ($venueAddress): ?><div><?php echo nl2br(esc_html($venueAddress)); ?></div><?php endif; ?>
                </div>
                <?php if ($venueMapUrl): ?>
                <div class="venue-map">
                    <iframe src="<?php echo esc_url($venueMapUrl); ?>" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?php esc_attr_e('Map', 'chess-podium'); ?>"></iframe>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($venuePhotos)): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Venue photos', 'chess-podium'); ?></h2>
                <div class="cp-venue-photos">
                    <?php foreach ($venuePhotos as $url): ?>
                        <?php if (!empty($url) && is_string($url)): ?>
                        <img src="<?php echo esc_url($url); ?>" alt="<?php esc_attr_e('Venue photo', 'chess-podium'); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($roundsTotal > 0): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Round schedule', 'chess-podium'); ?></h2>
                <table class="cp-brochure-table">
                    <thead><tr><th><?php esc_html_e('Round', 'chess-podium'); ?></th><th><?php esc_html_e('Date', 'chess-podium'); ?></th><th><?php esc_html_e('Start time', 'chess-podium'); ?></th></tr></thead>
                    <tbody>
                    <?php for ($r = 1; $r <= $roundsTotal; $r++): ?>
                        <tr>
                            <td><?php echo esc_html(sprintf(__('Round %d', 'chess-podium'), $r)); ?></td>
                            <td><?php echo esc_html($roundDates[$r] ?? '-'); ?></td>
                            <td><?php echo esc_html(!empty($roundTimes[$r]) ? $roundTimes[$r] : '-'); ?></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($timeControl): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Time control', 'chess-podium'); ?></h2>
                <div class="cp-brochure-box">
                    <p style="margin:0;font-size:11pt;"><?php echo esc_html($timeControl); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($feeTitled || $feeNormal || $feeUnder18): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Registration fees', 'chess-podium'); ?></h2>
                <table class="cp-brochure-table" style="max-width:320px;">
                    <tbody>
                    <?php if ($feeTitled): ?><tr><th style="width:140px;"><?php esc_html_e('Titled players', 'chess-podium'); ?></th><td><?php echo esc_html($feeTitled); ?></td></tr><?php endif; ?>
                    <?php if ($feeNormal): ?><tr><th><?php esc_html_e('Standard fee', 'chess-podium'); ?></th><td><?php echo esc_html($feeNormal); ?></td></tr><?php endif; ?>
                    <?php if ($feeUnder18): ?><tr><th><?php esc_html_e('Under 18', 'chess-podium'); ?></th><td><?php echo esc_html($feeUnder18); ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($prizeTable)): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Prizes', 'chess-podium'); ?></h2>
                <table class="cp-brochure-table">
                    <thead><tr><th><?php esc_html_e('Position', 'chess-podium'); ?></th><th><?php esc_html_e('Prize', 'chess-podium'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($prizeTable as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['position'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['prize'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($contacts)): ?>
            <div class="cp-brochure-section">
                <h2 class="cp-brochure-section-title"><?php esc_html_e('Contacts', 'chess-podium'); ?></h2>
                <div class="cp-brochure-box">
                    <?php foreach ($contacts as $c): ?>
                    <div class="contact-item">
                        <strong><?php echo esc_html($c['name'] ?? ''); ?></strong>
                        <?php if (!empty($c['email'])): ?> &ndash; <a href="mailto:<?php echo esc_attr($c['email']); ?>" style="color:#2c5282;"><?php echo esc_html($c['email']); ?></a><?php endif; ?>
                        <?php if (!empty($c['phone'])): ?> &ndash; <?php echo esc_html($c['phone']); ?><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($startDate): ?>
            <div class="cp-brochure-section" style="margin-bottom:8px;">
                <p style="margin:0;font-size:9pt;color:#718096;">
                    <?php echo esc_html(sprintf(__('Start date: %s', 'chess-podium'), $startDate)); ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="cp-brochure-footer">
                <?php echo esc_html((string) $tournament->name); ?> &ndash; <?php esc_html_e('Tournament announcement', 'chess-podium'); ?> &bull; Chess Podium
            </div>
        </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    public static function generate_pdf(object $tournament): ?string
    {
        $html = self::generate_html($tournament);
        $autoload = CHESS_PODIUM_PLUGIN_DIR . '/lib/dompdf/vendor/autoload.php';
        if (!file_exists($autoload)) {
            $autoload = CHESS_PODIUM_PLUGIN_DIR . '/vendor/autoload.php';
        }
        if (!file_exists($autoload)) {
            return null;
        }
        require_once $autoload;
        if (!class_exists('Dompdf\Dompdf')) {
            return null;
        }
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    private static function db_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'regina_tournament_meta';
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
            meta_key VARCHAR(100) NOT NULL,
            meta_value LONGTEXT,
            PRIMARY KEY (id),
            KEY tournament_meta (tournament_id, meta_key)
        ) $charset";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
