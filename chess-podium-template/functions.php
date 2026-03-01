<?php
/**
 * Chess Podium Theme Functions.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_template_directory() . '/inc/languages.php';
require_once get_template_directory() . '/inc/body-translations.php';
require_once get_template_directory() . '/inc/seo.php';

/**
 * Ensure chess-podium translations are loaded for theme templates (body content).
 * Plugin loads them on plugins_loaded, but theme explicitly loads again so body
 * content (front-page, sections) uses the correct locale when ?lang= is present.
 */
function chess_podium_theme_load_textdomain() {
    if (is_admin()) {
        return;
    }
    $plugin_lang = WP_PLUGIN_DIR . '/chess-podium/languages';
    if (!is_dir($plugin_lang)) {
        return;
    }
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    $mofile = $plugin_lang . '/chess-podium-' . $locale . '.mo';
    if (file_exists($mofile)) {
        load_textdomain('chess-podium', $mofile);
    }
}
add_action('after_setup_theme', 'chess_podium_theme_load_textdomain', 1);

function chess_podium_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
    add_theme_support('custom-logo', array(
        'height'      => 120,
        'width'       => 120,
        'flex-height' => true,
        'flex-width'  => true,
    ));

    register_nav_menus(array(
        'primary' => __('Primary Menu', 'chess-podium'),
    ));
}
add_action('after_setup_theme', 'chess_podium_setup');

add_action('after_switch_theme', function () {
    flush_rewrite_rules();
});

function chess_podium_scripts() {
    wp_enqueue_style(
        'chess-podium-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap',
        array(),
        null
    );
    wp_enqueue_style(
        'chess-podium-style',
        get_stylesheet_uri(),
        array('chess-podium-fonts'),
        wp_get_theme()->get('Version')
    );
}
add_action('wp_enqueue_scripts', 'chess_podium_scripts');

/**
 * Return first existing theme asset URL from a candidate list.
 *
 * @param array $candidates File names in /assets.
 * @return string
 */
function chess_podium_asset_url(array $candidates): string {
    $base_path = trailingslashit(get_template_directory()) . 'assets/';
    $base_uri  = trailingslashit(get_template_directory_uri()) . 'assets/';

    foreach ($candidates as $file) {
        $safe_file = ltrim((string) $file, '/');
        if ($safe_file !== '' && file_exists($base_path . $safe_file)) {
            return $base_uri . $safe_file;
        }
    }

    return '';
}

/**
 * Theme customizer settings.
 */
function chess_podium_customize_register($wp_customize) {
    $wp_customize->add_section('chess_podium_branding', array(
        'title'    => __('Chess Podium Branding', 'chess-podium'),
        'priority' => 30,
    ));

    $wp_customize->add_setting('chess_podium_hero_image', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ));

    $wp_customize->add_control(new WP_Customize_Image_Control(
        $wp_customize,
        'chess_podium_hero_image_control',
        array(
            'label'    => __('Homepage Hero Image', 'chess-podium'),
            'section'  => 'chess_podium_branding',
            'settings' => 'chess_podium_hero_image',
        )
    ));

    $wp_customize->add_setting('chess_podium_download_url', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ));

    $wp_customize->add_control('chess_podium_download_url', array(
        'label'       => __('Download URL (override)', 'chess-podium'),
        'description' => __('Leave empty to use the Download page (/download/). Set a URL (e.g. WordPress.org) to override.', 'chess-podium'),
        'section'     => 'chess_podium_branding',
        'type'        => 'url',
    ));

    $wp_customize->add_setting('chess_podium_direct_download_url', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ));

    $wp_customize->add_control('chess_podium_direct_download_url', array(
        'label'       => __('Direct download URL (ZIP)', 'chess-podium'),
        'description' => __('URL of the plugin ZIP file for direct download. Upload chess-podium-0.3.0.zip to Media Library and paste the file URL here.', 'chess-podium'),
        'section'     => 'chess_podium_branding',
        'type'        => 'url',
    ));
}
add_action('customize_register', 'chess_podium_customize_register');

add_filter('chess_podium_download_url', function ($url) {
    $custom = get_theme_mod('chess_podium_download_url', '');
    return $custom !== '' ? $custom : home_url('/download/');
});

add_filter('chess_podium_direct_download_url', function () {
    return get_theme_mod('chess_podium_direct_download_url', '');
});

function chess_podium_lang_switcher_script() {
    if (!function_exists('chess_podium_language_switcher')) {
        return;
    }
    wp_register_script('chess-podium-lang', '', [], null, true);
    wp_enqueue_script('chess-podium-lang');
    wp_add_inline_script('chess-podium-lang', "
document.querySelectorAll('.cp-lang-switcher').forEach(function(el){
    var btn = el.querySelector('.cp-lang-trigger');
    var menu = el.querySelector('.cp-lang-dropdown');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(e){
        e.preventDefault();
        var open = menu.style.display === 'block';
        menu.style.display = open ? 'none' : 'block';
        btn.setAttribute('aria-expanded', !open);
    });
    document.addEventListener('click', function(e){
        if (!el.contains(e.target)) {
            menu.style.display = 'none';
            btn.setAttribute('aria-expanded', 'false');
        }
    });
});
", 'after');
}
add_action('wp_enqueue_scripts', 'chess_podium_lang_switcher_script', 20);
