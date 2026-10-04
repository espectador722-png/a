<?php
/** Catálogo /mangas/ y /etiqueta/<slug>/: solo series y one-shots. */
get_header();
$ez_paged = max( 1, (int) get_query_var( 'paged' ) );
$ez_term  = is_tax( 'etiqueta' ) ? get_queried_object() : null;
?>
<div class="wrap">
	<?php ezt_breadcrumbs( $ez_term ? array( get_post_type_archive_link( 'manga' ) => 'Mangas', 0 => $ez_term->name ) : array( 0 => 'Mangas' ) ); ?>
	<header class="archive-head">
		<h1><?php echo esc_html( $ez_term ? 'Mangas: ' . $ez_term->name : 'Mangas en español' ); ?><?php echo $ez_paged > 1 ? esc_html( ' — página ' . $ez_paged ) : ''; ?></h1>
	</header>

	<?php
	if ( ! $ez_term ) {
		$ez_tags = get_terms( array( 'taxonomy' => 'etiqueta', 'orderby' => 'count', 'order' => 'DESC', 'number' => 40 ) );
		if ( $ez_tags && ! is_wp_error( $ez_tags ) ) {
			echo '<ul class="chips" style="margin-bottom:20px">';
			foreach ( $ez_tags as $t ) {
				printf( '<li><a href="%s">%s</a></li>', esc_url( get_term_link( $t ) ), esc_html( $t->name ) );
			}
			echo '</ul>';
		}
	}
	?>

	<?php if ( have_posts() ) : ?>
		<div class="grid">
			<?php
			while ( have_posts() ) {
				the_post();
				get_template_part( 'template-parts/card', 'manga' );
			}
			?>
		</div>
		<?php ezt_pagination(); ?>
	<?php else : ?>
		<p>Todavía no hay mangas.</p>
	<?php endif; ?>
</div>
<?php
get_footer();
