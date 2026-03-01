<?php
/**
 * Template Name: FAQ
 */
get_header();

$faqs = [
    chess_podium_t('What is Chess Podium?') => chess_podium_t('Chess Podium is a WordPress plugin that lets you create and manage chess tournaments directly from your website. Add players, generate pairings, enter results, and publish standings—all without spreadsheets.'),
    chess_podium_t('Do I need WordPress?') => chess_podium_t('Yes. Chess Podium runs as a WordPress plugin. You need a WordPress site (self-hosted or WordPress.com Business plan) with PHP 7.4 or higher.'),
    chess_podium_t('Is it really free?') => chess_podium_t('Yes. The free version lets you run full tournaments with up to 10 players per event. Pro (€79/year) removes the player limit, adds online registration with Stripe and PayPal to monetize tournaments, plus PGN games, photo galleries, and advanced exports.'),
    chess_podium_t('How do I install it?') => chess_podium_t('Download the plugin from the Download page, then in WordPress go to Plugins → Add New → Upload Plugin. Choose the ZIP file, install, and activate. After activation, go to Settings → Permalinks and click Save.'),
    chess_podium_t('How many players can I add?') => chess_podium_t('Free: up to 10 players per tournament. Pro: unlimited players.'),
    chess_podium_t('Can I edit or delete a player after adding them?') => chess_podium_t('Yes. You can edit name, rating, and nationality at any time. You can delete a player only if they have not yet been paired in any round.'),
    chess_podium_t('What if I enter a wrong result or make a mistake?') => chess_podium_t('Use "Undo current round" to cancel the last generated round. You can then correct results and regenerate. No data is lost.'),
    chess_podium_t('How does Swiss pairing work?') => chess_podium_t('Players with the same score are paired against each other. Chess Podium generates pairings automatically. The engine is practical for club events; FIDE-advanced mode is on the roadmap.'),
    chess_podium_t('What is Round Robin?') => chess_podium_t('Round Robin means everyone plays everyone. Choose this when creating the tournament if you have a small group (typically under 12 players) and want all possible games.'),
    chess_podium_t('What tiebreakers are supported?') => chess_podium_t('Buchholz, Sonneborn-Berger, rating, and direct encounter. You can set the order when creating the tournament.'),
    chess_podium_t('How do players see pairings and standings?') => chess_podium_t('Share the live tournament URL (e.g. yoursite.com/torneo/). Players can view current standings, round pairings, and results on any device. No login required.'),
    chess_podium_t('Can I import players from Excel or CSV?') => chess_podium_t('Yes. Use the bulk CSV import. Format: name;rating or name;rating;fide_id;country. The first row can be a header.'),
    chess_podium_t('Can I import players from FIDE?') => chess_podium_t('Yes. Enter a FIDE ID and Chess Podium fetches name, rating, and nationality from the official FIDE database.'),
    chess_podium_t('Can I show past tournaments on my homepage?') => chess_podium_t('Yes. Use the archive widget or shortcode to display your club\'s tournament history on any page.'),
    chess_podium_t('Can I add tournaments from other software (Vega, Swiss Manager)?') => chess_podium_t('Yes. If the tournament is already published elsewhere, you can add it as an "external tournament" with a link. It will appear in the same layout as Chess Podium tournaments.'),
    chess_podium_t('Can I charge for tournament registration?') => chess_podium_t('Yes, with Pro. Online registration lets you set an entry fee per tournament. Players register and pay via Stripe or PayPal; they are added to the tournament automatically and receive an email confirmation. Ideal for chess clubs that want to monetize events and cover costs.'),
    chess_podium_t('How does the Pro license work?') => chess_podium_t('Pro is €79/year. After purchase you receive a license key by email. Enter it in Chess Podium → License. The license is locked to your domain on first activation. One license per domain.'),
    chess_podium_t('Can I use Pro on multiple sites?') => chess_podium_t('No. Each license is valid for one domain only. On first activation, the license locks to that site. If you migrate your site, contact us to reset the domain.'),
    chess_podium_t('Will there be a desktop app?') => chess_podium_t('Yes. Chess Podium Desktop for Windows and macOS is planned for the future.'),
    chess_podium_t('Does it work on mobile?') => chess_podium_t('Yes. The admin panel and public tournament pages are responsive. Players can follow pairings and standings on their phone.'),
    chess_podium_t('Can I print the pairing sheet?') => chess_podium_t('Yes. Use "Generate round and print pairings" to create a round and open a printable sheet ready to post on the wall.'),
];
?>
<main class="content-wrap">
    <div class="container">
        <h1><?php chess_podium_te('Frequently Asked Questions'); ?></h1>
        <p class="muted" style="margin-bottom:2rem;"><?php chess_podium_te('Common questions about Chess Podium and tournament management.'); ?></p>
        <div class="faq-list">
            <?php foreach ($faqs as $q => $a): ?>
            <div class="faq-item">
                <strong><?php echo esc_html($q); ?></strong>
                <p><?php echo esc_html($a); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>
<?php get_footer(); ?>
