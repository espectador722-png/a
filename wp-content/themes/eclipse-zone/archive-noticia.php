<?php
get_header();
$ez_paged = max( 1, (int) get_query_var( 'paged' ) );
?>
<div class="wrap">
	<?php ezt_breadcrumbs( array( 0 => 'Noticias' ) ); ?>
	<header class="archive-head">
		<h1>Noticias<?php echo $ez_paged > 1 ? esc_html( ' — página ' . $ez_paged ) : ''; ?></h1>
		<p>Novedades, traducciones nuevas y actualizaciones de Eclipse Zone.</p>
	</header>
	<?php if ( have_posts() ) : ?>
		<div class="grid grid--news">
			<?php
			while ( have_posts() ) {
				the_post();
				get_template_part( 'template-parts/card', 'noticia' );
			}
			?>
		</div>
		<?php ezt_pagination(); ?>
	<?php else : ?>
		<p>Todavía no hay noticias.</p>
	<?php endif; ?>
</div>
<?php
get_footer();
