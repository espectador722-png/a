<?php
/**
 * Comunidad: reacciones con emojis y color por traductor.
 *
 * Reacciones: tabla {prefix}ez_reacciones (post_id, user_id, emoji). Cada
 * usuario con sesión puede marcar varias reacciones distintas por entrada
 * (como en Discord) y quitarlas con otro clic. Los totales se guardan en el
 * meta ez_reacciones ({"fuego": 12, ...}) para mostrarlos sin consultar la tabla.
 *
 * Color de traductor: meta de término "ez_color" (Juegos → Traductores →
 * editar). Sin color elegido, se calcula uno fijo a partir del nombre.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reacciones disponibles: clave => emoji. */
function ezc_reactions() {
	return array(
		'like'   => '👍',
		'amor'   => '❤️',
		'fuego'  => '🔥',
		'risa'   => '😂',
		'wow'    => '😮',
		'triste' => '😢',
	);
}

const EZC_COMUNIDAD_DB_VERSION = 1;

add_action( 'init', function () {
	if ( (int) get_option( 'ezc_comunidad_db_version' ) >= EZC_COMUNIDAD_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$wpdb->prefix}ez_reacciones (
		post_id bigint(20) unsigned NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		emoji varchar(16) NOT NULL,
		PRIMARY KEY  (post_id,user_id,emoji)
	) {$wpdb->get_charset_collate()};" );

	// Lo creado antes de esta versión quedó con comentarios cerrados (los
	// tipos no tenían 'comments'): abrirlos una sola vez, respetando el
	// valor por defecto de Ajustes → Comentarios.
	if ( 'open' === get_option( 'default_comment_status' ) ) {
		$wpdb->query( "UPDATE {$wpdb->posts} SET comment_status = 'open' WHERE post_type IN ('juego','noticia','manga') AND comment_status = 'closed'" );
	}
	update_option( 'ezc_comunidad_db_version', EZC_COMUNIDAD_DB_VERSION );
} );

/** Totales por reacción: ['like' => 3, ...] (solo las que tienen alguna). */
function ezc_reaction_counts( $post_id ) {
	$counts = json_decode( (string) get_post_meta( $post_id, 'ez_reacciones', true ), true );
	return is_array( $counts ) ? $counts : array();
}

/** Reacciones que marcó el usuario actual: ['fuego', ...]. */
function ezc_user_reactions( $post_id ) {
	global $wpdb;
	$uid = get_current_user_id();
	if ( ! $uid ) {
		return array();
	}
	return $wpdb->get_col( $wpdb->prepare( "SELECT emoji FROM {$wpdb->prefix}ez_reacciones WHERE post_id = %d AND user_id = %d", $post_id, $uid ) );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'ez/v1', '/reaccion', array(
		'methods'             => 'POST',
		'permission_callback' => 'is_user_logged_in',
		'args'                => array(
			'id'     => array(
				'required'          => true,
				'validate_callback' => function ( $id ) {
					return in_array( get_post_type( (int) $id ), array( 'juego', 'manga', 'noticia' ), true ) && 'publish' === get_post_status( (int) $id );
				},
			),
			'emoji'  => array(
				'required'          => true,
				'validate_callback' => function ( $e ) {
					return isset( ezc_reactions()[ $e ] );
				},
			),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			global $wpdb;
			$table = "{$wpdb->prefix}ez_reacciones";
			$id    = (int) $req['id'];
			$row   = array( 'post_id' => $id, 'user_id' => get_current_user_id(), 'emoji' => $req['emoji'] );

			// Clic = marcar; otro clic = quitar.
			if ( ! $wpdb->delete( $table, $row ) ) {
				$wpdb->insert( $table, $row );
			}

			$counts = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT emoji, COUNT(*) AS n FROM $table WHERE post_id = %d GROUP BY emoji", $id ) ) as $r ) {
				$counts[ $r->emoji ] = (int) $r->n;
			}
			update_post_meta( $id, 'ez_reacciones', wp_json_encode( $counts ) );
			return array( 'counts' => $counts, 'mias' => ezc_user_reactions( $id ) );
		},
	) );
} );

add_action( 'deleted_post', function ( $post_id ) {
	global $wpdb;
	$wpdb->delete( "{$wpdb->prefix}ez_reacciones", array( 'post_id' => $post_id ) );
} );

// ── Color por traductor ───────────────────────────────────────

/** Color del traductor en hex. Sin elegir: uno fijo según el nombre, en la gama rosa-púrpura-azul-cian. */
function ezc_translator_color( $term ) {
	$term  = is_object( $term ) ? $term : get_term( $term, 'traductor' );
	$color = $term ? get_term_meta( $term->term_id, 'ez_color', true ) : '';
	if ( $color ) {
		return $color;
	}
	$palette = array( '#f472b6', '#c084fc', '#818cf8', '#60a5fa', '#22d3ee', '#a78bfa', '#fb7185', '#e879f9' );
	return $palette[ abs( crc32( $term ? $term->slug : '' ) ) % count( $palette ) ];
}

add_action( 'traductor_edit_form_fields', function ( $term ) {
	?>
	<tr class="form-field">
		<th scope="row"><label for="ez_color">Color</label></th>
		<td>
			<input type="color" id="ez_color" name="ez_color" value="<?php echo esc_attr( ezc_translator_color( $term ) ); ?>">
			<p class="description">Color del nombre en las tarjetas de juegos.</p>
		</td>
	</tr>
	<?php
} );
add_action( 'traductor_add_form_fields', function () {
	?>
	<div class="form-field">
		<label for="ez_color">Color</label>
		<input type="color" id="ez_color" name="ez_color" value="#c084fc">
	</div>
	<?php
} );
$ezc_save_color = function ( $term_id ) {
	if ( isset( $_POST['ez_color'] ) && current_user_can( 'manage_categories' ) ) {
		$color = sanitize_hex_color( wp_unslash( $_POST['ez_color'] ) );
		update_term_meta( $term_id, 'ez_color', $color ?: '' );
	}
};
add_action( 'edited_traductor', $ezc_save_color );
add_action( 'created_traductor', $ezc_save_color );
