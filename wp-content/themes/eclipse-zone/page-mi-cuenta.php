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
