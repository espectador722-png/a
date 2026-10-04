<?php
/**
 * Campos de cada juego (post meta) y la caja para editarlos en el admin.
 *
 * ez_version            "v0.109"
 * ez_links              JSON: [{"nombre":"PC","url":"https://..."}]
 * ez_imagenes           JSON: ["https://.../1.jpg", ...] (capturas)
 * ez_imagen             URL de portada externa (si no hay imagen destacada)
 * ez_fecha_registro     fecha original de publicación (Y-m-d)
 * ez_source_key         clave del juego en juegos.json (para re-importar)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	foreach ( array( 'ez_version', 'ez_links', 'ez_imagenes', 'ez_imagen', 'ez_fecha_registro', 'ez_source_key' ) as $key ) {
		register_post_meta( 'juego', $key, array(
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
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

	$links_text = implode( "\n", array_map( function ( $l ) {
		return ( $l['nombre'] ?? '' ) . ' | ' . $l['url'];
	}, $links ) );
	?>
	<p><label><strong>Versión</strong><br>
		<input type="text" name="ez_version" value="<?php echo esc_attr( $version ); ?>" class="regular-text" placeholder="v0.109"></label></p>
	<p><label><strong>Portada (URL externa, opcional si hay imagen destacada)</strong><br>
		<input type="url" name="ez_imagen" value="<?php echo esc_attr( $imagen ); ?>" class="large-text"></label></p>
	<p><label><strong>Links de descarga</strong> — uno por línea: <code>Nombre | URL</code><br>
		<textarea name="ez_links" rows="4" class="large-text code"><?php echo esc_textarea( $links_text ); ?></textarea></label></p>
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
	update_post_meta( $post_id, 'ez_imagen', esc_url_raw( wp_unslash( $_POST['ez_imagen'] ?? '' ) ) );

	$links = array();
	foreach ( preg_split( '/\R/', wp_unslash( $_POST['ez_links'] ?? '' ) ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$url   = esc_url_raw( count( $parts ) === 2 ? $parts[1] : $parts[0] );
		if ( $url ) {
			$links[] = array( 'nombre' => count( $parts ) === 2 ? sanitize_text_field( $parts[0] ) : '', 'url' => $url );
		}
	}
	update_post_meta( $post_id, 'ez_links', wp_json_encode( $links ) );

	$shots = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', preg_split( '/\R/', wp_unslash( $_POST['ez_imagenes'] ?? '' ) ) ) ) ) );
	update_post_meta( $post_id, 'ez_imagenes', wp_json_encode( $shots ) );
} );
