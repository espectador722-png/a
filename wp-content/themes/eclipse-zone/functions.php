<?php
/**
 * Tema Eclipse Zone. Solo presentación: los tipos de contenido, campos e
 * importador viven en el plugin Eclipse Zone Core (así cambiar de diseño
 * no rompe los datos).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EZT_VERSION', '0.3.0' );

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

// Conectar antes con Google Fonts (las fuentes cargan con display=swap).
add_filter( 'wp_resource_hints', function ( $urls, $type ) {
	if ( 'preconnect' === $type ) {
		$urls[] = 'https://fonts.googleapis.com';
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	return $urls;
}, 10, 2 );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'ezt-fonts', 'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600;700&family=Orbitron:wght@600;700;800&display=swap', array(), null );
	wp_enqueue_style( 'eclipse-zone', get_stylesheet_uri(), array( 'ezt-fonts' ), EZT_VERSION );

	// JS chico y diferido: carrusel, pestañas del top, franja de Discord,
	// contador de vistas, votos y favoritos. El contenido ya llega en el HTML.
	wp_enqueue_script( 'ez-ui', get_theme_file_uri( 'assets/ui.js' ), array(), EZT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script( 'ez-ui', 'EZ', array(
		'api'    => esc_url_raw( rest_url( 'ez/v1/' ) ),
		'nonce'  => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		'logged' => is_user_logged_in(),
		'login'  => wp_login_url( is_singular() ? get_permalink() : home_url( '/' ) ),
		'vista'  => is_singular( array( 'juego', 'manga' ) ) ? get_queried_object_id() : 0,
	) );
	if ( is_singular( 'manga' ) && function_exists( 'ezc_manga_pages' ) && ezc_manga_pages() ) {
		wp_enqueue_script( 'ez-lector', get_theme_file_uri( 'assets/lector.js' ), array( 'ez-ui' ), EZT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
} );

// Apariencia → Personalizar → Eclipse Zone: enlace de Discord de la franja.
add_action( 'customize_register', function ( $wp_customize ) {
	$wp_customize->add_section( 'ezt', array( 'title' => 'Eclipse Zone', 'priority' => 30 ) );
	$wp_customize->add_setting( 'ezt_discord_url', array( 'default' => '', 'sanitize_callback' => 'esc_url_raw' ) );
	$wp_customize->add_control( 'ezt_discord_url', array( 'label' => 'Invitación de Discord (vacío = sin franja)', 'section' => 'ezt', 'type' => 'url' ) );
	$wp_customize->add_setting( 'ezt_discord_text', array( 'default' => '¡Únete a nuestra comunidad y no te pierdas las novedades!', 'sanitize_callback' => 'sanitize_text_field' ) );
	$wp_customize->add_control( 'ezt_discord_text', array( 'label' => 'Texto de la franja', 'section' => 'ezt', 'type' => 'text' ) );
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

/** "hace 8 horas" */
function ezt_ago( $post = null ) {
	return 'hace ' . human_time_diff( (int) get_post_modified_time( 'U', true, $post ), time() );
}

/** Clase de color para el motor: ren-py, unity, rpg-maker… */
function ezt_engine( $post_id ) {
	$terms = get_the_terms( $post_id, 'motor' );
	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
}

/** Bloque de puntuación: ★ 4,6 (12). Sin votos: ★ — (0). */
function ezt_rating_html( $post_id ) {
	$r = function_exists( 'ezc_rating' ) ? ezc_rating( $post_id ) : array( 'media' => 0, 'votos' => 0 );
	return sprintf(
		'<span class="stat stat--rating" title="%3$s"><span aria-hidden="true">★</span> %1$s <small>(%2$d)</small></span>',
		$r['votos'] ? esc_html( number_format_i18n( $r['media'], 1 ) ) : '—',
		(int) $r['votos'],
		esc_attr( sprintf( '%s de 5, %d votos', number_format_i18n( $r['media'], 1 ), $r['votos'] ) )
	);
}

function ezt_views_html( $post_id ) {
	$v = function_exists( 'ezc_views' ) ? ezc_views( $post_id ) : 0;
	return sprintf( '<span class="stat" title="%1$s vistas"><span aria-hidden="true">👁</span> %1$s</span>', esc_html( number_format_i18n( $v ) ) );
}
