<?php
/**
 * SEO for Chess Podium: meta tags, hreflang, Open Graph, multilingual sitemap.
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Main marketing pages: normalized path => [title_key, description_key] */
function chess_podium_seo_pages(): array {
    return [
        '/' => ['Manage chess tournaments from WordPress in minutes.', 'Chess Podium helps clubs organize players, pair rounds, publish standings, share archives, and sell registrations from one place.'],
        '/download' => ['Download Chess Podium', 'Free version: up to 10 players per tournament. Install on your WordPress site and start organizing chess tournaments.'],
        '/features' => ['Features', 'Everything you need to run chess tournaments from your WordPress site. Swiss pairing, standings, PGN, online registration.'],
        '/pricing' => ['Pricing', 'Chess Podium pricing: Free and Pro plans for chess clubs. Online registration with Stripe and PayPal.'],
        '/faq' => ['Frequently Asked Questions', 'Common questions about Chess Podium and tournament management.'],
        '/contact' => ['Contact', 'Contact Chess Podium for demos, partnerships, or support.'],
        '/docs' => ['Getting Started', 'Quick start guide for Chess Podium: install, create tournaments, publish standings.'],
    ];
}

/** Normalize request path for SEO lookup */
function chess_podium_seo_current_path(): string {
    $request = $_SERVER['REQUEST_URI'] ?? '/';
    $path = preg_replace('#\?.*$#', '', $request);
    $path = '/' . trim($path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

/** Meta descriptions per page (fallback when no translation) */
function chess_podium_seo_descriptions(): array {
    return [
        '/' => 'Manage chess tournaments from WordPress in minutes. Free plugin for clubs. Swiss pairing, standings, PGN.',
        '/download' => 'Download Chess Podium - free WordPress plugin for chess tournaments. Up to 10 players, Swiss pairing.',
        '/features' => 'Chess Podium features: Swiss pairing, standings, PGN, online registration with Stripe and PayPal.',
        '/pricing' => 'Chess Podium pricing: Free and Pro plans. Online registration, unlimited players, PGN, exports.',
        '/faq' => 'FAQ about Chess Podium - chess tournament management for WordPress.',
        '/contact' => 'Contact Chess Podium for demos, partnerships, or support.',
        '/docs' => 'Getting started with Chess Podium: install, create tournaments, publish standings.',
    ];
}

/** hreflang code from locale */
function chess_podium_hreflang_code(string $locale): string {
    $map = ['en_US' => 'en', 'it_IT' => 'it', 'de_DE' => 'de', 'fr_FR' => 'fr', 'es_ES' => 'es'];
    return $map[$locale] ?? substr($locale, 0, 2);
}

/** Output hreflang and x-default alternate links */
function chess_podium_seo_hreflang(): void {
    if (is_admin()) {
        return;
    }
    $base_path = chess_podium_seo_current_path();
    $locales = array_keys(chess_podium_supported_locales());
    $current_locale = function_exists('chess_podium_body_locale') ? chess_podium_body_locale() : 'en_US';

    foreach ($locales as $locale) {
        $code = chess_podium_hreflang_code($locale);
        $url = home_url($base_path);
        if ($locale !== 'en_US') {
            $url = add_query_arg('lang', $code, $url);
        } else {
            $url = remove_query_arg('lang', $url);
        }
        echo '<link rel="alternate" hreflang="' . esc_attr($code) . '" href="' . esc_url($url) . '" />' . "\n";
    }
    $xdefault = home_url($base_path);
    echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($xdefault) . '" />' . "\n";
}

/** Output meta description and Open Graph tags */
function chess_podium_seo_meta(): void {
    if (is_admin()) {
        return;
    }
    $base_path = chess_podium_seo_current_path();
    $pages = chess_podium_seo_pages();
    $descriptions = chess_podium_seo_descriptions();

    if (!isset($pages[$base_path])) {
        return;
    }

    $locale = function_exists('chess_podium_body_locale') ? chess_podium_body_locale() : 'en_US';
    $title = isset($pages[$base_path][0]) && function_exists('chess_podium_t')
        ? chess_podium_t($pages[$base_path][0])
        : get_bloginfo('name');
    $desc = isset($pages[$base_path][1]) && function_exists('chess_podium_t')
        ? chess_podium_t($pages[$base_path][1])
        : ($descriptions[$base_path] ?? get_bloginfo('description'));
    $url = home_url($base_path);
    if ($locale !== 'en_US') {
        $url = add_query_arg('lang', chess_podium_hreflang_code($locale), $url);
    }

    $site_name = get_bloginfo('name');
    $image = apply_filters('chess_podium_og_image', '');
    if ($image === '' && function_exists('chess_podium_asset_url')) {
        $logo = chess_podium_asset_url(['chess-podium-logo-mark.png', 'chess-podium-logo-mark-flat-transparent.png']);
        $image = $logo !== '' ? $logo : '';
    }

    echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($desc)) . '">' . "\n";
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr(wp_strip_all_tags($title)) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr(wp_strip_all_tags($desc)) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "\n";
    echo '<meta property="og:locale" content="' . esc_attr(str_replace('_', '-', $locale)) . '">' . "\n";

    $locales = array_keys(chess_podium_supported_locales());
    foreach ($locales as $loc) {
        if ($loc !== $locale) {
            echo '<meta property="og:locale:alternate" content="' . esc_attr(str_replace('_', '-', $loc)) . '">' . "\n";
        }
    }
    if ($image !== '') {
        echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr(wp_strip_all_tags($title)) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr(wp_strip_all_tags($desc)) . '">' . "\n";

    $canonical = $url;
    echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
}

/** JSON-LD Organization/WebSite for rich results */
function chess_podium_seo_jsonld(): void {
    if (is_admin()) {
        return;
    }
    $path = chess_podium_seo_current_path();
    $pages = chess_podium_seo_pages();
    $path_with_slash = $path !== '/' ? $path . '/' : $path;
    if (!isset($pages[$path]) && !isset($pages[$path_with_slash])) {
        return;
    }
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => get_bloginfo('name'),
        'url' => home_url('/'),
        'description' => get_bloginfo('description') ?: 'Chess tournament management for WordPress',
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => home_url('/?s={search_term_string}')],
            'query-input' => 'required name=search_term_string',
        ],
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
}
add_action('wp_head', 'chess_podium_seo_jsonld', 15);

/** Sitemap link for crawlers */
add_action('wp_head', function () {
    echo '<link rel="sitemap" type="application/xml" href="' . esc_url(home_url('/sitemap-lang.xml')) . '">' . "\n";
}, 1);

/** Fix HTML lang attribute for body locale */
function chess_podium_seo_lang_attr($output) {
    if (is_admin() || !function_exists('chess_podium_body_locale')) {
        return $output;
    }
    $locale = chess_podium_body_locale();
    $code = chess_podium_hreflang_code($locale);
    $new = ' lang="' . esc_attr($code) . '"';
    if (preg_match('/\s+lang="[^"]*"/', $output)) {
        return preg_replace('/\s+lang="[^"]*"/', $new, $output);
    }
    return $output . $new;
}

add_filter('language_attributes', 'chess_podium_seo_lang_attr', 20);

add_action('wp_head', function () {
    $path = chess_podium_seo_current_path();
    $path_with_slash = $path !== '/' ? $path . '/' : $path;
    $pages = chess_podium_seo_pages();
    if (!isset($pages[$path]) && !isset($pages[$path_with_slash])) {
        return;
    }
    chess_podium_seo_hreflang();
    chess_podium_seo_meta();
}, 5);

/** Register sitemap-lang.xml rewrite and output */
function chess_podium_sitemap_rewrite(): void {
    add_rewrite_rule('^sitemap-lang\.xml$', 'index.php?chess_podium_sitemap=1', 'top');
}
add_action('init', 'chess_podium_sitemap_rewrite');

function chess_podium_sitemap_query_vars(array $vars): array {
    $vars[] = 'chess_podium_sitemap';
    return $vars;
}
add_filter('query_vars', 'chess_podium_sitemap_query_vars');

function chess_podium_sitemap_output(): void {
    if ((int) get_query_var('chess_podium_sitemap') !== 1) {
        return;
    }
    $pages = chess_podium_seo_pages();
    $locales = array_keys(chess_podium_supported_locales());
    $base_url = home_url('/');

    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

    foreach (array_keys($pages) as $path) {
        $base = $path === '/' ? $base_url : rtrim($base_url, '/') . $path . '/';
        foreach ($locales as $locale) {
            $code = chess_podium_hreflang_code($locale);
            $url = $locale === 'en_US' ? $base : add_query_arg('lang', $code, $base);
            echo '  <url>' . "\n";
            echo '    <loc>' . esc_url($url) . '</loc>' . "\n";
            echo '    <changefreq>weekly</changefreq>' . "\n";
            echo '    <priority>1.0</priority>' . "\n";
            foreach ($locales as $loc) {
                $c = chess_podium_hreflang_code($loc);
                $alt = $loc === 'en_US' ? $base : add_query_arg('lang', $c, $base);
                echo '    <xhtml:link rel="alternate" hreflang="' . esc_attr($c) . '" href="' . esc_url($alt) . '" />' . "\n";
            }
            echo '    <xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url($base) . '" />' . "\n";
            echo '  </url>' . "\n";
        }
    }
    echo '</urlset>';
    exit;
}
add_action('template_redirect', 'chess_podium_sitemap_output');

/** Add multilingual sitemap to robots.txt */
add_filter('robots_txt', function ($output, $public) {
    if ($public) {
        $output .= "\nSitemap: " . esc_url(home_url('/sitemap-lang.xml')) . "\n";
    }
    return $output;
}, 10, 2);
