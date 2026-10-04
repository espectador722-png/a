<?php
/** /membresia/: beneficios de Patreon y estado de la cuenta. */
get_header();
$ezt_member  = function_exists( 'ezc_is_member' ) && ezc_is_member();
$ezt_patreon = function_exists( 'ezc_patreon_page_url' ) ? ezc_patreon_page_url() : 'https://www.patreon.com/';
?>
<div class="wrap">
	<?php ezt_breadcrumbs( array( 0 => 'Membresía' ) ); ?>
	<section class="member-hero">
		<p class="member-hero__kicker">💎 Patreon</p>
		<h1>Membresía Eclipse Zone</h1>
		<p>Apoyá las traducciones y descargá sin vueltas. Cualquier nivel de Patreon da acceso a todo.</p>
		<?php if ( $ezt_member ) : ?>
			<p class="member-status member-status--ok">✓ Tu membresía está activa.</p>
		<?php elseif ( is_user_logged_in() ) : ?>
			<div class="member-actions">
				<a class="btn btn--patreon" href="<?php echo esc_url( $ezt_patreon ); ?>" target="_blank" rel="noopener">1. Unirme en Patreon</a>
				<a class="btn btn--ghost" href="<?php echo esc_url( home_url( '/patreon/conectar/' ) ); ?>">2. Conectar mi cuenta</a>
			</div>
		<?php else : ?>
			<div class="member-actions">
				<a class="btn btn--patreon" href="<?php echo esc_url( $ezt_patreon ); ?>" target="_blank" rel="noopener">Unirme en Patreon</a>
				<a class="btn btn--ghost" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Ya soy miembro: entrar</a>
			</div>
		<?php endif; ?>
	</section>

	<div class="perks">
		<div class="perk"><span class="perk__icon">⚡</span><h2>Sin acortadores</h2><p>Link directo en todas las descargas que lo tengan.</p></div>
		<div class="perk"><span class="perk__icon">💎</span><h2>Juegos exclusivos</h2><p>Acceso a los juegos marcados como exclusivos.</p></div>
		<div class="perk"><span class="perk__icon">❤️</span><h2>Apoyás el proyecto</h2><p>Ayudás a que sigan saliendo traducciones.</p></div>
	</div>

	<section class="section faq">
		<div class="section-head"><h2>Preguntas</h2></div>
		<details><summary>¿Cómo se activa?</summary><p>Unite en Patreon, entrá a Eclipse Zone y tocá "Conectar mi cuenta". Patreon te pide permiso y volvés con la membresía activa.</p></details>
		<details><summary>¿Qué pasa si cancelo?</summary><p>La membresía se vuelve a verificar con Patreon cada pocos días; al cancelar, el acceso se cierra solo.</p></details>
		<details><summary>¿Ven mi contraseña de Patreon?</summary><p>No. Patreon solo nos confirma si tenés una membresía activa.</p></details>
	</section>
</div>
<?php
get_footer();
