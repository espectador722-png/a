<?php
/**
 * Catálogo /juegos/ y páginas de taxonomía (género, plataforma, traductor,
 * estado). 48 por página, con paginación de enlaces reales.
 */
get_header();

$ez_paged = max( 1, (int) get_query_var( 'paged' ) );
if ( is_tax() ) {
	$ez_term  = get_queried_object();
	$ez_title = sprintf( '%s: juegos traducidos al español', $ez_term->name );
	$ez_intro = $ez_term->description ?: sprintf( '%d juegos de %s traducidos al español.', $ez_term->count, $ez_term->name );
} else {
	$ez_title = 'Juegos traducidos al español';
	$ez_intro = sprintf( '%d juegos traducidos al español, ordenados por última actualización.', (int) wp_count_posts( 'juego' )->publish );
}
?>
<div class="wrap">
	<?php ezt_breadcrumbs( is_tax() ? array( get_post_type_archive_link( 'juego' ) => 'Juegos', 0 => $ez_term->name ) : array( 0 => 'Juegos' ) ); ?>
	<header class="archive-head">
		<h1><?php echo esc_html( $ez_title ); ?><?php echo $ez_paged > 1 ? esc_html( ' — página ' . $ez_paged ) : ''; ?></h1>
		<p><?php echo esc_html( $ez_intro ); ?></p>
	</header>

	<?php get_template_part( 'template-parts/filtros' ); ?>

	<?php if ( have_posts() ) : ?>
		<div class="grid grid--games">
			<?php
			while ( have_posts() ) {
				the_post();
				get_template_part( 'template-parts/card', 'juego' );
			}
			?>
		</div>
		<?php ezt_pagination(); ?>
	<?php else : ?>
		<p>No hay juegos con esos filtros. <a href="<?php echo esc_url( get_post_type_archive_link( 'juego' ) ); ?>">Ver todos</a></p>
	<?php endif; ?>
</div>
<?php
get_footer();
