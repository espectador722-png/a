<?php
get_header();
while ( have_posts() ) :
	the_post();
	?>
	<div class="wrap">
		<article class="entry">
			<h1><?php the_title(); ?></h1>
			<div class="entry__content"><?php the_content(); ?></div>
		</article>
	</div>
	<?php
endwhile;
get_footer();
