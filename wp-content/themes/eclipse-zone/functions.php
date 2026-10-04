<?php
/**
 * Tema Eclipse Zone. Solo presentación: los tipos de contenido, campos e
 * importador viven en el plugin Eclipse Zone Core (así cambiar de diseño
 * no rompe los datos).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EZT_VERSION', '0.1.0' );

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'custom-logo' );
} );

// Página "Mi cuenta" (favoritos, seguir leyendo): se crea sola al activar el tema
// y usa la plantilla page-mi-cuenta.php.
add_action( 'after_switch_theme', function () {
	if ( ! get_page_by_path( 'mi-cuenta' ) ) {
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Mi cuenta', 'post_name' => 'mi-cuenta' ) );
	}
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'eclipse-zone', get_stylesheet_uri(), array(), EZT_VERSION );

	// JS solo donde hace falta: el contenido ya llega completo en el HTML.
	if ( is_singular( array( 'juego', 'manga' ) ) && is_user_logged_in() ) {
		wp_enqueue_script( 'ez-acciones', get_theme_file_uri( 'assets/acciones.js' ), array(), EZT_VERSION, true );
		wp_localize_script( 'ez-acciones', 'EZ', array(
			'api'   => esc_url_raw( rest_url( 'ez/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
	}
	if ( is_singular( 'manga' ) && function_exists( 'ezc_manga_pages' ) && ezc_manga_pages() ) {
		wp_enqueue_script( 'ez-lector', get_theme_file_uri( 'assets/lector.js' ), array(), EZT_VERSION, true );
	}
} );

// Sin el plugin, el tema no tiene qué mostrar: avisar en el admin.
add_action( 'admin_notices', function () {
	if ( ! function_exists( 'ezc_register_content_types' ) ) {
		echo '<div class="notice notice-error"><p>El tema Eclipse Zone necesita el plugin <strong>Eclipse Zone Core</strong> activado.</p></div>';
	}
} );

/** Términos de una taxonomía como lista de chips enlazados. */
function ezt_chips( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	$out = '<ul class="chips">';
	foreach ( $terms as $t ) {
		$out .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( get_term_link( $t ) ), esc_html( $t->name ) );
	}
	return $out . '</ul>';
}

/** Nombres de términos separados por coma (texto plano). */
function ezt_term_names( $post_id, $taxonomy ) {
	$names = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
	return is_wp_error( $names ) ? '' : implode( ', ', $names );
}

/** Paginación con <a href> reales: /juegos/page/2/, /juegos/page/3/… */
function ezt_pagination() {
	the_posts_pagination( array(
		'mid_size'           => 2,
		'prev_text'          => '‹ Anterior',
		'next_text'          => 'Siguiente ›',
		'screen_reader_text' => 'Más páginas',
	) );
}

function ezt_nav_link( $url, $label, $active ) {
	printf( '<a href="%s"%s>%s</a>', esc_url( $url ), $active ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
}

function ezt_breadcrumbs( array $items ) {
	echo '<nav class="breadcrumbs" aria-label="Ruta"><a href="' . esc_url( home_url( '/' ) ) . '">Inicio</a>';
	foreach ( $items as $url => $label ) {
		echo ' › ' . ( is_string( $url ) ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ) );
	}
	echo '</nav>';
}
