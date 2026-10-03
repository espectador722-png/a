// static/js/sesion.js — sesión del usuario en todas las páginas.
//
//  1. Envuelve fetch(): un 401 (sesión vencida) manda al login y un 403 de
//     permisos avisa en vez de fallar en silencio.
//  2. Agrega a la barra de navegación (#navBtns) un menú con el usuario,
//     los atajos de admin, "cambiar contraseña" y "salir". Si la página no
//     tiene #navBtns, el botón queda flotando arriba a la derecha.
//
// El rol lo pone el servidor en <html data-rol="..." data-usuario="...">.
(function () {
  const raiz = document.documentElement;
  const rol = raiz.dataset.rol || '';
  const usuario = raiz.dataset.usuario || '';
  const esAdmin = rol === 'admin';

  // ── fetch con manejo de sesión ──────────────────────────────────────────────
  const fetchOriginal = window.fetch.bind(window);
  let avisado403 = 0;
  window.fetch = async function (...args) {
    const res = await fetchOriginal(...args);
    if (res.status === 401) {
      const destino = location.pathname + location.search;
      location.href = '/login?next=' + encodeURIComponent(destino);
    } else if (res.status === 403 && Date.now() - avisado403 > 3000) {
      avisado403 = Date.now();
      res.clone().json()
        .then(d => alert(d.error === 'solo administradores'
          ? 'Esta acción es solo para administradores.' : (d.error || 'Acceso denegado')))
        .catch(() => {});
    }
    return res;
  };

  // ── Menú de usuario ─────────────────────────────────────────────────────────
  function el(tag, attrs = {}, html = '') {
    const e = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
    if (html) e.innerHTML = html;
    return e;
  }
  const esc = s => String(s).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function crearMenu() {
    if (!usuario) return;
    const nav = document.getElementById('navBtns');
    const cont = el('div', { class: 'sesion-menu' });
    const btn = el('button', { class: 'nb', type: 'button', title: 'Tu cuenta' },
      `<i class="bi bi-person-circle"></i> ${esc(usuario)}`);
    if (!nav) {
      cont.style.cssText = 'position:fixed;top:10px;right:12px;z-index:2000;';
      btn.style.cssText = 'background:#2a2a36;color:#eee;border:0;border-radius:8px;padding:6px 12px;';
    }
    const panel = el('div', { class: 'sesion-panel' });
    let items = `<div class="sesion-quien"><b>${esc(usuario)}</b>${esAdmin ? 'Administrador' : 'Lector'}</div>`;
    if (location.pathname !== '/manga') {
      items += '<a href="/manga"><i class="bi bi-book"></i> Biblioteca</a>';
    }
    if (esAdmin) {
      items += `
        <a href="/descargas"><i class="bi bi-cloud-arrow-down"></i> Descargas</a>
        <a href="/manga-duplicados"><i class="bi bi-files"></i> Duplicados</a>
        <a href="/manga-sorteo"><i class="bi bi-shuffle"></i> Sorteo</a>
        <a href="/usuarios"><i class="bi bi-people"></i> Usuarios</a>`;
    }
    items += `
      <button type="button" data-accion="password"><i class="bi bi-key"></i> Cambiar contraseña</button>
      <button type="button" data-accion="salir"><i class="bi bi-box-arrow-right"></i> Salir</button>`;
    panel.innerHTML = items;
    cont.append(btn, panel);
    (nav || document.body).appendChild(cont);

    btn.addEventListener('click', e => { e.stopPropagation(); panel.classList.toggle('abierto'); });
    document.addEventListener('click', e => { if (!cont.contains(e.target)) panel.classList.remove('abierto'); });
    panel.querySelector('[data-accion="salir"]').addEventListener('click', salir);
    panel.querySelector('[data-accion="password"]').addEventListener('click', () => {
      panel.classList.remove('abierto');
      dialogoPassword();
    });
  }

  async function salir() {
    try { await fetchOriginal('/logout', { method: 'POST' }); } catch {}
    location.href = '/login';
  }

  function dialogoPassword() {
    const fondo = el('div', {
      style: 'position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:16px;',
    });
    fondo.innerHTML = `
      <form style="background:#1e1e26;color:#eee;border-radius:12px;padding:20px;width:100%;max-width:340px;display:flex;flex-direction:column;gap:10px;">
        <h3 style="margin:0 0 4px;font-size:18px;">Cambiar contraseña</h3>
        <input type="password" name="actual" placeholder="Contraseña actual" autocomplete="current-password" required
               style="padding:9px;border-radius:8px;border:1px solid #444;background:#14141a;color:#eee;">
        <input type="password" name="nueva" placeholder="Nueva (mínimo 8 caracteres)" autocomplete="new-password" minlength="8" required
               style="padding:9px;border-radius:8px;border:1px solid #444;background:#14141a;color:#eee;">
        <div data-msg style="color:#ef9a9a;font-size:13px;min-height:1em;"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
          <button type="button" data-cancelar style="padding:8px 14px;border-radius:8px;border:0;background:#333;color:#eee;">Cancelar</button>
          <button type="submit" style="padding:8px 14px;border-radius:8px;border:0;background:#ec407a;color:#fff;">Guardar</button>
        </div>
      </form>`;
    document.body.appendChild(fondo);
    const form = fondo.querySelector('form');
    const msg = fondo.querySelector('[data-msg]');
    const cerrar = () => fondo.remove();
    fondo.querySelector('[data-cancelar]').addEventListener('click', cerrar);
    fondo.addEventListener('click', e => { if (e.target === fondo) cerrar(); });
    form.addEventListener('submit', async e => {
      e.preventDefault();
      msg.textContent = '';
      const r = await fetchOriginal('/api/me/password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ actual: form.actual.value, nueva: form.nueva.value }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok) { msg.textContent = d.error || 'No se pudo cambiar'; return; }
      cerrar();
      alert('Contraseña actualizada.');
    });
    form.actual.focus();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', crearMenu);
  } else {
    crearMenu();
  }
})();
