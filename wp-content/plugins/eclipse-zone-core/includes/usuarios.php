<?php
/**
 * Cosas personales de cada usuario (como en la biblioteca local):
 * favoritos, progreso de lectura, leído/sin leer e historial.
 *
 * Se guardan en user meta:
 *   ez_favoritos   [post_id, ...]                      juegos y mangas
 *   ez_progreso    {post_id: {p: página, n: total, t: timestamp}}
 *   ez_historial   [post_id, ...] (más reciente primero, máx. 50)
 *
 * API (requiere sesión iniciada y el nonce wp_rest):
 *   POST /wp-json/ez/v1/favorito   {id}             → {favorito: bool}
 *   POST /wp-json/ez/v1/progreso   {id, pagina, total}
 *   POST /wp-json/ez/v1/leido      {id, leido: bool}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ezc_user_list( $user_id, $key ) {
	$v = get_user_meta( $user_id, $key, true );
	return is_array( $v ) ? $v : array();
}

function ezc_is_favorite( $post_id, $user_id = null ) {
	$user_id = $user_id ?: get_current_user_id();
	return $user_id && in_array( (int) $post_id, ezc_user_list( $user_id, 'ez_favoritos' ), true );
}

/** Progreso de un manga/capítulo: ['p' => página (1..n), 'n' => total] o null. */
function ezc_get_progress( $post_id, $user_id = null ) {
	$user_id = $user_id ?: get_current_user_id();
	$all     = $user_id ? ezc_user_list( $user_id, 'ez_progreso' ) : array();
	return $all[ (int) $post_id ] ?? null;
}

function ezc_is_read( $post_id, $user_id = null ) {
	$p = ezc_get_progress( $post_id, $user_id );
	return $p && $p['n'] > 0 && $p['p'] >= $p['n'];
}

/** Entradas favoritas del usuario actual, de los tipos pedidos. */
function ezc_user_favorites( $types = array( 'juego', 'manga' ) ) {
	$ids = ezc_user_list( get_current_user_id(), 'ez_favoritos' );
	return $ids ? get_posts( array( 'post_type' => $types, 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => -1 ) ) : array();
}

function ezc_user_history( $limit = 12 ) {
	$ids = array_slice( ezc_user_list( get_current_user_id(), 'ez_historial' ), 0, $limit );
	return $ids ? get_posts( array( 'post_type' => 'manga', 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => $limit ) ) : array();
}

add_action( 'rest_api_init', function () {
	$logged_in = function () {
		return is_user_logged_in();
	};
	$valid_post = function ( $id ) {
		return in_array( get_post_type( (int) $id ), array( 'juego', 'manga' ), true );
	};

	register_rest_route( 'ez/v1', '/favorito', array(
		'methods'             => 'POST',
		'permission_callback' => $logged_in,
		'args'                => array( 'id' => array( 'required' => true, 'validate_callback' => $valid_post ) ),
		'callback'            => function ( WP_REST_Request $req ) {
			$uid = get_current_user_id();
			$id  = (int) $req['id'];
			$fav = ezc_user_list( $uid, 'ez_favoritos' );
			$on  = ! in_array( $id, $fav, true );
			$fav = $on ? array_merge( array( $id ), $fav ) : array_values( array_diff( $fav, array( $id ) ) );
			update_user_meta( $uid, 'ez_favoritos', $fav );
			return array( 'favorito' => $on );
		},
	) );

	register_rest_route( 'ez/v1', '/progreso', array(
		'methods'             => 'POST',
		'permission_callback' => $logged_in,
		'args'                => array(
			'id'     => array( 'required' => true, 'validate_callback' => $valid_post ),
			'pagina' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			'total'  => array( 'required' => true, 'sanitize_callback' => 'absint' ),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			$uid  = get_current_user_id();
			$id   = (int) $req['id'];
			$prog = ezc_user_list( $uid, 'ez_progreso' );
			$prev = $prog[ $id ]['p'] ?? 0;
			// Volver atrás a releer no borra el avance.
			$prog[ $id ] = array( 'p' => max( $prev, min( $req['pagina'], $req['total'] ) ), 'n' => $req['total'], 't' => time() );
			update_user_meta( $uid, 'ez_progreso', $prog );

			$hist = array_values( array_diff( ezc_user_list( $uid, 'ez_historial' ), array( $id ) ) );
			array_unshift( $hist, $id );
			update_user_meta( $uid, 'ez_historial', array_slice( $hist, 0, 50 ) );
			return $prog[ $id ];
		},
	) );

	register_rest_route( 'ez/v1', '/leido', array(
		'methods'             => 'POST',
		'permission_callback' => $logged_in,
		'args'                => array( 'id' => array( 'required' => true, 'validate_callback' => $valid_post ) ),
		'callback'            => function ( WP_REST_Request $req ) {
			$uid  = get_current_user_id();
			$id   = (int) $req['id'];
			$prog = ezc_user_list( $uid, 'ez_progreso' );
			if ( $req['leido'] ) {
				$n           = max( 1, count( ezc_manga_pages( $id ) ) );
				$prog[ $id ] = array( 'p' => $n, 'n' => $n, 't' => time() );
			} else {
				unset( $prog[ $id ] );
			}
			update_user_meta( $uid, 'ez_progreso', $prog );
			return array( 'leido' => (bool) $req['leido'] );
		},
	) );
} );
