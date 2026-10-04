<?php
get_header();
?>
<div class="wrap">
	<header class="archive-head">
		<h1>Página no encontrada</h1>
		<p>El enlace no existe o cambió de dirección. Buscá el juego o manga:</p>
	</header>
	<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="header-search" style="max-width:420px;margin:0 0 24px">
		<input type="search" name="s" placeholder="Buscar…" style="width:100%">
	</form>
	<p><a class="btn" href="<?php echo esc_url( get_post_type_archive_link( 'juego' ) ); ?>">Ver todos los juegos</a></p>
</div>
<?php
get_footer();
