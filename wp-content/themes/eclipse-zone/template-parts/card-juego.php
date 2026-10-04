<?php
/**
 * Tarjeta de juego.
 * Arriba: traductor + bandera. Imagen con motor (izq.) y estado/versión (der.).
 * Título, descripción corta, "hace X", vistas y puntuación.
 * Al pasar el mouse se despliega: desarrollador y géneros.
 * El título es un <a href> real a la ficha (lo que Google sigue).
 */
$ez_id      = get_the_ID();
$ez_img     = function_exists( 'ezc_get_cover_url' ) ? ezc_get_cover_url( $ez_id, 'medium_large' ) : '';
$ez_version = get_post_meta( $ez_id, 'ez_version', true );
$ez_trad    = ezt_term_names( $ez_id, 'traductor' );
$ez_estado  = ezt_term_names( $ez_id, 'estado' );
$ez_motor   = ezt_engine( $ez_id );
$ez_dev     = ezt_term_names( $ez_id, 'desarrollador' );
$ez_generos = get_the_terms( $ez_id, 'genero' );
$ez_desc    = wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 18, '…' );
?>
<article class="gcard">
	<div class="gcard__main">
		<header class="gcard__top">
			<span class="gcard__trad"><?php echo $ez_trad ? ezt_translators_html( $ez_id ) : 'Eclipse Zone'; // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función ?></span>
			<span class="gcard__flag" title="Traducido al español">🇪🇸</span>
		</header>
		<div class="gcard__media">
			<?php if ( $ez_img ) : ?>
				<img src="<?php echo esc_url( $ez_img ); ?>" alt="" loading="lazy" decoding="async" width="400" height="225">
			<?php endif; ?>
			<?php if ( $ez_motor ) : ?>
				<span class="tag tag--motor tag--<?php echo esc_attr( $ez_motor->slug ); ?>"><?php echo esc_html( $ez_motor->name ); ?></span>
			<?php endif; ?>
			<span class="gcard__badges">
				<?php if ( $ez_estado && ! preg_match( '/desarrollo/i', $ez_estado ) ) : ?>
					<span class="tag tag--estado"><?php echo esc_html( $ez_estado ); ?></span>
				<?php endif; ?>
				<?php if ( $ez_version ) : ?><span class="tag tag--version"><?php echo esc_html( $ez_version ); ?></span><?php endif; ?>
			</span>
			<?php if ( function_exists( 'ezc_is_favorite' ) && ezc_is_favorite( $ez_id ) ) : ?><span class="card__fav" title="Favorito">★</span><?php endif; ?>
		</div>
		<div class="gcard__body">
			<a class="gcard__title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
			<?php if ( $ez_desc ) : ?><p class="gcard__desc"><?php echo esc_html( $ez_desc ); ?></p><?php endif; ?>
		</div>
		<footer class="gcard__stats">
			<span class="stat" title="Última actualización"><span aria-hidden="true">🕒</span> <?php echo esc_html( ezt_ago() ); ?></span>
			<span class="gcard__nums">
				<span class="stat" title="Comentarios"><span aria-hidden="true">💬</span> <?php echo (int) get_comments_number( $ez_id ); ?></span>
				<?php echo ezt_views_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función ?>
				<?php echo ezt_rating_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</span>
		</footer>
	</div>
	<?php if ( $ez_dev || ( $ez_generos && ! is_wp_error( $ez_generos ) ) ) : ?>
		<div class="gcard__extra">
			<?php if ( $ez_dev ) : ?><p class="gcard__dev"><span aria-hidden="true">👤</span> <?php echo esc_html( $ez_dev ); ?></p><?php endif; ?>
			<?php echo ezt_chips( $ez_id, 'genero' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	<?php endif; ?>
</article>
