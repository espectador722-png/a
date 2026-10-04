<?php
/**
 * Descargas de la ficha: botón que abre una ventana con los links agrupados
 * por traductor (con cuántos acortadores tiene cada uno) y la zona amarilla
 * de descarga directa sin acortador para los miembros de Patreon.
 *
 * Juego exclusivo y visitante sin membresía: no se imprime ningún link,
 * ni siquiera oculto; solo la invitación a Patreon.
 */
$ez_id     = get_the_ID();
$ez_member = function_exists( 'ezc_is_member' ) && ezc_is_member();
$ez_excl   = function_exists( 'ezc_is_exclusive' ) && ezc_is_exclusive( $ez_id );
$ez_links  = ezc_get_links( $ez_id );
$ez_direct = function_exists( 'ezc_get_direct_links' ) ? ezc_get_direct_links( $ez_id ) : array();
$ez_sizes  = array( 'pc' => get_post_meta( $ez_id, 'ez_tamano_pc', true ), 'apk' => get_post_meta( $ez_id, 'ez_tamano_apk', true ) );
$ez_join   = home_url( '/membresia/' );

/** Botón de un link: nombre, tamaño (si es PC/APK) y acortadores. */
$ez_link_btn = function ( $l, $direct = false ) use ( $ez_sizes ) {
	$name = $l['nombre'] ?: 'Descargar';
	$size = '';
	if ( preg_match( '/\bpc\b|windows/i', $name ) && $ez_sizes['pc'] ) {
		$size = $ez_sizes['pc'];
	} elseif ( preg_match( '/android|apk/i', $name ) && $ez_sizes['apk'] ) {
		$size = $ez_sizes['apk'];
	}
	$n = (int) ( $l['acortadores'] ?? 0 );
	printf(
		'<a class="dl-link%s" href="%s" rel="nofollow noopener" target="_blank"><span class="dl-link__name">%s%s</span>%s</a>',
		$direct ? ' dl-link--direct' : '',
		esc_url( $l['url'] ),
		esc_html( $name ),
		$size ? ' <small>(' . esc_html( $size ) . ')</small>' : '',
		$direct ? '<span class="dl-badge dl-badge--direct">Directo</span>' : ( $n ? '<span class="dl-badge">' . esc_html( $n . ( 1 === $n ? ' acortador' : ' acortadores' ) ) . '</span>' : '' )
	);
};
?>
<?php $ez_plat = function_exists( 'ezc_game_platforms' ) ? ezc_game_platforms( $ez_id ) : array( 'android' => false ); ?>
<h2 class="dl-title">Descargar <?php the_title(); ?> en español<?php echo ! empty( $ez_plat['solo_android'] ) ? ' APK' : ( $ez_plat['android'] && ! empty( $ez_plat['pc'] ) ? ' (PC y APK)' : '' ); ?></h2>

<?php if ( $ez_excl && ! $ez_member ) : ?>
	<div class="dl-lock">
		<p class="dl-lock__title">💎 Exclusivo de Patreon</p>
		<p>Este juego lo pueden descargar los miembros de Patreon de cualquier nivel.</p>
		<?php if ( is_user_logged_in() ) : ?>
			<a class="btn btn--patreon" href="<?php echo esc_url( home_url( '/patreon/conectar/' ) ); ?>">Conectar con Patreon</a>
		<?php else : ?>
			<a class="btn btn--patreon" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">Entrá y conectá Patreon</a>
		<?php endif; ?>
		<a class="dl-lock__more" href="<?php echo esc_url( $ez_join ); ?>">¿Qué incluye la membresía?</a>
	</div>

<?php elseif ( $ez_links || $ez_direct ) : ?>
	<button type="button" class="btn btn--download" data-ez-dialog="ez-descargas">⬇ Descargar</button>
	<?php if ( $ez_excl ) : ?><p class="dl-note">💎 Exclusivo: lo ves porque sos miembro.</p><?php endif; ?>

	<dialog id="ez-descargas" class="dl-dialog" aria-labelledby="ez-dl-h">
		<header class="dl-dialog__head">
			<h2 id="ez-dl-h"><?php echo esc_html( function_exists( 'ezc_game_title' ) ? ezc_game_title( $ez_id ) : get_the_title() ); ?></h2>
			<button type="button" class="dl-dialog__close" data-ez-dialog-close aria-label="Cerrar">✕</button>
		</header>

		<div class="dl-dialog__body">
			<?php
			// Agrupar por traductor (como en el sitio anterior).
			$ez_groups = array();
			foreach ( $ez_links as $l ) {
				$ez_groups[ $l['traductor'] ?? '' ][] = $l;
			}
			$ez_terms = array();
			foreach ( (array) get_the_terms( $ez_id, 'traductor' ) as $t ) {
				if ( $t instanceof WP_Term ) {
					$ez_terms[ mb_strtolower( $t->name ) ] = $t;
				}
			}
			foreach ( $ez_groups as $ez_trad => $ez_list ) :
				$ez_term  = $ez_terms[ mb_strtolower( $ez_trad ) ] ?? null;
				$ez_label = $ez_trad ?: ezt_term_names( $ez_id, 'traductor' );
				?>
				<section class="dl-group">
					<?php if ( $ez_label ) : ?>
						<h3 class="dl-group__title">
							<span class="trad" style="--c:<?php echo esc_attr( $ez_term && function_exists( 'ezc_translator_color' ) ? ezc_translator_color( $ez_term ) : '#c4b5fd' ); ?>"><?php echo esc_html( $ez_label ); ?></span>
							<small><?php echo esc_html( count( $ez_list ) . ( 1 === count( $ez_list ) ? ' enlace' : ' enlaces' ) ); ?></small>
						</h3>
					<?php endif; ?>
					<div class="dl-list"><?php array_map( $ez_link_btn, $ez_list ); ?></div>
				</section>
			<?php endforeach; ?>

			<section class="dl-direct">
				<?php if ( $ez_member && $ez_direct ) : ?>
					<p class="dl-direct__title">⚡ Descarga directa sin acortador</p>
					<div class="dl-list">
						<?php
						foreach ( $ez_direct as $l ) {
							$ez_link_btn( $l, true );
						}
						?>
					</div>
				<?php elseif ( $ez_member ) : ?>
					<p class="dl-direct__title">⚡ Sos miembro</p>
					<p>Este juego todavía no tiene link directo. Los links de arriba son los únicos por ahora.</p>
				<?php else : ?>
					<p class="dl-direct__title">⚡ Descargá sin acortadores</p>
					<p>Los miembros de Patreon, de cualquier nivel, descargan con link directo y acceden a los juegos exclusivos.</p>
					<a class="btn btn--patreon" href="<?php echo esc_url( is_user_logged_in() ? home_url( '/patreon/conectar/' ) : wp_login_url( get_permalink() ) ); ?>">
						<?php echo is_user_logged_in() ? 'Conectar con Patreon' : 'Entrá y conectá Patreon'; ?>
					</a>
					<a class="dl-lock__more" href="<?php echo esc_url( $ez_join ); ?>">Ver beneficios</a>
				<?php endif; ?>
			</section>

			<p class="dl-warn">Los acortadores pueden mostrar publicidad. Nunca instales extensiones ni "actualizaciones" que te pidan en el camino.</p>
		</div>
	</dialog>

<?php else : ?>
	<p>Descarga no disponible por ahora.</p>
<?php endif; ?>
