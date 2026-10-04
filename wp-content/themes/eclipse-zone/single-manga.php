<?php
/**
 * Manga: si tiene páginas (one-shot o capítulo) muestra el lector; si es
 * una serie, muestra la ficha con la lista de capítulos.
 * Las páginas van como <img> en el HTML: el lector funciona sin JS (modo
 * cascada) y lector.js agrega modo página, teclado y progreso.
 */
get_header();

while ( have_posts() ) :
	the_post();
	$ez_id     = get_the_ID();
	$ez_pages  = ezc_manga_pages( $ez_id );
	$ez_parent = wp_get_post_parent_id( $ez_id );
	$ez_serie  = $ez_parent ?: $ez_id;
	?>
	<div class="wrap">
		<?php
		$ez_crumbs = array( get_post_type_archive_link( 'manga' ) => 'Mangas' );
		if ( $ez_parent ) {
			$ez_crumbs[ get_permalink( $ez_parent ) ] = get_the_title( $ez_parent );
		}
		$ez_crumbs[0] = get_the_title();
		ezt_breadcrumbs( $ez_crumbs );
		?>

		<?php if ( $ez_pages ) : ?>
			<?php get_template_part( 'template-parts/lector', null, array( 'pages' => $ez_pages, 'parent' => $ez_parent, 'serie' => $ez_serie ) ); ?>

		<?php else : ?>
			<?php $ez_chapters = ezc_manga_chapters( $ez_id ); ?>
			<div class="manga-head">
				<?php if ( $ez_cover = ezc_manga_cover_url( $ez_id, 'large' ) ) : ?>
					<img src="<?php echo esc_url( $ez_cover ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" width="300" height="450">
				<?php endif; ?>
				<div>
					<h1><?php the_title(); ?></h1>
					<div class="entry__content"><?php the_content(); ?></div>
					<?php
					$ez_info = array_filter( array(
						'Tipo'       => ezt_term_names( $ez_id, 'tipo_manga' ),
						'Demografía' => ezt_term_names( $ez_id, 'demografia' ),
						'Estado'     => ezt_term_names( $ez_id, 'estado_manga' ),
						'Capítulos'  => count( $ez_chapters ) ?: null,
					) );
					if ( $ez_info ) :
						?>
						<dl class="facts facts--inline">
							<?php foreach ( $ez_info as $k => $v ) : ?><div><dt><?php echo esc_html( $k ); ?></dt><dd><?php echo esc_html( $v ); ?></dd></div><?php endforeach; ?>
						</dl>
					<?php endif; ?>
					<?php echo ezt_chips( $ez_id, 'etiqueta' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php if ( function_exists( 'ezc_library_states' ) ) : ?>
						<?php $ez_lib = ezc_library_state( $ez_id ); ?>
						<div class="lib" role="group" aria-label="Mi Biblioteca" data-ez-lib="<?php echo (int) $ez_id; ?>">
							<?php foreach ( ezc_library_states() as $k => $label ) : ?>
								<button type="button" value="<?php echo esc_attr( $k ); ?>" aria-pressed="<?php echo $ez_lib === $k ? 'true' : 'false'; ?>"><?php echo esc_html( array( 'leyendo' => '📖 ', 'por_leer' => '🔖 ', 'completado' => '✅ ' )[ $k ] . $label ); ?></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<div class="manga-actions">
						<?php
						// "Seguir leyendo": primer capítulo sin terminar.
						$ez_continue = null;
						foreach ( $ez_chapters as $c ) {
							if ( ! ezc_is_read( $c->ID ) ) {
								$ez_continue = $c;
								break;
							}
						}
						if ( $ez_chapters ) :
							$ez_target = $ez_continue ?: $ez_chapters[0];
							?>
							<a class="btn" href="<?php echo esc_url( get_permalink( $ez_target ) ); ?>"><?php echo ezc_get_progress( $ez_target->ID ) ? 'Seguir leyendo' : 'Empezar a leer'; ?></a>
						<?php endif; ?>
						<?php if ( is_user_logged_in() ) : ?>
							<button type="button" class="btn btn--ghost" data-ez-fav="<?php echo (int) $ez_id; ?>" aria-pressed="<?php echo ezc_is_favorite( $ez_id ) ? 'true' : 'false'; ?>">★ Favorito</button>
						<?php endif; ?>
					</div>
					<?php echo ezt_vote_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo ezt_reactions_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			</div>

			<h2>Capítulos</h2>
			<?php if ( $ez_chapters ) : ?>
				<ol class="chapters">
					<?php foreach ( $ez_chapters as $c ) : ?>
						<li class="<?php echo ezc_is_read( $c->ID ) ? 'is-read' : ''; ?>">
							<a href="<?php echo esc_url( get_permalink( $c ) ); ?>">
								<span><?php echo esc_html( get_the_title( $c ) ); ?></span>
								<small><?php echo esc_html( get_the_date( '', $c ) ); ?></small>
							</a>
							<?php if ( is_user_logged_in() ) : ?>
								<button type="button" class="btn btn--ghost" data-ez-read="<?php echo (int) $c->ID; ?>" data-read="<?php echo ezc_is_read( $c->ID ) ? '1' : '0'; ?>" style="margin-top:4px;padding:4px 10px;font-size:.8rem">
									<?php echo ezc_is_read( $c->ID ) ? 'Marcar sin leer' : 'Marcar leído'; ?>
								</button>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p>Todavía no hay capítulos.</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
		?>
	</div>
	<?php
endwhile;

get_footer();
