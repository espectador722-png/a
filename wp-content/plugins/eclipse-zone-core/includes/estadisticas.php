<?php
/**
 * Vistas y puntuaciones de juegos y mangas.
 *
 * Vistas: tabla {prefix}ez_vistas (post_id, dia, vistas). Las cuenta un
 * pedido JS al cargar la ficha (POST /ez/v1/vista), así los bots que no
 * ejecutan JS no inflan el número y funciona aunque la página esté en caché.
 * El total queda también en el meta ez_vistas_total (para mostrar y ordenar).
 *
 * Votos: tabla {prefix}ez_votos (post_id, user_id, nota 1-5). Un voto por
 * usuario con sesión, se puede cambiar. Media y cantidad quedan en los
 * metas ez_nota_media y ez_nota_votos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EZC_DB_VERSION = 1;

function ezc_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$wpdb->prefix}ez_vistas (
		post_id bigint(20) unsigned NOT NULL,
		dia date NOT NULL,
		vistas int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (post_id,dia),
		KEY dia (dia)
	) $charset;" );
	dbDelta( "CREATE TABLE {$wpdb->prefix}ez_votos (
		post_id bigint(20) unsigned NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		nota tinyint(3) unsigned NOT NULL,
		fecha datetime NOT NULL,
		PRIMARY KEY  (post_id,user_id)
	) $charset;" );
	update_option( 'ezc_db_version', EZC_DB_VERSION );
}

// Al actualizar el plugin (subir archivos nuevos) no se ejecuta la activación:
// crear las tablas si faltan.
add_action( 'init', function () {
	if ( (int) get_option( 'ezc_db_version' ) < EZC_DB_VERSION ) {
		ezc_install_tables();
	}
} );

function ezc_views( $post_id = null ) {
	return (int) get_post_meta( $post_id ?: get_the_ID(), 'ez_vistas_total', true );
}

/** ['media' => 4.6, 'votos' => 12] */
function ezc_rating( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();
	return array(
		'media' => (float) get_post_meta( $post_id, 'ez_nota_media', true ),
		'votos' => (int) get_post_meta( $post_id, 'ez_nota_votos', true ),
	);
}

function ezc_user_vote( $post_id, $user_id = null ) {
	global $wpdb;
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id ) {
		return 0;
	}
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT nota FROM {$wpdb->prefix}ez_votos WHERE post_id = %d AND user_id = %d", $post_id, $user_id ) );
}

/**
 * Lo más visto en los últimos $dias días.
 * Devuelve [['post' => WP_Post, 'vistas' => int], ...]. Se cachea 10 minutos.
 */
function ezc_top( $dias = 30, $limit = 5, $type = 'juego' ) {
	$key = "ezc_top_{$type}_{$dias}_{$limit}";
	$ids = get_transient( $key );
	if ( false === $ids ) {
		global $wpdb;
		$desde = gmdate( 'Y-m-d', time() - $dias * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT v.post_id, SUM(v.vistas) AS total
			 FROM {$wpdb->prefix}ez_vistas v
			 JOIN {$wpdb->posts} p ON p.ID = v.post_id
			 WHERE v.dia >= %s AND p.post_type = %s AND p.post_status = 'publish'
			 GROUP BY v.post_id ORDER BY total DESC LIMIT %d",
			$desde, $type, $limit
		) );
		$ids = array();
		foreach ( $rows as $r ) {
			$ids[ (int) $r->post_id ] = (int) $r->total;
		}
		set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );
	}
	$out = array();
	foreach ( $ids as $id => $vistas ) {
		if ( $post = get_post( $id ) ) {
			$out[] = array( 'post' => $post, 'vistas' => $vistas );
		}
	}
	return $out;
}

add_action( 'rest_api_init', function () {
	$valid_post = function ( $id ) {
		return in_array( get_post_type( (int) $id ), array( 'juego', 'manga' ), true ) && 'publish' === get_post_status( (int) $id );
	};

	register_rest_route( 'ez/v1', '/vista', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'args'                => array( 'id' => array( 'required' => true, 'validate_callback' => $valid_post ) ),
		'callback'            => function ( WP_REST_Request $req ) {
			global $wpdb;
			$id = (int) $req['id'];
			// Una vista por visitante y entrada cada 6 horas (por IP), para que
			// recargar la página no infle el contador.
			$ip_key = 'ezc_v_' . md5( $id . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			if ( get_transient( $ip_key ) ) {
				return array( 'vistas' => ezc_views( $id ) );
			}
			set_transient( $ip_key, 1, 6 * HOUR_IN_SECONDS );

			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}ez_vistas (post_id, dia, vistas) VALUES (%d, %s, 1)
				 ON DUPLICATE KEY UPDATE vistas = vistas + 1",
				$id, gmdate( 'Y-m-d' )
			) );
			$total = ezc_views( $id ) + 1;
			update_post_meta( $id, 'ez_vistas_total', $total );
			return array( 'vistas' => $total );
		},
	) );

	register_rest_route( 'ez/v1', '/votar', array(
		'methods'             => 'POST',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id'   => array( 'required' => true, 'validate_callback' => $valid_post ),
			'nota' => array(
				'required'          => true,
				'validate_callback' => function ( $n ) {
					return is_numeric( $n ) && $n >= 1 && $n <= 5;
				},
			),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			global $wpdb;
			$id = (int) $req['id'];
			$wpdb->replace( "{$wpdb->prefix}ez_votos", array(
				'post_id' => $id,
				'user_id' => get_current_user_id(),
				'nota'    => (int) $req['nota'],
				'fecha'   => current_time( 'mysql', true ),
			) );
			$agg = $wpdb->get_row( $wpdb->prepare( "SELECT AVG(nota) AS media, COUNT(*) AS votos FROM {$wpdb->prefix}ez_votos WHERE post_id = %d", $id ) );
			update_post_meta( $id, 'ez_nota_media', round( (float) $agg->media, 1 ) );
			update_post_meta( $id, 'ez_nota_votos', (int) $agg->votos );
			return array( 'media' => round( (float) $agg->media, 1 ), 'votos' => (int) $agg->votos, 'tuya' => (int) $req['nota'] );
		},
	) );
} );

// Si se borra un juego o manga, se borran sus vistas y votos.
add_action( 'deleted_post', function ( $post_id ) {
	global $wpdb;
	$wpdb->delete( "{$wpdb->prefix}ez_vistas", array( 'post_id' => $post_id ) );
	$wpdb->delete( "{$wpdb->prefix}ez_votos", array( 'post_id' => $post_id ) );
} );
