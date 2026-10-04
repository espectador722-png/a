<?php
/**
 * Redirecciones 301 desde las URLs del sitio anterior (generate-everything.js).
 *
 * Solo actúan cuando WordPress no encontró la página (404), así nunca
 * pisan una URL que existe.
 *
 *   /categoria/<slug>/      → /genero/<slug>/  (o /motor/<slug>/ si era "Ren'Py", "Unity"…)
 *   /juego/<slug-viejo>/    → la ficha cuyo título da ese slug (título cambiado, sufijo -2…)
 *   /noticia/<slug-viejo>/  → idem para noticias
 *   /juegos/?q=texto        → /?s=texto
 *   /index.html, /juegos/index.html … → sin index.html
 *   /mangas/<algo de MangaDex>/ → /mangas/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', function () {
	// /juegos/?q= era el buscador del sitio anterior (SearchAction en el JSON-LD).
	if ( is_post_type_archive( 'juego' ) && isset( $_GET['q'] ) && '' !== $_GET['q'] ) {
		wp_safe_redirect( add_query_arg( 's', rawurlencode( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ), home_url( '/' ) ), 301 );
		exit;
	}
	if ( ! is_404() ) {
		return;
	}
	$target = ezc_legacy_target( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
	if ( $target ) {
		wp_safe_redirect( $target, 301 );
		exit;
	}
}, 1 );

/** URL nueva para una ruta vieja, o null. */
function ezc_legacy_target( $path ) {
	$requested = $path;
	$path      = '/' . trim( preg_replace( '#/index\.html?$#', '/', $path ), '/' ) . '/';

	if ( preg_match( '#^/categoria/([^/]+)/$#', $path, $m ) ) {
		$slug = sanitize_title( urldecode( $m[1] ) );
		foreach ( array( 'genero', 'motor', 'plataforma' ) as $tax ) {
			$term = get_term_by( 'slug', $slug, $tax );
			if ( $term ) {
				return get_term_link( $term );
			}
		}
		return get_post_type_archive_link( 'juego' );
	}

	if ( preg_match( '#^/(juego|noticia)/([^/]+)/$#', $path, $m ) ) {
		$slug = sanitize_title( urldecode( $m[2] ) );
		// Primero el slug tal cual (p. ej. /index.html quitado); después sin el
		// sufijo de duplicado del sitio viejo (-2, -3), que acá pudo quedar distinto.
		foreach ( array_unique( array( $slug, preg_replace( '/-\d+$/', '', $slug ) ) ) as $cand ) {
			$found = get_posts( array( 'post_type' => $m[1], 'name' => $cand, 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( $found ) {
				$url = get_permalink( $found[0] );
				// Sin bucle: nunca redirigir a la misma URL que se pidió.
				return untrailingslashit( $url ) !== untrailingslashit( home_url( $requested ) ) ? $url : null;
			}
		}
		return 'juego' === $m[1] ? get_post_type_archive_link( 'juego' ) : get_post_type_archive_link( 'noticia' );
	}

	if ( preg_match( '#^/mangas?/.+#', $path ) ) {
		return get_post_type_archive_link( 'manga' );
	}

	if ( '/' !== $path && preg_match( '#^/(juegos|noticias|mangas)/$#', $path, $m ) ) {
		return home_url( '/' . $m[1] . '/' );
	}
	return null;
}
