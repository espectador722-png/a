<?php
/**
 * Tarjeta de manga: portada vertical con capítulos (o "One-shot"), progreso
 * de lectura, título, vistas y puntuación. Al pasar el mouse: etiquetas.
 */
$ez_id       = get_the_ID();
$ez_img      = function_exists( 'ezc_manga_cover_url' ) ? ezc_manga_cover_url( $ez_id ) : '';
$ez_chapters = function_exists( 'ezc_manga_chapters' ) ? count( ezc_manga_chapters( $ez_id ) ) : 0;
$ez_progress = function_exists( 'ezc_get_progress' ) ? ezc_get_progress( $ez_id ) : null;
$ez_tags     = get_the_terms( $ez_id, 'etiqueta' );
$ez_parent   = wp_get_post_parent_id( $ez_id ); // en "Seguir leyendo" aparecen capítulos
?>
<article class="gcard mcard">
	<div class="gcard__main">
		<div class="gcard__media">
			<?php if ( $ez_img ) : ?>
				<img src="<?php echo esc_url( $ez_img ); ?>" alt="" loading="lazy" decoding="async" width="300" height="450">
			<?php endif; ?>
			<span class="tag tag--manga"><?php echo $ez_parent ? 'Capítulo' : ( $ez_chapters ? esc_html( $ez_chapters . ( 1 === $ez_chapters ? ' cap.' : ' caps.' ) ) : 'One-shot' ); ?></span>
			<?php if ( function_exists( 'ezc_is_favorite' ) && ezc_is_favorite( $ez_id ) ) : ?><span class="card__fav" title="Favorito">★</span><?php endif; ?>
			<?php if ( $ez_progress && $ez_progress['n'] ) : ?>
				<div class="card__progress<?php echo $ez_progress['p'] >= $ez_progress['n'] ? ' card__progress--done' : ''; ?>">
					<span style="width:<?php echo (int) round( 100 * $ez_progress['p'] / $ez_progress['n'] ); ?>%"></span>
				</div>
			<?php endif; ?>
		</div>
		<div class="gcard__body">
			<a class="gcard__title" href="<?php the_permalink(); ?>"><?php echo esc_html( $ez_parent ? get_the_title( $ez_parent ) . ' · ' . get_the_title() : get_the_title() ); ?></a>
		</div>
		<footer class="gcard__stats">
			<?php echo ezt_views_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo ezt_rating_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</footer>
	</div>
	<?php if ( $ez_tags && ! is_wp_error( $ez_tags ) ) : ?>
		<div class="gcard__extra"><?php echo ezt_chips( $ez_id, 'etiqueta' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<?php endif; ?>
</article>
