/* Lector de mangas.
 * Sin JS se lee en cascada (todas las páginas una debajo de otra).
 * Con JS: modo página (clic / flechas / A-D), contador, y guarda el
 * progreso si hay sesión. El modo elegido se recuerda en este navegador. */
(function () {
	'use strict';
	var reader = document.getElementById('ez-reader');
	if (!reader) return;

	var imgs = Array.prototype.slice.call(reader.querySelectorAll('img'));
	var total = imgs.length;
	var counter = document.querySelector('[data-ez-count]');
	var modeBtn = document.querySelector('[data-ez-mode]');
	var nextUrl = reader.dataset.next;
	var id = +reader.dataset.id;
	var current = Math.min(Math.max(1, +reader.dataset.start || 1), total);
	var saved = 0, saveTimer = null;

	function getMode() { try { return localStorage.getItem('ez-modo') || 'cascada'; } catch (e) { return 'cascada'; } }
	function setMode(m) { try { localStorage.setItem('ez-modo', m); } catch (e) {} }

	function save(page) {
		if (!window.EZ || !EZ.post || page <= saved) return;
		clearTimeout(saveTimer);
		saveTimer = setTimeout(function () {
			saved = page;
			EZ.post('progreso', { id: id, pagina: page, total: total }).catch(function () { saved = 0; });
		}, 800);
	}

	function show(n) {
		current = Math.min(Math.max(1, n), total);
		if (counter) counter.textContent = current + ' / ' + total;
		if (reader.classList.contains('reader--page')) {
			imgs.forEach(function (img, i) { img.classList.toggle('is-current', i === current - 1); });
			var next = imgs[current];
			if (next) next.loading = 'eager'; // precargar la siguiente
			window.scrollTo({ top: reader.offsetTop - 130 });
		}
		save(current);
	}

	function apply(mode) {
		var page = mode === 'pagina';
		reader.classList.toggle('reader--page', page);
		modeBtn.textContent = page ? 'Modo cascada' : 'Modo página';
		if (page) {
			show(current);
		} else {
			imgs.forEach(function (img) { img.classList.remove('is-current'); });
			if (current > 1) imgs[current - 1].scrollIntoView();
		}
	}

	modeBtn.hidden = false;
	modeBtn.addEventListener('click', function () {
		var m = getMode() === 'pagina' ? 'cascada' : 'pagina';
		setMode(m);
		apply(m);
	});

	function go(delta) {
		if (delta > 0 && current >= total) {
			if (nextUrl) location.href = nextUrl;
			return;
		}
		show(current + delta);
	}

	// Modo página: clic en la mitad izquierda = atrás, derecha = adelante.
	reader.addEventListener('click', function (e) {
		if (!reader.classList.contains('reader--page') || e.target.tagName !== 'IMG') return;
		var r = e.target.getBoundingClientRect();
		go(e.clientX < r.left + r.width / 2 ? -1 : 1);
	});

	document.addEventListener('keydown', function (e) {
		if (!reader.classList.contains('reader--page') || /input|textarea/i.test(e.target.tagName)) return;
		if (e.key === 'ArrowRight' || e.key === 'd') go(1);
		if (e.key === 'ArrowLeft' || e.key === 'a') go(-1);
	});

	// Modo cascada: la página visible cuenta como progreso.
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			if (reader.classList.contains('reader--page')) return;
			entries.forEach(function (en) {
				if (en.isIntersecting) {
					current = imgs.indexOf(en.target) + 1;
					if (counter) counter.textContent = current + ' / ' + total;
					save(current);
				}
			});
		}, { threshold: 0.5 });
		imgs.forEach(function (img) { io.observe(img); });
	}

	apply(getMode());
})();
