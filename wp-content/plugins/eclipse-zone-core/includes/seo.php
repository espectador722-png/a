<?php
/**
 * SEO básico. WordPress ya hace de serie: canonical, robots, paginación
 * y el sitemap /wp-sitemap.xml (con juegos, noticias, mangas y taxonomías).
 * Aquí se agrega: título "X vN Español", meta description, Open Graph y
 * JSON-LD (VideoGame, NewsArticle, BreadcrumbList), fecha de actualización
 * en el sitemap e imagen 1200×630 para redes. Si Rank Math o Yoast están
 * activos, se les deja meta, Open Graph y migas a ellos (ver compat.php).
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

/**
 * Plataformas de un juego para el texto SEO.
 * ['android' => bool, 'pc' => bool, 'solo_android' => bool, 'lista' => "PC, Android y JoiPlay"]
 */
function ezc_game_platforms( $post_id ) {
	$names   = (array) wp_get_post_terms( $post_id, 'plataforma', array( 'fields' => 'names' ) );
	$android = array_filter( $names, function ( $n ) {
		return (bool) preg_match( '/android|joiplay|apk/i', $n );
	} );
	$pc      = array_filter( $names, function ( $n ) {
		return (bool) preg_match( '/\bpc\b|windows|linux|mac/i', $n );
	} );
	$lista   = count( $names ) > 1 ? implode( ', ', array_slice( $names, 0, -1 ) ) . ' y ' . end( $names ) : ( $names[0] ?? '' );
	return array(
		'android'      => (bool) $android,
		'pc'           => (bool) $pc,
		// "APK" solo si TODO es Android/JoiPlay (regla del sitio anterior: "PC APK" no existe).
		'solo_android' => $names && count( $android ) === count( $names ),
		'lista'        => $lista,
	);
}

function ezc_game_version( $post_id ) {
	$v = trim( (string) get_post_meta( $post_id, 'ez_version', true ) );
	return '' === $v ? '' : preg_replace( '/^v*(?=\d)/i', 'v', $v );
}

/**
 * Título de la ficha, con las palabras que la gente busca:
 *   "Summertime Saga v21.0 en Español APK"           (solo Android)
 *   "Champion of Realms v0.109 en Español PC y APK"  (PC + Android)
 *   "Juego v1.0 en Español PC"                       (solo PC)
 */
function ezc_game_title( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();
	$version = ezc_game_version( $post_id );
	$p       = ezc_game_platforms( $post_id );
	if ( $p['solo_android'] ) {
		$suffix = ' APK';
	} elseif ( $p['android'] && $p['pc'] ) {
		$suffix = ' PC y APK';
	} elseif ( $p['pc'] ) {
		$suffix = ' PC';
	} else {
		$suffix = '';
	}
	return trim( get_the_title( $post_id ) . ( $version ? ' ' . $version : '' ) ) . ' en Español' . $suffix;
}

/** Variantes de búsqueda reales del juego (para schema alternateName y el texto de la ficha). */
function ezc_game_search_names( $post_id ) {
	$t     = get_the_title( $post_id );
	$p     = ezc_game_platforms( $post_id );
	$names = array( "$t en español", "$t español", "$t traducido al español" );
	if ( $p['android'] ) {
		array_push( $names, "$t APK en español", "$t APK español", "$t en español APK" );
	}
	if ( $p['pc'] ) {
		$names[] = "$t en español PC";
	}
	return $names;
}

/** Descripción para Google: plataformas, versión y traductor delante; luego la sinopsis. */
function ezc_game_description( $post_id ) {
	$t     = get_the_title( $post_id );
	$p     = ezc_game_platforms( $post_id );
	$v     = ezc_game_version( $post_id );
	$trads = implode( ', ', (array) wp_get_post_terms( $post_id, 'traductor', array( 'fields' => 'names' ) ) );
	$para  = $p['lista'] ? ' para ' . $p['lista'] : '';
	if ( $p['android'] ) {
		$para .= ' (APK)';
	}
	$intro = sprintf( 'Descargá %s%s en español%s.', $t, $v ? " $v" : '', $para );
	if ( $trads ) {
		$intro .= " Traducción de $trads.";
	}
	$post = get_post( $post_id );
	$syn  = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
	$text = trim( $intro . ' ' . preg_replace( '/\s+/', ' ', $syn ) );
	// ~155 caracteres, cortando en palabra.
	if ( mb_strlen( $text ) > 158 ) {
		$text = rtrim( mb_substr( $text, 0, 155 ) );
		$text = preg_replace( '/\s+\S*$/u', '', $text ) . '…';
	}
	return $text;
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
		if ( 'juego' === $post->post_type ) {
			return ezc_game_description( $post->ID );
		}
		$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$term = get_queried_object();
		$text = $term->description ?: sprintf( 'Descargá juegos de %s en español para PC y Android (APK). %d juegos traducidos al español en Eclipse Zone.', $term->name, $term->count );
	} else {
		$text = get_bloginfo( 'description' );
	}
	return wp_trim_words( preg_replace( '/\s+/', ' ', (string) $text ), 30, '…' );
}

// Recorte 1200×630 (lo que muestran Discord, WhatsApp, Facebook y X).
add_action( 'after_setup_theme', function () {
	add_image_size( 'ez-og', 1200, 630, true );
} );

// <lastmod> en el sitemap: Google vuelve antes a lo que cambió (versión nueva).
add_filter( 'wp_sitemaps_posts_entry', function ( $entry, $post ) {
	$entry['lastmod'] = get_post_modified_time( 'c', true, $post );
	return $entry;
}, 10, 2 );

// Páginas que no tienen que aparecer en Google.
add_filter( 'wp_robots', function ( $robots ) {
	if ( is_search() || is_page( 'mi-cuenta' ) || get_query_var( 'ez_patreon' ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
} );

/** Imagen para redes: el recorte ez-og si la portada está en el servidor. ['url','w','h'] */
function ezc_og_image_data() {
	$id = is_singular() ? get_queried_object_id() : 0;
	if ( $id && has_post_thumbnail( $id ) ) {
		$src = wp_get_attachment_image_src( get_post_thumbnail_id( $id ), 'ez-og' );
		if ( $src ) {
			return array( 'url' => $src[0], 'w' => $src[1], 'h' => $src[2] );
		}
	}
	$url = ezc_og_image();
	return $url ? array( 'url' => $url, 'w' => 0, 'h' => 0 ) : null;
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
		printf( "<meta property=\"og:type\" content=\"%s\">\n", is_singular() ? 'article' : 'website' );
		if ( is_singular() ) {
			printf( "<meta property=\"og:url\" content=\"%s\">\n", esc_url( get_permalink() ) );
		}
		$og = ezc_og_image_data();
		if ( $og ) {
			printf( "<meta property=\"og:image\" content=\"%s\">\n", esc_url( $og['url'] ) );
			if ( $og['w'] ) {
				printf( "<meta property=\"og:image:width\" content=\"%d\">\n<meta property=\"og:image:height\" content=\"%d\">\n", $og['w'], $og['h'] );
			}
			echo "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
		}
		// Migas para Google ("Inicio › Juegos › Nombre" en vez de la URL).
		$crumbs = ezc_breadcrumb_schema();
		if ( $crumbs ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $crumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
		}
	}

	$schema = ezc_schema();
	if ( $schema ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
}, 5 );

/** Ruta de migas de la página actual: [[nombre, url], ...]. */
function ezc_breadcrumb_trail() {
	$trail = array( array( 'Inicio', home_url( '/' ) ) );
	$archives = array( 'juego' => 'Juegos', 'noticia' => 'Noticias', 'manga' => 'Mangas' );
	if ( is_singular( array_keys( $archives ) ) ) {
		$post    = get_queried_object();
		$trail[] = array( $archives[ $post->post_type ], get_post_type_archive_link( $post->post_type ) );
		if ( $post->post_parent ) {
			$trail[] = array( get_the_title( $post->post_parent ), get_permalink( $post->post_parent ) );
		}
		$trail[] = array( get_the_title( $post ), get_permalink( $post ) );
	} elseif ( is_post_type_archive( array_keys( $archives ) ) ) {
		$type    = get_query_var( 'post_type' );
		$type    = is_array( $type ) ? reset( $type ) : $type;
		$trail[] = array( $archives[ $type ] ?? '', get_post_type_archive_link( $type ) );
	} elseif ( is_tax() ) {
		$term    = get_queried_object();
		$parent  = 'etiqueta' === $term->taxonomy ? 'manga' : 'juego';
		$trail[] = array( $archives[ $parent ], get_post_type_archive_link( $parent ) );
		$trail[] = array( $term->name, get_term_link( $term ) );
	} elseif ( is_page() && ! is_front_page() ) {
		$trail[] = array( get_the_title(), get_permalink() );
	}
	return count( $trail ) > 1 ? $trail : array();
}

function ezc_breadcrumb_schema() {
	$trail = ezc_breadcrumb_trail();
	if ( ! $trail ) {
		return null;
	}
	return array(
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => array_map( function ( $i, $c ) {
			return array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] );
		}, array_keys( $trail ), $trail ),
	);
}

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
		'image'         => ( ezc_og_image_data()['url'] ?? null ),
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
			$base['alternateName']   = ezc_game_search_names( $id );
			$base['description']     = ezc_game_description( $id );
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
