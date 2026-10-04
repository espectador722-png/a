<?php
/** Catálogo /mangas/ y /etiqueta/<slug>/: solo series y one-shots. */
get_header();
$ez_paged = max( 1, (int) get_query_var( 'paged' ) );
$ez_term  = is_tax() ? get_queried_object() : null;
?>
<div class="wrap">
	<?php ezt_breadcrumbs( $ez_term ? array( get_post_type_archive_link( 'manga' ) => 'Mangas', 0 => $ez_term->name ) : array( 0 => 'Mangas' ) ); ?>
	<header class="archive-head">
		<h1><?php echo esc_html( $ez_term ? 'Mangas: ' . $ez_term->name : 'Mangas en español' ); ?><?php echo $ez_paged > 1 ? esc_html( ' — página ' . $ez_paged ) : ''; ?></h1>
	</header>

	<?php get_template_part( 'template-parts/filtros-manga' ); ?>

	<?php if ( have_posts() ) : ?>
		<div class="grid grid--manga">
			<?php
			while ( have_posts() ) {
				the_post();
				get_template_part( 'template-parts/card', 'manga' );
			}
			?>
		</div>
		<?php ezt_pagination(); ?>
	<?php else : ?>
		<p>No hay mangas con esos filtros. <a href="<?php echo esc_url( get_post_type_archive_link( 'manga' ) ); ?>">Ver todos</a></p>
	<?php endif; ?>
</div>
<?php
get_footer();
