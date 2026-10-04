</main>
<footer class="site-footer">
	<div class="wrap">
		<span>© <?php echo esc_html( gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?> — Juegos y mangas traducidos al español</span>
		<nav aria-label="Pie">
			<a href="<?php echo esc_url( get_post_type_archive_link( 'juego' ) ); ?>">Juegos</a>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'noticia' ) ); ?>">Noticias</a>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'manga' ) ); ?>">Mangas</a>
			<?php if ( get_privacy_policy_url() ) : ?>
				<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">Privacidad</a>
			<?php endif; ?>
		</nav>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
