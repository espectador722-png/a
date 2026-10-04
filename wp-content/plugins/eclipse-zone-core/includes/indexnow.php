<?php
/**
 * IndexNow: avisa a Bing, Yandex, Seznam y Naver apenas se publica o
 * actualiza un juego, noticia o manga (el sitio anterior también lo hacía).
 * Google no usa IndexNow: para Google está el sitemap con <lastmod>.
 *
 * La clave se genera sola y se sirve en https://tu-dominio/<clave>.txt,
 * que es como IndexNow verifica que el sitio es tuyo.
 *
 * Si Rank Math (módulo "Instant Indexing") o el plugin oficial IndexNow
 * están activos, este módulo se apaga para no avisar dos veces.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ezc_indexnow_enabled() {
	if ( class_exists( 'RankMath\\Instant_Indexing\\Instant_Indexing' ) || defined( 'INDEXNOW_PLUGIN_VERSION' ) || class_exists( 'BWT_IndexNow' ) ) {
		return false;
	}
	// Solo en el sitio real: en local o con "disuadir a los buscadores" no hay que avisar.
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$local = 'localhost' === $host || preg_match( '/\.(local|test|localhost)$|^127\.|^192\.168\./', $host );
	return ! $local && '1' === (string) get_option( 'blog_public' ) && 'production' === wp_get_environment_type();
}

function ezc_indexnow_key() {
	$key = get_option( 'ezc_indexnow_key' );
	if ( ! $key ) {
		$key = strtolower( wp_generate_password( 32, false ) );
		update_option( 'ezc_indexnow_key', $key, false );
	}
	return $key;
}

// /<clave>.txt → la clave en texto plano.
add_action( 'parse_request', function ( $wp ) {
	$key  = get_option( 'ezc_indexnow_key' );
	$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	if ( $key && $path === $key . '.txt' ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $key );
		exit;
	}
} );

/** URLs que cambiaron en este pedido; se mandan juntas al final. */
function ezc_indexnow_queue( $url = null ) {
	static $urls = array();
	if ( $url ) {
		$urls[ $url ] = true;
	}
	return array_keys( $urls );
}

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( ! in_array( $post->post_type, array( 'juego', 'noticia', 'manga' ), true ) || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return;
	}
	// Publicado, actualizado o despublicado (para que lo saquen).
	if ( 'publish' === $new || 'publish' === $old ) {
		ezc_indexnow_queue( get_permalink( $post ) );
	}
}, 10, 3 );

add_action( 'shutdown', function () {
	$urls = ezc_indexnow_queue();
	// Una importación masiva no debe mandar 600 URLs de golpe en cada corrida.
	if ( ! $urls || ! ezc_indexnow_enabled() || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
		return;
	}
	wp_remote_post( 'https://api.indexnow.org/indexnow', array(
		'timeout'  => 5,
		'blocking' => false,
		'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
		'body'     => wp_json_encode( array(
			'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
			'key'         => ezc_indexnow_key(),
			'keyLocation' => home_url( '/' . ezc_indexnow_key() . '.txt' ),
			'urlList'     => array_slice( $urls, 0, 10000 ),
		) ),
	) );
} );

// Generar la clave al cargar el plugin por primera vez, así /<clave>.txt existe antes del primer aviso.
add_action( 'init', 'ezc_indexnow_key' );
