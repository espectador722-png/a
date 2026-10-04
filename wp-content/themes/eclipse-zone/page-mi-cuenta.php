<?php
/** /mi-cuenta/: favoritos y "seguir leyendo" del usuario. */
if ( ! is_user_logged_in() ) {
	wp_safe_redirect( wp_login_url( get_permalink() ) );
	exit;
}
get_header();

global $post;
$ez_favs    = ezc_user_favorites();
$ez_history = ezc_user_history( 12 );
?>
<div class="wrap">
	<header class="archive-head">
		<h1>Hola, <?php echo esc_html( wp_get_current_user()->display_name ); ?></h1>
		<p><a href="<?php echo esc_url( get_edit_profile_url() ); ?>">Editar perfil y contraseña</a> · <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Salir</a></p>
	</header>

	<?php
	$ez_msgs = array(
		'ok'            => array( 'ok', '¡Conectado con Patreon! Ya tenés descargas directas y exclusivos 💎' ),
		'sin-membresia' => array( 'warn', 'Tu cuenta de Patreon no tiene una membresía activa todavía.' ),
		'cancelado'     => array( 'info', 'Conexión con Patreon cancelada.' ),
		'error'         => array( 'warn', 'No se pudo verificar con Patreon. Probá de nuevo.' ),
		'desconectado'  => array( 'info', 'Patreon desconectado.' ),
	);
	$ez_flag = isset( $_GET['patreon'] ) ? sanitize_key( $_GET['patreon'] ) : '';
	if ( isset( $ez_msgs[ $ez_flag ] ) ) {
		printf( '<p class="notice notice--%s">%s</p>', esc_attr( $ez_msgs[ $ez_flag ][0] ), esc_html( $ez_msgs[ $ez_flag ][1] ) );
	}
	$ez_p = get_user_meta( get_current_user_id(), '_ez_patreon', true );
	?>
	<section class="panel patreon-box">
		<h2>💎 Patreon</h2>
		<?php if ( function_exists( 'ezc_is_member' ) && ezc_is_member() ) : ?>
			<p class="member-status member-status--ok">✓ Membresía activa<?php echo current_user_can( 'edit_posts' ) && empty( $ez_p['connected'] ) ? ' (acceso de editor)' : ''; ?>.</p>
		<?php else : ?>
			<p>Conectá tu cuenta de Patreon para descargar sin acortadores y acceder a los exclusivos.</p>
		<?php endif; ?>
		<p class="member-actions">
			<?php if ( empty( $ez_p['connected'] ) ) : ?>
				<a class="btn btn--patreon" href="<?php echo esc_url( home_url( '/patreon/conectar/' ) ); ?>">Conectar con Patreon</a>
				<a class="btn btn--ghost" href="<?php echo esc_url( home_url( '/membresia/' ) ); ?>">Beneficios</a>
			<?php else : ?>
				<a class="btn btn--ghost" href="<?php echo esc_url( wp_nonce_url( home_url( '/patreon/desconectar/' ), 'ez_patreon_off' ) ); ?>">Desconectar Patreon</a>
			<?php endif; ?>
		</p>
	</section>

	<?php if ( function_exists( 'ezc_library_states' ) ) : ?>
		<section class="section">
			<h2>Mi Biblioteca</h2>
			<?php
			$ez_any = false;
			foreach ( ezc_library_states() as $ez_k => $ez_label ) :
				$ez_list = ezc_library_posts( $ez_k );
				if ( ! $ez_list ) {
					continue;
				}
				$ez_any = true;
				?>
				<h3 class="lib-title"><?php echo esc_html( $ez_label ); ?> <small><?php echo count( $ez_list ); ?></small></h3>
				<div class="grid grid--manga">
					<?php
					foreach ( $ez_list as $post ) {
						setup_postdata( $post );
						get_template_part( 'template-parts/card', 'manga' );
					}
					wp_reset_postdata();
					?>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $ez_any ) : ?><p>Usá los botones 📖 Leyendo, 🔖 Por leer y ✅ Completado en cada manga para armar tu biblioteca.</p><?php endif; ?>
		</section>
	<?php endif; ?>

	<section class="section">
		<h2>Seguir leyendo</h2>
		<?php if ( $ez_history ) : ?>
			<div class="grid grid--manga">
				<?php
				foreach ( $ez_history as $post ) {
					setup_postdata( $post );
					get_template_part( 'template-parts/card', 'manga' );
				}
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<p>Todavía no leíste nada.</p>
		<?php endif; ?>
	</section>

	<section class="section">
		<h2>Mis favoritos</h2>
		<?php if ( $ez_favs ) : ?>
			<div class="grid grid--games">
				<?php
				foreach ( $ez_favs as $post ) {
					setup_postdata( $post );
					get_template_part( 'template-parts/card', get_post_type() );
				}
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<p>Marcá juegos o mangas con ★ para verlos aquí.</p>
		<?php endif; ?>
	</section>
</div>
<?php
get_footer();
