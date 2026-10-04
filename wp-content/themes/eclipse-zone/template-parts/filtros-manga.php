<?php
/** Filtros del catálogo de mangas (formulario GET; con JS filtra al cambiar). */
if ( ! function_exists( 'ezc_manga_filter_taxonomies' ) ) {
	return;
}
global $wp_query;
$ezt_active  = ezc_manga_active_filters();
$ezt_order   = ezc_manga_current_order();
$ezt_q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$ezt_base    = is_tax() ? get_term_link( get_queried_object() ) : get_post_type_archive_link( 'manga' );
$ezt_current = is_tax() ? get_queried_object()->taxonomy : '';
$ezt_total   = (int) $wp_query->found_posts;
?>
<div class="catalog-head">
	<span class="catalog-head__n"><?php echo esc_html( number_format_i18n( $ezt_total ) . ( 1 === $ezt_total ? ' serie' : ' series' ) ); ?></span>
	<a class="btn btn--ghost" href="<?php echo esc_url( add_query_arg( 'aleatorio', 1, get_post_type_archive_link( 'manga' ) ) ); ?>" rel="nofollow">🎲 Aleatorio</a>
</div>
<form id="filtros" class="filters" method="get" action="<?php echo esc_url( $ezt_base ); ?>" data-ez-filters>
	<input type="search" name="q" value="<?php echo esc_attr( $ezt_q ); ?>" placeholder="Buscar por título…" class="filters__q" aria-label="Buscar por título">
	<?php
	foreach ( ezc_manga_filter_taxonomies() as $ezt_tax => $ezt_label ) :
		if ( $ezt_tax === $ezt_current ) {
			continue;
		}
		$ezt_terms = get_terms( array( 'taxonomy' => $ezt_tax, 'hide_empty' => 'etiqueta' === $ezt_tax, 'orderby' => 'etiqueta' === $ezt_tax ? 'count' : 'term_id', 'order' => 'etiqueta' === $ezt_tax ? 'DESC' : 'ASC', 'number' => 300 ) );
		if ( ! $ezt_terms || is_wp_error( $ezt_terms ) ) {
			continue;
		}
		$ezt_sel = $ezt_active[ $ezt_tax ][0] ?? '';
		?>
		<label class="filters__field">
			<span><?php echo esc_html( $ezt_label ); ?></span>
			<select name="<?php echo esc_attr( $ezt_tax ); ?>">
				<option value="">Todos</option>
				<?php foreach ( $ezt_terms as $t ) : ?>
					<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $ezt_sel, $t->slug ); ?>><?php echo esc_html( $t->name . ( $t->count ? ' (' . $t->count . ')' : '' ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	<?php endforeach; ?>
	<label class="filters__field">
		<span>Ordenar por</span>
		<select name="orden">
			<?php foreach ( ezc_manga_orders() as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ezt_order, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<button class="btn" type="submit">Filtrar</button>
	<?php if ( ezc_manga_is_filtered() ) : ?>
		<a class="filters__clear" href="<?php echo esc_url( $ezt_base ); ?>">Limpiar ✕</a>
	<?php endif; ?>
</form>
