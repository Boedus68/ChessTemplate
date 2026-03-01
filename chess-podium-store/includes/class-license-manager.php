<?php
/**
 * License storage and management for Chess Podium Pro.
 *
 * @package Chess_Podium_Store
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ChessPodium_Store_LicenseManager
{
    private const TABLE = 'chess_podium_licenses';

    public static function create_tables(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_key varchar(64) NOT NULL,
            email varchar(255) NOT NULL,
            domain varchar(255) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            stripe_customer_id varchar(255) DEFAULT NULL,
            stripe_subscription_id varchar(255) DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key),
            KEY email (email),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('chess_podium_store_db_version', 1);
    }

    public static function generate_license_key(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $segments = [];
        for ($i = 0; $i < 4; $i++) {
            $seg = '';
            for ($j = 0; $j < 5; $j++) {
                $seg .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $segments[] = $seg;
        }
        $key = implode('-', $segments);
        if (self::get_by_key($key)) {
            return self::generate_license_key();
        }
        return $key;
    }

    public static function create_license(string $email, ?string $stripeCustomerId = null, ?string $stripeSubscriptionId = null, ?string $domain = null): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $key = self::generate_license_key();
        $expires = gmdate('Y-m-d H:i:s', strtotime('+1 year'));
        $domainNorm = ($domain !== null && trim((string) $domain) !== '') ? self::normalize_domain((string) $domain) : null;
        $wpdb->insert($table, [
            'license_key' => $key,
            'email' => $email,
            'domain' => $domainNorm,
            'status' => 'active',
            'stripe_customer_id' => $stripeCustomerId,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'expires_at' => $expires,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        if ($wpdb->insert_id) {
            return self::get_by_key($key);
        }
        return null;
    }

    public static function get_by_key(string $key): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE license_key = %s",
                $key
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function validate(string $key, string $email, string $domain): array
    {
        $license = self::get_by_key($key);
        if (!$license) {
            return ['valid' => false, 'reason' => 'invalid_key'];
        }
        if (($license['status'] ?? '') !== 'active') {
            return ['valid' => false, 'reason' => 'inactive'];
        }
        if (strtolower(trim($license['email'])) !== strtolower(trim($email))) {
            return ['valid' => false, 'reason' => 'email_mismatch'];
        }
        $expires = $license['expires_at'] ?? null;
        if ($expires && strtotime($expires) < time()) {
            return ['valid' => false, 'reason' => 'expired'];
        }

        $domain = self::normalize_domain($domain);
        $storedDomain = self::normalize_domain((string) ($license['domain'] ?? ''));

        if ($storedDomain !== '') {
            if ($domain === '' || $domain !== $storedDomain) {
                return ['valid' => false, 'reason' => 'domain_mismatch', 'allowed_domain' => $license['domain']];
            }
        } else {
            if ($domain !== '') {
                self::update_domain($license['id'], $domain);
            }
        }

        return ['valid' => true];
    }

    private static function normalize_domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }
        if (strpos($domain, 'www.') === 0) {
            $domain = substr($domain, 4);
        }
        $port = strpos($domain, ':');
        if ($port !== false) {
            $domain = substr($domain, 0, $port);
        }
        return $domain;
    }

    private static function update_domain(int $id, string $domain): void
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->update($table, ['domain' => $domain ?: null], ['id' => $id], ['%s'], ['%d']);
    }

    public static function clear_domain(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE $table SET domain = NULL WHERE id = %d",
            $id
        ));
    }

    public static function revoke(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (bool) $wpdb->update($table, ['status' => 'revoked'], ['id' => $id], ['%s'], ['%d']);
    }

    public static function get_all(int $limit = 100, int $offset = 0): array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function get_count(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    public static function send_license_email(string $email, string $licenseKey): bool
    {
        $subject = sprintf(__('[%s] Your Chess Podium Pro License', 'chess-podium-store'), get_bloginfo('name'));
        $message = sprintf(
            __("Thank you for purchasing Chess Podium Pro!\n\nYour license key: %s\n\nTo activate:\n1. Go to your WordPress admin\n2. Navigate to Chess Podium → License\n3. Enter this key and your email (%s)\n\nSupport: %s", 'chess-podium-store'),
            $licenseKey,
            $email,
            home_url('/contact/')
        );
        return wp_mail($email, $subject, $message, ['Content-Type: text/plain; charset=UTF-8']);
    }

    public static function render_licenses_page(): void
    {
        $licenses = self::get_all(50, 0);
        $domain_cleared = isset($_GET['domain_cleared']) ? (int) $_GET['domain_cleared'] : 0;
        $count = self::get_count();
        $manual_msg = isset($_GET['manual_created']) ? 1 : 0;
        ?>
        <?php if ($manual_msg): ?>
            <div class="notice notice-success"><p><?php echo esc_html__('License created. Key sent to email.', 'chess-podium-store'); ?></p></div>
        <?php endif; ?>
        <?php if ($domain_cleared): ?>
            <div class="notice notice-success"><p><?php echo esc_html__('Domain cleared. License can be activated on a new site.', 'chess-podium-store'); ?></p></div>
        <?php endif; ?>
        <p><?php echo esc_html(sprintf(__('Total licenses: %d', 'chess-podium-store'), $count)); ?></p>
        <h3><?php echo esc_html__('Create license manually', 'chess-podium-store'); ?></h3>
        <p class="description"><?php echo esc_html__('One license per domain. The domain is locked on first activation, or you can pre-set it here.', 'chess-podium-store'); ?></p>
        <form method="post" style="max-width:400px;margin-bottom:1.5rem;">
            <?php wp_nonce_field('chess_podium_manual_license'); ?>
            <input type="hidden" name="chess_podium_manual_license" value="1">
            <p>
                <label for="manual_email"><?php echo esc_html__('Email', 'chess-podium-store'); ?></label><br>
                <input type="email" id="manual_email" name="manual_email" required class="regular-text" style="width:100%;">
            </p>
            <p>
                <label for="manual_domain"><?php echo esc_html__('Domain (optional)', 'chess-podium-store'); ?></label><br>
                <input type="text" id="manual_domain" name="manual_domain" class="regular-text" style="width:100%;" placeholder="example.com">
                <span class="description"><?php echo esc_html__('Leave empty to lock on first activation.', 'chess-podium-store'); ?></span>
            </p>
            <p><button type="submit" class="button button-primary"><?php echo esc_html__('Create & send license', 'chess-podium-store'); ?></button></p>
        </form>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('License Key', 'chess-podium-store'); ?></th>
                    <th><?php echo esc_html__('Email', 'chess-podium-store'); ?></th>
                    <th><?php echo esc_html__('Domain', 'chess-podium-store'); ?></th>
                    <th><?php echo esc_html__('Status', 'chess-podium-store'); ?></th>
                    <th><?php echo esc_html__('Expires', 'chess-podium-store'); ?></th>
                    <th><?php echo esc_html__('Created', 'chess-podium-store'); ?></th>
                    <th><?php echo esc_html__('Actions', 'chess-podium-store'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($licenses as $l): ?>
                <tr>
                    <td><code><?php echo esc_html($l['license_key']); ?></code></td>
                    <td><?php echo esc_html($l['email']); ?></td>
                    <td><?php echo esc_html($l['domain'] ?? '-'); ?></td>
                    <td><?php echo esc_html($l['status']); ?></td>
                    <td><?php echo esc_html($l['expires_at'] ?? '-'); ?></td>
                    <td><?php echo esc_html($l['created_at'] ?? '-'); ?></td>
                    <td>
                        <?php if (!empty($l['domain']) && ($l['status'] ?? '') === 'active'): ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('chess_podium_clear_domain_' . $l['id']); ?>
                            <input type="hidden" name="chess_podium_clear_domain" value="<?php echo (int) $l['id']; ?>">
                            <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('Clear domain? License can be activated on a new site.', 'chess-podium-store')); ?>');"><?php echo esc_html__('Clear domain', 'chess-podium-store'); ?></button>
                        </form>
                        <?php else: ?>
                        —
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
