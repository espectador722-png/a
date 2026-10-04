<?php
/**
 * Lector de capítulos / one-shots.
 *
 * Sin JS se lee en cascada (todas las <img> del HTML). lector.js agrega:
 * modo paginado, ancho de página, zoom, pantalla completa, auto-scroll,
 * "ir a página", barra de progreso, tocar para pasar (celular), teclado,
 * recarga de imágenes que fallan, reportes, compartir y guardar progreso.
 */
$ez_id     = get_the_ID();
$ez_pages  = $args['pages'];
$ez_parent = $args['parent'];
$ez_serie  = $args['serie'];
$ez_nb     = $ez_parent ? ezc_manga_neighbors( $ez_id ) : array( 'prev' => null, 'next' => null );
$ez_prog   = ezc_get_progress( $ez_id );
$ez_total  = count( $ez_pages );
$ez_rec    = function_exists( 'ezc_manga_recommended_mode' ) ? ezc_manga_recommended_mode( $ez_id ) : '';
$ez_tipo   = ezt_term_names( $ez_serie, 'tipo_manga' );
$ez_title  = $ez_parent ? get_the_title( $ez_parent ) . ' — ' . get_the_title() : get_the_title();
$ez_member = function_exists( 'ezc_is_member' ) && ezc_is_member();
?>
<div class="rd-progress" aria-hidden="true"><span data-rd-bar></span></div>

<h1 class="rd-title"><?php echo esc_html( $ez_title ); ?></h1>

<div class="rd-bar" id="ez-reader-bar">
	<a class="rd-icon" href="<?php echo esc_url( $ez_parent ? get_permalink( $ez_parent ) : get_post_type_archive_link( 'manga' ) ); ?>" title="Ver listado de capítulos" aria-label="Ver listado de capítulos">☰</a>
	<?php if ( $ez_nb['prev'] ) : ?>
		<a class="rd-icon" href="<?php echo esc_url( get_permalink( $ez_nb['prev'] ) ); ?>" title="Capítulo anterior" aria-label="Capítulo anterior">‹</a>
	<?php endif; ?>
	<span class="rd-count"><span data-rd-cap><?php echo esc_html( $ez_parent ? get_the_title() : 'One-shot' ); ?></span> · Pág <b data-ez-count>1</b>/<?php echo (int) $ez_total; ?></span>
	<?php if ( $ez_nb['next'] ) : ?>
		<a class="rd-icon" href="<?php echo esc_url( get_permalink( $ez_nb['next'] ) ); ?>" title="Siguiente capítulo" aria-label="Siguiente capítulo">›</a>
	<?php endif; ?>
	<span class="rd-spacer"></span>
	<span class="rd-time" title="Tiempo promedio de lectura">🕒 <?php echo (int) ezc_reading_minutes( $ez_total ); ?> min</span>
	<button type="button" class="rd-icon" data-rd-toggle="settings" title="Ajustes" aria-label="Ajustes de lectura" aria-expanded="false">⚙</button>
	<button type="button" class="rd-icon" data-rd-fullscreen title="Pantalla completa" aria-label="Pantalla completa" hidden>⛶</button>
	<button type="button" class="rd-icon" data-rd-share title="Compartir" aria-label="Compartir">⤴</button>
	<button type="button" class="rd-icon" data-ez-dialog="rd-report" title="Reportar problema" aria-label="Reportar problema">⚑</button>
	<?php if ( is_user_logged_in() ) : ?>
		<button type="button" class="rd-icon" data-ez-fav="<?php echo (int) $ez_serie; ?>" aria-pressed="<?php echo ezc_is_favorite( $ez_serie ) ? 'true' : 'false'; ?>" title="Favorito" aria-label="Favorito">★</button>
	<?php endif; ?>
</div>

<div class="rd-settings" data-rd-panel="settings" hidden>
	<div class="rd-set">
		<span class="rd-set__label">Modo de lectura</span>
		<div class="rd-seg" role="group">
			<button type="button" data-rd-mode="cascada">▤ Cascada</button>
			<button type="button" data-rd-mode="pagina">▢ Paginado</button>
		</div>
	</div>
	<div class="rd-set">
		<span class="rd-set__label">Ancho de página</span>
		<div class="rd-seg" role="group">
			<button type="button" data-rd-width="angosto">Angosto</button>
			<button type="button" data-rd-width="normal">Normal</button>
			<button type="button" data-rd-width="completo">Completo</button>
		</div>
	</div>
	<div class="rd-set">
		<span class="rd-set__label">Zoom</span>
		<div class="rd-seg">
			<button type="button" data-rd-zoom="-1" aria-label="Reducir tamaño">−</button>
			<button type="button" data-rd-zoom="0" title="Restablecer"><span data-rd-zoom-label>100%</span></button>
			<button type="button" data-rd-zoom="1" aria-label="Aumentar tamaño">+</button>
		</div>
	</div>
	<div class="rd-set">
		<span class="rd-set__label">Auto-scroll</span>
		<div class="rd-seg">
			<button type="button" data-rd-auto aria-pressed="false">▶ Iniciar</button>
			<button type="button" data-rd-speed title="Cambiar velocidad">1x</button>
		</div>
	</div>
	<div class="rd-set">
		<label class="rd-set__label" for="rd-goto">Ir a página</label>
		<select id="rd-goto" data-rd-goto>
			<?php for ( $i = 1; $i <= $ez_total; $i++ ) : ?>
				<option value="<?php echo (int) $i; ?>"><?php echo (int) $i; ?></option>
			<?php endfor; ?>
		</select>
	</div>
	<p class="rd-hint">Teclado: ← → para pasar página · F pantalla completa · Espacio auto-scroll</p>
</div>

<?php if ( $ez_rec ) : ?>
	<div class="rd-tip" data-rd-tip="<?php echo esc_attr( $ez_rec ); ?>" hidden>
		<span>✨ Este contenido es un <strong><?php echo esc_html( $ez_tipo ?: 'manga' ); ?></strong>. Te recomendamos el modo <strong><?php echo 'pagina' === $ez_rec ? 'Paginado' : 'Cascada'; ?></strong>.</span>
		<button type="button" class="btn" data-rd-mode="<?php echo esc_attr( $ez_rec ); ?>">Cambiar</button>
		<button type="button" class="rd-icon" data-rd-tip-close aria-label="Ocultar sugerencia">✕</button>
	</div>
<?php endif; ?>

<div class="reader" id="ez-reader"
	data-id="<?php echo (int) $ez_id; ?>"
	data-start="<?php echo $ez_prog ? (int) $ez_prog['p'] : 1; ?>"
	data-next="<?php echo $ez_nb['next'] ? esc_url( get_permalink( $ez_nb['next'] ) ) : ''; ?>">
	<?php foreach ( $ez_pages as $i => $src ) : ?>
		<figure class="rd-page" data-n="<?php echo (int) $i + 1; ?>">
			<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( sprintf( '%s página %d', $ez_title, $i + 1 ) ); ?>" <?php echo $i > 1 ? 'loading="lazy"' : 'fetchpriority="high"'; ?> decoding="async">
			<figcaption class="rd-fail" hidden>
				<span>No se pudo cargar la página <?php echo (int) $i + 1; ?>.</span>
				<button type="button" class="btn" data-rd-retry>↻ Reintentar</button>
				<button type="button" class="btn btn--ghost" data-ez-dialog="rd-report" data-rd-page="<?php echo (int) $i + 1; ?>">Reportar</button>
			</figcaption>
		</figure>
	<?php endforeach; ?>
	<button type="button" class="rd-zone rd-zone--prev" data-rd-go="-1" aria-label="Página anterior" tabindex="-1"></button>
	<button type="button" class="rd-zone rd-zone--next" data-rd-go="1" aria-label="Página siguiente" tabindex="-1"></button>
</div>

<nav class="rd-pager" aria-label="Páginas" hidden>
	<button type="button" class="btn btn--ghost" data-rd-go="-1">‹ Anterior</button>
	<span><b data-ez-count>1</b> / <?php echo (int) $ez_total; ?></span>
	<button type="button" class="btn" data-rd-go="1">Siguiente ›</button>
</nav>

<section class="rd-end" aria-label="Fin del capítulo">
	<p class="rd-end__kicker">Fin del capítulo</p>
	<h2>¿Qué te pareció?</h2>
	<?php echo ezt_reactions_html( $ez_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<div class="rd-end__nav">
		<?php if ( $ez_nb['prev'] ) : ?><a class="btn btn--ghost" href="<?php echo esc_url( get_permalink( $ez_nb['prev'] ) ); ?>">‹ Anterior</a><?php endif; ?>
		<?php if ( $ez_parent ) : ?><a class="btn btn--ghost" href="<?php echo esc_url( get_permalink( $ez_parent ) ); ?>">☰ Ver capítulos</a><?php endif; ?>
		<?php if ( $ez_nb['next'] ) : ?>
			<a class="btn rd-end__next" href="<?php echo esc_url( get_permalink( $ez_nb['next'] ) ); ?>">Siguiente capítulo →</a>
		<?php else : ?>
			<a class="btn" href="<?php echo esc_url( get_post_type_archive_link( 'manga' ) ); ?>">Más mangas</a>
		<?php endif; ?>
	</div>
	<?php if ( ! $ez_member ) : ?>
		<div class="rd-support">
			<p><strong>¡Necesitamos tu apoyo!</strong> Con Patreon mantenés vivo el proyecto y descargás sin acortadores.</p>
			<a class="btn btn--patreon" href="<?php echo esc_url( home_url( '/membresia/' ) ); ?>">💎 Ver membresía</a>
		</div>
	<?php endif; ?>
</section>

<dialog id="rd-report" class="dl-dialog" aria-labelledby="rd-report-h">
	<form class="rd-report" data-rd-report data-id="<?php echo (int) $ez_id; ?>">
		<header class="dl-dialog__head">
			<h2 id="rd-report-h">Reportar un problema</h2>
			<button type="button" class="dl-dialog__close" data-ez-dialog-close aria-label="Cerrar">✕</button>
		</header>
		<div class="dl-dialog__body">
			<label class="filters__field"><span>Motivo</span>
				<select name="motivo" required>
					<option>Imagen rota o que no carga</option>
					<option>Páginas desordenadas o repetidas</option>
					<option>Capítulo incompleto</option>
					<option>Error de traducción</option>
					<option>Otro</option>
				</select>
			</label>
			<label class="filters__field"><span>Página (opcional)</span>
				<input type="number" name="pagina" min="1" max="<?php echo (int) $ez_total; ?>">
			</label>
			<label class="filters__field"><span>Detalle (opcional)</span>
				<textarea name="detalle" rows="3" maxlength="2000"></textarea>
			</label>
			<p class="rd-report__msg" data-rd-report-msg role="status"></p>
			<button class="btn" type="submit">Enviar reporte</button>
		</div>
	</form>
</dialog>
