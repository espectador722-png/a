<?php
/** Tarjeta de juego: enlace <a href> real a la ficha (lo que Google sigue). */
$ez_id      = get_the_ID();
$ez_img     = function_exists( 'ezc_get_cover_url' ) ? ezc_get_cover_url( $ez_id, 'medium_large' ) : '';
$ez_version = get_post_meta( $ez_id, 'ez_version', true );
$ez_plat    = ezt_term_names( $ez_id, 'plataforma' );
?>
<article class="card">
	<?php if ( $ez_version ) : ?><span class="card__badge"><?php echo esc_html( $ez_version ); ?></span><?php endif; ?>
	<?php if ( function_exists( 'ezc_is_favorite' ) && ezc_is_favorite( $ez_id ) ) : ?><span class="card__fav" title="Favorito">★</span><?php endif; ?>
	<?php if ( $ez_img ) : ?>
		<img class="card__img" src="<?php echo esc_url( $ez_img ); ?>" alt="" loading="lazy" decoding="async" width="400" height="250">
	<?php else : ?>
		<div class="card__img"></div>
	<?php endif; ?>
	<div class="card__body">
		<a class="card__title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
		<?php if ( $ez_plat ) : ?><span class="card__meta"><?php echo esc_html( $ez_plat ); ?></span><?php endif; ?>
	</div>
</article>
