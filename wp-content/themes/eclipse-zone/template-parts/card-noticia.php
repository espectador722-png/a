<?php
$ez_img = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'medium_large' ) : get_post_meta( get_the_ID(), 'ez_imagen', true );
?>
<article class="card">
	<?php if ( $ez_img ) : ?>
		<img class="card__img" src="<?php echo esc_url( $ez_img ); ?>" alt="" loading="lazy" decoding="async" width="560" height="350">
	<?php endif; ?>
	<div class="card__body">
		<a class="card__title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
		<span class="card__meta"><?php echo esc_html( get_the_date() ); ?></span>
		<p class="card__meta"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22, '…' ) ); ?></p>
	</div>
</article>
