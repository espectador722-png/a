/* Interfaz de Eclipse Zone. Todo es mejora: sin JS el contenido y los
 * enlaces siguen estando en el HTML. */
(function () {
	'use strict';
	var EZ = window.EZ || {};

	EZ.post = function (path, body) {
		var headers = { 'Content-Type': 'application/json' };
		if (EZ.nonce) headers['X-WP-Nonce'] = EZ.nonce;
		return fetch(EZ.api + path, {
			method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body)
		}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		});
	};
	function store(fn) { try { return fn(); } catch (e) { return null; } }

	// ── Vista de la ficha ──────────────────────────────────────
	if (EZ.vista) {
		EZ.post('vista', { id: EZ.vista }).then(function (r) {
			document.querySelectorAll('[data-ez-views]').forEach(function (el) {
				el.textContent = r.vistas.toLocaleString('es') + (r.vistas === 1 ? ' vista' : ' vistas');
			});
		}).catch(function () {});
	}

	// ── Franja de Discord (se recuerda cerrada en este navegador) ──
	var strip = document.querySelector('[data-ez-strip]');
	if (strip) {
		if (store(function () { return localStorage.getItem('ez-strip-closed'); })) strip.remove();
		else document.addEventListener('click', function (e) {
			if (!e.target.closest('[data-ez-strip-close]')) return;
			strip.remove();
			store(function () { localStorage.setItem('ez-strip-closed', '1'); });
		});
	}

	// ── Carrusel de destacados ─────────────────────────────────
	document.querySelectorAll('[data-ez-carousel]').forEach(function (car) {
		var track = car.querySelector('.carousel__track');
		var slides = track.children;
		var dots = car.querySelectorAll('.carousel__dots button');
		var i = 0, timer;
		function go(n) {
			i = (n + slides.length) % slides.length;
			track.scrollTo({ left: slides[i].offsetLeft, behavior: 'smooth' });
		}
		function mark() {
			var n = Math.round(track.scrollLeft / track.clientWidth);
			i = n;
			dots.forEach(function (d, k) { d.setAttribute('aria-current', k === n ? 'true' : 'false'); });
		}
		car.querySelector('[data-prev]').addEventListener('click', function () { go(i - 1); restart(); });
		car.querySelector('[data-next]').addEventListener('click', function () { go(i + 1); restart(); });
		dots.forEach(function (d, k) { d.addEventListener('click', function () { go(k); restart(); }); });
		track.addEventListener('scroll', function () { clearTimeout(track._t); track._t = setTimeout(mark, 80); });
		function restart() {
			clearInterval(timer);
			if (!matchMedia('(prefers-reduced-motion: reduce)').matches) timer = setInterval(function () { go(i + 1); }, 6000);
		}
		car.addEventListener('mouseenter', function () { clearInterval(timer); });
		car.addEventListener('mouseleave', restart);
		mark();
		restart();
	});

	// ── Pestañas (Top semana / mes / año) ──────────────────────
	document.querySelectorAll('[data-ez-tabs]').forEach(function (box) {
		var tabs = box.querySelectorAll('[role="tab"]');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				tabs.forEach(function (t) {
					var on = t === tab;
					t.setAttribute('aria-selected', on ? 'true' : 'false');
					document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
				});
			});
		});
	});

	// ── Clics: favoritos, leído, votos ─────────────────────────
	function needLogin() { if (!EZ.logged) { location.href = EZ.login; return true; } return false; }

	document.addEventListener('click', function (e) {
		var fav = e.target.closest('[data-ez-fav]');
		if (fav) {
			if (needLogin()) return;
			fav.disabled = true;
			EZ.post('favorito', { id: +fav.dataset.ezFav }).then(function (r) {
				document.querySelectorAll('[data-ez-fav="' + fav.dataset.ezFav + '"]').forEach(function (b) {
					b.setAttribute('aria-pressed', r.favorito ? 'true' : 'false');
				});
			}).catch(function () { alert('No se pudo guardar. Probá de nuevo.'); })
				.finally(function () { fav.disabled = false; });
			return;
		}

		var read = e.target.closest('[data-ez-read]');
		if (read) {
			var leido = read.dataset.read !== '1';
			read.disabled = true;
			EZ.post('leido', { id: +read.dataset.ezRead, leido: leido }).then(function () {
				read.dataset.read = leido ? '1' : '0';
				read.textContent = leido ? 'Marcar sin leer' : 'Marcar leído';
				read.closest('li').classList.toggle('is-read', leido);
			}).catch(function () { alert('No se pudo guardar. Probá de nuevo.'); })
				.finally(function () { read.disabled = false; });
			return;
		}

		var react = e.target.closest('[data-ez-react] button');
		if (react) {
			if (needLogin()) return;
			var bar = react.closest('[data-ez-react]');
			react.disabled = true;
			EZ.post('reaccion', { id: +bar.dataset.ezReact, emoji: react.value }).then(function (r) {
				bar.querySelectorAll('button').forEach(function (b) {
					var n = r.counts[b.value] || 0;
					b.setAttribute('aria-pressed', r.mias.indexOf(b.value) !== -1 ? 'true' : 'false');
					b.querySelector('b').textContent = n ? n.toLocaleString('es') : '';
				});
			}).catch(function () { alert('No se pudo guardar la reacción.'); })
				.finally(function () { react.disabled = false; });
			return;
		}

		var star = e.target.closest('[data-ez-vote] button');
		if (star) {
			if (needLogin()) return;
			var box = star.closest('[data-ez-vote]');
			var nota = +star.value;
			EZ.post('votar', { id: +box.dataset.ezVote, nota: nota }).then(function (r) {
				box.querySelectorAll('button').forEach(function (b) {
					b.setAttribute('aria-pressed', +b.value <= r.tuya ? 'true' : 'false');
				});
				var out = box.querySelector('[data-ez-vote-result]');
				if (out) out.textContent = r.media.toLocaleString('es', { minimumFractionDigits: 1 }) + ' de 5 · ' + r.votos + (r.votos === 1 ? ' voto' : ' votos') + ' · tu voto: ' + r.tuya;
			}).catch(function () { alert('No se pudo guardar el voto.'); });
		}
	});

	window.EZ = EZ;
})();
