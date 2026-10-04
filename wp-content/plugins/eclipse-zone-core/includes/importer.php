<?php
/**
 * Importador de juegos.json y noticias.json (los mismos archivos de GitLab
 * que usaba el sitio anterior).
 *
 * Admin:   Herramientas → Importar Eclipse Zone
 * WP-CLI:  wp eclipse importar juegos  [--fuente=<url|archivo>]
 *          wp eclipse importar noticias [--fuente=<url|archivo>]
 *
 * Re-importar actualiza los existentes (no duplica): cada entrada guarda
 * ez_source_key. Los slugs salen del título igual que antes, así
 * /juego/<slug>/ y /noticia/<slug>/ conservan sus URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EZC_JUEGOS_URL   = 'https://gitlab.com/senpai1940/juegos/-/raw/main/juegos.json';
const EZC_NOTICIAS_URL = 'https://gitlab.com/senpai1940/noticias/-/raw/main/noticias.json';

/** Descarga o lee un JSON (URL o ruta local). */
function ezc_load_json( $source ) {
	if ( preg_match( '#^https?://#', $source ) ) {
		$res = wp_remote_get( $source, array( 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'ezc_http', 'HTTP ' . wp_remote_retrieve_response_code( $res ) . ' al descargar ' . $source );
		}
		$body = wp_remote_retrieve_body( $res );
	} else {
		if ( ! is_readable( $source ) ) {
			return new WP_Error( 'ezc_file', 'No se puede leer ' . $source );
		}
		$body = file_get_contents( $source );
	}
	$data = json_decode( $body, true );
	return is_array( $data ) ? $data : new WP_Error( 'ezc_json', 'El archivo no es un JSON con una lista' );
}

/** El JSON tiene campos que a veces son string y a veces array. */
function ezc_as_list( $v ) {
	if ( is_array( $v ) ) {
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $v ) ) ) );
	}
	return ( is_string( $v ) && trim( $v ) !== '' ) ? array( trim( $v ) ) : array();
}

function ezc_parse_date( $v ) {
	$ts = is_numeric( $v ) ? (int) ( $v > 1e11 ? $v / 1000 : $v ) : strtotime( (string) $v );
	return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
}

/** Busca la entrada ya importada con esa clave. */
function ezc_find_by_key( $type, $key ) {
	$ids = get_posts( array(
		'post_type'      => $type,
		'post_status'    => 'any',
		'meta_key'       => 'ez_source_key',
		'meta_value'     => $key,
		'fields'         => 'ids',
		'posts_per_page' => 1,
	) );
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Clave estable por título: títulos repetidos ("Single Again" 1 y 2) se
 * distinguen por el orden de aparición, como hacía generate-everything.js.
 */
function ezc_source_keys( array $items, $title_field ) {
	$seen = array();
	$keys = array();
	foreach ( $items as $i => $item ) {
		$t          = strtolower( trim( (string) ( $item[ $title_field ] ?? '' ) ) );
		$seen[ $t ] = ( $seen[ $t ] ?? 0 ) + 1;
		$keys[ $i ] = md5( $t . '#' . $seen[ $t ] );
	}
	return $keys;
}

function ezc_import_juegos( $source = EZC_JUEGOS_URL ) {
	$data = ezc_load_json( $source );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	wp_raise_memory_limit( 'admin' );
	wp_defer_term_counting( true );
	$keys  = ezc_source_keys( $data, 'titulo' );
	$stats = array( 'creados' => 0, 'actualizados' => 0, 'omitidos' => 0 );

	foreach ( $data as $i => $j ) {
		$titulo = trim( (string) ( $j['titulo'] ?? '' ) );
		if ( '' === $titulo ) {
			$stats['omitidos']++;
			continue;
		}
		$existing = ezc_find_by_key( 'juego', $keys[ $i ] );
		$postarr  = array(
			'ID'           => $existing,
			'post_type'    => 'juego',
			'post_status'  => 'publish',
			'post_title'   => $titulo,
			'post_content' => wp_kses_post( (string) ( $j['descripcion'] ?? '' ) ),
		);
		if ( ! $existing ) {
			$postarr['post_name'] = sanitize_title( $titulo );
			if ( $d = ezc_parse_date( $j['fechaRegistro'] ?? $j['fechaActualizacion'] ?? '' ) ) {
				$postarr['post_date_gmt'] = $d;
				$postarr['post_date']     = get_date_from_gmt( $d );
			}
		}
		// wp_update_post conserva lo que no se manda (fecha, extracto…); wp_insert_post con ID lo pisaría.
		$id = $existing ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			$stats['omitidos']++;
			continue;
		}
		$stats[ $existing ? 'actualizados' : 'creados' ]++;

		$links = array();
		if ( empty( $j['exclusivo'] ) ) {
			foreach ( (array) ( $j['links'] ?? array() ) as $l ) {
				$url = is_array( $l ) ? ( $l['url'] ?? '' ) : (string) $l;
				if ( $url = esc_url_raw( $url ) ) {
					$name    = is_array( $l ) ? ( $l['nombre'] ?? $l['name'] ?? $l['texto'] ?? $l['label'] ?? $l['plataforma'] ?? $l['servidor'] ?? '' ) : '';
					$links[] = array( 'nombre' => sanitize_text_field( (string) $name ), 'url' => $url );
				}
			}
		}
		$imagenes = array_values( array_filter( array_map( 'esc_url_raw', ezc_as_list( $j['imagenes'] ?? array() ) ) ) );

		update_post_meta( $id, 'ez_source_key', $keys[ $i ] );
		update_post_meta( $id, 'ez_version', sanitize_text_field( (string) ( $j['version'] ?? '' ) ) );
		update_post_meta( $id, 'ez_links', wp_json_encode( $links ) );
		update_post_meta( $id, 'ez_imagenes', wp_json_encode( $imagenes ) );
		update_post_meta( $id, 'ez_imagen', esc_url_raw( (string) ( $j['imagen'] ?? ( $imagenes[0] ?? '' ) ) ) );

		wp_set_object_terms( $id, ezc_as_list( $j['categorias'] ?? array() ), 'genero' );
		wp_set_object_terms( $id, ezc_as_list( $j['plataformas'] ?? $j['plataforma'] ?? array() ), 'plataforma' );
		wp_set_object_terms( $id, ezc_as_list( $j['traductores'] ?? array() ), 'traductor' );
		wp_set_object_terms( $id, ezc_as_list( $j['estado'] ?? array() ), 'estado' );

		// "Actualizado" = fecha del JSON, no la de la importación (orden del catálogo).
		if ( $mod = ezc_parse_date( $j['fechaActualizacion'] ?? '' ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $mod, 'post_modified' => get_date_from_gmt( $mod ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
	}
	wp_defer_term_counting( false );
	return $stats;
}

function ezc_import_noticias( $source = EZC_NOTICIAS_URL ) {
	$data = ezc_load_json( $source );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$keys  = ezc_source_keys( $data, 'title' );
	$stats = array( 'creados' => 0, 'actualizados' => 0, 'omitidos' => 0 );

	foreach ( $data as $i => $n ) {
		$title = trim( (string) ( $n['title'] ?? '' ) );
		if ( '' === $title ) {
			$stats['omitidos']++;
			continue;
		}
		$existing = ezc_find_by_key( 'noticia', $keys[ $i ] );
		$postarr  = array(
			'ID'           => $existing,
			'post_type'    => 'noticia',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => wp_kses_post( (string) ( $n['content'] ?? '' ) ),
			'post_excerpt' => sanitize_text_field( (string) ( $n['shortContent'] ?? '' ) ),
		);
		if ( ! $existing ) {
			$postarr['post_name'] = sanitize_title( $title );
		}
		if ( $d = ezc_parse_date( $n['date'] ?? $n['fecha'] ?? '' ) ) {
			$postarr['post_date_gmt'] = $d;
			$postarr['post_date']     = get_date_from_gmt( $d );
		}
		// wp_update_post conserva lo que no se manda (fecha, extracto…); wp_insert_post con ID lo pisaría.
		$id = $existing ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			$stats['omitidos']++;
			continue;
		}
		$stats[ $existing ? 'actualizados' : 'creados' ]++;

		$img = $n['image'] ?? $n['thumbnail'] ?? ( ezc_as_list( $n['images'] ?? array() )[0] ?? '' );
		update_post_meta( $id, 'ez_source_key', $keys[ $i ] );
		update_post_meta( $id, 'ez_imagen', esc_url_raw( (string) $img ) );
		update_post_meta( $id, 'ez_autor', sanitize_text_field( (string) ( $n['author'] ?? '' ) ) );
		wp_set_object_terms( $id, array_merge( ezc_as_list( $n['tags'] ?? array() ), ezc_as_list( $n['categoria'] ?? array() ) ), 'post_tag' );
	}
	return $stats;
}

// ── Página de admin ───────────────────────────────────────────

add_action( 'admin_menu', function () {
	add_management_page( 'Importar Eclipse Zone', 'Importar Eclipse Zone', 'manage_options', 'ezc-import', 'ezc_render_import_page' );
} );

function ezc_render_import_page() {
	$result = null;
	if ( isset( $_POST['ezc_import'] ) && check_admin_referer( 'ezc_import' ) ) {
		@set_time_limit( 600 );
		$what   = 'noticias' === $_POST['ezc_import'] ? 'noticias' : 'juegos';
		$source = trim( wp_unslash( $_POST[ 'fuente_' . $what ] ?? '' ) );
		$result = 'noticias' === $what ? ezc_import_noticias( $source ?: EZC_NOTICIAS_URL ) : ezc_import_juegos( $source ?: EZC_JUEGOS_URL );
	}
	?>
	<div class="wrap">
		<h1>Importar Eclipse Zone</h1>
		<?php if ( is_wp_error( $result ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
		<?php elseif ( $result ) : ?>
			<div class="notice notice-success"><p>
				<?php printf( 'Listo: %d creados, %d actualizados, %d omitidos.', (int) $result['creados'], (int) $result['actualizados'], (int) $result['omitidos'] ); ?>
			</p></div>
		<?php endif; ?>
		<p>Se puede repetir cuantas veces quieras: actualiza lo existente, no duplica.</p>
		<form method="post">
			<?php wp_nonce_field( 'ezc_import' ); ?>
			<h2>Juegos</h2>
			<p><input type="text" name="fuente_juegos" class="large-text" placeholder="<?php echo esc_attr( EZC_JUEGOS_URL ); ?>"></p>
			<p><button class="button button-primary" name="ezc_import" value="juegos">Importar juegos</button></p>
			<h2>Noticias</h2>
			<p><input type="text" name="fuente_noticias" class="large-text" placeholder="<?php echo esc_attr( EZC_NOTICIAS_URL ); ?>"></p>
			<p><button class="button button-primary" name="ezc_import" value="noticias">Importar noticias</button></p>
		</form>
	</div>
	<?php
}

// ── WP-CLI ────────────────────────────────────────────────────

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Importa juegos o noticias.
	 *
	 * ## OPTIONS
	 * <tipo>
	 * : juegos | noticias
	 * [--fuente=<url-o-archivo>]
	 * : URL o ruta del JSON. Por defecto, GitLab.
	 */
	WP_CLI::add_command( 'eclipse importar', function ( $args, $assoc ) {
		$tipo   = $args[0] ?? '';
		$source = $assoc['fuente'] ?? null;
		if ( 'juegos' === $tipo ) {
			$r = ezc_import_juegos( $source ?: EZC_JUEGOS_URL );
		} elseif ( 'noticias' === $tipo ) {
			$r = ezc_import_noticias( $source ?: EZC_NOTICIAS_URL );
		} else {
			WP_CLI::error( 'Tipo: juegos | noticias' );
		}
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( sprintf( '%d creados, %d actualizados, %d omitidos.', $r['creados'], $r['actualizados'], $r['omitidos'] ) );
	} );
}
