/* Lector de mangas.
 * Sin JS se lee en cascada. Con JS: paginado, ancho, zoom, pantalla
 * completa, auto-scroll, ir a página, barra de progreso, tocar/deslizar
 * para pasar (celular), teclado, reintentar imágenes, reportar, compartir
 * y progreso guardado. Las preferencias se recuerdan en este navegador. */
(function () {
	'use strict';
	var reader = document.getElementById('ez-reader');
	if (!reader) return;

	var EZ = window.EZ || {};
	var pages = Array.prototype.slice.call(reader.querySelectorAll('.rd-page'));
	var total = pages.length;
	var id = +reader.dataset.id;
	var nextUrl = reader.dataset.next;
	var current = Math.min(Math.max(1, +reader.dataset.start || 1), total);
	var bar = document.querySelector('[data-rd-bar]');
	var counters = document.querySelectorAll('[data-ez-count]');
	var pager = document.querySelector('.rd-pager');
	var gotoSel = document.querySelector('[data-rd-goto]');
	var saved = 0, saveTimer = null;

	// ── Preferencias ──────────────────────────────────────────
	function pref(k, v) {
		try {
			if (v === undefined) return localStorage.getItem('ez-rd-' + k);
			localStorage.setItem('ez-rd-' + k, v);
		} catch (e) { return null; }
	}
	var tip = document.querySelector('[data-rd-tip]');
	var state = {
		mode: pref('modo') || (tip && tip.dataset.rdTip) || 'cascada',
		width: pref('ancho') || 'normal',
		zoom: +(pref('zoom') || 100),
		speed: +(pref('vel') || 1)
	};

	function mark(sel, attr, val) {
		document.querySelectorAll(sel).forEach(function (b) {
			b.setAttribute('aria-pressed', b.getAttribute(attr) === String(val) ? 'true' : 'false');
		});
	}

	// ── Progreso ──────────────────────────────────────────────
	function save(n) {
		if (!EZ.post || !EZ.logged || n <= saved) return;
		clearTimeout(saveTimer);
		saveTimer = setTimeout(function () {
			saved = n;
			EZ.post('progreso', { id: id, pagina: n, total: total }).catch(function () { saved = 0; });
		}, 800);
	}
	function setCurrent(n) {
		current = Math.min(Math.max(1, n), total);
		counters.forEach(function (c) { c.textContent = current; });
		if (gotoSel) gotoSel.value = current;
		save(current);
		updateBar();
	}
	function updateBar() {
		if (!bar) return;
		var pct;
		if (state.mode === 'pagina') {
			pct = current / total;
		} else {
			var top = reader.getBoundingClientRect().top + window.scrollY;
			pct = (window.scrollY + innerHeight - top) / reader.offsetHeight;
		}
		bar.style.width = Math.max(0, Math.min(1, pct)) * 100 + '%';
	}

	// ── Modo, ancho y zoom ────────────────────────────────────
	function applyMode(m) {
		state.mode = m;
		pref('modo', m);
		var paged = m === 'pagina';
		reader.classList.toggle('reader--page', paged);
		if (pager) pager.hidden = !paged;
		mark('[data-rd-mode]', 'data-rd-mode', m);
		if (tip) tip.hidden = !!pref('tip-off') || tip.dataset.rdTip === m;
		if (paged) {
			show(current);
		} else {
			pages.forEach(function (p) { p.classList.remove('is-current'); });
			if (current > 1) pages[current - 1].scrollIntoView();
		}
		updateBar();
	}
	function applyWidth(w) {
		state.width = w;
		pref('ancho', w);
		reader.dataset.width = w;
		mark('[data-rd-width]', 'data-rd-width', w);
	}
	function applyZoom(z) {
		state.zoom = Math.max(50, Math.min(200, z));
		pref('zoom', state.zoom);
		reader.style.setProperty('--rd-zoom', state.zoom / 100);
		var l = document.querySelector('[data-rd-zoom-label]');
		if (l) l.textContent = state.zoom + '%';
	}

	// ── Paginado ──────────────────────────────────────────────
	function show(n) {
		setCurrent(n);
		pages.forEach(function (p, i) { p.classList.toggle('is-current', i === current - 1); });
		var next = pages[current];
		if (next) next.querySelector('img').loading = 'eager'; // precargar la siguiente
		var top = reader.getBoundingClientRect().top + window.scrollY - 70;
		if (window.scrollY > top) window.scrollTo({ top: top });
	}
	function go(delta) {
		if (state.mode !== 'pagina') {
			var target = pages[Math.min(Math.max(0, current - 1 + delta), total - 1)];
			target.scrollIntoView({ behavior: 'smooth' });
			return;
		}
		if (delta > 0 && current >= total) {
			document.querySelector('.rd-end').scrollIntoView({ behavior: 'smooth' });
			return;
		}
		show(current + delta);
	}

	// Cascada: la página más visible es la actual.
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			if (state.mode === 'pagina') return;
			entries.forEach(function (en) {
				if (en.isIntersecting) setCurrent(+en.target.dataset.n);
			});
		}, { rootMargin: '-45% 0px -45% 0px' });
		pages.forEach(function (p) { io.observe(p); });
	}
	window.addEventListener('scroll', function () { if (state.mode !== 'pagina') updateBar(); }, { passive: true });

	// ── Auto-scroll ───────────────────────────────────────────
	var speeds = [0.5, 1, 1.5, 2, 3];
	var autoOn = false, raf = 0, last = 0;
	var autoBtn = document.querySelector('[data-rd-auto]');
	var speedBtn = document.querySelector('[data-rd-speed]');
	function tick(t) {
		if (!autoOn) return;
		var dt = last ? t - last : 16;
		last = t;
		if (state.mode === 'pagina') {
			// En paginado avanza una página cada 6 s / velocidad.
			tick.acc = (tick.acc || 0) + dt;
			if (tick.acc > 6000 / state.speed) { tick.acc = 0; go(1); }
		} else {
			window.scrollBy(0, 0.06 * dt * state.speed);
			if (innerHeight + window.scrollY >= document.body.scrollHeight - 2) return setAuto(false);
		}
		raf = requestAnimationFrame(tick);
	}
	function setAuto(on) {
		autoOn = on;
		last = 0;
		if (autoBtn) {
			autoBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
			autoBtn.textContent = on ? '❚❚ Pausar' : '▶ Iniciar';
		}
		cancelAnimationFrame(raf);
		if (on) raf = requestAnimationFrame(tick);
	}
	function setSpeed(s) {
		state.speed = s;
		pref('vel', s);
		if (speedBtn) speedBtn.textContent = s + 'x';
	}
	// Tocar la pantalla o usar la rueda pausa el auto-scroll.
	['wheel', 'touchstart'].forEach(function (ev) {
		window.addEventListener(ev, function () { if (autoOn && state.mode !== 'pagina') setAuto(false); }, { passive: true });
	});

	// ── Pantalla completa ─────────────────────────────────────
	var fsBtn = document.querySelector('[data-rd-fullscreen]');
	var fsTarget = document.documentElement;
	if (fsBtn && fsTarget.requestFullscreen) {
		fsBtn.hidden = false;
	}
	function toggleFs() {
		if (document.fullscreenElement) document.exitFullscreen();
		else if (fsTarget.requestFullscreen) fsTarget.requestFullscreen().catch(function () {});
	}
	document.addEventListener('fullscreenchange', function () {
		document.body.classList.toggle('rd-is-fullscreen', !!document.fullscreenElement);
	});

	// ── Imágenes que fallan: reintentar ───────────────────────
	pages.forEach(function (p) {
		var img = p.querySelector('img');
		function fail() { p.classList.add('is-broken'); p.querySelector('.rd-fail').hidden = false; }
		img.addEventListener('error', fail);
		if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) fail();
		img.addEventListener('load', function () { p.classList.remove('is-broken'); p.querySelector('.rd-fail').hidden = true; });
	});

	// ── Clics ─────────────────────────────────────────────────
	document.addEventListener('click', function (e) {
		var t;
		if ((t = e.target.closest('[data-rd-mode]'))) return applyMode(t.dataset.rdMode);
		if ((t = e.target.closest('[data-rd-width]'))) return applyWidth(t.dataset.rdWidth);
		if ((t = e.target.closest('[data-rd-zoom]'))) {
			var d = +t.dataset.rdZoom;
			return applyZoom(d ? state.zoom + d * 10 : 100);
		}
		if (e.target.closest('[data-rd-auto]')) return setAuto(!autoOn);
		if (e.target.closest('[data-rd-speed]')) return setSpeed(speeds[(speeds.indexOf(state.speed) + 1) % speeds.length]);
		if (e.target.closest('[data-rd-fullscreen]')) return toggleFs();
		if ((t = e.target.closest('[data-rd-go]'))) return go(+t.dataset.rdGo);
		if ((t = e.target.closest('[data-rd-toggle]'))) {
			var panel = document.querySelector('[data-rd-panel="' + t.dataset.rdToggle + '"]');
			panel.hidden = !panel.hidden;
			t.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
			return;
		}
		if (e.target.closest('[data-rd-tip-close]')) { pref('tip-off', '1'); tip.hidden = true; return; }
		if ((t = e.target.closest('[data-rd-retry]'))) {
			var img = t.closest('.rd-page').querySelector('img');
			var src = img.getAttribute('src').replace(/([?&])ezr=\d+/, '');
			img.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'ezr=' + Date.now();
			return;
		}
		if ((t = e.target.closest('[data-rd-page]'))) {
			var f = document.querySelector('[data-rd-report] [name="pagina"]');
			if (f) f.value = t.dataset.rdPage;
		}
		if (e.target.closest('[data-rd-share]')) {
			var data = { title: document.title, url: location.href.split('#')[0] };
			if (navigator.share) navigator.share(data).catch(function () {});
			else if (navigator.clipboard) navigator.clipboard.writeText(data.url).then(function () { alert('Enlace copiado'); });
		}
	});
	if (gotoSel) gotoSel.addEventListener('change', function () {
		var n = +gotoSel.value;
		if (state.mode === 'pagina') show(n);
		else pages[n - 1].scrollIntoView();
	});

	// Deslizar en el celular (paginado).
	var sx = 0, sy = 0;
	reader.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
	reader.addEventListener('touchend', function (e) {
		if (state.mode !== 'pagina') return;
		var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
		if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) go(dx < 0 ? 1 : -1);
	}, { passive: true });

	document.addEventListener('keydown', function (e) {
		if (/input|textarea|select/i.test(e.target.tagName) || e.ctrlKey || e.metaKey || e.altKey) return;
		if (e.key === 'ArrowRight' || e.key === 'd') { e.preventDefault(); go(1); }
		else if (e.key === 'ArrowLeft' || e.key === 'a') { e.preventDefault(); go(-1); }
		else if (e.key === 'f') toggleFs();
		else if (e.key === ' ' && state.mode === 'cascada' && autoBtn) { e.preventDefault(); setAuto(!autoOn); }
	});

	// ── Reporte ───────────────────────────────────────────────
	var form = document.querySelector('[data-rd-report]');
	if (form) form.addEventListener('submit', function (e) {
		e.preventDefault();
		var msg = form.querySelector('[data-rd-report-msg]');
		var fd = new FormData(form);
		var btn = form.querySelector('[type=submit]');
		btn.disabled = true;
		EZ.post('reporte', { id: +form.dataset.id, motivo: fd.get('motivo'), pagina: +fd.get('pagina') || 0, detalle: fd.get('detalle') })
			.then(function () { msg.textContent = '¡Gracias! Lo revisamos pronto.'; form.reset(); setTimeout(function () { form.closest('dialog').close(); msg.textContent = ''; }, 1500); })
			.catch(function () { msg.textContent = 'No se pudo enviar. Probá de nuevo más tarde.'; })
			.finally(function () { btn.disabled = false; });
	});

	// ── Inicio ────────────────────────────────────────────────
	applyWidth(state.width);
	applyZoom(state.zoom);
	setSpeed(state.speed);
	applyMode(state.mode);
	setCurrent(current);
})();
