<?php
/**
 * Membresía con Patreon (misma lógica que el Worker del sitio anterior).
 *
 * - Cualquier membresía activa (patron_status = active_patron), de cualquier
 *   nivel, da acceso.
 * - Beneficios: descargas directas sin acortador y juegos exclusivos.
 * - El intercambio del código OAuth pasa en el servidor con el client secret,
 *   que nunca llega al navegador.
 * - La membresía se vuelve a verificar con Patreon cada 3 días (con el refresh
 *   token), así una baja en Patreon se nota sola.
 *
 * Ajustes → Eclipse Zone: Client ID, Client Secret, página de Patreon y
 * géneros exclusivos. Redirect URI a registrar en Patreon:
 *   https://<tu-dominio>/patreon/callback/
 *
 * Datos del juego:
 *   ez_exclusivo        "1" = solo miembros (descargas ocultas para el resto)
 *   _ez_links_directos  JSON como ez_links, sin acortador. Con "_" adelante:
 *                       WordPress no lo expone en la API ni en Campos personalizados.
 * Datos del usuario (user meta, privado):
 *   _ez_patreon         {connected, status, patreon_id, verified, access, refresh, expires}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EZC_PATREON_RECHECK = 3 * DAY_IN_SECONDS;

function ezc_patreon_opt( $key, $default = '' ) {
	$opts = get_option( 'ezc_patreon', array() );
	return isset( $opts[ $key ] ) && '' !== $opts[ $key ] ? $opts[ $key ] : $default;
}

function ezc_patreon_configured() {
	return ezc_patreon_opt( 'client_id' ) && ezc_patreon_opt( 'client_secret' );
}

function ezc_patreon_page_url() {
	return ezc_patreon_opt( 'page_url', 'https://www.patreon.com/cw/EclipseZone' );
}

function ezc_patreon_redirect_uri() {
	return home_url( '/patreon/callback/' );
}

/** ¿El usuario tiene acceso de miembro? Editores y admins siempre. */
function ezc_is_member( $user_id = null ) {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'edit_posts' ) ) {
		return true;
	}
	$p = get_user_meta( $user_id, '_ez_patreon', true );
	if ( ! is_array( $p ) || empty( $p['connected'] ) ) {
		return false;
	}
	if ( time() - (int) ( $p['verified'] ?? 0 ) > EZC_PATREON_RECHECK ) {
		$p = ezc_patreon_recheck( $user_id, $p );
	}
	return ! empty( $p['connected'] );
}

/** Géneros cuyo juego es exclusivo (Ajustes → Eclipse Zone), en minúsculas. */
function ezc_exclusive_genres() {
	$raw = (string) ezc_patreon_opt( 'generos_exclusivos', '' );
	return array_filter( array_map( function ( $g ) {
		return mb_strtolower( trim( $g ) );
	}, preg_split( '/[,\n]/', $raw ) ) );
}

function ezc_is_exclusive( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();
	if ( '1' === get_post_meta( $post_id, 'ez_exclusivo', true ) ) {
		return true;
	}
	$excl = ezc_exclusive_genres();
	if ( ! $excl ) {
		return false;
	}
	$generos = array_map( 'mb_strtolower', (array) wp_get_post_terms( $post_id, 'genero', array( 'fields' => 'names' ) ) );
	return (bool) array_intersect( $excl, $generos );
}

function ezc_get_direct_links( $post_id = null ) {
	$links = json_decode( (string) get_post_meta( $post_id ?: get_the_ID(), '_ez_links_directos', true ), true );
	return is_array( $links ) ? array_values( array_filter( $links, function ( $l ) {
		return ! empty( $l['url'] );
	} ) ) : array();
}

// ── OAuth ──────────────────────────────────────────────────────

add_action( 'init', function () {
	add_rewrite_rule( '^patreon/(conectar|callback|desconectar)/?$', 'index.php?ez_patreon=$matches[1]', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'ez_patreon';
	return $vars;
} );

add_action( 'template_redirect', function () {
	$action = get_query_var( 'ez_patreon' );
	if ( ! $action ) {
		return;
	}
	nocache_headers();
	$cuenta = home_url( '/mi-cuenta/' );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( home_url( '/patreon/conectar/' ) ) );
		exit;
	}
	$uid = get_current_user_id();

	if ( 'desconectar' === $action ) {
		check_admin_referer( 'ez_patreon_off' );
		delete_user_meta( $uid, '_ez_patreon' );
		wp_safe_redirect( add_query_arg( 'patreon', 'desconectado', $cuenta ) );
		exit;
	}

	if ( 'conectar' === $action ) {
		if ( ! ezc_patreon_configured() ) {
			wp_redirect( ezc_patreon_page_url() ); // phpcs:ignore WordPress.Security.SafeRedirect -- URL de Patreon configurada por el admin
			exit;
		}
		$state = wp_generate_password( 24, false );
		set_transient( 'ez_patreon_state_' . $uid, $state, 15 * MINUTE_IN_SECONDS );
		wp_redirect( add_query_arg( array( // phpcs:ignore WordPress.Security.SafeRedirect
			'response_type' => 'code',
			'client_id'     => ezc_patreon_opt( 'client_id' ),
			'redirect_uri'  => ezc_patreon_redirect_uri(),
			'scope'         => 'identity identity.memberships',
			'state'         => $state,
		), 'https://www.patreon.com/oauth2/authorize' ) );
		exit;
	}

	// callback
	$expected = get_transient( 'ez_patreon_state_' . $uid );
	delete_transient( 'ez_patreon_state_' . $uid );
	$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
	if ( isset( $_GET['error'] ) || ! $code ) {
		wp_safe_redirect( add_query_arg( 'patreon', 'cancelado', $cuenta ) );
		exit;
	}
	if ( ! $expected || ! hash_equals( $expected, (string) ( $_GET['state'] ?? '' ) ) ) {
		wp_safe_redirect( add_query_arg( 'patreon', 'error', $cuenta ) );
		exit;
	}
	$tokens = ezc_patreon_token( array(
		'grant_type'   => 'authorization_code',
		'code'         => $code,
		'redirect_uri' => ezc_patreon_redirect_uri(),
	) );
	$p = $tokens ? ezc_patreon_identity( $tokens ) : null;
	if ( ! $p ) {
		wp_safe_redirect( add_query_arg( 'patreon', 'error', $cuenta ) );
		exit;
	}
	update_user_meta( $uid, '_ez_patreon', $p );
	wp_safe_redirect( add_query_arg( 'patreon', $p['connected'] ? 'ok' : 'sin-membresia', $cuenta ) );
	exit;
} );

/** POST al endpoint de tokens de Patreon. Devuelve el JSON o null. */
function ezc_patreon_token( array $params ) {
	$res = wp_remote_post( 'https://www.patreon.com/api/oauth2/token', array(
		'timeout' => 20,
		'body'    => $params + array(
			'client_id'     => ezc_patreon_opt( 'client_id' ),
			'client_secret' => ezc_patreon_opt( 'client_secret' ),
		),
	) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return ! empty( $data['access_token'] ) ? $data : null;
}

/** Lee la identidad y membresías; devuelve el registro a guardar o null. */
function ezc_patreon_identity( array $tokens ) {
	$res = wp_remote_get( 'https://www.patreon.com/api/oauth2/v2/identity?include=memberships&fields%5Bmember%5D=patron_status', array(
		'timeout' => 20,
		'headers' => array( 'Authorization' => 'Bearer ' . $tokens['access_token'] ),
	) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$data    = json_decode( wp_remote_retrieve_body( $res ), true );
	$members = array_filter( (array) ( $data['included'] ?? array() ), function ( $i ) {
		return 'member' === ( $i['type'] ?? '' );
	} );
	$status  = 'none';
	foreach ( $members as $m ) {
		$s = $m['attributes']['patron_status'] ?? '';
		if ( 'active_patron' === $s ) {
			$status = $s;
			break;
		}
		$status = $s ?: $status;
	}
	return array(
		'connected'  => 'active_patron' === $status,
		'status'     => $status,
		'patreon_id' => $data['data']['id'] ?? '',
		'verified'   => time(),
		'access'     => $tokens['access_token'],
		'refresh'    => $tokens['refresh_token'] ?? '',
		'expires'    => time() + (int) ( $tokens['expires_in'] ?? 0 ),
	);
}

/** Vuelve a consultar a Patreon. Si Patreon no responde, mantiene el estado un día más. */
function ezc_patreon_recheck( $user_id, array $p ) {
	$tokens = null;
	if ( ! empty( $p['access'] ) && (int) ( $p['expires'] ?? 0 ) > time() + 60 ) {
		$tokens = array( 'access_token' => $p['access'], 'refresh_token' => $p['refresh'] ?? '', 'expires_in' => (int) $p['expires'] - time() );
	} elseif ( ! empty( $p['refresh'] ) ) {
		$tokens = ezc_patreon_token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $p['refresh'] ) );
	}
	$fresh = $tokens ? ezc_patreon_identity( $tokens ) : null;
	if ( $fresh ) {
		$p = $fresh;
	} elseif ( $tokens || empty( $p['refresh'] ) ) {
		$p['verified'] = time() - EZC_PATREON_RECHECK + DAY_IN_SECONDS; // reintentar mañana
	} else {
		$p['connected'] = false; // el refresh token ya no sirve: reconectar
	}
	update_user_meta( $user_id, '_ez_patreon', $p );
	return $p;
}

// ── Ajustes → Eclipse Zone ─────────────────────────────────────

add_action( 'admin_menu', function () {
	add_options_page( 'Eclipse Zone', 'Eclipse Zone', 'manage_options', 'ezc-ajustes', 'ezc_render_settings' );
} );

add_action( 'admin_init', function () {
	register_setting( 'ezc_ajustes', 'ezc_patreon', array(
		'type'              => 'array',
		'sanitize_callback' => function ( $in ) {
			$old = get_option( 'ezc_patreon', array() );
			return array(
				'client_id'          => sanitize_text_field( $in['client_id'] ?? '' ),
				// Vacío = no cambiar (el secreto no se vuelve a mostrar).
				'client_secret'      => '' !== ( $in['client_secret'] ?? '' ) ? sanitize_text_field( $in['client_secret'] ) : ( $old['client_secret'] ?? '' ),
				'page_url'           => esc_url_raw( $in['page_url'] ?? '' ),
				'generos_exclusivos' => sanitize_textarea_field( $in['generos_exclusivos'] ?? '' ),
				'gitlab_token'       => '' !== ( $in['gitlab_token'] ?? '' ) ? sanitize_text_field( $in['gitlab_token'] ) : ( $old['gitlab_token'] ?? '' ),
			);
		},
	) );
} );

function ezc_render_settings() {
	?>
	<div class="wrap">
		<h1>Eclipse Zone</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'ezc_ajustes' ); ?>
			<h2>Patreon</h2>
			<p>Creá un cliente en <a href="https://www.patreon.com/portal/registration/register-clients" target="_blank" rel="noopener">patreon.com/portal</a> con esta <strong>Redirect URI</strong>:<br>
				<code><?php echo esc_html( ezc_patreon_redirect_uri() ); ?></code></p>
			<table class="form-table">
				<tr><th><label for="ezp-id">Client ID</label></th>
					<td><input id="ezp-id" class="regular-text" name="ezc_patreon[client_id]" value="<?php echo esc_attr( ezc_patreon_opt( 'client_id' ) ); ?>"></td></tr>
				<tr><th><label for="ezp-secret">Client Secret</label></th>
					<td><input id="ezp-secret" class="regular-text" type="password" name="ezc_patreon[client_secret]" value="" placeholder="<?php echo ezc_patreon_opt( 'client_secret' ) ? '•••••••• (guardado)' : ''; ?>" autocomplete="off"></td></tr>
				<tr><th><label for="ezp-page">Página de Patreon</label></th>
					<td><input id="ezp-page" class="regular-text" type="url" name="ezc_patreon[page_url]" value="<?php echo esc_attr( ezc_patreon_page_url() ); ?>"></td></tr>
				<tr><th><label for="ezp-gen">Géneros exclusivos</label></th>
					<td><textarea id="ezp-gen" class="large-text" rows="3" name="ezc_patreon[generos_exclusivos]"><?php echo esc_textarea( ezc_patreon_opt( 'generos_exclusivos' ) ); ?></textarea>
					<p class="description">Separados por coma. Los juegos de esos géneros solo los descargan los miembros (como <code>generosExclusivos</code> de patreon-config.json).</p></td></tr>
			</table>
			<h2>Importación</h2>
			<table class="form-table">
				<tr><th><label for="ezp-gl">Token de GitLab</label></th>
					<td><input id="ezp-gl" class="regular-text" type="password" name="ezc_patreon[gitlab_token]" value="" placeholder="<?php echo ezc_patreon_opt( 'gitlab_token' ) ? '•••••••• (guardado)' : 'solo para exclusivos.json (privado)'; ?>" autocomplete="off">
					<p class="description">Token de solo lectura para leer <code>exclusivos.json</code> de GitLab.</p></td></tr>
			</table>
			<?php submit_button( 'Guardar' ); ?>
		</form>
	</div>
	<?php
}

// Casilla "Exclusivo de Patreon" y links directos en la caja "Datos del juego".
add_action( 'ezc_meta_box_extra', function ( $post ) {
	$directos = implode( "\n", array_map( function ( $l ) {
		return ( $l['nombre'] ?? '' ) . ' | ' . $l['url'];
	}, ezc_get_direct_links( $post->ID ) ) );
	?>
	<p><label><input type="checkbox" name="ez_exclusivo" value="1" <?php checked( get_post_meta( $post->ID, 'ez_exclusivo', true ), '1' ); ?>>
		<strong>Exclusivo de Patreon</strong> (solo los miembros ven las descargas)</label></p>
	<p><label><strong>Links directos sin acortador (solo miembros)</strong> — <code>Nombre | URL</code><br>
		<textarea name="ez_links_directos" rows="3" class="large-text code"><?php echo esc_textarea( $directos ); ?></textarea></label></p>
	<?php
} );
add_action( 'ezc_meta_box_save', function ( $post_id ) {
	update_post_meta( $post_id, 'ez_exclusivo', empty( $_POST['ez_exclusivo'] ) ? '' : '1' );
	update_post_meta( $post_id, '_ez_links_directos', wp_json_encode( ezc_parse_links_text( wp_unslash( $_POST['ez_links_directos'] ?? '' ) ) ) );
} );
