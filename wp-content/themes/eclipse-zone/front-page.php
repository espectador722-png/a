<?php
/**
 * Inicio: últimos juegos, noticias y mangas, todo en HTML con enlaces
 * reales, más un acceso directo a cada página del catálogo.
 */
get_header();

$ez_juegos   = new WP_Query( array( 'post_type' => 'juego', 'posts_per_page' => 12, 'orderby' => 'modified', 'no_found_rows' => true ) );
$ez_noticias = new WP_Query( array( 'post_type' => 'noticia', 'posts_per_page' => 6, 'no_found_rows' => true ) );
$ez_mangas   = new WP_Query( array( 'post_type' => 'manga', 'post_parent' => 0, 'posts_per_page' => 12, 'orderby' => 'modified', 'no_found_rows' => true ) );
$ez_total    = (int) wp_count_posts( 'juego' )->publish;
?>
<div class="wrap">
	<h1 class="screen-reader-text"><?php bloginfo( 'name' ); ?> — Juegos y mangas traducidos al español</h1>
	<?php
	// Carrusel: los marcados "Destacado"; si no hay, los más vistos del mes; si
	// tampoco, los últimos actualizados.
	$ez_dest = get_posts( array( 'post_type' => 'juego', 'posts_per_page' => 6, 'meta_key' => 'ez_destacado', 'meta_value' => '1', 'orderby' => 'modified' ) );
	if ( ! $ez_dest && function_exists( 'ezc_top' ) ) {
		$ez_dest = wp_list_pluck( ezc_top( 30, 6 ), 'post' );
	}
	if ( ! $ez_dest ) {
		$ez_dest = get_posts( array( 'post_type' => 'juego', 'posts_per_page' => 5, 'orderby' => 'modified' ) );
	}
	$ez_periodos = array( 'semana' => array( 'Semana', 7 ), 'mes' => array( 'Mes', 30 ), 'ano' => array( 'Año', 365 ) );
	?>
	<div class="home-top">
		<?php if ( $ez_dest ) : ?>
		<section class="carousel" data-ez-carousel aria-roledescription="carrusel" aria-label="Destacados">
			<h2 class="home-top__title"><span aria-hidden="true">✦</span> Destacados</h2>
			<div class="carousel__track">
				<?php foreach ( $ez_dest as $n => $ez_p ) : ?>
					<?php
					$ez_m  = ezt_engine( $ez_p->ID );
					$ez_bg = ezc_get_cover_url( $ez_p->ID, 'large' );
					?>
					<article class="slide" aria-roledescription="diapositiva" aria-label="<?php echo esc_attr( ( $n + 1 ) . ' de ' . count( $ez_dest ) ); ?>">
						<?php if ( $ez_bg ) : ?><img class="slide__bg" src="<?php echo esc_url( $ez_bg ); ?>" alt="" <?php echo $n ? 'loading="lazy"' : 'fetchpriority="high"'; ?> decoding="async"><?php endif; ?>
						<span class="slide__rank">#<?php echo (int) $n + 1; ?></span>
						<div class="slide__info">
							<?php if ( $ez_m ) : ?><span class="tag tag--motor tag--<?php echo esc_attr( $ez_m->slug ); ?>"><?php echo esc_html( $ez_m->name ); ?></span><?php endif; ?>
							<a class="slide__title" href="<?php echo esc_url( get_permalink( $ez_p ) ); ?>"><?php echo esc_html( get_the_title( $ez_p ) ); ?></a>
							<dl class="slide__facts">
								<?php
								foreach ( array(
									'Versión'   => get_post_meta( $ez_p->ID, 'ez_version', true ),
									'Dev'       => ezt_term_names( $ez_p->ID, 'desarrollador' ),
									'Traductor' => ezt_term_names( $ez_p->ID, 'traductor' ),
								) as $ez_k => $ez_v ) :
									if ( ! $ez_v ) {
										continue;
									}
									?>
									<div><dt><?php echo esc_html( $ez_k ); ?></dt><dd><?php echo esc_html( $ez_v ); ?></dd></div>
								<?php endforeach; ?>
								<div><dt>Traducción</dt><dd>🇪🇸 Español</dd></div>
							</dl>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
			<?php if ( count( $ez_dest ) > 1 ) : ?>
				<button type="button" class="carousel__btn carousel__btn--prev" data-prev aria-label="Anterior">‹</button>
				<button type="button" class="carousel__btn carousel__btn--next" data-next aria-label="Siguiente">›</button>
				<div class="carousel__dots">
					<?php foreach ( $ez_dest as $n => $ez_p ) : ?>
						<button type="button" aria-label="<?php echo esc_attr( 'Ir al ' . ( $n + 1 ) ); ?>" aria-current="<?php echo $n ? 'false' : 'true'; ?>"></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<aside class="top" data-ez-tabs>
			<h2 class="home-top__title"><span aria-hidden="true">🏆</span> Top</h2>
			<div class="top__tabs" role="tablist" aria-label="Período">
				<?php foreach ( $ez_periodos as $ez_k => $ez_pp ) : ?>
					<button type="button" role="tab" id="tab-<?php echo esc_attr( $ez_k ); ?>" aria-controls="top-<?php echo esc_attr( $ez_k ); ?>" aria-selected="<?php echo 'mes' === $ez_k ? 'true' : 'false'; ?>"><?php echo esc_html( $ez_pp[0] ); ?></button>
				<?php endforeach; ?>
			</div>
			<?php foreach ( $ez_periodos as $ez_k => $ez_pp ) : ?>
				<?php $ez_list = function_exists( 'ezc_top' ) ? ezc_top( $ez_pp[1], 5 ) : array(); ?>
				<ol class="top__list" role="tabpanel" id="top-<?php echo esc_attr( $ez_k ); ?>" aria-labelledby="tab-<?php echo esc_attr( $ez_k ); ?>" <?php echo 'mes' === $ez_k ? '' : 'hidden'; ?>>
					<?php foreach ( $ez_list as $n => $ez_row ) : ?>
						<?php $ez_tp = $ez_row['post']; ?>
						<li class="top__item">
							<span class="top__rank"><?php echo (int) $n + 1; ?></span>
							<?php if ( $ez_th = ezc_get_cover_url( $ez_tp->ID, 'thumbnail' ) ) : ?>
								<img class="top__thumb" src="<?php echo esc_url( $ez_th ); ?>" alt="" loading="lazy" width="56" height="56">
							<?php endif; ?>
							<div>
								<a class="top__name" href="<?php echo esc_url( get_permalink( $ez_tp ) ); ?>"><?php echo esc_html( get_the_title( $ez_tp ) ); ?></a>
								<span class="top__meta">
									<span class="stat"><span aria-hidden="true">👁</span> <?php echo esc_html( number_format_i18n( $ez_row['vistas'] ) ); ?></span>
									<?php echo ezt_rating_html( $ez_tp->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</span>
							</div>
						</li>
					<?php endforeach; ?>
					<?php if ( ! $ez_list ) : ?><li class="top__empty">Todavía no hay datos de este período.</li><?php endif; ?>
				</ol>
			<?php endforeach; ?>
		</aside>
	</div>

	<?php if ( $ez_juegos->have_posts() ) : ?>
	<section class="section">
		<div class="section-head">
			<h2>Últimas publicaciones</h2>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'juego' ) ); ?>">Ver los <?php echo esc_html( $ez_total ); ?> juegos ›</a>
		</div>
		<div class="grid grid--games">
			<?php
			while ( $ez_juegos->have_posts() ) {
				$ez_juegos->the_post();
				get_template_part( 'template-parts/card', 'juego' );
			}
			wp_reset_postdata();
			?>
		</div>
		<?php
		// Atajos a todas las páginas del catálogo: ningún juego queda a más de 2 clics del inicio.
		$ez_pages = (int) ceil( $ez_total / 48 );
		if ( $ez_pages > 1 ) :
			?>
			<nav class="pagination" aria-label="Catálogo de juegos"><div class="nav-links">
				<?php for ( $i = 1; $i <= $ez_pages; $i++ ) : ?>
					<a class="page-numbers" href="<?php echo esc_url( 1 === $i ? get_post_type_archive_link( 'juego' ) : trailingslashit( get_post_type_archive_link( 'juego' ) ) . 'page/' . $i . '/' ); ?>"><?php echo (int) $i; ?></a>
				<?php endfor; ?>
			</div></nav>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<?php
	$ez_generos = get_terms( array( 'taxonomy' => 'genero', 'orderby' => 'count', 'order' => 'DESC', 'number' => 30, 'hide_empty' => true ) );
	if ( $ez_generos && ! is_wp_error( $ez_generos ) ) :
		?>
	<section class="section">
		<h2>Géneros</h2>
		<ul class="chips">
			<?php foreach ( $ez_generos as $t ) : ?>
				<li><a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><?php echo esc_html( $t->name ); ?> (<?php echo (int) $t->count; ?>)</a></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>

	<?php if ( $ez_noticias->have_posts() ) : ?>
	<section class="section">
		<div class="section-head">
			<h2>Noticias</h2>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'noticia' ) ); ?>">Todas las noticias ›</a>
		</div>
		<div class="grid grid--news">
			<?php
			while ( $ez_noticias->have_posts() ) {
				$ez_noticias->the_post();
				get_template_part( 'template-parts/card', 'noticia' );
			}
			wp_reset_postdata();
			?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $ez_mangas->have_posts() ) : ?>
	<section class="section">
		<div class="section-head">
			<h2>Mangas</h2>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'manga' ) ); ?>">Todos los mangas ›</a>
		</div>
		<div class="grid">
			<?php
			while ( $ez_mangas->have_posts() ) {
				$ez_mangas->the_post();
				get_template_part( 'template-parts/card', 'manga' );
			}
			wp_reset_postdata();
			?>
		</div>
	</section>
	<?php endif; ?>
</div>
<?php
get_footer();
