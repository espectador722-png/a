<?php
/**
 * Compatibilidad con los plugins recomendados.
 *
 * Rank Math / Yoast: cuando están activos manejan meta, Open Graph, sitemap
 * y migas. Acá se les pasa lo propio de Eclipse Zone:
 *   - título "Juego v0.1 en Español" en las fichas,
 *   - la portada (aunque sea una URL externa) como imagen para redes,
 *   - se quita su schema genérico (Article/WebPage) de juegos y mangas,
 *     porque el plugin ya imprime VideoGame/ComicSeries, que es más preciso.
 *
 * Caché (LiteSpeed Cache, WP Super Cache, W3TC, WP Rocket): no cachear
 * /mi-cuenta/ ni /patreon/*, y vaciar la ficha cuando cambian votos,
 * reacciones o comentarios no hace falta: esos números se actualizan por JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Rank Math ─────────────────────────────────────────────────

add_filter( 'rank_math/frontend/title', function ( $title ) {
	if ( is_singular( 'juego' ) ) {
		return ezc_game_title() . ' - ' . get_bloginfo( 'name' );
	}
	return $title;
} );

add_filter( 'rank_math/opengraph/facebook/image', function ( $img ) {
	return $img ?: ( ezc_og_image_data()['url'] ?? $img );
} );
add_filter( 'rank_math/opengraph/twitter/image', function ( $img ) {
	return $img ?: ( ezc_og_image_data()['url'] ?? $img );
} );

add_filter( 'rank_math/json_ld', function ( $data ) {
	if ( is_singular( array( 'juego', 'manga' ) ) ) {
		foreach ( $data as $k => $item ) {
			$type = (array) ( $item['@type'] ?? '' );
			if ( array_intersect( $type, array( 'Article', 'BlogPosting', 'NewsArticle' ) ) ) {
				unset( $data[ $k ] );
			}
		}
	}
	return $data;
}, 99 );

// ── Yoast ─────────────────────────────────────────────────────

add_filter( 'wpseo_title', function ( $title ) {
	return is_singular( 'juego' ) ? ezc_game_title() . ' - ' . get_bloginfo( 'name' ) : $title;
} );
add_filter( 'wpseo_opengraph_image', function ( $img ) {
	return $img ?: ( ezc_og_image_data()['url'] ?? $img );
} );
add_filter( 'wpseo_schema_graph_pieces', function ( $pieces ) {
	if ( is_singular( array( 'juego', 'manga' ) ) ) {
		$pieces = array_filter( $pieces, function ( $p ) {
			return ! ( $p instanceof \Yoast\WP\SEO\Generators\Schema\Article );
		} );
	}
	return $pieces;
}, 99 );

// ── Caché de páginas ──────────────────────────────────────────

add_action( 'template_redirect', function () {
	if ( is_page( 'mi-cuenta' ) || get_query_var( 'ez_patreon' ) ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Super Cache, W3TC, WP Rocket
		}
		do_action( 'litespeed_control_set_nocache', 'Eclipse Zone: página personal' );
		nocache_headers();
	}
}, 0 );

// Al publicar o actualizar un juego, vaciar también el inicio y el catálogo
// (muestran "Últimas publicaciones").
add_action( 'save_post_juego', function ( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return;
	}
	do_action( 'litespeed_purge_url', home_url( '/' ) );
	do_action( 'litespeed_purge_url', get_post_type_archive_link( 'juego' ) );
	if ( function_exists( 'wpsc_delete_url_cache' ) ) {
		wpsc_delete_url_cache( home_url( '/' ) );
		wpsc_delete_url_cache( get_post_type_archive_link( 'juego' ) );
	}
} );

// ── Seguridad (Wordfence / Solid Security) ────────────────────
// Nada que configurar: los endpoints /wp-json/ez/v1/* usan el nonce de
// WordPress y solo aceptan POST. Si el firewall bloquea la REST API para
// visitantes, dejar permitido /wp-json/ez/v1/vista (cuenta las vistas).
