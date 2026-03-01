<?php
/**
 * Country/flag helpers for Chess Podium
 *
 * @package Chess_Podium
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_CountryHelper
{
    /** FIDE 3-letter federation code to ISO 3166-1 alpha-2 */
    private const FIDE_TO_ISO = [
        'AFG' => 'AF', 'ALB' => 'AL', 'ALG' => 'DZ', 'AND' => 'AD', 'ANG' => 'AO', 'ARG' => 'AR',
        'ARM' => 'AM', 'AUS' => 'AU', 'AUT' => 'AT', 'AZE' => 'AZ', 'BAH' => 'BH', 'BAN' => 'BD',
        'BEL' => 'BE', 'BIH' => 'BA', 'BLR' => 'BY', 'BOL' => 'BO', 'BRA' => 'BR', 'BRN' => 'BN',
        'BUL' => 'BG', 'BUR' => 'MM', 'CAM' => 'KH', 'CAN' => 'CA', 'CHI' => 'CL', 'CHN' => 'CN',
        'COL' => 'CO', 'CRC' => 'CR', 'CRO' => 'HR', 'CUB' => 'CU', 'CYP' => 'CY', 'CZE' => 'CZ',
        'DEN' => 'DK', 'DOM' => 'DO', 'ECU' => 'EC', 'EGY' => 'EG', 'ENG' => 'GB', 'ESA' => 'SV',
        'EST' => 'EE', 'ETH' => 'ET', 'FIN' => 'FI', 'FRA' => 'FR', 'GEO' => 'GE', 'GER' => 'DE',
        'GRE' => 'GR', 'GUA' => 'GT', 'HKG' => 'HK', 'HON' => 'HN', 'HUN' => 'HU', 'ICE' => 'IS',
        'IND' => 'IN', 'INA' => 'ID', 'IRI' => 'IR', 'IRL' => 'IE', 'ISR' => 'IL', 'ITA' => 'IT',
        'JAM' => 'JM', 'JPN' => 'JP', 'KAZ' => 'KZ', 'KEN' => 'KE', 'KOR' => 'KR', 'KOS' => 'XK',
        'KSA' => 'SA', 'KUW' => 'KW', 'LAT' => 'LV', 'LIB' => 'LB', 'LIE' => 'LI', 'LTU' => 'LT',
        'LUX' => 'LU', 'MAC' => 'MO', 'MAD' => 'MG', 'MAL' => 'MY', 'MAR' => 'MA', 'MDA' => 'MD',
        'MEX' => 'MX', 'MGL' => 'MN', 'MKD' => 'MK', 'MLT' => 'MT', 'MNE' => 'ME', 'MON' => 'MC',
        'NED' => 'NL', 'NOR' => 'NO', 'NZL' => 'NZ', 'PAK' => 'PK', 'PAN' => 'PA', 'PAR' => 'PY',
        'PER' => 'PE', 'PHI' => 'PH', 'POL' => 'PL', 'POR' => 'PT', 'PUR' => 'PR', 'QAT' => 'QA',
        'ROU' => 'RO', 'RSA' => 'ZA', 'RUS' => 'RU', 'SCO' => 'GB', 'SEN' => 'SN', 'SGP' => 'SG',
        'SLO' => 'SI', 'SUI' => 'CH', 'SVK' => 'SK', 'SWE' => 'SE', 'SYR' => 'SY', 'THA' => 'TH',
        'TUN' => 'TN', 'TUR' => 'TR', 'UAE' => 'AE', 'UKR' => 'UA', 'URU' => 'UY', 'USA' => 'US',
        'UZB' => 'UZ', 'VEN' => 'VE', 'VIE' => 'VN', 'WLS' => 'GB', 'YEM' => 'YE',
    ];

    /** ISO 2-letter to country name (for dropdown) */
    public static function get_countries(): array
    {
        return [
            '' => __('— Select country —', 'chess-podium'),
            'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra',
            'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria',
            'AZ' => 'Azerbaijan', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BE' => 'Belgium',
            'BA' => 'Bosnia and Herzegovina', 'BR' => 'Brazil', 'BG' => 'Bulgaria',
            'CA' => 'Canada', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia',
            'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic',
            'DK' => 'Denmark', 'EC' => 'Ecuador', 'EG' => 'Egypt',
            'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FI' => 'Finland', 'FR' => 'France',
            'GE' => 'Georgia', 'DE' => 'Germany', 'GR' => 'Greece', 'HK' => 'Hong Kong',
            'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia',
            'IR' => 'Iran', 'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy',
            'JP' => 'Japan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KR' => 'South Korea',
            'LV' => 'Latvia', 'LB' => 'Lebanon', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
            'MY' => 'Malaysia', 'MT' => 'Malta', 'MX' => 'Mexico', 'MN' => 'Mongolia',
            'ME' => 'Montenegro', 'NL' => 'Netherlands', 'NZ' => 'New Zealand',
            'NO' => 'Norway', 'PK' => 'Pakistan', 'PE' => 'Peru', 'PH' => 'Philippines',
            'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania',
            'RU' => 'Russia', 'SA' => 'Saudi Arabia', 'SG' => 'Singapore', 'SI' => 'Slovenia',
            'ZA' => 'South Africa', 'ES' => 'Spain', 'CH' => 'Switzerland', 'SE' => 'Sweden',
            'SY' => 'Syria', 'TH' => 'Thailand', 'TN' => 'Tunisia', 'TR' => 'Turkey',
            'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom',
            'US' => 'United States', 'UZ' => 'Uzbekistan', 'VE' => 'Venezuela', 'VN' => 'Vietnam',
        ];
    }

    public static function fide_to_iso(string $fideCode): ?string
    {
        $key = strtoupper(substr($fideCode, 0, 3));
        return self::FIDE_TO_ISO[$key] ?? null;
    }

    /** Convert ISO 2-letter code to flag emoji (Unicode regional indicators) */
    public static function flag_emoji(string $iso): string
    {
        $iso = strtoupper(substr($iso, 0, 2));
        if (strlen($iso) !== 2 || $iso[0] < 'A' || $iso[0] > 'Z' || $iso[1] < 'A' || $iso[1] > 'Z') {
            return '';
        }
        $flag = '';
        for ($i = 0; $i < 2; $i++) {
            $flag .= mb_chr(0x1F1E6 + ord($iso[$i]) - ord('A'));
        }
        return $flag;
    }

    /** Render flag with optional title for accessibility (emoji - works in admin) */
    public static function render_flag(string $iso, string $title = ''): string
    {
        $emoji = self::flag_emoji($iso);
        if ($emoji === '') {
            return '';
        }
        $title = $title ?: $iso;
        return '<span class="cp-flag" title="' . esc_attr($title) . '" role="img" aria-label="' . esc_attr($title) . '">' . $emoji . '</span>';
    }

    /** Render flag as image (reliable in static HTML/export - emoji may not display on all systems) */
    public static function render_flag_img(string $iso, string $title = '', int $width = 20): string
    {
        $iso = strtoupper(substr($iso, 0, 2));
        if (strlen($iso) !== 2 || !ctype_alpha($iso)) {
            return '';
        }
        $title = $title ?: $iso;
        $height = (int) round($width * 0.75);
        $url = 'https://flagcdn.com/w' . $width . '/' . strtolower($iso) . '.png';
        return '<img src="' . esc_url($url) . '" alt="' . esc_attr($title) . '" title="' . esc_attr($title) . '" class="cp-flag-img" width="' . $width . '" height="' . $height . '" loading="lazy" style="vertical-align:middle;">';
    }
}
