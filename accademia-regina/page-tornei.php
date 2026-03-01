<?php
/**
 * Template Name: Tornei
 * Template Pagina Tornei
 * 
 * I link ai tornei si inseriscono con gli shortcode:
 * [tornei]
 *   [torneo nome="Nome Torneo" url="https://reginacattolica.com/tornei/xyz/" anchor="round1"]
 *   [torneo nome="Altro Torneo" url="https://reginacattolica.com/tornei/abc/"]
 * [/tornei]
 * 
 * Ogni link punta all'index.html della cartella del torneo (generata da Vega).
 * Opzionale: anchor per sezioni specifiche (es. round, classifica).
 *
 * @package Accademia_Regina
 */

get_header();
?>

<div class="container">
    <header class="tornei-intro">
        <h1><?php the_title(); ?></h1>
    </header>

    <div class="tornei-content">
        <?php the_content(); ?>
    </div>
</div>

<?php get_footer(); ?>
