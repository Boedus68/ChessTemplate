<?php
/**
 * Multilingual support for Chess Podium template.
 * Detects visitor language via cookie, GET param, or Accept-Language header.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Supported locales: code => [name, flag emoji, native name] */
function chess_podium_supported_locales(): array {
    return [
        'en_US' => ['English', '🇬🇧', 'English'],
        'it_IT' => ['Italian', '🇮🇹', 'Italiano'],
        'de_DE' => ['German', '🇩🇪', 'Deutsch'],
        'fr_FR' => ['French', '🇫🇷', 'Français'],
        'es_ES' => ['Spanish', '🇪🇸', 'Español'],
    ];
}

function chess_podium_get_current_locale(): string {
    return function_exists('determine_locale') ? determine_locale() : get_locale();
}

function chess_podium_language_switcher(string $class = ''): void {
    $locales = chess_podium_supported_locales();
    $current = chess_podium_get_current_locale();
    $current_url = remove_query_arg('lang');
    ?>
    <div class="cp-lang-switcher <?php echo esc_attr($class); ?>" role="navigation" aria-label="<?php esc_attr_e('Language', 'chess-podium'); ?>">
        <button type="button" class="cp-lang-trigger" aria-expanded="false" aria-haspopup="true" title="<?php esc_attr_e('Select language', 'chess-podium'); ?>">
            <span class="cp-lang-flag"><?php echo esc_html($locales[$current][1] ?? '🌐'); ?></span>
            <span class="cp-lang-code"><?php echo esc_html(strtoupper(substr($current, 0, 2))); ?></span>
        </button>
        <ul class="cp-lang-dropdown" role="menu">
            <?php foreach ($locales as $code => $data): ?>
                <li role="none">
                    <a href="<?php echo esc_url(add_query_arg('lang', $code === 'en_US' ? 'en' : substr($code, 0, 2), $current_url)); ?>" role="menuitem" class="<?php echo $code === $current ? 'active' : ''; ?>">
                        <span class="cp-lang-flag"><?php echo esc_html($data[1]); ?></span>
                        <span><?php echo esc_html($data[2]); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

function chess_podium_language_badges(): void {
    $locales = chess_podium_supported_locales();
    $current = chess_podium_get_current_locale();
    $current_url = remove_query_arg('lang');
    ?>
    <div class="cp-lang-badges" aria-label="<?php esc_attr_e('Plugin available in', 'chess-podium'); ?>">
        <span class="cp-lang-badges-label"><?php esc_html_e('Plugin available in:', 'chess-podium'); ?></span>
        <?php foreach ($locales as $code => $data): ?>
            <a href="<?php echo esc_url(add_query_arg('lang', $code === 'en_US' ? 'en' : substr($code, 0, 2), $current_url)); ?>" class="cp-lang-badge <?php echo $code === $current ? 'active' : ''; ?>" title="<?php echo esc_attr($data[2]); ?>" aria-label="<?php echo esc_attr($data[2]); ?>">
                <?php echo esc_html($data[1]); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}
