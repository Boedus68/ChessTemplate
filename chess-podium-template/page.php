<?php get_header(); ?>
<main class="content-wrap">
    <div class="container">
        <?php while (have_posts()) : the_post(); ?>
            <article class="card">
                <h1><?php the_title(); ?></h1>
                <?php the_content(); ?>
            </article>
        <?php endwhile; ?>
    </div>
</main>
<?php get_footer(); ?>
