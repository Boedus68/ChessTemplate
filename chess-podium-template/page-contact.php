<?php
/**
 * Template Name: Contact
 */
get_header();
?>
<main class="content-wrap">
    <div class="container">
        <h1><?php chess_podium_te('Contact'); ?></h1>
        <p class="muted"><?php chess_podium_te('Want a demo or partnership? Send us a message.'); ?></p>
        <div class="card">
            <?php
            echo do_shortcode('[contact-form-7 id="ec8e004" title="Contact form 1"]');
            ?>
        </div>
    </div>
</main>
<?php get_footer(); ?>
