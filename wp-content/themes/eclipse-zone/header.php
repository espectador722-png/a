<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#0b0c10">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#contenido">Saltar al contenido</a>
<header class="site-header">
	<div class="wrap">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php
			$logo_id = get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				echo wp_get_attachment_image( $logo_id, 'thumbnail', false, array( 'alt' => '' ) );
			}
			bloginfo( 'name' );
			?>
		</a>
		<nav class="main-nav" aria-label="Principal">
			<?php
			ezt_nav_link( home_url( '/' ), 'Inicio', is_front_page() );
			ezt_nav_link( get_post_type_archive_link( 'juego' ), 'Juegos', is_post_type_archive( 'juego' ) || is_singular( 'juego' ) || is_tax( array( 'genero', 'plataforma', 'traductor', 'estado' ) ) );
			ezt_nav_link( get_post_type_archive_link( 'noticia' ), 'Noticias', is_post_type_archive( 'noticia' ) || is_singular( 'noticia' ) );
			ezt_nav_link( get_post_type_archive_link( 'manga' ), 'Mangas', is_post_type_archive( 'manga' ) || is_singular( 'manga' ) || is_tax( 'etiqueta' ) );
			?>
		</nav>
		<form class="header-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="s">Buscar</label>
			<input type="search" id="s" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="Buscar juegos, mangas…">
		</form>
		<span class="header-user">
			<?php if ( is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( home_url( '/mi-cuenta/' ) ); ?>"><?php echo esc_html( wp_get_current_user()->display_name ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( wp_login_url( home_url( add_query_arg( array() ) ) ) ); ?>">Entrar</a>
			<?php endif; ?>
		</span>
	</div>
</header>
<?php if ( $ezt_discord = get_theme_mod( 'ezt_discord_url' ) ) : ?>
	<div class="strip" data-ez-strip>
		<div class="wrap">
			<span><?php echo esc_html( get_theme_mod( 'ezt_discord_text', '¡Únete a nuestra comunidad y no te pierdas las novedades!' ) ); ?></span>
			<a class="btn btn--discord" href="<?php echo esc_url( $ezt_discord ); ?>" target="_blank" rel="noopener">Discord</a>
			<button type="button" class="strip__close" data-ez-strip-close aria-label="Cerrar aviso">✕</button>
		</div>
	</div>
<?php endif; ?>
<main id="contenido" class="site-main">
