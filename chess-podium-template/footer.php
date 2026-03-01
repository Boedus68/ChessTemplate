<?php if (!defined('ABSPATH')) { exit; } ?>

<footer class="site-footer">
    <div class="container">
        <?php if (function_exists('chess_podium_language_badges')) : ?>
            <div style="margin-bottom:1rem;"><?php chess_podium_language_badges(); ?></div>
        <?php endif; ?>
        <p>&copy; <?php echo esc_html(date('Y')); ?> Chess Podium. <?php chess_podium_te('Run your chess tournaments with confidence.'); ?></p>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
