<?php
/**
 * Copiar al servidor las imágenes de los juegos que vienen de afuera
 * (portada y capturas importadas con URL externa).
 *
 * Así cargan desde tu dominio (más rápido, con miniaturas en varios tamaños
 * y con el caché/CDN del hosting) y no se rompen si el sitio externo las borra.
 *
 *   Herramientas → Importar Eclipse Zone → "Copiar imágenes al servidor":
 *     arma la cola y la procesa de a 5 juegos por minuto con WP-Cron.
 *   WP-CLI: wp eclipse imagenes   (procesa todo de una vez)
 *
 * Cada juego copiado queda marcado (_ez_img_local) y no se vuelve a bajar,
 * salvo que cambie su URL de portada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EZC_IMG_BATCH = 5;

/** ¿La URL es de este sitio? */
function ezc_is_local_url( $url ) {
	return 0 === strpos( (string) $url, content_url( '/uploads/' ) ) || 0 === strpos( (string) $url, home_url( '/' ) );
}

/** Copia la portada (como imagen destacada) y las capturas de un juego. Devuelve cuántas bajó. */
function ezc_localize_images( $post_id ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$done  = 0;
	$cover = get_post_meta( $post_id, 'ez_imagen', true );
	if ( $cover && ! ezc_is_local_url( $cover ) && get_post_meta( $post_id, '_ez_img_local', true ) !== $cover ) {
		$att = media_sideload_image( $cover, $post_id, get_the_title( $post_id ), 'id' );
		if ( ! is_wp_error( $att ) ) {
			set_post_thumbnail( $post_id, $att );
			update_post_meta( $post_id, '_ez_img_local', $cover );
			$done++;
		}
	}

	$shots   = ezc_get_screenshots( $post_id );
	$source  = wp_json_encode( $shots );
	$changed = false;
	foreach ( $shots as $i => $url ) {
		if ( ezc_is_local_url( $url ) ) {
			continue;
		}
		if ( $url === $cover && has_post_thumbnail( $post_id ) ) {
			$shots[ $i ] = get_the_post_thumbnail_url( $post_id, 'full' );
			$changed     = true;
			continue;
		}
		$att = media_sideload_image( $url, $post_id, get_the_title( $post_id ) . ' captura ' . ( $i + 1 ), 'id' );
		if ( ! is_wp_error( $att ) ) {
			$shots[ $i ] = wp_get_attachment_url( $att );
			$changed     = true;
			$done++;
		}
	}
	if ( $changed ) {
		update_post_meta( $post_id, 'ez_imagenes', wp_json_encode( array_values( $shots ) ) );
		// Lista externa original: el importador la compara para no deshacer la copia.
		if ( ! get_post_meta( $post_id, '_ez_img_src', true ) ) {
			update_post_meta( $post_id, '_ez_img_src', $source );
		}
	}
	return $done;
}

/** Juegos que todavía tienen imágenes externas. */
function ezc_pending_image_posts() {
	$ids = get_posts( array( 'post_type' => 'juego', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'publish' ) );
	return array_values( array_filter( $ids, function ( $id ) {
		$cover = get_post_meta( $id, 'ez_imagen', true );
		if ( $cover && ! ezc_is_local_url( $cover ) && get_post_meta( $id, '_ez_img_local', true ) !== $cover ) {
			return true;
		}
		foreach ( ezc_get_screenshots( $id ) as $u ) {
			if ( ! ezc_is_local_url( $u ) ) {
				return true;
			}
		}
		return false;
	} ) );
}

add_filter( 'cron_schedules', function ( $s ) {
	$s['ezc_minuto'] = array( 'interval' => 60, 'display' => 'Cada minuto' );
	return $s;
} );

function ezc_start_image_queue() {
	$queue = ezc_pending_image_posts();
	update_option( 'ezc_img_queue', $queue, false );
	if ( $queue && ! wp_next_scheduled( 'ezc_img_cron' ) ) {
		wp_schedule_event( time(), 'ezc_minuto', 'ezc_img_cron' );
	}
	return count( $queue );
}

add_action( 'ezc_img_cron', function () {
	$queue = (array) get_option( 'ezc_img_queue', array() );
	foreach ( array_splice( $queue, 0, EZC_IMG_BATCH ) as $id ) {
		ezc_localize_images( (int) $id );
	}
	update_option( 'ezc_img_queue', $queue, false );
	if ( ! $queue ) {
		wp_clear_scheduled_hook( 'ezc_img_cron' );
	}
} );

add_action( 'ezc_import_page_extra', function () {
	if ( isset( $_POST['ezc_img_start'] ) && check_admin_referer( 'ezc_img' ) ) {
		$n = ezc_start_image_queue();
		echo '<div class="notice notice-success"><p>' . esc_html( $n ? "En cola: $n juegos. Se copian de a " . EZC_IMG_BATCH . ' por minuto en segundo plano.' : 'No hay imágenes externas para copiar.' ) . '</p></div>';
	}
	$left = count( (array) get_option( 'ezc_img_queue', array() ) );
	?>
	<h2>Copiar imágenes al servidor</h2>
	<p>Baja las portadas y capturas que están en otros sitios a tu biblioteca de medios: cargan más rápido y no se rompen.</p>
	<?php if ( $left ) : ?><p><strong>Faltan <?php echo (int) $left; ?> juegos.</strong> Siguen en segundo plano.</p><?php endif; ?>
	<form method="post"><?php wp_nonce_field( 'ezc_img' ); ?>
		<p><button class="button" name="ezc_img_start" value="1">Copiar imágenes al servidor</button></p>
	</form>
	<?php
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/** Copia al servidor todas las imágenes externas de los juegos. */
	WP_CLI::add_command( 'eclipse imagenes', function () {
		$ids = ezc_pending_image_posts();
		$bar = \WP_CLI\Utils\make_progress_bar( 'Copiando imágenes', count( $ids ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$n += ezc_localize_images( $id );
			$bar->tick();
		}
		$bar->finish();
		WP_CLI::success( "$n imágenes copiadas de " . count( $ids ) . ' juegos.' );
	} );
}
