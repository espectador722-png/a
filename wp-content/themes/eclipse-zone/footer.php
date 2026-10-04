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
<dialog id="ez-qs" class="qs" aria-label="Búsqueda rápida">
	<form method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="qs__form">
		<span aria-hidden="true">🔍</span>
		<input type="search" name="s" placeholder="Buscar juegos y mangas…" autocomplete="off" data-ez-qs-input aria-label="Buscar juegos y mangas">
		<kbd>Esc</kbd>
	</form>
	<ul class="qs__list" data-ez-qs-list role="listbox"></ul>
	<p class="qs__empty" data-ez-qs-empty hidden>No se encontraron resultados. Probá con palabras más generales.</p>
</dialog>
<?php wp_footer(); ?>
</body>
</html>
