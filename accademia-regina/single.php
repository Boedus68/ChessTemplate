<?php
/**
 * Template singolo articolo
 *
 * @package Accademia_Regina
 */

get_header();
?>

<div class="container">
    <?php while (have_posts()) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('single-post'); ?>>
            <header class="entry-header">
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <div class="post-meta">
                    <?php echo get_the_date(); ?>
                    <?php if (get_the_author()) : ?>
                        &ndash; <?php the_author(); ?>
                    <?php endif; ?>
                </div>
            </header>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
</div>

<?php get_footer(); ?>
