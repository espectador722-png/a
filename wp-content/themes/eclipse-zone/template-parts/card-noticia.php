<?php
/** Tarjeta de noticia: imagen 16:9, fecha, título, resumen y comentarios. */
$ez_id   = get_the_ID();
$ez_img  = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'medium_large' ) : get_post_meta( $ez_id, 'ez_imagen', true );
$ez_tags = get_the_tags();
?>
<article class="gcard ncard">
	<div class="gcard__main">
		<div class="gcard__media">
			<?php if ( $ez_img ) : ?>
				<img src="<?php echo esc_url( $ez_img ); ?>" alt="" loading="lazy" decoding="async" width="560" height="315">
			<?php endif; ?>
			<?php if ( $ez_tags ) : ?><span class="tag tag--news"><?php echo esc_html( $ez_tags[0]->name ); ?></span><?php endif; ?>
		</div>
		<div class="gcard__body">
			<a class="gcard__title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
			<p class="gcard__desc"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 24, '…' ) ); ?></p>
		</div>
		<footer class="gcard__stats">
			<span class="stat"><span aria-hidden="true">📅</span> <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></span>
			<span class="stat" title="Comentarios"><span aria-hidden="true">💬</span> <?php echo (int) get_comments_number( $ez_id ); ?></span>
		</footer>
	</div>
</article>
