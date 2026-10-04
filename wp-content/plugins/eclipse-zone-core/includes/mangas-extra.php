<?php
/**
 * Mangas: clasificación, catálogo con filtros, Mi Biblioteca, reportes de
 * imágenes, aleatorio y búsqueda rápida (Ctrl+K).
 *
 * Taxonomías (además de "etiqueta"):
 *   tipo_manga   Manga, Manhwa, Manhua, One Shot…   /tipo/<slug>/
 *   demografia   Shounen, Seinen, Shoujo, Josei…    /demografia/<slug>/
 *   estado_manga En emisión, Completado…            /estado-manga/<slug>/
 *
 * Catálogo /mangas/?tipo_manga=manhwa&demografia=seinen&orden=capitulos&q=…
 * Biblioteca (user meta ez_biblioteca): {post_id: leyendo|por_leer|completado}
 * Contadores por serie: ez_capitulos, ez_lib_leyendo, ez_lib_por_leer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	$taxes = array(
		'tipo_manga'   => array( 'Tipos', 'Tipo', 'tipo' ),
		'demografia'   => array( 'Demografías', 'Demografía', 'demografia' ),
		'estado_manga' => array( 'Estados', 'Estado', 'estado-manga' ),
	);
	foreach ( $taxes as $tax => list( $plural, $singular, $slug ) ) {
		register_taxonomy( $tax, 'manga', array(
			'labels'            => array( 'name' => $plural, 'singular_name' => $singular ),
			'public'            => true,
			'hierarchical'      => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => $slug, 'with_front' => false ),
		) );
	}

	// Términos base para elegir con una casilla en el admin.
	if ( ! get_option( 'ezc_manga_terms_v1' ) ) {
		foreach ( array(
			'tipo_manga'   => array( 'Manga', 'Manhwa', 'Manhua', 'One Shot', 'Doujinshi', 'Novela' ),
			'demografia'   => array( 'Shounen', 'Seinen', 'Shoujo', 'Josei', 'Kodomo' ),
			'estado_manga' => array( 'En emisión', 'Completado', 'Pausado', 'Cancelado' ),
		) as $tax => $names ) {
			foreach ( $names as $n ) {
				if ( ! term_exists( $n, $tax ) ) {
					wp_insert_term( $n, $tax );
				}
			}
		}
		update_option( 'ezc_manga_terms_v1', 1 );
	}
} );

function ezc_manga_filter_taxonomies() {
	return array(
		'tipo_manga'   => 'Tipo',
		'demografia'   => 'Demografía',
		'estado_manga' => 'Estado',
		'etiqueta'     => 'Categoría',
	);
}

function ezc_manga_orders() {
	return array(
		'recientes'  => 'Recientes',
		'puntuacion' => 'Mejor valorados',
		'vistas'     => 'Más vistos',
		'leyendo'    => 'Más gente leyendo',
		'por_leer'   => 'Más gente por leer',
		'capitulos'  => 'Más capítulos',
		'titulo'     => 'A-Z',
	);
}

function ezc_manga_active_filters() {
	$out = array();
	foreach ( array_keys( ezc_manga_filter_taxonomies() ) as $tax ) {
		if ( ! empty( $_GET[ $tax ] ) && ! is_tax( $tax ) ) {
			$out[ $tax ] = array_filter( array_map( 'sanitize_title', explode( ',', wp_unslash( $_GET[ $tax ] ) ) ) );
		}
	}
	return array_filter( $out );
}

function ezc_manga_current_order() {
	$o = isset( $_GET['orden'] ) ? sanitize_key( wp_unslash( $_GET['orden'] ) ) : 'recientes';
	return isset( ezc_manga_orders()[ $o ] ) ? $o : 'recientes';
}

function ezc_manga_is_filtered() {
	return ezc_manga_active_filters() || 'recientes' !== ezc_manga_current_order() || ! empty( $_GET['q'] );
}

add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( ! $query->is_post_type_archive( 'manga' ) && ! $query->is_tax( array_keys( ezc_manga_filter_taxonomies() ) ) ) {
		return;
	}
	$query->set( 'post_type', 'manga' );
	$query->set( 'post_parent', 0 );
	$query->set( 'posts_per_page', 36 );
	$tq = array();
	foreach ( ezc_manga_active_filters() as $tax => $slugs ) {
		$tq[] = array( 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $slugs );
	}
	if ( $tq ) {
		$query->set( 'tax_query', array_merge( array( 'relation' => 'AND' ), (array) $query->get( 'tax_query' ), $tq ) );
	}
	if ( ! empty( $_GET['q'] ) ) {
		$query->set( 's', sanitize_text_field( wp_unslash( $_GET['q'] ) ) );
	}
	$keys = array( 'vistas' => 'ez_vistas_total', 'puntuacion' => 'ez_nota_media', 'leyendo' => 'ez_lib_leyendo', 'por_leer' => 'ez_lib_por_leer', 'capitulos' => 'ez_capitulos' );
	$o    = ezc_manga_current_order();
	if ( isset( $keys[ $o ] ) ) {
		$query->set( 'meta_key', $keys[ $o ] );
		$query->set( 'orderby', array( 'meta_value_num' => 'DESC', 'modified' => 'DESC' ) );
	} elseif ( 'titulo' === $o ) {
		$query->set( 'orderby', 'title' );
		$query->set( 'order', 'ASC' );
	}
}, 30 );

add_action( 'parse_query', function ( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->is_post_type_archive( 'manga' ) && ! empty( $_GET['q'] ) ) {
		$query->is_search = false;
	}
}, 99 );

add_filter( 'wp_robots', function ( $robots ) {
	if ( ( is_post_type_archive( 'manga' ) || is_tax( array_keys( ezc_manga_filter_taxonomies() ) ) ) && ezc_manga_is_filtered() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
} );

// ── Contadores por serie (para ordenar sin que ninguna quede afuera) ──

function ezc_manga_update_counts( $serie_id ) {
	if ( ! $serie_id || wp_get_post_parent_id( $serie_id ) ) {
		return;
	}
	update_post_meta( $serie_id, 'ez_capitulos', count( ezc_manga_chapters( $serie_id ) ) );
	foreach ( array( 'leyendo', 'por_leer' ) as $estado ) {
		if ( '' === get_post_meta( $serie_id, 'ez_lib_' . $estado, true ) ) {
			update_post_meta( $serie_id, 'ez_lib_' . $estado, 0 );
		}
	}
}

add_action( 'save_post_manga', function ( $post_id, $post ) {
	ezc_manga_update_counts( $post->post_parent ?: $post_id );
}, 20, 2 );
add_action( 'deleted_post', function ( $post_id, $post ) {
	if ( $post && 'manga' === $post->post_type && $post->post_parent ) {
		ezc_manga_update_counts( $post->post_parent );
	}
}, 10, 2 );

add_action( 'init', function () {
	if ( get_option( 'ezc_manga_counts_v1' ) ) {
		return;
	}
	foreach ( get_posts( array( 'post_type' => 'manga', 'post_parent' => 0, 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any' ) ) as $id ) {
		ezc_manga_update_counts( $id );
	}
	update_option( 'ezc_manga_counts_v1', 1 );
}, 20 );

/** Tiempo de lectura estimado en minutos (~5 s por página). */
function ezc_reading_minutes( $pages ) {
	return max( 1, (int) round( $pages * 5 / 60 ) );
}

/** "paginado" para manga/one shot (se lee de a página), "cascada" para manhwa/manhua (tira vertical). */
function ezc_manga_recommended_mode( $post_id ) {
	$serie = wp_get_post_parent_id( $post_id ) ?: $post_id;
	$tipos = array_map( 'strtolower', (array) wp_get_post_terms( $serie, 'tipo_manga', array( 'fields' => 'names' ) ) );
	if ( array_intersect( $tipos, array( 'manhwa', 'manhua', 'webtoon' ) ) ) {
		return 'cascada';
	}
	return $tipos ? 'pagina' : '';
}

// ── Mi Biblioteca ─────────────────────────────────────────────

function ezc_library_states() {
	return array( 'leyendo' => 'Leyendo', 'por_leer' => 'Por leer', 'completado' => 'Completado' );
}

function ezc_library_state( $post_id, $user_id = null ) {
	$user_id = $user_id ?: get_current_user_id();
	$lib     = $user_id ? get_user_meta( $user_id, 'ez_biblioteca', true ) : array();
	return is_array( $lib ) ? ( $lib[ (int) $post_id ] ?? '' ) : '';
}

/** Series del usuario en un estado, las más recientes primero. */
function ezc_library_posts( $estado ) {
	$lib = get_user_meta( get_current_user_id(), 'ez_biblioteca', true );
	$ids = is_array( $lib ) ? array_reverse( array_keys( array_filter( $lib, function ( $s ) use ( $estado ) {
		return $s === $estado;
	} ) ) ) : array();
	return $ids ? get_posts( array( 'post_type' => 'manga', 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => -1 ) ) : array();
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'ez/v1', '/biblioteca', array(
		'methods'             => 'POST',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id'     => array( 'required' => true, 'validate_callback' => function ( $id ) {
				return 'manga' === get_post_type( (int) $id ) && ! wp_get_post_parent_id( (int) $id );
			} ),
			'estado' => array( 'required' => true, 'validate_callback' => function ( $e ) {
				return '' === $e || isset( ezc_library_states()[ $e ] );
			} ),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			$uid = get_current_user_id();
			$id  = (int) $req['id'];
			$lib = get_user_meta( $uid, 'ez_biblioteca', true );
			$lib = is_array( $lib ) ? $lib : array();
			$old = $lib[ $id ] ?? '';
			$new = (string) $req['estado'];
			unset( $lib[ $id ] );
			if ( $new ) {
				$lib[ $id ] = $new; // al final = más reciente
			}
			update_user_meta( $uid, 'ez_biblioteca', $lib );
			foreach ( array( 'leyendo', 'por_leer' ) as $e ) {
				$delta = ( $new === $e ? 1 : 0 ) - ( $old === $e ? 1 : 0 );
				if ( $delta ) {
					update_post_meta( $id, 'ez_lib_' . $e, max( 0, (int) get_post_meta( $id, 'ez_lib_' . $e, true ) + $delta ) );
				}
			}
			return array( 'estado' => $new );
		},
	) );

	// Reportar un problema (imagen rota, páginas desordenadas…). Sin sesión también,
	// con un límite por IP para que no lo usen para spam.
	register_rest_route( 'ez/v1', '/reporte', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'args'                => array(
			'id'     => array( 'required' => true, 'validate_callback' => function ( $id ) {
				return in_array( get_post_type( (int) $id ), array( 'manga', 'juego' ), true );
			} ),
			'motivo' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			'pagina' => array( 'sanitize_callback' => 'absint' ),
			'detalle' => array( 'sanitize_callback' => 'sanitize_textarea_field' ),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			$ip_key = 'ezc_rep_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
			$count  = (int) get_transient( $ip_key );
			if ( $count >= 5 ) {
				return new WP_Error( 'ezc_limite', 'Demasiados reportes. Probá más tarde.', array( 'status' => 429 ) );
			}
			set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );
			$id    = (int) $req['id'];
			$title = sprintf( '%s — %s%s', get_the_title( $id ), mb_substr( $req['motivo'], 0, 60 ), $req['pagina'] ? ' (pág. ' . (int) $req['pagina'] . ')' : '' );
			wp_insert_post( array(
				'post_type'    => 'ez_reporte',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => mb_substr( (string) $req['detalle'], 0, 2000 ),
				'post_parent'  => $id,
				'post_author'  => get_current_user_id(),
			) );
			return array( 'ok' => true );
		},
	) );

	// Búsqueda rápida (Ctrl+K): juegos y mangas por título.
	register_rest_route( 'ez/v1', '/buscar', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array( 'q' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		'callback'            => function ( WP_REST_Request $req ) {
			$q = trim( (string) $req['q'] );
			if ( mb_strlen( $q ) < 2 ) {
				return array();
			}
			$posts = get_posts( array(
				'post_type'      => array( 'juego', 'manga' ),
				's'              => $q,
				'post_parent'    => 0,
				'posts_per_page' => 8,
				'post_status'    => 'publish',
			) );
			return array_map( function ( $p ) {
				$img = 'juego' === $p->post_type ? ezc_get_cover_url( $p->ID, 'thumbnail' ) : ezc_manga_cover_url( $p->ID, 'thumbnail' );
				return array(
					'titulo' => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
					'url'    => get_permalink( $p ),
					'tipo'   => 'juego' === $p->post_type ? 'Juego' : 'Manga',
					'img'    => $img ?: '',
				);
			}, $posts );
		},
	) );
} );

// Reportes: lista privada en el admin (Mangas → Reportes).
add_action( 'init', function () {
	register_post_type( 'ez_reporte', array(
		'labels'          => array( 'name' => 'Reportes', 'singular_name' => 'Reporte' ),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'edit.php?post_type=manga',
		'supports'        => array( 'title', 'editor' ),
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
	) );
} );
add_filter( 'manage_ez_reporte_posts_columns', function ( $cols ) {
	$cols['ez_ver'] = 'Página';
	return $cols;
} );
add_action( 'manage_ez_reporte_posts_custom_column', function ( $col, $id ) {
	$parent = wp_get_post_parent_id( $id );
	if ( 'ez_ver' === $col && $parent ) {
		printf( '<a href="%s" target="_blank">Ver</a> · <a href="%s">Editar</a>', esc_url( get_permalink( $parent ) ), esc_url( get_edit_post_link( $parent ) ) );
	}
}, 10, 2 );

// /mangas/?aleatorio=1 y /juegos/?aleatorio=1 → una serie/juego al azar.
add_action( 'template_redirect', function () {
	if ( empty( $_GET['aleatorio'] ) ) {
		return;
	}
	$type = is_post_type_archive( 'juego' ) ? 'juego' : ( is_post_type_archive( 'manga' ) ? 'manga' : '' );
	if ( ! $type ) {
		return;
	}
	$ids = get_posts( array( 'post_type' => $type, 'post_parent' => 0, 'orderby' => 'rand', 'posts_per_page' => 1, 'fields' => 'ids' ) );
	if ( $ids ) {
		nocache_headers();
		wp_safe_redirect( get_permalink( $ids[0] ), 302 );
		exit;
	}
} );
