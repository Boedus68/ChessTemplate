<?php
/**
 * Template principale
 *
 * @package Accademia_Regina
 */

get_header();
?>

<div class="container">
    <?php if (have_posts()) : ?>
        <?php if (is_home() && !is_front_page()) : ?>
            <header class="page-header">
                <h1 class="page-title"><?php single_post_title(); ?></h1>
            </header>
        <?php endif; ?>

        <ul class="posts-list">
            <?php while (have_posts()) : the_post(); ?>
                <li class="post-item">
                    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                        <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                        <div class="post-meta">
                            <?php echo get_the_date(); ?> &ndash; <?php the_author(); ?>
                        </div>
                        <div class="excerpt"><?php the_excerpt(); ?></div>
                    </article>
                </li>
            <?php endwhile; ?>
        </ul>

        <?php the_posts_pagination(array(
            'mid_size'  => 2,
            'prev_text' => '&larr;',
            'next_text' => '&rarr;',
        )); ?>
    <?php else : ?>
        <p><?php esc_html_e('Nessun contenuto trovato.', 'accademia-regina'); ?></p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
