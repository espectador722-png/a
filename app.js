'use strict';
// ---------- IndexedDB ----------
const DB_NAME = 'manga-reader', STORE = 'mangas';
const dbp = new Promise((res, rej) => {
  const r = indexedDB.open(DB_NAME, 1);
  r.onupgradeneeded = () => r.result.createObjectStore(STORE, { keyPath: 'id' });
  r.onsuccess = () => res(r.result);
  r.onerror = () => rej(r.error);
});
async function tx(mode, fn) {
  const db = await dbp;
  return new Promise((res, rej) => {
    const t = db.transaction(STORE, mode);
    const out = fn(t.objectStore(STORE));
    t.oncomplete = () => res(out && 'result' in out ? out.result : undefined);
    t.onerror = () => rej(t.error);
  });
}
const dbAll = () => tx('readonly', s => s.getAll());
const dbGet = id => tx('readonly', s => s.get(id));
const dbPut = m => tx('readwrite', s => s.put(m));
const dbDel = id => tx('readwrite', s => s.delete(id));

// ---------- Utilidades ----------
const $ = id => document.getElementById(id);
const IMG_RE = /\.(jpe?g|png|gif|webp|avif|bmp)$/i;
const natural = (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
const MIME = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp', avif: 'image/avif', bmp: 'image/bmp' };
const toast = msg => { const t = $('toast'); t.textContent = msg; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => t.hidden = true, 3000); };
const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
const clean = n => n.replace(/\.(cbz|zip)$/i, '');

// ---------- Importación ----------
async function fromZip(file) {
  if (!window.JSZip) throw new Error('JSZip no cargó (¿sin conexión?)');
  const zip = await JSZip.loadAsync(file);
  const entries = Object.values(zip.files).filter(e => !e.dir && IMG_RE.test(e.name) && !/(^|\/)(__MACOSX|\.)/.test(e.name));
  entries.sort(natural);
  const pages = [];
  for (const e of entries) {
    const ext = e.name.split('.').pop().toLowerCase();
    const buf = await e.async('arraybuffer');
    pages.push(new Blob([buf], { type: MIME[ext] || 'image/jpeg' }));
  }
  return { title: clean(file.name), pages };
}

function fromImages(files, title) {
  const imgs = files.filter(f => IMG_RE.test(f.name)).sort((a, b) =>
    (a.webkitRelativePath || a.name).localeCompare(b.webkitRelativePath || b.name, undefined, { numeric: true }));
  return { title, pages: imgs };
}

async function addEntry({ title, pages }) {
  if (!pages.length) return false;
  await dbPut({ id: uid(), title, pages, cover: pages[0], added: Date.now(), fav: false, progress: 0, mode: 'page', rtl: true });
  return true;
}

async function importFiles(fileList) {
  const files = [...fileList];
  let n = 0;
  try {
    const archives = files.filter(f => /\.(cbz|zip)$/i.test(f.name));
    for (const f of archives) { toast('Leyendo ' + f.name + '…'); if (await addEntry(await fromZip(f))) n++; }

    const loose = files.filter(f => !/\.(cbz|zip)$/i.test(f.name) && IMG_RE.test(f.name));
    // Agrupar por carpeta (si viene de selector de carpeta) o todo junto
    const groups = new Map();
    for (const f of loose) {
      const parts = (f.webkitRelativePath || '').split('/');
      const key = parts.length > 1 ? parts.slice(0, -1).join('/') : '';
      (groups.get(key) || groups.set(key, []).get(key)).push(f);
    }
    for (const [key, list] of groups) {
      const title = key ? key.split('/').pop() : (list.length === 1 ? clean(list[0].name) : 'Imágenes ' + new Date().toLocaleDateString());
      if (await addEntry(fromImages(list, title))) n++;
    }
  } catch (e) { console.error(e); toast('Error: ' + e.message); }
  if (n) toast(`Añadido(s): ${n}`); else if (files.length) toast('No se encontraron imágenes válidas');
  renderLibrary();
}

// ---------- Biblioteca ----------
let onlyFav = false;
async function renderLibrary() {
  const q = $('search').value.trim().toLowerCase();
  let list = (await dbAll()).sort((a, b) => b.added - a.added);
  if (onlyFav) list = list.filter(m => m.fav);
  if (q) list = list.filter(m => m.title.toLowerCase().includes(q));
  const grid = $('library');
  grid.querySelectorAll('img').forEach(i => URL.revokeObjectURL(i.src));
  grid.replaceChildren();
  $('empty').hidden = list.length > 0 || q || onlyFav;
  for (const m of list) {
    const pct = m.pages.length > 1 ? Math.round(m.progress / (m.pages.length - 1) * 100) : 0;
    const c = document.createElement('div');
    c.className = 'card';
    c.innerHTML = `<img alt=""><button class="fav" title="Favorito">${m.fav ? '★' : '☆'}</button><button class="del" title="Eliminar">✕</button>
      <div class="bar"><i style="width:${pct}%"></i></div>
      <div class="meta"><div class="name"></div><div class="sub">${m.progress + 1}/${m.pages.length} págs · ${pct}%</div></div>`;
    c.querySelector('img').src = URL.createObjectURL(m.cover);
    c.querySelector('.name').textContent = m.title;
    c.querySelector('.fav').classList.toggle('on', m.fav);
    c.onclick = () => openReader(m.id);
    c.querySelector('.fav').onclick = async e => { e.stopPropagation(); m.fav = !m.fav; await dbPut(m); renderLibrary(); };
    c.querySelector('.del').onclick = async e => {
      e.stopPropagation();
      if (confirm(`¿Eliminar "${m.title}"?`)) { await dbDel(m.id); renderLibrary(); }
    };
    grid.append(c);
  }
}

// ---------- Lector ----------
let cur = null, urls = [], io = null;
const view = $('r-view'), slider = $('r-slider');

async function openReader(id) {
  cur = await dbGet(id);
  if (!cur) return;
  urls = cur.pages.map(b => URL.createObjectURL(b));
  $('reader').hidden = false;
  document.body.style.overflow = 'hidden';
  $('r-title').textContent = cur.title;
  slider.max = cur.pages.length;
  render();
  view.focus();
}

async function closeReader() {
  if (!cur) return;
  io && io.disconnect();
  await save();
  urls.forEach(u => URL.revokeObjectURL(u));
  urls = []; cur = null;
  view.replaceChildren();
  $('reader').hidden = true;
  document.body.style.overflow = '';
  renderLibrary();
}

let saveT;
function saveSoon() { clearTimeout(saveT); saveT = setTimeout(save, 400); }
async function save() { if (cur) await dbPut(cur); }

function updateUI() {
  $('r-pos').textContent = `${cur.progress + 1} / ${cur.pages.length}`;
  slider.value = cur.progress + 1;
  slider.style.direction = cur.rtl && cur.mode === 'page' ? 'rtl' : 'ltr';
  $('r-mode').textContent = cur.mode === 'page' ? '📄 Página' : '📜 Cascada';
  $('r-dir').textContent = cur.rtl ? '← Der→Izq' : '→ Izq→Der';
  $('r-dir').hidden = cur.mode !== 'page';
  $('r-fav').textContent = cur.fav ? '★' : '☆';
}

function render() {
  io && io.disconnect();
  view.replaceChildren();
  view.className = 'r-view ' + cur.mode;
  if (cur.mode === 'page') {
    const img = document.createElement('img');
    img.draggable = false;
    img.src = urls[cur.progress];
    view.append(img);
    view.scrollTop = 0;
    // precarga la siguiente
    if (urls[cur.progress + 1]) new Image().src = urls[cur.progress + 1];
  } else {
    const imgs = urls.map((u, i) => {
      const img = document.createElement('img');
      img.dataset.i = i; img.loading = 'lazy'; img.src = u;
      view.append(img);
      return img;
    });
    io = new IntersectionObserver(es => {
      for (const e of es) if (e.isIntersecting) { cur.progress = +e.target.dataset.i; updateUI(); saveSoon(); }
    }, { root: view, threshold: 0.5 });
    imgs.forEach(i => io.observe(i));
    const target = imgs[cur.progress];
    requestAnimationFrame(() => target && target.scrollIntoView());
  }
  updateUI();
}

function goTo(i) {
  i = Math.max(0, Math.min(cur.pages.length - 1, i));
  if (i === cur.progress && cur.mode === 'page') return;
  cur.progress = i;
  if (cur.mode === 'page') render();
  else { view.children[i]?.scrollIntoView(); updateUI(); }
  saveSoon();
}
// "next" respeta la dirección de lectura sólo en el sentido visual
const next = () => goTo(cur.progress + 1), prev = () => goTo(cur.progress - 1);

function toggleMode() { cur.mode = cur.mode === 'page' ? 'cascade' : 'page'; saveSoon(); render(); }
function toggleDir() { cur.rtl = !cur.rtl; saveSoon(); updateUI(); }
async function toggleFav() { cur.fav = !cur.fav; saveSoon(); updateUI(); }

view.addEventListener('click', e => {
  if (cur.mode !== 'page') return;
  const left = e.clientX < view.getBoundingClientRect().left + view.clientWidth / 2;
  (left === cur.rtl ? next : prev)();
});

document.addEventListener('keydown', e => {
  if (!cur || e.target === slider && /Arrow/.test(e.key)) return;
  const k = e.key;
  if (k === 'Escape') closeReader();
  else if (k === 'm' || k === 'M') toggleMode();
  else if (k === 'd' || k === 'D') toggleDir();
  else if (k === 'f' || k === 'F') toggleFav();
  else if (k === 'Home') goTo(0);
  else if (k === 'End') goTo(cur.pages.length - 1);
  else if (cur.mode === 'page') {
    if (k === 'ArrowLeft') { e.preventDefault(); (cur.rtl ? next : prev)(); }
    else if (k === 'ArrowRight') { e.preventDefault(); (cur.rtl ? prev : next)(); }
    else if (k === ' ' || k === 'PageDown') { e.preventDefault(); next(); }
    else if (k === 'PageUp') { e.preventDefault(); prev(); }
  } else if (k === 'ArrowLeft' || k === 'ArrowRight') {
    /* en cascada, las flechas arriba/abajo hacen scroll nativo */
  }
});

slider.oninput = () => goTo(+slider.value - 1);
$('r-back').onclick = closeReader;
$('r-mode').onclick = toggleMode;
$('r-dir').onclick = toggleDir;
$('r-fav').onclick = toggleFav;
window.addEventListener('beforeunload', save);

// ---------- Eventos de biblioteca ----------
$('btn-add-files').onclick = () => $('in-files').click();
$('btn-add-folder').onclick = () => $('in-folder').click();
for (const id of ['in-files', 'in-folder'])
  $(id).onchange = e => { importFiles(e.target.files); e.target.value = ''; };
$('search').oninput = renderLibrary;
$('btn-fav-filter').onclick = e => { onlyFav = !onlyFav; e.currentTarget.classList.toggle('on', onlyFav); renderLibrary(); };

document.addEventListener('dragover', e => e.preventDefault());
document.addEventListener('drop', async e => {
  e.preventDefault();
  const items = [...(e.dataTransfer.items || [])].map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
  if (!items.some(i => i.isDirectory)) return importFiles(e.dataTransfer.files);
  const files = [];
  const walk = async (en, path) => {
    if (en.isFile) {
      const f = await new Promise(r => en.file(r));
      Object.defineProperty(f, 'webkitRelativePath', { value: path + f.name });
      files.push(f);
    } else {
      const rd = en.createReader();
      let batch;
      do { batch = await new Promise(r => rd.readEntries(r)); for (const c of batch) await walk(c, path + en.name + '/'); } while (batch.length);
    }
  };
  for (const en of items) await walk(en, '');
  importFiles(files);
});

renderLibrary();
