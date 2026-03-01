<?php if (!defined('ABSPATH')) { exit; } ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-EZJ0YTDME9"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-EZJ0YTDME9');
    </script>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="site-header">
    <div class="container site-header-inner">
        <div class="branding">
            <?php
            $logo_fallback = chess_podium_asset_url(array(
                'chess-podium-logo-mark-flat-transparent.png',
                'chess-podium-logo-mark-transparent.png',
                'chess-podium-logo-mark.png',
            ));
            ?>
            <?php if (has_custom_logo()) : ?>
                <?php the_custom_logo(); ?>
            <?php elseif ($logo_fallback !== '') : ?>
                <img src="<?php echo esc_url($logo_fallback); ?>" alt="Chess Podium">
            <?php else : ?>
                <span style="font-size:1.6rem;line-height:1;">&#9818;</span>
            <?php endif; ?>
            <p class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a></p>
        </div>
        <?php if (function_exists('chess_podium_language_switcher')) chess_podium_language_switcher('cp-lang-header'); ?>
        <nav class="main-nav" aria-label="<?php esc_attr_e('Primary navigation', 'chess-podium'); ?>">
            <?php
            wp_nav_menu(array(
                'theme_location' => 'primary',
                'container'      => false,
                'fallback_cb'    => function () {
                    $download_url = apply_filters('chess_podium_download_url', home_url('/download/'));
                    echo '<ul>';
                    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'chess-podium') . '</a></li>';
                    echo '<li><a href="' . esc_url($download_url) . '">' . esc_html__('Download', 'chess-podium') . '</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/features/')) . '">' . esc_html__('Features', 'chess-podium') . '</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/pricing/')) . '">' . esc_html__('Pricing', 'chess-podium') . '</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/faq/')) . '">' . esc_html__('FAQ', 'chess-podium') . '</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/docs/')) . '">' . esc_html__('Docs', 'chess-podium') . '</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/contact/')) . '">' . esc_html__('Contact', 'chess-podium') . '</a></li>';
                    echo '</ul>';
                },
            ));
            ?>
        </nav>
    </div>
</header>
