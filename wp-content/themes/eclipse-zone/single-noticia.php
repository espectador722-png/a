<?php
get_header();
while ( have_posts() ) :
	the_post();
	$ez_img   = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'large' ) : get_post_meta( get_the_ID(), 'ez_imagen', true );
	$ez_autor = get_post_meta( get_the_ID(), 'ez_autor', true ) ?: get_the_author();
	?>
	<div class="wrap">
		<?php ezt_breadcrumbs( array( get_post_type_archive_link( 'noticia' ) => 'Noticias', 0 => get_the_title() ) ); ?>
		<article class="entry">
			<h1><?php the_title(); ?></h1>
			<p class="entry__meta"><?php echo esc_html( get_the_date() ); ?><?php echo $ez_autor ? ' · ' . esc_html( $ez_autor ) : ''; ?></p>
			<?php if ( $ez_img ) : ?>
				<img src="<?php echo esc_url( $ez_img ); ?>" alt="" style="border-radius:12px;margin-bottom:20px" width="1200" height="630" fetchpriority="high">
			<?php endif; ?>
			<div class="entry__content"><?php the_content(); ?></div>
			<?php echo ezt_reactions_html( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php
			$ez_tags = get_the_tags();
			if ( $ez_tags ) :
				?>
				<ul class="chips" style="margin-top:24px">
					<?php foreach ( $ez_tags as $t ) : ?>
						<li><a href="<?php echo esc_url( get_tag_link( $t ) ); ?>"><?php echo esc_html( $t->name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<nav class="reader-end" aria-label="Más noticias">
				<?php previous_post_link( '%link', '‹ %title' ); ?>
				<?php next_post_link( '%link', '%title ›' ); ?>
			</nav>
			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
		</article>
	</div>
	<?php
endwhile;
get_footer();
