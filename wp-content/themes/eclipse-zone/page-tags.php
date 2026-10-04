<?php
/** /tags/: todas las etiquetas del catálogo agrupadas, con buscador y cantidad. */
get_header();
?>
<div class="wrap">
	<?php ezt_breadcrumbs( array( 0 => 'Tags' ) ); ?>
	<header class="archive-head">
		<h1>Tags</h1>
		<p>Explorá los juegos por género, motor, estado, plataforma o traductor. Para combinar varios, usá los <a href="<?php echo esc_url( get_post_type_archive_link( 'juego' ) ); ?>#filtros">filtros del catálogo</a>.</p>
	</header>
	<input type="search" class="tags-search" placeholder="Filtrar tags…" data-ez-tag-filter aria-label="Filtrar tags">
	<?php
	$ezt_taxes = function_exists( 'ezc_filter_taxonomies' ) ? ezc_filter_taxonomies() : array( 'genero' => 'Género' );
	$ezt_taxes['desarrollador'] = 'Desarrollador';
	foreach ( $ezt_taxes as $ezt_tax => $ezt_label ) :
		$ezt_terms = get_terms( array( 'taxonomy' => $ezt_tax, 'hide_empty' => true, 'orderby' => 'name' ) );
		if ( ! $ezt_terms || is_wp_error( $ezt_terms ) ) {
			continue;
		}
		?>
		<section class="section tags-group">
			<div class="section-head"><h2><?php echo esc_html( $ezt_label . ( 'Género' === $ezt_label ? 's' : ( preg_match( '/r$/', $ezt_label ) ? 'es' : 's' ) ) ); ?></h2><span class="tags-group__n"><?php echo count( $ezt_terms ); ?></span></div>
			<ul class="chips chips--big">
				<?php foreach ( $ezt_terms as $t ) : ?>
					<li><a href="<?php echo esc_url( get_term_link( $t ) ); ?>"<?php echo 'traductor' === $ezt_tax && function_exists( 'ezc_translator_color' ) ? ' style="--c:' . esc_attr( ezc_translator_color( $t ) ) . '" class="trad"' : ''; ?>><?php echo esc_html( $t->name ); ?> <small><?php echo (int) $t->count; ?></small></a></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>
</div>
<?php
get_footer();
