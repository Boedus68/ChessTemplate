<?php
/**
 * Template Name: Features
 */
get_header();

$features = [
    [
        'icon' => 'trophy',
        'title' => chess_podium_t('Tournament Management'),
        'desc'  => chess_podium_t('Create tournaments, add players, manage rounds and results—all in one intuitive panel.'),
    ],
    [
        'icon' => 'users',
        'title' => chess_podium_t('Swiss & Round Robin'),
        'desc'  => chess_podium_t('Swiss pairing by score or Round Robin where everyone plays everyone. Configurable BYE points.'),
    ],
    [
        'icon' => 'globe',
        'title' => chess_podium_t('Public Pages'),
        'desc'  => chess_podium_t('Publish standings, pairings, and results instantly. Share a live URL with players during the event.'),
        'link'  => 'https://www.chesspodium.com/wp-content/uploads/chess-podium/oxford-university-chess-tournament/',
    ],
    [
        'icon' => 'chart',
        'title' => chess_podium_t('Standings & Tiebreakers'),
        'desc'  => chess_podium_t('Automatic standings with Buchholz, Sonneborn-Berger, rating, and direct encounter tiebreakers.'),
    ],
    [
        'icon' => 'printer',
        'title' => chess_podium_t('Printable Pairings'),
        'desc'  => chess_podium_t('Generate a round and print the pairing sheet in one click—ready to post on the wall.'),
    ],
    [
        'icon' => 'undo',
        'title' => chess_podium_t('Round Rollback'),
        'desc'  => chess_podium_t('Made a mistake? Undo the current round and regenerate. No data loss.'),
    ],
    [
        'icon' => 'file',
        'title' => chess_podium_t('CSV Export & Import'),
        'desc'  => chess_podium_t('Export pairings and standings to CSV. Bulk import players from a spreadsheet.'),
    ],
    [
        'icon' => 'search',
        'title' => chess_podium_t('FIDE Import'),
        'desc'  => chess_podium_t('Enter a FIDE ID to auto-import name, rating, and nationality from the official database.'),
    ],
    [
        'icon' => 'archive',
        'title' => chess_podium_t('Archive Widget'),
        'desc'  => chess_podium_t('Display your club\'s tournament history on any page with a shortcode or widget.'),
    ],
    [
        'icon' => 'link',
        'title' => chess_podium_t('External Tournaments'),
        'desc'  => chess_podium_t('Add tournaments from Vega, Swiss Manager, or other software. Unified display on your site.'),
    ],
    [
        'icon' => 'credit',
        'title' => chess_podium_t('Online registration with payment'),
        'desc'  => chess_podium_t('Public registration page with Stripe and PayPal. Players pay the fee and are added automatically. Email confirmation included.'),
        'pro'   => true,
    ],
    [
        'icon' => 'game',
        'title' => chess_podium_t('PGN Games (Pro)'),
        'desc'  => chess_podium_t('Upload PGN files and display games with an interactive board viewer. Share with players.'),
        'pro'   => true,
    ],
    [
        'icon' => 'image',
        'title' => chess_podium_t('Photo Gallery (Pro)'),
        'desc'  => chess_podium_t('Add a photo gallery to each tournament. Perfect for event memories and club archives.'),
        'pro'   => true,
    ],
    [
        'icon' => 'folder',
        'title' => chess_podium_t('Export to Folder (Pro)'),
        'desc'  => chess_podium_t('Generate a complete HTML export: standings, rounds, games, and gallery in one package.'),
        'pro'   => true,
    ],
];

$icons = [
    'trophy' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
    'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'globe' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
    'chart' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>',
    'printer' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/></svg>',
    'undo' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h10a5 5 0 0 1 5 5v2"/><path d="M3 10 7 6"/><path d="M3 10 7 14"/></svg>',
    'file' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg>',
    'search' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
    'archive' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>',
    'link' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
    'game' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
    'image' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
    'folder' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>',
    'credit' => '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>',
];
?>
<main class="content-wrap">
    <div class="container">
        <h1><?php chess_podium_te('Features'); ?></h1>
        <p class="muted" style="margin-bottom:2rem;"><?php chess_podium_te('Everything you need to run chess tournaments from your WordPress site.'); ?></p>

        <div class="cp-registration-pro-spotlight card">
            <span class="feature-badge"><?php chess_podium_te('Pro only'); ?></span>
            <div class="cp-spotlight-inner">
                <div class="feature-icon"><?php echo $icons['credit'] ?? ''; ?></div>
                <div>
                    <h2 style="margin:0 0 0.5rem;"><?php chess_podium_te('Monetize your tournaments with online registration'); ?></h2>
                    <p class="muted" style="margin:0 0 1rem;"><?php chess_podium_te('Let players register and pay online with Stripe or PayPal. Set the entry fee per tournament, collect payments automatically, and add players to the list—no manual work. Perfect for chess clubs that want to monetize events and cover costs.'); ?></p>
                    <ul class="cp-spotlight-list">
                        <li><?php chess_podium_te('Public registration page with shortcode'); ?></li>
                        <li><?php chess_podium_te('Stripe and PayPal integration'); ?></li>
                        <li><?php chess_podium_te('Automatic player creation on payment'); ?></li>
                        <li><?php chess_podium_te('Email confirmation to players'); ?></li>
                    </ul>
                    <p><a class="btn btn-primary" href="<?php echo esc_url(home_url('/pricing/')); ?>"><?php chess_podium_te('Upgrade to Pro'); ?> →</a></p>
                </div>
            </div>
        </div>

        <div class="features-grid">
            <?php foreach ($features as $f): ?>
            <div class="card feature-card<?php echo !empty($f['pro']) ? ' feature-pro' : ''; ?>">
                <div class="feature-icon"><?php echo $icons[$f['icon']] ?? ''; ?></div>
                <h3><?php echo esc_html($f['title']); ?></h3>
                <p class="muted"><?php echo esc_html($f['desc']); ?></p>
                <?php if (!empty($f['link'])): ?>
                    <p><a href="<?php echo esc_url($f['link']); ?>" target="_blank" rel="noopener noreferrer"><?php chess_podium_te('View live example'); ?> →</a></p>
                <?php endif; ?>
                <?php if (!empty($f['pro'])): ?>
                    <span class="feature-badge">Pro</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>
<?php get_footer(); ?>
