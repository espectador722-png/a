<?php
/**
 * Tipo de contenido "juego" y sus taxonomías.
 *
 * URLs resultantes (mismas que el sitio actual para no perder lo indexado):
 *   /juego/<slug>/            ficha
 *   /juegos/  /juegos/page/2/ catálogo paginado
 *   /genero/<slug>/           equivalente a /categoria/ del sitio anterior
 *   /plataforma/<slug>/  /traductor/<slug>/  /estado/<slug>/
 *   /noticia/<slug>/  /noticias/
 * Mangas: ver mangas.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'ezc_register_content_types' );

function ezc_register_content_types() {
	register_post_type( 'juego', array(
		'labels'        => array(
			'name'          => 'Juegos',
			'singular_name' => 'Juego',
			'add_new_item'  => 'Añadir juego',
			'edit_item'     => 'Editar juego',
			'search_items'  => 'Buscar juegos',
			'all_items'     => 'Todos los juegos',
		),
		'public'        => true,
		'has_archive'   => 'juegos',
		'rewrite'       => array( 'slug' => 'juego', 'with_front' => false ),
		'menu_icon'     => 'dashicons-games',
		'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
		'show_in_rest'  => true,
		'menu_position' => 5,
	) );

	register_post_type( 'noticia', array(
		'labels'       => array(
			'name'          => 'Noticias',
			'singular_name' => 'Noticia',
			'add_new_item'  => 'Añadir noticia',
			'edit_item'     => 'Editar noticia',
		),
		'public'       => true,
		'has_archive'  => 'noticias',
		'rewrite'      => array( 'slug' => 'noticia', 'with_front' => false ),
		'menu_icon'    => 'dashicons-megaphone',
		'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ),
		'taxonomies'   => array( 'post_tag' ),
		'show_in_rest' => true,
		'menu_position' => 6,
	) );

	$taxonomies = array(
		'genero'     => array( 'Géneros', 'Género', true ),
		'plataforma' => array( 'Plataformas', 'Plataforma', true ),
		'traductor'  => array( 'Traductores', 'Traductor', false ),
		'estado'     => array( 'Estados', 'Estado', true ),
	);
	foreach ( $taxonomies as $tax => list( $plural, $singular, $hierarchical ) ) {
		register_taxonomy( $tax, 'juego', array(
			'labels'            => array( 'name' => $plural, 'singular_name' => $singular ),
			'public'            => true,
			'hierarchical'      => $hierarchical,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => $tax, 'with_front' => false ),
		) );
	}
}

/**
 * Catálogo y taxonomías: 48 juegos por página. Con ~590 juegos son ~13
 * páginas /juegos/page/N/, todas enlazadas entre sí con <a href> reales.
 */
add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( $query->is_post_type_archive( 'juego' ) || $query->is_tax( array( 'genero', 'plataforma', 'traductor', 'estado' ) ) ) {
		$query->set( 'posts_per_page', 48 );
		$query->set( 'orderby', 'modified' );
		$query->set( 'order', 'DESC' );
	}
	if ( $query->is_post_type_archive( 'noticia' ) ) {
		$query->set( 'posts_per_page', 20 );
	}
	if ( $query->is_search() ) {
		$query->set( 'post_type', array( 'juego', 'noticia', 'manga' ) );
	}
} );
