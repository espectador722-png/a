<?php
/**
 * /tops/: los más vistos (semana, mes, año o siempre) y los mejor
 * puntuados. Cada período es una URL propia (?periodo=semana).
 */
get_header();
$ezt_periods = array( 'semana' => array( 'Semana', 7 ), 'mes' => array( 'Mes', 30 ), 'ano' => array( 'Año', 365 ), 'siempre' => array( 'Siempre', 3650 ) );
$ezt_period  = isset( $_GET['periodo'] ) && isset( $ezt_periods[ $_GET['periodo'] ] ) ? sanitize_key( $_GET['periodo'] ) : 'mes';
$ezt_viewed  = function_exists( 'ezc_top' ) ? ezc_top( $ezt_periods[ $ezt_period ][1], 30 ) : array();
$ezt_rated   = function_exists( 'ezc_top_rated' ) ? ezc_top_rated( 30 ) : array();

/** Fila del ranking. */
$ezt_row = function ( $n, $post, $meta ) {
	$cover = function_exists( 'ezc_get_cover_url' ) ? ezc_get_cover_url( $post->ID, 'medium' ) : '';
	?>
	<li class="rank">
		<span class="rank__n"><?php echo (int) $n; ?></span>
		<?php if ( $cover ) : ?><img class="rank__img" src="<?php echo esc_url( $cover ); ?>" alt="" loading="lazy" width="120" height="68"><?php endif; ?>
		<div class="rank__body">
			<a class="rank__title" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
			<span class="rank__meta"><?php echo ezt_translators_html( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
		</div>
		<span class="rank__stat"><?php echo $meta; // phpcs:ignore WordPress.Security.EscapeOutput -- armado con esc_html abajo ?></span>
	</li>
	<?php
};
?>
<div class="wrap">
	<?php ezt_breadcrumbs( array( 0 => 'Tops' ) ); ?>
	<header class="archive-head">
		<h1>Tops</h1>
		<p>Los juegos más vistos y los mejor puntuados por la comunidad.</p>
	</header>

	<div class="tops">
		<section>
			<div class="section-head"><h2>Más vistos</h2></div>
			<nav class="top__tabs" aria-label="Período">
				<?php foreach ( $ezt_periods as $k => $p ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'periodo', $k, get_permalink() ) ); ?>" <?php echo $k === $ezt_period ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $p[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<ol class="ranking">
				<?php
				foreach ( $ezt_viewed as $i => $r ) {
					$ezt_row( $i + 1, $r['post'], '<span aria-hidden="true">👁</span> ' . esc_html( number_format_i18n( $r['vistas'] ) ) );
				}
				if ( ! $ezt_viewed ) {
					echo '<li class="top__empty">Todavía no hay datos de este período.</li>';
				}
				?>
			</ol>
		</section>

		<section>
			<div class="section-head"><h2>Mejor puntuados</h2></div>
			<p class="tops__note">Ordenados por puntuación ponderada: un 5 con un solo voto no le gana a un 4,8 con muchos.</p>
			<ol class="ranking">
				<?php
				foreach ( $ezt_rated as $i => $r ) {
					$ezt_row( $i + 1, $r['post'], '<span class="stat--rating"><span aria-hidden="true">★</span></span> ' . esc_html( number_format_i18n( $r['media'], 1 ) ) . ' <small>(' . (int) $r['votos'] . ')</small>' );
				}
				if ( ! $ezt_rated ) {
					echo '<li class="top__empty">Todavía nadie votó.</li>';
				}
				?>
			</ol>
		</section>
	</div>
</div>
<?php
get_footer();
