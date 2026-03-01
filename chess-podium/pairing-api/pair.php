<?php
/**
 * Chess Podium Pairing API
 *
 * Deploy this script on a server where exec() is enabled and bbpPairings is installed.
 * The WordPress plugin will call it via HTTP when exec() is disabled on the hosting.
 *
 * Usage: POST with trf=<base64-encoded TRF content>
 * Response: JSON { "ok": true, "pairs": [[white_tpn, black_tpn], ...] } or { "ok": false, "error": "..." }
 *
 * Configure bbpPairings path via constant or query param (for testing).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!function_exists('exec')) {
    echo json_encode(['ok' => false, 'error' => 'exec() is disabled on this server']);
    exit;
}

$input = file_get_contents('php://input');
$params = [];
if (strpos($input, '=') !== false) {
    parse_str($input, $params);
} else {
    $json = json_decode($input, true);
    $params = is_array($json) ? $json : [];
}

$trfB64 = $params['trf'] ?? $_POST['trf'] ?? '';
if ($trfB64 === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing trf parameter']);
    exit;
}

$trf = base64_decode($trfB64, true);
if ($trf === false || $trf === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid trf encoding']);
    exit;
}

$bbpPath = $params['bbp_path'] ?? $_POST['bbp_path'] ?? '';
if ($bbpPath === '' && defined('CHESS_PODIUM_BBP_PATH')) {
    $bbpPath = CHESS_PODIUM_BBP_PATH;
}
if ($bbpPath === '') {
    $scriptDir = __DIR__;
    $candidates = [
        $scriptDir . '/bbpPairings',
        $scriptDir . '/bbpPairings.exe',
        $scriptDir . '/bin/bbpPairings',
        $scriptDir . '/bin/bbpPairings.exe',
        '/usr/local/bin/bbpPairings',
    ];
    foreach ($candidates as $c) {
        if (is_readable($c)) {
            $bbpPath = $c;
            break;
        }
    }
    if ($bbpPath === '') {
        $bbpPath = '/usr/local/bin/bbpPairings';
    }
}
$bbpPath = trim($bbpPath);
if ($bbpPath === '' || !is_readable($bbpPath)) {
    echo json_encode(['ok' => false, 'error' => 'bbpPairings not found or not readable']);
    exit;
}

$tmpDir = sys_get_temp_dir() . '/';
$trfFile = $tmpDir . 'cp_api_trf_' . bin2hex(random_bytes(8)) . '.trfx';
$outFile = $tmpDir . 'cp_api_out_' . bin2hex(random_bytes(8)) . '.txt';

if (file_put_contents($trfFile, $trf) === false) {
    echo json_encode(['ok' => false, 'error' => 'Could not write temp file']);
    exit;
}

$bbpEsc = escapeshellarg($bbpPath);
$trfEsc = escapeshellarg($trfFile);
$outEsc = escapeshellarg($outFile);
$cmd = $bbpEsc . ' --dutch ' . $trfEsc . ' -p ' . $outEsc . ' 2>&1';

$output = [];
$ret = 0;
exec($cmd, $output, $ret);

@unlink($trfFile);

if ($ret !== 0) {
    $errMsg = implode("\n", $output) ?: "bbpPairings exited with code $ret";
    echo json_encode(['ok' => false, 'error' => $errMsg]);
    exit;
}

if (!is_readable($outFile)) {
    echo json_encode(['ok' => false, 'error' => 'bbpPairings produced no output']);
    exit;
}

$lines = array_map('trim', (array) file($outFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
@unlink($outFile);

$pairs = [];
$pairCount = (int) ($lines[0] ?? 0);
if ($pairCount > 0) {
    for ($i = 1; $i <= $pairCount && isset($lines[$i]); $i++) {
        $parts = preg_split('/\s+/', $lines[$i], 2);
        $wTpn = isset($parts[0]) ? (int) $parts[0] : 0;
        $bTpn = isset($parts[1]) ? (int) $parts[1] : 0;
        $pairs[] = [$wTpn, $bTpn];
    }
}

echo json_encode(['ok' => true, 'pairs' => $pairs]);
