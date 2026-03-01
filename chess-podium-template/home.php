<?php get_header(); ?>
<main class="content-wrap">
    <div class="container">
        <h1>Blog</h1>
        <?php if (have_posts()) : ?>
            <?php while (have_posts()) : the_post(); ?>
                <article class="card" style="margin-bottom:12px;">
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <p class="muted"><?php echo esc_html(get_the_date()); ?></p>
                    <?php the_excerpt(); ?>
                </article>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>
</main>
<?php get_footer(); ?>
