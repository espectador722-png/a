<?php
/**
 * Inicio: últimos juegos, noticias y mangas, todo en HTML con enlaces
 * reales, más un acceso directo a cada página del catálogo.
 */
get_header();

$ez_juegos   = new WP_Query( array( 'post_type' => 'juego', 'posts_per_page' => 18, 'orderby' => 'modified', 'no_found_rows' => true ) );
$ez_noticias = new WP_Query( array( 'post_type' => 'noticia', 'posts_per_page' => 6, 'no_found_rows' => true ) );
$ez_mangas   = new WP_Query( array( 'post_type' => 'manga', 'post_parent' => 0, 'posts_per_page' => 12, 'orderby' => 'modified', 'no_found_rows' => true ) );
$ez_total    = (int) wp_count_posts( 'juego' )->publish;
?>
<div class="wrap">
	<section class="hero">
		<h1><?php bloginfo( 'name' ); ?> — Juegos y mangas traducidos al español</h1>
		<p>Más de <?php echo esc_html( number_format_i18n( $ez_total ) ); ?> juegos traducidos al español para PC y Android, noticias y mangas. Gratis.</p>
	</section>

	<?php if ( $ez_juegos->have_posts() ) : ?>
	<section class="section">
		<div class="section-head">
			<h2>Juegos actualizados</h2>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'juego' ) ); ?>">Ver los <?php echo esc_html( $ez_total ); ?> juegos ›</a>
		</div>
		<div class="grid">
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
