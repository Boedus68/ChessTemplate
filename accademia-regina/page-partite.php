<?php
/**
 * Template Name: Partite
 * Template Pagina Partite Interessanti
 * 
 * Le partite si inseriscono con lo shortcode [pgn]:
 * 
 * Da URL (file .pgn sul sito):
 * [pgn url="https://reginacattolica.com/partite/esempio.pgn" titolo="Partita memorabile"]
 * 
 * Inline (PGN nel contenuto):
 * [pgn titolo="Siciliana"]
 * [Event "Torneo"]
 * 1. e4 c5 2. Nf3 d6 ...
 * [/pgn]
 * 
 * Parametri: url, titolo, layout (left|right|top|bottom), theme (brown|blue|green|...)
 *
 * @package Accademia_Regina
 */

get_header();
?>

<div class="container">
    <header class="pgn-page-header">
        <h1><?php the_title(); ?></h1>
    </header>

    <div class="pgn-games-list">
        <?php the_content(); ?>
    </div>
</div>

<?php get_footer(); ?>
