<?php
$ez_id       = get_the_ID();
$ez_img      = function_exists( 'ezc_manga_cover_url' ) ? ezc_manga_cover_url( $ez_id ) : '';
$ez_chapters = function_exists( 'ezc_manga_chapters' ) ? count( ezc_manga_chapters( $ez_id ) ) : 0;
$ez_progress = function_exists( 'ezc_get_progress' ) ? ezc_get_progress( $ez_id ) : null;
?>
<article class="card card--manga">
	<span class="card__badge"><?php echo $ez_chapters ? esc_html( $ez_chapters . ' cap.' ) : 'One-shot'; ?></span>
	<?php if ( function_exists( 'ezc_is_favorite' ) && ezc_is_favorite( $ez_id ) ) : ?><span class="card__fav" title="Favorito">★</span><?php endif; ?>
	<?php if ( $ez_img ) : ?>
		<img class="card__img" src="<?php echo esc_url( $ez_img ); ?>" alt="" loading="lazy" decoding="async" width="300" height="450">
	<?php else : ?>
		<div class="card__img"></div>
	<?php endif; ?>
	<?php if ( $ez_progress && $ez_progress['n'] ) : ?>
		<div class="card__progress<?php echo $ez_progress['p'] >= $ez_progress['n'] ? ' card__progress--done' : ''; ?>">
			<span style="width:<?php echo (int) round( 100 * $ez_progress['p'] / $ez_progress['n'] ); ?>%"></span>
		</div>
	<?php endif; ?>
	<div class="card__body">
		<a class="card__title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
	</div>
</article>
