<?php
/**
 * Template Blog / Novità
 * Usato quando è impostata una pagina statica come homepage.
 *
 * @package Accademia_Regina
 */

get_header();
?>

<div class="container">
    <header class="page-header">
        <h1><?php esc_html_e('Novità', 'accademia-regina'); ?></h1>
        <p class="page-description">
            <?php esc_html_e('Le ultime notizie e gli aggiornamenti dall\'Accademia Scacchistica La Regina.', 'accademia-regina'); ?>
        </p>
    </header>

    <?php if (have_posts()) : ?>
        <ul class="posts-list">
            <?php while (have_posts()) : the_post(); ?>
                <li class="post-item">
                    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                        <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                        <div class="post-meta">
                            <?php echo get_the_date(); ?>
                            <?php if (get_the_author()) : ?>
                                &ndash; <?php the_author(); ?>
                            <?php endif; ?>
                        </div>
                        <div class="excerpt"><?php the_excerpt(); ?></div>
                    </article>
                </li>
            <?php endwhile; ?>
        </ul>

        <?php
        the_posts_pagination(array(
            'mid_size'  => 2,
            'prev_text' => '&larr; ' . __('Precedente', 'accademia-regina'),
            'next_text' => __('Successivo', 'accademia-regina') . ' &rarr;',
        ));
        ?>
    <?php else : ?>
        <p><?php esc_html_e('Nessun articolo pubblicato ancora. Torna presto!', 'accademia-regina'); ?></p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
