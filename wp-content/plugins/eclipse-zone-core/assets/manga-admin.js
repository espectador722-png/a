/* Botón "Elegir / subir páginas": abre la biblioteca de medios y agrega los
 * IDs elegidos al textarea, ordenados por nombre de archivo. */
jQuery(function ($) {
	var frame;
	$('#ezc-pick-pages').on('click', function (e) {
		e.preventDefault();
		if (!frame) {
			frame = wp.media({ title: 'Páginas del manga', button: { text: 'Usar estas páginas' }, multiple: 'add', library: { type: 'image' } });
			frame.on('select', function () {
				var picked = frame.state().get('selection').toJSON();
				picked.sort(function (a, b) {
					return (a.filename || '').localeCompare(b.filename || '', undefined, { numeric: true });
				});
				var $ta = $('#ezc-pages');
				var current = $ta.val().split(/\n/).map(function (s) { return s.trim(); }).filter(Boolean);
				picked.forEach(function (att) {
					if (current.indexOf(String(att.id)) === -1) current.push(String(att.id));
				});
				$ta.val(current.join('\n'));
			});
		}
		frame.open();
	});
});
