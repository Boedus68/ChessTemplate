<?php
/**
 * Template Name: Contatti
 * Template Pagina Contatti
 *
 * @package Accademia_Regina
 */

get_header();
?>

<div class="container">
    <header class="contatti-header">
        <h1><?php the_title(); ?></h1>
        <?php
        $content = get_the_content();
        if (trim($content)) {
            echo '<div class="contatti-intro">' . apply_filters('the_content', $content) . '</div>';
        }
        ?>
    </header>

    <div class="contatti-form-wrapper">
        <?php echo do_shortcode('[contact-form-7 id="ec8e004" title="Modulo di contatto 1"]'); ?>
    </div>
</div>

<?php get_footer(); ?>
