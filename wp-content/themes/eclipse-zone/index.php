<?php
/** Respaldo genérico (etiquetas de noticias, búsquedas, etc.). */
get_header();
?>
<div class="wrap">
	<header class="archive-head">
		<h1><?php echo esc_html( is_search() ? sprintf( 'Resultados para “%s”', get_search_query() ) : wp_strip_all_tags( get_the_archive_title() ) ); ?></h1>
	</header>
	<?php if ( have_posts() ) : ?>
		<div class="grid">
			<?php
			while ( have_posts() ) {
				the_post();
				$ez_type = get_post_type();
				get_template_part( 'template-parts/card', in_array( $ez_type, array( 'juego', 'noticia', 'manga' ), true ) ? $ez_type : 'noticia' );
			}
			?>
		</div>
		<?php ezt_pagination(); ?>
	<?php else : ?>
		<p>No se encontró nada. Probá con otra búsqueda o mirá el <a href="<?php echo esc_url( get_post_type_archive_link( 'juego' ) ); ?>">catálogo de juegos</a>.</p>
	<?php endif; ?>
</div>
<?php
get_footer();
