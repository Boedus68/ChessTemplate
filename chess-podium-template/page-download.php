<?php
/**
 * Template Name: Download
 */
get_header();

$direct_download = apply_filters('chess_podium_direct_download_url', '');
?>
<main class="content-wrap">
    <div class="container">
        <h1><?php chess_podium_te('Download Chess Podium'); ?></h1>
        <p class="muted"><?php chess_podium_te('Free version: up to 10 players per tournament. Install on your WordPress site and start organizing chess tournaments.'); ?></p>

        <div class="card" style="max-width:560px;padding:1.5rem;">
            <h3 style="margin-top:0;"><?php chess_podium_te('Installation'); ?></h3>
            <ol style="margin:0 0 1rem 1.25rem;padding:0;">
                <li><?php chess_podium_te('Download the plugin ZIP below'); ?></li>
                <li><?php chess_podium_te('In WordPress: Plugins → Add New → Upload Plugin'); ?></li>
                <li><?php chess_podium_te('Choose the ZIP file and click Install Now'); ?></li>
                <li><?php chess_podium_te('Activate Chess Podium'); ?></li>
            </ol>
            <?php if ($direct_download !== ''): ?>
                <p><a class="btn btn-primary" href="<?php echo esc_url($direct_download); ?>" download><?php chess_podium_te('Download Chess Podium (ZIP)'); ?></a></p>
            <?php else: ?>
                <p><a class="btn btn-primary" href="https://wordpress.org/plugins/chess-podium/" target="_blank" rel="noopener noreferrer"><?php chess_podium_te('Download from WordPress.org'); ?></a></p>
                <p class="description"><?php chess_podium_te('If the plugin is not yet on WordPress.org, upload the ZIP to your site via Appearance → Customize → Chess Podium Branding → Direct download URL.'); ?></p>
            <?php endif; ?>
        </div>

        <p style="margin-top:1.5rem;"><a href="<?php echo esc_url(home_url('/pricing/')); ?>"><?php chess_podium_te('Upgrade to Pro for unlimited players'); ?> →</a></p>
    </div>
</main>
<?php get_footer(); ?>
