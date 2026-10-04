<?php
/**
 * Mangas propios (sin MangaDex).
 *
 * Un manga es una entrada del tipo "manga". Sus capítulos son entradas
 * "manga" hijas (post_parent), así WordPress arma solo las URLs:
 *   /manga/<serie>/                ficha de la serie (o lector, si es un one-shot)
 *   /manga/<serie>/<capitulo>/     lector del capítulo
 *   /mangas/  /mangas/page/2/      catálogo (solo series, no capítulos)
 *   /etiqueta/<slug>/              etiquetas (como los tags de la biblioteca local)
 *
 * Las páginas de cada capítulo se guardan en ez_paginas (JSON, en orden de
 * lectura): IDs de la biblioteca de medios o URLs de imagen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'ezc_register_mangas' );

function ezc_register_mangas() {
	register_post_type( 'manga', array(
		'labels'        => array(
			'name'               => 'Mangas',
			'singular_name'      => 'Manga',
			'add_new_item'       => 'Añadir manga o capítulo',
			'edit_item'          => 'Editar manga',
			'parent_item_colon'  => 'Serie:',
			'all_items'          => 'Todos los mangas',
		),
		'public'        => true,
		'hierarchical'  => true,
		'has_archive'   => 'mangas',
		'rewrite'       => array( 'slug' => 'manga', 'with_front' => false ),
		'menu_icon'     => 'dashicons-book-alt',
		'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions', 'comments' ),
		'show_in_rest'  => true,
		'menu_position' => 7,
	) );

	register_taxonomy( 'etiqueta', 'manga', array(
		'labels'            => array( 'name' => 'Etiquetas', 'singular_name' => 'Etiqueta' ),
		'public'            => true,
		'hierarchical'      => false,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'etiqueta', 'with_front' => false ),
	) );

	register_post_meta( 'manga', 'ez_paginas', array(
		'type'          => 'string',
		'single'        => true,
		'show_in_rest'  => true,
		'auth_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	) );
}

// El catálogo y las etiquetas muestran series, no capítulos sueltos.
add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( $query->is_post_type_archive( 'manga' ) || $query->is_tax( 'etiqueta' ) ) {
		$query->set( 'post_parent', 0 );
		$query->set( 'posts_per_page', 36 );
		$query->set( 'orderby', 'modified' );
		$query->set( 'order', 'DESC' );
	}
} );

/** URLs de las páginas en orden de lectura. */
function ezc_manga_pages( $post_id = null ) {
	$raw  = json_decode( (string) get_post_meta( $post_id ?: get_the_ID(), 'ez_paginas', true ), true );
	$urls = array();
	foreach ( is_array( $raw ) ? $raw : array() as $item ) {
		if ( is_numeric( $item ) ) {
			$url = wp_get_attachment_image_url( (int) $item, 'full' );
		} else {
			$url = esc_url_raw( (string) $item );
		}
		if ( $url ) {
			$urls[] = $url;
		}
	}
	return $urls;
}

/** Capítulos de una serie, en orden (menu_order, luego fecha). */
function ezc_manga_chapters( $serie_id ) {
	return get_posts( array(
		'post_type'      => 'manga',
		'post_parent'    => $serie_id,
		'posts_per_page' => -1,
		'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
		'post_status'    => 'publish',
	) );
}

/** Capítulo anterior y siguiente de un capítulo dentro de su serie. */
function ezc_manga_neighbors( $chapter ) {
	$chapter = get_post( $chapter );
	$list    = $chapter && $chapter->post_parent ? ezc_manga_chapters( $chapter->post_parent ) : array();
	$ids     = wp_list_pluck( $list, 'ID' );
	$i       = array_search( $chapter->ID, $ids, true );
	return array(
		'prev' => ( false !== $i && $i > 0 ) ? $list[ $i - 1 ] : null,
		'next' => ( false !== $i && $i < count( $list ) - 1 ) ? $list[ $i + 1 ] : null,
	);
}

/** Portada: imagen destacada, o la primera página del manga / su primer capítulo. */
function ezc_manga_cover_url( $post_id = null, $size = 'medium_large' ) {
	$post_id = $post_id ?: get_the_ID();
	if ( has_post_thumbnail( $post_id ) ) {
		return get_the_post_thumbnail_url( $post_id, $size );
	}
	$pages = ezc_manga_pages( $post_id );
	if ( ! $pages ) {
		$chapters = ezc_manga_chapters( $post_id );
		$pages    = $chapters ? ezc_manga_pages( $chapters[0]->ID ) : array();
	}
	return $pages ? $pages[0] : '';
}

// ── Admin: elegir las páginas desde la biblioteca de medios ──────

add_action( 'add_meta_boxes_manga', function () {
	add_meta_box( 'ezc_paginas', 'Páginas (orden de lectura)', 'ezc_render_pages_box', 'manga', 'normal', 'high' );
} );

function ezc_render_pages_box( $post ) {
	wp_nonce_field( 'ezc_save_pages', 'ezc_pages_nonce' );
	$raw = json_decode( (string) get_post_meta( $post->ID, 'ez_paginas', true ), true );
	$raw = is_array( $raw ) ? $raw : array();
	?>
	<p>Para una <strong>serie</strong> dejá esto vacío y creá cada capítulo como un manga nuevo con esta serie como "Superior" (en Atributos).
	Para un <strong>one-shot</strong> o un <strong>capítulo</strong>, cargá aquí sus páginas.</p>
	<p><button type="button" class="button button-primary" id="ezc-pick-pages">Elegir / subir páginas</button>
	<span class="description">Se ordenan por nombre de archivo (001.jpg, 002.jpg…). Podés reordenar a mano abajo.</span></p>
	<p><label>Una por línea: ID de la biblioteca de medios o URL de imagen.<br>
		<textarea name="ez_paginas" id="ezc-pages" rows="8" class="large-text code"><?php echo esc_textarea( implode( "\n", $raw ) ); ?></textarea></label></p>
	<?php
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || get_post_type() !== 'manga' ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script( 'ezc-manga-admin', EZC_URL . 'assets/manga-admin.js', array( 'jquery' ), EZC_VERSION, true );
} );

add_action( 'save_post_manga', function ( $post_id ) {
	if ( ! isset( $_POST['ezc_pages_nonce'] ) || ! wp_verify_nonce( $_POST['ezc_pages_nonce'], 'ezc_save_pages' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$items = array();
	foreach ( preg_split( '/\R/', wp_unslash( $_POST['ez_paginas'] ?? '' ) ) as $line ) {
		$line = trim( $line );
		if ( ctype_digit( $line ) ) {
			$items[] = (int) $line;
		} elseif ( $url = esc_url_raw( $line ) ) {
			$items[] = $url;
		}
	}
	update_post_meta( $post_id, 'ez_paginas', wp_json_encode( $items ) );
} );
