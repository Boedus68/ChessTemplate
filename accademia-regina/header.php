<?php
/**
 * Header del tema
 *
 * @package Accademia_Regina
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="site-wrapper">

<header class="site-header">
    <div class="container">
        <div class="site-branding">
            <?php if (has_custom_logo()) : ?>
                <?php the_custom_logo(); ?>
            <?php else : ?>
                <div class="site-logo" aria-hidden="true">♕</div>
            <?php endif; ?>
            <div>
                <h1 class="site-title">
                    <a href="<?php echo esc_url(home_url('/')); ?>" rel="home">
                        <?php bloginfo('name'); ?>
                    </a>
                </h1>
                <?php if (get_bloginfo('description')) : ?>
                    <p class="site-description"><?php bloginfo('description'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <nav class="main-navigation" aria-label="<?php esc_attr_e('Menu principale', 'accademia-regina'); ?>">
            <button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false">
                <?php esc_html_e('Menu', 'accademia-regina'); ?>
            </button>
            <?php
            wp_nav_menu(array(
                'theme_location' => 'primary',
                'menu_id'        => 'primary-menu',
                'container'      => false,
                'fallback_cb'    => function() {
                    echo '<ul id="primary-menu">';
                    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'accademia-regina') . '</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/tornei/')) . '">' . esc_html__('Tornei', 'accademia-regina') . '</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/partite/')) . '">' . esc_html__('Partite', 'accademia-regina') . '</a></li>';
                    echo '<li><a href="' . esc_url(get_permalink(get_option('page_for_posts'))) . '">' . esc_html__('Novità', 'accademia-regina') . '</a></li>';
                    echo '<li><a href="' . esc_url(home_url('/contatti/')) . '">' . esc_html__('Contatti', 'accademia-regina') . '</a></li>';
                    echo '</ul>';
                },
            ));
            ?>
        </nav>
    </div>
</header>

<main class="site-content">
