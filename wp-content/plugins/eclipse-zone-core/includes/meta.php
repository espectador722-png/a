<?php
/**
 * Campos de cada juego (post meta) y la caja para editarlos en el admin.
 *
 * ez_version            "v0.109"
 * ez_links              JSON: [{"nombre":"PC","url":"https://...","acortadores":2,"traductor":"Daniw"}]
 *                       (no se expone en la API: un juego exclusivo no debe filtrar sus links)
 * ez_tamano_pc / ez_tamano_apk   "1.2 GB" (se muestran junto al botón)
 * ez_imagenes           JSON: ["https://.../1.jpg", ...] (capturas)
 * ez_imagen             URL de portada externa (si no hay imagen destacada)
 * ez_fecha_registro     fecha original de publicación (Y-m-d)
 * ez_source_key         clave del juego en juegos.json (para re-importar)
 * ez_destacado          "1" si aparece en el carrusel del inicio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	$private = array( 'ez_links', 'ez_source_key' );
	foreach ( array( 'ez_version', 'ez_destacado', 'ez_exclusivo', 'ez_links', 'ez_imagenes', 'ez_imagen', 'ez_fecha_registro', 'ez_source_key', 'ez_tamano_pc', 'ez_tamano_apk' ) as $key ) {
		register_post_meta( 'juego', $key, array(
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => ! in_array( $key, $private, true ),
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		) );
	}
} );

/** Links de descarga como array de ['nombre' => ..., 'url' => ...]. */
function ezc_get_links( $post_id = null ) {
	$links = json_decode( (string) get_post_meta( $post_id ?: get_the_ID(), 'ez_links', true ), true );
	return is_array( $links ) ? array_values( array_filter( $links, function ( $l ) {
		return ! empty( $l['url'] );
	} ) ) : array();
}

/**
 * Texto del admin → links. Una línea por link:
 *   Nombre | URL | acortadores | traductor   (los dos últimos son opcionales)
 */
function ezc_parse_links_text( $text ) {
	$links = array();
	foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( 1 === count( $parts ) ) {
			array_unshift( $parts, '' );
		}
		$url = esc_url_raw( $parts[1] ?? '' );
		if ( $url ) {
			$links[] = array_filter( array(
				'nombre'      => sanitize_text_field( $parts[0] ),
				'url'         => $url,
				'acortadores' => absint( $parts[2] ?? 0 ),
				'traductor'   => sanitize_text_field( $parts[3] ?? '' ),
			) );
		}
	}
	return $links;
}

function ezc_links_to_text( array $links ) {
	return implode( "\n", array_map( function ( $l ) {
		$row = array( $l['nombre'] ?? '', $l['url'] );
		if ( ! empty( $l['acortadores'] ) || ! empty( $l['traductor'] ) ) {
			$row[] = (int) ( $l['acortadores'] ?? 0 );
		}
		if ( ! empty( $l['traductor'] ) ) {
			$row[] = $l['traductor'];
		}
		return implode( ' | ', $row );
	}, $links ) );
}

function ezc_get_screenshots( $post_id = null ) {
	$imgs = json_decode( (string) get_post_meta( $post_id ?: get_the_ID(), 'ez_imagenes', true ), true );
	return is_array( $imgs ) ? array_values( array_filter( $imgs, 'is_string' ) ) : array();
}

/** Portada: imagen destacada si existe, si no la URL externa importada. */
function ezc_get_cover_url( $post_id = null, $size = 'large' ) {
	$post_id = $post_id ?: get_the_ID();
	if ( has_post_thumbnail( $post_id ) ) {
		return get_the_post_thumbnail_url( $post_id, $size );
	}
	$url = get_post_meta( $post_id, 'ez_imagen', true );
	if ( ! $url ) {
		$shots = ezc_get_screenshots( $post_id );
		$url   = $shots ? $shots[0] : '';
	}
	return $url;
}

// ── Caja de edición ────────────────────────────────────────────

add_action( 'add_meta_boxes_juego', function () {
	add_meta_box( 'ezc_datos', 'Datos del juego', 'ezc_render_meta_box', 'juego', 'normal', 'high' );
} );

function ezc_render_meta_box( $post ) {
	wp_nonce_field( 'ezc_save', 'ezc_nonce' );
	$version = get_post_meta( $post->ID, 'ez_version', true );
	$imagen  = get_post_meta( $post->ID, 'ez_imagen', true );
	$links   = ezc_get_links( $post->ID );
	$shots   = ezc_get_screenshots( $post->ID );

	$links_text = ezc_links_to_text( $links );
	?>
	<p><label><strong>Versión</strong><br>
		<input type="text" name="ez_version" value="<?php echo esc_attr( $version ); ?>" class="regular-text" placeholder="v0.109"></label></p>
	<p><label><input type="checkbox" name="ez_destacado" value="1" <?php checked( get_post_meta( $post->ID, 'ez_destacado', true ), '1' ); ?>>
		<strong>Destacado</strong> (aparece en el carrusel del inicio)</label></p>
	<p><label><strong>Portada (URL externa, opcional si hay imagen destacada)</strong><br>
		<input type="url" name="ez_imagen" value="<?php echo esc_attr( $imagen ); ?>" class="large-text"></label></p>
	<p><label><strong>Links de descarga</strong> — uno por línea: <code>Nombre | URL | acortadores | traductor</code> (los dos últimos opcionales)<br>
		<textarea name="ez_links" rows="4" class="large-text code"><?php echo esc_textarea( $links_text ); ?></textarea></label></p>
	<p><label>Tamaño PC <input type="text" name="ez_tamano_pc" value="<?php echo esc_attr( get_post_meta( $post->ID, 'ez_tamano_pc', true ) ); ?>" placeholder="1.2 GB"></label>
		<label style="margin-left:12px">Tamaño APK <input type="text" name="ez_tamano_apk" value="<?php echo esc_attr( get_post_meta( $post->ID, 'ez_tamano_apk', true ) ); ?>" placeholder="900 MB"></label></p>
	<?php do_action( 'ezc_meta_box_extra', $post ); ?>
	<p><label><strong>Capturas</strong> — una URL por línea<br>
		<textarea name="ez_imagenes" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", $shots ) ); ?></textarea></label></p>
	<?php
}

add_action( 'save_post_juego', function ( $post_id ) {
	if ( ! isset( $_POST['ezc_nonce'] ) || ! wp_verify_nonce( $_POST['ezc_nonce'], 'ezc_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, 'ez_version', sanitize_text_field( wp_unslash( $_POST['ez_version'] ?? '' ) ) );
	update_post_meta( $post_id, 'ez_destacado', empty( $_POST['ez_destacado'] ) ? '' : '1' );
	update_post_meta( $post_id, 'ez_imagen', esc_url_raw( wp_unslash( $_POST['ez_imagen'] ?? '' ) ) );

	update_post_meta( $post_id, 'ez_links', wp_json_encode( ezc_parse_links_text( wp_unslash( $_POST['ez_links'] ?? '' ) ) ) );
	update_post_meta( $post_id, 'ez_tamano_pc', sanitize_text_field( wp_unslash( $_POST['ez_tamano_pc'] ?? '' ) ) );
	update_post_meta( $post_id, 'ez_tamano_apk', sanitize_text_field( wp_unslash( $_POST['ez_tamano_apk'] ?? '' ) ) );
	do_action( 'ezc_meta_box_save', $post_id );

	$shots = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', preg_split( '/\R/', wp_unslash( $_POST['ez_imagenes'] ?? '' ) ) ) ) ) );
	update_post_meta( $post_id, 'ez_imagenes', wp_json_encode( $shots ) );
} );
