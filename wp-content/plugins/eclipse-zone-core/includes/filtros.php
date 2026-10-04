<?php
/**
 * Filtros del catálogo (/juegos/?genero=rpg&motor=renpy&orden=vistas…) y
 * listas para la página de Tops.
 *
 * Parámetros (todos opcionales, combinables):
 *   q        texto en el título
 *   genero, motor, estado, traductor, plataforma   slug del término (varios separados por coma)
 *   orden    recientes (por defecto) | vistas | puntuacion | titulo | nuevos
 *
 * Las páginas filtradas llevan noindex,follow: Google sigue los enlaces a
 * las fichas, pero no indexa miles de combinaciones de filtros.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ezc_filter_taxonomies() {
	return array(
		'genero'     => 'Género',
		'motor'      => 'Motor',
		'estado'     => 'Estado',
		'plataforma' => 'Plataforma',
		'traductor'  => 'Traductor',
	);
}

function ezc_orders() {
	return array(
		'recientes'  => 'Actualizados',
		'nuevos'     => 'Nuevos',
		'vistas'     => 'Más vistos',
		'puntuacion' => 'Mejor puntuados',
		'titulo'     => 'A-Z',
	);
}

/** Filtros activos según la URL: ['genero' => ['rpg'], ...]. */
function ezc_active_filters() {
	$out = array();
	foreach ( array_keys( ezc_filter_taxonomies() ) as $tax ) {
		// En /genero/rpg/ el término de la URL ya filtra: no se lee el GET de esa misma taxonomía.
		if ( isset( $_GET[ $tax ] ) && '' !== $_GET[ $tax ] && ! is_tax( $tax ) ) {
			$out[ $tax ] = array_filter( array_map( 'sanitize_title', explode( ',', wp_unslash( $_GET[ $tax ] ) ) ) );
		}
	}
	return array_filter( $out );
}

function ezc_current_order() {
	$o = isset( $_GET['orden'] ) ? sanitize_key( wp_unslash( $_GET['orden'] ) ) : 'recientes';
	return isset( ezc_orders()[ $o ] ) ? $o : 'recientes';
}

function ezc_is_filtered() {
	return ezc_active_filters() || 'recientes' !== ezc_current_order() || ! empty( $_GET['q'] );
}

/** Orden para WP_Query. */
function ezc_order_args( $orden ) {
	switch ( $orden ) {
		case 'vistas':
			// Todos los juegos tienen el contador (en 0 si no tienen vistas,
			// ver estadisticas.php), así ninguno queda afuera del listado.
			return array( 'meta_key' => 'ez_vistas_total', 'orderby' => array( 'meta_value_num' => 'DESC', 'modified' => 'DESC' ) );
		case 'puntuacion':
			return array( 'meta_key' => 'ez_nota_media', 'orderby' => array( 'meta_value_num' => 'DESC', 'modified' => 'DESC' ) );
		case 'titulo':
			return array( 'orderby' => 'title', 'order' => 'ASC' );
		case 'nuevos':
			return array( 'orderby' => 'date', 'order' => 'DESC' );
		default:
			return array( 'orderby' => 'modified', 'order' => 'DESC' );
	}
}

add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	$taxes = array_keys( ezc_filter_taxonomies() );
	$taxes[] = 'desarrollador';
	if ( ! $query->is_post_type_archive( 'juego' ) && ! $query->is_tax( $taxes ) ) {
		return;
	}

	$tax_query = array();
	foreach ( ezc_active_filters() as $tax => $slugs ) {
		$tax_query[] = array( 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $slugs, 'operator' => 'IN' );
	}
	if ( $tax_query ) {
		$prev = (array) $query->get( 'tax_query' );
		$query->set( 'tax_query', array_merge( array( 'relation' => 'AND' ), $prev, $tax_query ) );
	}
	if ( ! empty( $_GET['q'] ) ) {
		$query->set( 's', sanitize_text_field( wp_unslash( $_GET['q'] ) ) );
	}
	foreach ( ezc_order_args( ezc_current_order() ) as $k => $v ) {
		$query->set( $k, $v );
	}
}, 20 ); // después del pre_get_posts de content-types.php (que pone el orden por defecto)

// Las búsquedas dentro del catálogo no deben convertirse en la plantilla de búsqueda global.
add_action( 'parse_query', function ( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->is_post_type_archive( 'juego' ) && ! empty( $_GET['q'] ) ) {
		$query->is_search = false;
	}
}, 99 );

add_filter( 'wp_robots', function ( $robots ) {
	if ( ( is_post_type_archive( 'juego' ) || is_tax() ) && ezc_is_filtered() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );

/**
 * Mejor puntuados con media bayesiana: un 5,0 con 1 voto no le gana a un
 * 4,8 con 40. Devuelve [['post' => WP_Post, 'media' => 4.8, 'votos' => 40], ...].
 */
function ezc_top_rated( $limit = 30, $type = 'juego', $min_votes = 1 ) {
	$key = "ezc_toprated_{$type}_{$limit}_{$min_votes}";
	$ids = get_transient( $key );
	if ( false === $ids ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, CAST(m.meta_value AS DECIMAL(4,2)) AS media, CAST(v.meta_value AS UNSIGNED) AS votos
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'ez_nota_media'
			 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'ez_nota_votos'
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND CAST(v.meta_value AS UNSIGNED) >= %d",
			$type, $min_votes
		) );
		$total_votes = array_sum( wp_list_pluck( $rows, 'votos' ) );
		$global_mean = $total_votes ? array_sum( array_map( function ( $r ) {
			return $r->media * $r->votos;
		}, $rows ) ) / $total_votes : 0;
		$c = 5; // votos "de prueba" con la media general
		usort( $rows, function ( $a, $b ) use ( $global_mean, $c ) {
			$sa = ( $c * $global_mean + $a->media * $a->votos ) / ( $c + $a->votos );
			$sb = ( $c * $global_mean + $b->media * $b->votos ) / ( $c + $b->votos );
			return $sb <=> $sa;
		} );
		$ids = array();
		foreach ( array_slice( $rows, 0, $limit ) as $r ) {
			$ids[ (int) $r->ID ] = array( (float) $r->media, (int) $r->votos );
		}
		set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );
	}
	$out = array();
	foreach ( $ids as $id => list( $media, $votos ) ) {
		if ( $post = get_post( $id ) ) {
			$out[] = array( 'post' => $post, 'media' => $media, 'votos' => $votos );
		}
	}
	return $out;
}
