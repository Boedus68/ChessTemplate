<?php
/**
 * Accademia Scacchistica La Regina - Functions
 *
 * @package Accademia_Regina
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Setup del tema
 */
function accademia_regina_setup() {
    load_theme_textdomain('accademia-regina', get_template_directory() . '/languages');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array('search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script'));
    add_theme_support('custom-logo', array(
        'height'      => 80,
        'width'       => 80,
        'flex-height' => true,
        'flex-width'  => true,
    ));
    add_theme_support('responsive-embeds');
    
    register_nav_menus(array(
        'primary' => __('Menu principale', 'accademia-regina'),
    ));
}
add_action('after_setup_theme', 'accademia_regina_setup');

/**
 * Enqueue script e stili
 */
function accademia_regina_scripts() {
    wp_enqueue_style(
        'accademia-regina-fonts',
        'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap',
        array(),
        null
    );
    
    wp_enqueue_style(
        'accademia-regina-style',
        get_stylesheet_uri(),
        array('accademia-regina-fonts'),
        wp_get_theme()->get('Version')
    );
    
    // PGN Viewer - caricato solo nelle pagine che lo richiedono (via shortcode)
}
add_action('wp_enqueue_scripts', 'accademia_regina_scripts');

/**
 * Shortcode per link torneo
 * Uso: [torneo nome="Nome Torneo" url="https://..." anchor="round1"]
 * 
 * @param array $atts Attributi shortcode
 * @return string HTML del link
 */
function accademia_regina_torneo_shortcode($atts) {
    $atts = shortcode_atts(array(
        'nome'   => 'Torneo',
        'url'    => '#',
        'anchor' => '',
    ), $atts, 'torneo');
    
    $href = esc_url($atts['url']);
    if (!empty($atts['anchor'])) {
        $href .= '#' . esc_attr($atts['anchor']);
    }
    
    return sprintf(
        '<li><a href="%s" target="_blank" rel="noopener">%s</a></li>',
        $href,
        esc_html($atts['nome'])
    );
}
add_shortcode('torneo', 'accademia_regina_torneo_shortcode');

/**
 * Wrapper shortcode per lista tornei
 * Uso: [tornei]...[torneo nome="..." url="..." anchor=""]...[/tornei]
 */
function accademia_regina_tornei_shortcode($atts, $content = '') {
    return '<ul class="tornei-list">' . do_shortcode($content) . '</ul>';
}
add_shortcode('tornei', 'accademia_regina_tornei_shortcode');

/**
 * Shortcode per visualizzare una partita PGN
 * Uso: [pgn url="https://.../partita.pgn"] oppure [pgn]contenuto PGN[/pgn]
 * 
 * @param array  $atts    Attributi
 * @param string $content Contenuto PGN inline (opzionale)
 * @return string HTML del viewer
 */
function accademia_regina_pgn_shortcode($atts, $content = '') {
    static $pgn_counter = 0;
    $pgn_counter++;
    $id = 'pgn-viewer-' . $pgn_counter;
    
    $atts = shortcode_atts(array(
        'url'     => '',
        'titolo'  => '',
        'layout'  => 'right', // left, right, top, bottom
        'theme'   => 'brown',
    ), $atts, 'pgn');
    
    // Carica PGN Viewer (dist.js contiene tutto, inclusi gli stili)
    wp_enqueue_script(
        'pgn-viewer-js',
        'https://cdn.jsdelivr.net/npm/@mliebelt/pgn-viewer@1.6.11/lib/dist.js',
        array(),
        '1.6.11',
        true
    );
    
    $pgn_data = '';
    if (!empty($atts['url'])) {
        $url = esc_url_raw($atts['url']);
        // Prova a caricare il PGN da URL (stesso dominio o CORS permettendo)
        $response = wp_remote_get($url, array('timeout' => 10));
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $pgn_data = wp_remote_retrieve_body($response);
        } else {
            $pgn_data = $url; // Fallback: passa l'URL al viewer (se supportato)
        }
    } elseif (!empty(trim($content))) {
        $pgn_data = trim($content);
    }
    
    if (empty($pgn_data)) {
        return '<p class="pgn-error">' . __('Nessun dato PGN fornito.', 'accademia-regina') . '</p>';
    }
    
    $output = '<div class="pgn-game-item">';
    if (!empty($atts['titolo'])) {
        $output .= '<h3>' . esc_html($atts['titolo']) . '</h3>';
    }
    $output .= '<div id="' . esc_attr($id) . '" class="pgn-viewer-container"></div>';
    $output .= '</div>';
    
    $config = array(
        'boardSize'   => '400px',
        'layout'      => $atts['layout'],
        'theme'       => $atts['theme'],
        'showResult'  => true,
        'showFen'     => false,
        'pieceStyle'  => 'merida',
        'pgn'         => $pgn_data,
    );
    
    $script = sprintf(
        'document.addEventListener("DOMContentLoaded", function() {
            if (typeof PGNV !== "undefined") {
                PGNV.pgnView("%s", %s);
            }
        });',
        $id,
        wp_json_encode($config)
    );
    
    wp_add_inline_script('pgn-viewer-js', $script, 'after');
    
    return $output;
}
add_shortcode('pgn', 'accademia_regina_pgn_shortcode');
