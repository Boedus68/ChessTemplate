<?php
/**
 * Locale detection for front-end language switching.
 * Cookie + GET param + Accept-Language. Must run before load_plugin_textdomain.
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Bypass page cache when switching language (Aruba, CDN, etc.) */
add_action('send_headers', function () {
    if (is_admin()) {
        return;
    }
    if (isset($_GET['lang']) || isset($_COOKIE['chess_podium_lang'])) {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie');
    }
}, 1);

/** Set cookie when ?lang= is present (no redirect: cache treats ?lang=it as different URL) */
add_action('template_redirect', function () {
    if (is_admin() || !isset($_GET['lang'])) {
        return;
    }
    $lang = sanitize_text_field(wp_unslash($_GET['lang']));
    $supported = ['it', 'de', 'fr', 'es', 'en'];
    if (!in_array($lang, $supported, true)) {
        return;
    }
    $map = ['it' => 'it_IT', 'de' => 'de_DE', 'fr' => 'fr_FR', 'es' => 'es_ES', 'en' => 'en_US'];
    $locale = $map[$lang];
    $expire = time() + YEAR_IN_SECONDS;
    if (PHP_VERSION_ID >= 70300) {
        setcookie('chess_podium_lang', $locale, [
            'expires' => $expire,
            'path' => '/',
            'domain' => '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie('chess_podium_lang', $locale, $expire, '/; samesite=Lax', '', is_ssl(), true);
    }
}, 1);

function chess_podium_determine_locale(string $locale): string {
    $supported = ['en_US', 'it_IT', 'de_DE', 'fr_FR', 'es_ES'];
    $default = 'en_US';

    // 1. Explicit GET param (page loads with ?lang= in URL; cookie set in template_redirect for later)
    if (isset($_GET['lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['lang']));
        $resolved = $lang;
        if (strlen($lang) === 2) {
            $map = ['it' => 'it_IT', 'de' => 'de_DE', 'fr' => 'fr_FR', 'es' => 'es_ES', 'en' => 'en_US'];
            $resolved = $map[$lang] ?? $lang;
        }
        if (in_array($resolved, $supported, true)) {
            return $resolved;
        }
    }

    // 2. Cookie from previous choice
    if (isset($_COOKIE['chess_podium_lang'])) {
        $cookie = sanitize_text_field(wp_unslash($_COOKIE['chess_podium_lang']));
        if (in_array($cookie, $supported, true)) {
            return $cookie;
        }
    }

    // 3. Accept-Language header (front-end only, not admin)
    if (!is_admin() && isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
        $parts = array_map('trim', explode(',', $accept));
        foreach ($parts as $part) {
            if (preg_match('/^([a-z]{2})(?:-[a-z]{2})?(?:;q=([0-9.]+))?$/i', $part, $m)) {
                $code = strtolower($m[1]);
                foreach ($supported as $s) {
                    if (strpos($s, $code) === 0) {
                        return $s;
                    }
                }
            }
        }
    }

    return $locale ?: $default;
}

/**
 * Force correct .mo file when loading chess-podium translations.
 * Ensures body content uses our locale even if get_locale() was cached earlier.
 */
add_filter('load_textdomain_mofile', function ($mofile, $domain) {
    if ($domain !== 'chess-podium') {
        return $mofile;
    }
    // Use get_locale() as fallback so WordPress site language is respected when no ?lang= or cookie
    $locale = chess_podium_determine_locale(function_exists('get_locale') ? get_locale() : '');
    if ($locale === 'en_US') {
        return $mofile;
    }
    $plugin_dir = defined('CHESS_PODIUM_PLUGIN_DIR') ? CHESS_PODIUM_PLUGIN_DIR : (WP_PLUGIN_DIR . '/chess-podium');
    $custom = $plugin_dir . '/languages/chess-podium-' . $locale . '.mo';
    return file_exists($custom) ? $custom : $mofile;
}, 10, 2);
