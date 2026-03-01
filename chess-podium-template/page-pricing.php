<?php
/**
 * Template Name: Pricing
 */
get_header();

$cp_success = isset($_GET['cp_success']) && $_GET['cp_success'] === '1';
$ajax_url = admin_url('admin-ajax.php');
$pricing_nonce = wp_create_nonce('chess_podium_checkout');
?>
<main class="content-wrap">
    <div class="container">
        <h1><?php chess_podium_te('Pricing'); ?></h1>
        <p class="muted"><?php chess_podium_te('Start free, upgrade when your club needs advanced automation.'); ?></p>

        <?php if ($cp_success): ?>
            <div class="cp-success-notice card" style="margin-bottom:1.5rem;border-color:var(--cp-blue);">
                <h3 style="margin-top:0;"><?php chess_podium_te('Thank you for your purchase!'); ?></h3>
                <p><?php chess_podium_te('We have sent your Chess Podium Pro license key to your email address. Check your inbox (and spam folder).'); ?></p>
                <p><?php chess_podium_te('To activate: go to your WordPress admin → Chess Podium → License, then enter the key and your email.'); ?></p>
            </div>
        <?php endif; ?>

        <div class="pricing">
            <div class="card">
                <h3><?php chess_podium_te('Free'); ?></h3>
                <p class="price">€0</p>
                <ul>
                    <li><?php chess_podium_te('Core tournament flow'); ?></li>
                    <li><?php chess_podium_te('Swiss pairing basic'); ?></li>
                    <li><?php chess_podium_te('Up to 10 players per tournament'); ?></li>
                    <li><?php chess_podium_te('Public standings page'); ?></li>
                </ul>
                <?php $download_url = apply_filters('chess_podium_download_url', home_url('/download/')); ?>
                <p><a class="btn btn-primary" href="<?php echo esc_url($download_url); ?>" target="_blank" rel="noopener noreferrer"><?php chess_podium_te('Download from WordPress.org'); ?></a></p>
            </div>
            <div class="card cp-pro-card">
                <h3><?php chess_podium_te('Pro Club'); ?></h3>
                <p class="price">€79<span class="price-period">/<?php chess_podium_te('year'); ?></span></p>
                <ul>
                    <li><strong><?php chess_podium_te('Online registration with Stripe & PayPal'); ?></strong> — <?php chess_podium_te('Monetize tournaments: players pay and register automatically'); ?></li>
                    <li><?php chess_podium_te('Unlimited players'); ?></li>
                    <li><?php chess_podium_te('Advanced exports'); ?></li>
                    <li><?php chess_podium_te('PGN pages'); ?></li>
                    <li><?php chess_podium_te('Brand customization'); ?></li>
                </ul>
                <?php if (class_exists('ChessPodium_Store')): ?>
                <form class="cp-checkout-form" data-plan="pro" data-nonce="<?php echo esc_attr($pricing_nonce); ?>" data-ajax="<?php echo esc_url($ajax_url); ?>">
                    <p>
                        <label for="cp_checkout_email" class="screen-reader-text"><?php chess_podium_te('Email'); ?></label>
                        <input type="email" id="cp_checkout_email" name="email" required placeholder="<?php echo chess_podium_attr_t('your@email.com'); ?>" class="regular-text" style="width:100%;max-width:100%;margin-bottom:8px;">
                    </p>
                    <p><button type="submit" class="btn btn-primary cp-checkout-btn"><?php chess_podium_te('Buy Pro — €79/year'); ?></button></p>
                </form>
                <?php else: ?>
                <p><a class="btn btn-primary" href="<?php echo esc_url(home_url('/contact/')); ?>"><?php chess_podium_te('Contact us'); ?></a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php if (class_exists('ChessPodium_Store')): ?>
<script>
(function() {
    var forms = document.querySelectorAll('.cp-checkout-form');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('.cp-checkout-btn');
            var emailInput = form.querySelector('input[type="email"]');
            if (!btn || !emailInput) return;
            var email = emailInput.value.trim();
            if (!email) return;
            btn.disabled = true;
            btn.textContent = '<?php echo esc_js(chess_podium_t('Processing...')); ?>';
            var fd = new FormData();
            fd.append('action', 'chess_podium_create_checkout');
            fd.append('email', email);
            fd.append('plan', form.dataset.plan || 'pro');
            fd.append('_wpnonce', form.dataset.nonce || '');
            fetch(form.dataset.ajax || '', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data && data.data.url) {
                        window.location.href = data.data.url;
                    } else {
                        alert(data.data && data.data.message ? data.data.message : '<?php echo esc_js(chess_podium_t('An error occurred.')); ?>');
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js(chess_podium_t('Buy Pro — €79/year')); ?>';
                    }
                })
                .catch(function() {
                    alert('<?php echo esc_js(chess_podium_t('An error occurred.')); ?>');
                    btn.disabled = false;
                    btn.textContent = '<?php echo esc_js(chess_podium_t('Buy Pro — €79/year')); ?>';
                });
        });
    });
})();
</script>
<?php endif; ?>
<?php get_footer(); ?>
