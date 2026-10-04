/* Favoritos y leído/sin leer (solo usuarios con sesión). */
(function () {
	'use strict';
	if (!window.EZ) return;

	function post(path, body) {
		return fetch(EZ.api + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': EZ.nonce },
			body: JSON.stringify(body)
		}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		});
	}
	window.EZ.post = post;

	document.addEventListener('click', function (e) {
		var fav = e.target.closest('[data-ez-fav]');
		if (fav) {
			fav.disabled = true;
			post('favorito', { id: +fav.dataset.ezFav })
				.then(function (r) {
					document.querySelectorAll('[data-ez-fav="' + fav.dataset.ezFav + '"]').forEach(function (b) {
						b.setAttribute('aria-pressed', r.favorito ? 'true' : 'false');
					});
				})
				.catch(function () { alert('No se pudo guardar. Probá de nuevo.'); })
				.finally(function () { fav.disabled = false; });
			return;
		}

		var read = e.target.closest('[data-ez-read]');
		if (read) {
			var leido = read.dataset.read !== '1';
			read.disabled = true;
			post('leido', { id: +read.dataset.ezRead, leido: leido })
				.then(function () {
					read.dataset.read = leido ? '1' : '0';
					read.textContent = leido ? 'Marcar sin leer' : 'Marcar leído';
					read.closest('li').classList.toggle('is-read', leido);
				})
				.catch(function () { alert('No se pudo guardar. Probá de nuevo.'); })
				.finally(function () { read.disabled = false; });
		}
	});
})();
