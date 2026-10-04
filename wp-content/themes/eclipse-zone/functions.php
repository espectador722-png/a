<?php
/**
 * Tema Eclipse Zone. Solo presentación: los tipos de contenido, campos e
 * importador viven en el plugin Eclipse Zone Core (así cambiar de diseño
 * no rompe los datos).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EZT_VERSION', '0.4.0' );

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
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
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

/** Traductores con su color: <span style="--c:#f472b6">Nombre</span>, … */
function ezt_translators_html( $post_id ) {
	$terms = get_the_terms( $post_id, 'traductor' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	return implode( ', ', array_map( function ( $t ) {
		$color = function_exists( 'ezc_translator_color' ) ? ezc_translator_color( $t ) : '#c4b5fd';
		return sprintf( '<span class="trad" style="--c:%s">%s</span>', esc_attr( $color ), esc_html( $t->name ) );
	}, $terms ) );
}

/** Barra de reacciones con emojis (👍 ❤️ 🔥 😂 😮 😢) y sus totales. */
function ezt_reactions_html( $post_id ) {
	if ( ! function_exists( 'ezc_reactions' ) ) {
		return '';
	}
	$counts = ezc_reaction_counts( $post_id );
	$mias   = ezc_user_reactions( $post_id );
	$out    = '<div class="reactions" data-ez-react="' . (int) $post_id . '" role="group" aria-label="Reacciones">';
	foreach ( ezc_reactions() as $key => $emoji ) {
		$n    = (int) ( $counts[ $key ] ?? 0 );
		$out .= sprintf(
			'<button type="button" value="%1$s" aria-pressed="%2$s" aria-label="%1$s: %4$d"><span aria-hidden="true">%3$s</span> <b>%5$s</b></button>',
			esc_attr( $key ),
			in_array( $key, $mias, true ) ? 'true' : 'false',
			$emoji,
			$n,
			$n ? esc_html( number_format_i18n( $n ) ) : ''
		);
	}
	return $out . '</div>';
}

/** Un comentario en la lista (callback de wp_list_comments). */
function ezt_comment( $comment, $args, $depth ) {
	$tag = 'div' === $args['style'] ? 'div' : 'li';
	?>
	<<?php echo $tag; // phpcs:ignore ?> id="comment-<?php comment_ID(); ?>" <?php comment_class( 'comment' ); ?>>
		<article class="comment__body">
			<header class="comment__head">
				<?php echo get_avatar( $comment, 40, '', '', array( 'class' => 'comment__avatar' ) ); ?>
				<span class="comment__author"><?php comment_author(); ?></span>
				<time class="comment__date" datetime="<?php comment_time( 'c' ); ?>"><?php echo esc_html( 'hace ' . human_time_diff( get_comment_time( 'U', true ), time() ) ); ?></time>
			</header>
			<?php if ( '0' === $comment->comment_approved ) : ?>
				<p class="comment__pending">Tu comentario está esperando aprobación.</p>
			<?php endif; ?>
			<div class="comment__text"><?php comment_text(); ?></div>
			<?php
			comment_reply_link( array_merge( $args, array(
				'depth'     => $depth,
				'max_depth' => $args['max_depth'],
				'before'    => '<div class="comment__reply">',
				'after'     => '</div>',
			) ) );
			?>
		</article>
	<?php
	// wp_list_comments cierra la etiqueta.
}

/** Estrellas para votar (1-5), resultado y vistas. */
function ezt_vote_html( $post_id ) {
	if ( ! function_exists( 'ezc_rating' ) ) {
		return '';
	}
	$r     = ezc_rating( $post_id );
	$tuyo  = ezc_user_vote( $post_id );
	$views = ezc_views( $post_id );
	$out   = '<div class="vote" data-ez-vote="' . (int) $post_id . '"><div class="vote__stars" role="group" aria-label="Puntuar del 1 al 5">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$out .= sprintf( '<button type="button" value="%1$d" aria-label="%1$d de 5" aria-pressed="%2$s">★</button>', $i, $i <= $tuyo ? 'true' : 'false' );
	}
	$out .= '</div><p class="vote__result" data-ez-vote-result>';
	$out .= $r['votos']
		? esc_html( sprintf( '%s de 5 · %d %s', number_format_i18n( $r['media'], 1 ), $r['votos'], 1 === $r['votos'] ? 'voto' : 'votos' ) . ( $tuyo ? ' · tu voto: ' . $tuyo : '' ) )
		: 'Sin votos todavía. ¡Sé el primero!';
	$out .= '</p><p class="vote__views"><span aria-hidden="true">👁</span> <span data-ez-views>' . esc_html( number_format_i18n( $views ) . ( 1 === $views ? ' vista' : ' vistas' ) ) . '</span></p></div>';
	return $out;
}

// La barra negra de WordPress solo para quien administra: a los lectores les
// tapaba el menú.
add_filter( 'show_admin_bar', function ( $show ) {
	return $show && current_user_can( 'edit_posts' );
} );
