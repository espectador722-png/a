<?php
/**
 * Comentarios de juegos, noticias y mangas.
 * Quién puede comentar se decide en Ajustes → Comentarios (recomendado:
 * "Los usuarios deben registrarse e iniciar sesión para comentar").
 */
if ( post_password_required() ) {
	return;
}
$ezt_count = (int) get_comments_number();
?>
<section id="comentarios" class="comments section">
	<div class="section-head">
		<h2><?php echo $ezt_count ? esc_html( sprintf( '%d %s', $ezt_count, 1 === $ezt_count ? 'comentario' : 'comentarios' ) ) : 'Comentarios'; ?></h2>
	</div>

	<?php if ( have_comments() ) : ?>
		<ol class="comment-list">
			<?php
			wp_list_comments( array(
				'style'       => 'ol',
				'callback'    => 'ezt_comment',
				'max_depth'   => 3,
				'avatar_size' => 40,
				'reply_text'  => 'Responder',
			) );
			?>
		</ol>
		<?php
		the_comments_pagination( array(
			'prev_text' => '‹ Anteriores',
			'next_text' => 'Más nuevos ›',
		) );
		?>
	<?php endif; ?>

	<?php if ( ! comments_open() && $ezt_count ) : ?>
		<p class="comments__closed">Los comentarios están cerrados.</p>
	<?php endif; ?>

	<?php
	comment_form( array(
		'title_reply'          => $ezt_count ? 'Dejá tu comentario' : 'Sé el primero en comentar',
		'title_reply_to'       => 'Responder a %s',
		'cancel_reply_link'    => 'Cancelar',
		'label_submit'         => 'Publicar',
		'class_submit'         => 'btn',
		'comment_notes_before' => '',
		'logged_in_as'         => '',
		'comment_field'        => '<p class="comment-form-comment"><label class="screen-reader-text" for="comment">Comentario</label><textarea id="comment" name="comment" rows="4" maxlength="65525" required placeholder="¿Qué te pareció?"></textarea></p>',
		'must_log_in'          => '<p class="must-log-in"><a class="btn btn--ghost" href="' . esc_url( wp_login_url( get_permalink() . '#comentarios' ) ) . '">Entrá para comentar</a></p>',
	) );
	?>
</section>
