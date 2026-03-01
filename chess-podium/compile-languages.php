<?php
/**
 * Compile .po files to .mo for Chess Podium
 *
 * Run: php compile-languages.php
 *
 * Uses built-in PHP converter (no msgfmt required).
 */
$pluginDir = __DIR__;
$langDir = $pluginDir . DIRECTORY_SEPARATOR . 'languages';
$phpMo = $langDir . DIRECTORY_SEPARATOR . 'php-mo.php';

if (!is_dir($langDir)) {
    echo "Languages directory not found.\n";
    exit(1);
}

if (!file_exists($phpMo)) {
    echo "php-mo.php not found in languages folder.\n";
    exit(1);
}

require_once $phpMo;

$compiled = 0;
foreach (glob($langDir . DIRECTORY_SEPARATOR . '*.po') as $poFile) {
    $base = pathinfo($poFile, PATHINFO_FILENAME);
    $moFile = $langDir . DIRECTORY_SEPARATOR . $base . '.mo';

    if (phpmo_convert($poFile, $moFile)) {
        echo "Compiled: $base.mo\n";
        $compiled++;
    } else {
        echo "Failed: $base.po\n";
    }
}

if ($compiled > 0) {
    echo "\nDone. Compiled $compiled file(s).\n";
} else {
    echo "\nNo files compiled.\n";
    exit(1);
}
