<?php
/**
 * Footer del tema
 *
 * @package Accademia_Regina
 */
?>

</main><!-- .site-content -->

<footer class="site-footer">
    <div class="container">
        <div class="footer-info">
            <p>
                &copy; <?php echo date('Y'); ?> 
                <strong><?php bloginfo('name'); ?></strong> &ndash; 
                <?php esc_html_e('Accademia Scacchistica La Regina di Cattolica', 'accademia-regina'); ?>
            </p>
        </div>
    </div>
</footer>

</div><!-- .site-wrapper -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.querySelector('.menu-toggle');
    var nav = document.querySelector('.main-navigation');
    if (toggle && nav) {
        toggle.addEventListener('click', function() {
            nav.classList.toggle('toggled');
            toggle.setAttribute('aria-expanded', nav.classList.contains('toggled'));
        });
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
