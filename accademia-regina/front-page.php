<?php
/**
 * Template Homepage - Accademia Scacchistica La Regina
 *
 * @package Accademia_Regina
 */

get_header();
?>

<section class="home-hero">
    <div class="container">
        <h1><?php bloginfo('name'); ?></h1>
        <p class="tagline">
            <?php esc_html_e('Il circolo scacchistico di Cattolica: dove la passione per gli scacchi incontra la convivialità e la crescita.', 'accademia-regina'); ?>
        </p>
    </div>
</section>

<div class="container home-sections">
    <section class="home-section">
        <h2><?php esc_html_e('Il nostro scopo', 'accademia-regina'); ?></h2>
        <p>
            <?php esc_html_e('L\'Accademia Scacchistica La Regina di Cattolica è un circolo nato per promuovere gli scacchi sul territorio, creando uno spazio di incontro per appassionati di ogni livello. Crediamo che gli scacchi siano molto più di un gioco: sono un\'occasione di socialità, di crescita personale e di sfida intellettuale.', 'accademia-regina'); ?>
        </p>
    </section>

    <section class="home-section">
        <h2><?php esc_html_e('Finalità e obiettivi', 'accademia-regina'); ?></h2>
        <p>
            <?php esc_html_e('Il nostro circolo si propone di:', 'accademia-regina'); ?>
        </p>
        <ul>
            <li><?php esc_html_e('Organizzare tornei e competizioni amatoriali e agonistiche', 'accademia-regina'); ?></li>
            <li><?php esc_html_e('Diffondere la cultura scacchistica nella comunità locale', 'accademia-regina'); ?></li>
            <li><?php esc_html_e('Offrire momenti di aggregazione e confronto tra i soci', 'accademia-regina'); ?></li>
            <li><?php esc_html_e('Supportare i giocatori nella loro crescita tecnica e strategica', 'accademia-regina'); ?></li>
        </ul>
    </section>

    <section class="home-section">
        <h2><?php esc_html_e('Convivialità e didattica', 'accademia-regina'); ?></h2>
        <p>
            <?php esc_html_e('Per noi la scacchiera è un luogo di incontro. Le partite si disputano in un clima amichevole, dove principianti e esperti giocano fianco a fianco. Chi vuole imparare trova sempre qualcuno disposto a spiegare, a commentare una partita o a suggerire mosse. La didattica è al centro della nostra attività: lezioni, analisi di partite e sessioni di gioco libero aiutano tutti a migliorare.', 'accademia-regina'); ?>
        </p>
    </section>

    <section class="home-section">
        <h2><?php esc_html_e('Perché unirsi a noi', 'accademia-regina'); ?></h2>
        <div class="objectives-grid">
            <div class="objective-card">
                <div class="icon">♟</div>
                <h3><?php esc_html_e('Impara a giocare', 'accademia-regina'); ?></h3>
                <p><?php esc_html_e('Corsi e sessioni per chi muove i primi passi nel mondo degli scacchi.', 'accademia-regina'); ?></p>
            </div>
            <div class="objective-card">
                <div class="icon">🏆</div>
                <h3><?php esc_html_e('Partecipa ai tornei', 'accademia-regina'); ?></h3>
                <p><?php esc_html_e('Tornei locali e nazionali per metterti alla prova e crescere.', 'accademia-regina'); ?></p>
            </div>
            <div class="objective-card">
                <div class="icon">🤝</div>
                <h3><?php esc_html_e('Condividi la passione', 'accademia-regina'); ?></h3>
                <p><?php esc_html_e('Un ambiente accogliente dove incontrare altri appassionati di scacchi.', 'accademia-regina'); ?></p>
            </div>
        </div>
    </section>
</div>

<?php get_footer(); ?>
