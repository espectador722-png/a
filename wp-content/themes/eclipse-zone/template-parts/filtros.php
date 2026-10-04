<?php
/**
 * Barra de filtros del catálogo: formulario GET normal (funciona sin JS).
 * Con JS, cambiar un select envía el formulario solo.
 */
if ( ! function_exists( 'ezc_filter_taxonomies' ) ) {
	return;
}
$ezt_active  = ezc_active_filters();
$ezt_order   = ezc_current_order();
$ezt_q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$ezt_base    = is_tax() ? get_term_link( get_queried_object() ) : get_post_type_archive_link( 'juego' );
$ezt_current = is_tax() ? get_queried_object()->taxonomy : '';
?>
<div class="catalog-head">
	<span class="catalog-head__n"><?php echo esc_html( number_format_i18n( (int) $GLOBALS['wp_query']->found_posts ) . ' juegos' ); ?></span>
	<a class="btn btn--ghost" href="<?php echo esc_url( add_query_arg( 'aleatorio', 1, get_post_type_archive_link( 'juego' ) ) ); ?>" rel="nofollow">🎲 Aleatorio</a>
</div>
<form id="filtros" class="filters" method="get" action="<?php echo esc_url( $ezt_base ); ?>" data-ez-filters>
	<input type="search" name="q" value="<?php echo esc_attr( $ezt_q ); ?>" placeholder="Buscar en el catálogo…" class="filters__q" aria-label="Buscar en el catálogo">
	<?php
	foreach ( ezc_filter_taxonomies() as $ezt_tax => $ezt_label ) :
		if ( $ezt_tax === $ezt_current ) {
			continue;
		}
		$ezt_terms = get_terms( array( 'taxonomy' => $ezt_tax, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 200 ) );
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
					<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $ezt_sel, $t->slug ); ?>><?php echo esc_html( $t->name . ' (' . $t->count . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	<?php endforeach; ?>
	<label class="filters__field">
		<span>Orden</span>
		<select name="orden">
			<?php foreach ( ezc_orders() as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $ezt_order, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<button class="btn" type="submit">Filtrar</button>
	<?php if ( ezc_is_filtered() ) : ?>
		<a class="filters__clear" href="<?php echo esc_url( $ezt_base ); ?>">Limpiar ✕</a>
	<?php endif; ?>
</form>
<?php
global $wp_query;
if ( ezc_is_filtered() ) {
	printf( '<p class="filters__count">%s</p>', esc_html( sprintf( 1 === (int) $wp_query->found_posts ? '%d juego encontrado' : '%d juegos encontrados', (int) $wp_query->found_posts ) ) );
}
