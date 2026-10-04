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
			<?php $ez_nb = $ez_parent ? ezc_manga_neighbors( $ez_id ) : array( 'prev' => null, 'next' => null ); ?>
			<h1><?php echo esc_html( $ez_parent ? get_the_title( $ez_parent ) . ' — ' . get_the_title() : get_the_title() ); ?></h1>

			<div class="reader-bar" id="ez-reader-bar">
				<?php if ( $ez_nb['prev'] ) : ?><a class="btn btn--ghost" href="<?php echo esc_url( get_permalink( $ez_nb['prev'] ) ); ?>">‹ Cap. anterior</a><?php endif; ?>
				<button type="button" class="btn btn--ghost" data-ez-mode hidden>Modo página</button>
				<?php if ( is_user_logged_in() ) : ?>
					<button type="button" class="btn btn--ghost" data-ez-fav="<?php echo (int) $ez_serie; ?>" aria-pressed="<?php echo ezc_is_favorite( $ez_serie ) ? 'true' : 'false'; ?>">★</button>
				<?php endif; ?>
				<?php if ( $ez_nb['next'] ) : ?><a class="btn" href="<?php echo esc_url( get_permalink( $ez_nb['next'] ) ); ?>">Cap. siguiente ›</a><?php endif; ?>
				<span class="count" data-ez-count><?php echo count( $ez_pages ); ?> páginas</span>
			</div>

			<?php $ez_prog = ezc_get_progress( $ez_id ); ?>
			<div class="reader" id="ez-reader"
				data-id="<?php echo (int) $ez_id; ?>"
				data-start="<?php echo $ez_prog ? (int) $ez_prog['p'] : 1; ?>"
				data-next="<?php echo $ez_nb['next'] ? esc_url( get_permalink( $ez_nb['next'] ) ) : ''; ?>">
				<?php foreach ( $ez_pages as $i => $src ) : ?>
					<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( sprintf( '%s página %d', get_the_title(), $i + 1 ) ); ?>" <?php echo $i > 1 ? 'loading="lazy"' : ''; ?> decoding="async">
				<?php endforeach; ?>
			</div>

			<div class="reader-end">
				<?php if ( $ez_nb['next'] ) : ?>
					<a class="btn" href="<?php echo esc_url( get_permalink( $ez_nb['next'] ) ); ?>">Siguiente capítulo ›</a>
				<?php elseif ( $ez_parent ) : ?>
					<a class="btn btn--ghost" href="<?php echo esc_url( get_permalink( $ez_parent ) ); ?>">Volver a la serie</a>
				<?php else : ?>
					<a class="btn btn--ghost" href="<?php echo esc_url( get_post_type_archive_link( 'manga' ) ); ?>">Más mangas</a>
				<?php endif; ?>
			</div>
			<?php echo ezt_reactions_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<?php else : ?>
			<?php $ez_chapters = ezc_manga_chapters( $ez_id ); ?>
			<div class="manga-head">
				<?php if ( $ez_cover = ezc_manga_cover_url( $ez_id, 'large' ) ) : ?>
					<img src="<?php echo esc_url( $ez_cover ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" width="300" height="450">
				<?php endif; ?>
				<div>
					<h1><?php the_title(); ?></h1>
					<div class="entry__content"><?php the_content(); ?></div>
					<?php echo ezt_chips( $ez_id, 'etiqueta' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
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
