<?php
/**
 * Ficha de juego, generada completa en el servidor: título, descripción,
 * datos, descargas, capturas y relacionados están en el HTML inicial.
 */
get_header();

while ( have_posts() ) :
	the_post();
	$ez_id    = get_the_ID();
	$ez_cover = ezc_get_cover_url( $ez_id );
	$ez_links = ezc_get_links( $ez_id );
	$ez_shots = ezc_get_screenshots( $ez_id );
	$ez_facts = array_filter( array(
		'Versión'      => get_post_meta( $ez_id, 'ez_version', true ),
		'Motor'        => ezt_term_names( $ez_id, 'motor' ),
		'Desarrollador' => ezt_term_names( $ez_id, 'desarrollador' ),
		'Plataforma'   => ezt_term_names( $ez_id, 'plataforma' ),
		'Traductor'    => ezt_term_names( $ez_id, 'traductor' ),
		'Estado'       => ezt_term_names( $ez_id, 'estado' ),
		'Actualizado'  => get_the_modified_date(),
	) );
	$ez_generos = get_the_terms( $ez_id, 'genero' );
	?>
	<div class="wrap">
		<?php
		ezt_breadcrumbs( array(
			get_post_type_archive_link( 'juego' ) => 'Juegos',
			0                                     => get_the_title(),
		) );
		?>
		<div class="game">
			<article>
				<h1><?php echo esc_html( ezc_game_title( $ez_id ) ); ?></h1>
				<?php if ( $ez_cover ) : ?>
					<img class="game__cover" src="<?php echo esc_url( $ez_cover ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" width="1280" height="720" fetchpriority="high">
				<?php endif; ?>
				<div class="game__content"><?php the_content(); ?></div>
				<?php echo ezt_reactions_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función ?>

				<?php if ( $ez_shots ) : ?>
					<h2>Capturas de <?php the_title(); ?></h2>
					<div class="shots">
						<?php foreach ( array_slice( $ez_shots, 0, 8 ) as $i => $src ) : ?>
							<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( get_the_title() . ' captura ' . ( $i + 1 ) ); ?>" loading="lazy" decoding="async" width="640" height="360">
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</article>

			<aside class="panel">
				<dl class="facts">
					<?php foreach ( $ez_facts as $label => $value ) : ?>
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo 'Traductor' === $label ? ezt_translators_html( $ez_id ) : esc_html( $value ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
					<?php endforeach; ?>
				</dl>
				<?php echo ezt_chips( $ez_id, 'genero' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en ezt_chips ?>

				<?php echo ezt_vote_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función ?>

				<?php get_template_part( 'template-parts/descargas' ); ?>

				<?php if ( is_user_logged_in() ) : ?>
					<p style="margin-top:12px">
						<button class="btn btn--ghost" data-ez-fav="<?php echo (int) $ez_id; ?>" aria-pressed="<?php echo ezc_is_favorite( $ez_id ) ? 'true' : 'false'; ?>">★ Favorito</button>
					</p>
				<?php endif; ?>
			</aside>
		</div>

		<?php
		// Relacionados: mismos géneros (los que más comparte primero). Más
		// enlaces internos entre fichas = Google llega a todas.
		$ez_related = array();
		if ( $ez_generos && ! is_wp_error( $ez_generos ) ) {
			$ez_related = get_posts( array(
				'post_type'      => 'juego',
				'posts_per_page' => 12,
				'post__not_in'   => array( $ez_id ),
				'tax_query'      => array( array( 'taxonomy' => 'genero', 'terms' => wp_list_pluck( $ez_generos, 'term_id' ) ) ),
				'orderby'        => 'rand(' . $ez_id . ')', // estable por juego, no cambia en cada visita
			) );
		}
		if ( $ez_related ) :
			?>
			<section class="section" style="margin-top:40px">
				<h2>Juegos relacionados</h2>
				<div class="grid grid--games">
					<?php
					global $post;
					foreach ( $ez_related as $post ) {
						setup_postdata( $post );
						get_template_part( 'template-parts/card', 'juego' );
					}
					wp_reset_postdata();
					?>
				</div>
			</section>
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
