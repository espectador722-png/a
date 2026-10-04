<?php
/**
 * SEO básico. WordPress ya hace de serie: canonical, robots, paginación
 * y el sitemap /wp-sitemap.xml (con juegos, noticias, mangas y taxonomías).
 * Aquí se agrega: título "X vN Español", meta description, Open Graph y
 * JSON-LD. Si Rank Math o Yoast están activos, se les deja la meta
 * description y Open Graph a ellos para no duplicar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// El sitemap de usuarios publicaría los nombres de login (incluido el admin).
add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}, 10, 2 );

// Las entradas/categorías normales de WP no se usan: fuera del sitemap.
add_filter( 'wp_sitemaps_post_types', function ( $types ) {
	unset( $types['post'] );
	return $types;
} );
add_filter( 'wp_sitemaps_taxonomies', function ( $taxes ) {
	unset( $taxes['category'] );
	return $taxes;
} );

function ezc_seo_plugin_active() {
	return defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' );
}

/** "Champion of Realms v0.109 en Español" — mismo formato que el sitio anterior. */
function ezc_game_title( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();
	$version = trim( (string) get_post_meta( $post_id, 'ez_version', true ) );
	if ( $version !== '' ) {
		$version = ' ' . preg_replace( '/^v*(?=\d)/i', 'v', $version );
	}
	return get_the_title( $post_id ) . $version . ' en Español';
}

add_filter( 'document_title_parts', function ( $parts ) {
	if ( is_singular( 'juego' ) ) {
		$parts['title'] = ezc_game_title();
	} elseif ( is_post_type_archive( 'juego' ) ) {
		$parts['title'] = 'Juegos traducidos al español';
	} elseif ( is_post_type_archive( 'manga' ) ) {
		$parts['title'] = 'Mangas en español';
	}
	return $parts;
} );

function ezc_meta_description() {
	if ( is_singular() ) {
		$post = get_queried_object();
		$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		if ( '' === trim( $text ) && 'juego' === $post->post_type ) {
			$text = 'Descarga ' . get_the_title( $post ) . ' traducido al español gratis en Eclipse Zone.';
		}
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$term = get_queried_object();
		$text = $term->description ?: sprintf( 'Juegos de %s traducidos al español en Eclipse Zone.', $term->name );
	} else {
		$text = get_bloginfo( 'description' );
	}
	return wp_trim_words( preg_replace( '/\s+/', ' ', (string) $text ), 30, '…' );
}

function ezc_og_image() {
	if ( ! is_singular() ) {
		return '';
	}
	$id = get_queried_object_id();
	switch ( get_post_type( $id ) ) {
		case 'juego':
			return ezc_get_cover_url( $id );
		case 'manga':
			return ezc_manga_cover_url( $id );
		default:
			return has_post_thumbnail( $id ) ? get_the_post_thumbnail_url( $id, 'large' ) : get_post_meta( $id, 'ez_imagen', true );
	}
}

add_action( 'wp_head', function () {
	if ( ! ezc_seo_plugin_active() ) {
		$desc = ezc_meta_description();
		$img  = ezc_og_image();
		if ( $desc ) {
			printf( "<meta name=\"description\" content=\"%s\">\n", esc_attr( $desc ) );
			printf( "<meta property=\"og:description\" content=\"%s\">\n", esc_attr( $desc ) );
		}
		printf( "<meta property=\"og:title\" content=\"%s\">\n", esc_attr( wp_get_document_title() ) );
		printf( "<meta property=\"og:site_name\" content=\"%s\">\n", esc_attr( get_bloginfo( 'name' ) ) );
		printf( "<meta property=\"og:locale\" content=\"es_ES\">\n" );
		if ( $img ) {
			printf( "<meta property=\"og:image\" content=\"%s\">\n", esc_url( $img ) );
			echo "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
		}
	}

	$schema = ezc_schema();
	if ( $schema ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
}, 5 );

function ezc_schema() {
	if ( ! is_singular( array( 'juego', 'noticia', 'manga' ) ) ) {
		return null;
	}
	$id   = get_queried_object_id();
	$url  = get_permalink( $id );
	$base = array(
		'@context'      => 'https://schema.org',
		'url'           => $url,
		'name'          => get_the_title( $id ),
		'inLanguage'    => 'es',
		'datePublished' => get_post_time( 'c', true, $id ),
		'dateModified'  => get_post_modified_time( 'c', true, $id ),
		'image'         => ezc_og_image() ?: null,
		'description'   => ezc_meta_description(),
		'publisher'     => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
	);

	switch ( get_post_type( $id ) ) {
		case 'juego':
			$base['@type']         = 'VideoGame';
			$base['name']          = ezc_game_title( $id );
			$base['gamePlatform']  = wp_get_post_terms( $id, 'plataforma', array( 'fields' => 'names' ) );
			$base['genre']         = wp_get_post_terms( $id, 'genero', array( 'fields' => 'names' ) );
			$base['softwareVersion'] = get_post_meta( $id, 'ez_version', true ) ?: null;
			$base['translator']    = array_map( function ( $n ) {
				return array( '@type' => 'Person', 'name' => $n );
			}, wp_get_post_terms( $id, 'traductor', array( 'fields' => 'names' ) ) );
			break;
		case 'noticia':
			$base['@type']    = 'NewsArticle';
			$base['headline'] = get_the_title( $id );
			break;
		case 'manga':
			$base['@type'] = wp_get_post_parent_id( $id ) ? 'Chapter' : 'ComicSeries';
			break;
	}
	return array_filter( $base, function ( $v ) {
		return null !== $v && array() !== $v && '' !== $v;
	} );
}
