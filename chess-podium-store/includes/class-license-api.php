<?php
/**
 * License validation API endpoint.
 *
 * @package Chess_Podium_Store
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_Store_LicenseAPI
{
    public static function handle_request(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json_response(['valid' => false], 405);
            return;
        }
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = $_POST;
        }
        $key = isset($data['license_key']) ? trim((string) $data['license_key']) : '';
        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $domain = isset($data['domain']) ? trim((string) $data['domain']) : '';
        if ($key === '' || $email === '') {
            self::json_response(['valid' => false]);
            return;
        }
        $result = ChessPodium_Store_LicenseManager::validate($key, $email, $domain);
        self::json_response($result);
    }

    public static function rest_validate(WP_REST_Request $request): WP_REST_Response
    {
        $key = $request->get_param('license_key') ?: '';
        $email = $request->get_param('email') ?: '';
        $domain = $request->get_param('domain') ?: '';
        if ($key === '' || $email === '') {
            return new WP_REST_Response(['valid' => false], 200);
        }
        $result = ChessPodium_Store_LicenseManager::validate($key, $email, $domain);
        return new WP_REST_Response($result, 200);
    }

    private static function json_response(array $data, int $status = 200): void
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit;
        }
        echo wp_json_encode($data);
        exit;
    }
}
