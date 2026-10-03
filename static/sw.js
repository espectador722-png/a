/* sw.js — service worker de la Biblioteca de Manga
 *
 * Objetivo: que la app abra al instante en el celular y que las miniaturas ya
 * vistas no se vuelvan a bajar por wifi.
 *
 * Reglas:
 *   1. Las imágenes (previews y páginas) van a caché primero: el nombre del
 *      archivo identifica el contenido, así que si está cacheado, sirve.
 *   2. Las páginas HTML van a red primero (dependen de la sesión y del rol);
 *      el caché solo se usa si el servidor no responde.
 *   3. Las APIs no se cachean nunca.
 *
 * Al tocar este archivo, subí VERSION: eso invalida los cachés viejos.
 */
const VERSION    = 'v2';
const CACHE_APP  = `app-${VERSION}`;     // shell: HTML, CSS, iconos
const CACHE_IMG  = `img-${VERSION}`;     // previews y páginas
const MAX_IMG    = 900;                  // techo del caché de imágenes

const SHELL = [
  '/manga',
  '/static/favicon.svg',
  '/static/icons/icon-192.png',
  '/static/vendor/bootstrap-icons/font/bootstrap-icons.css',
];

// Rutas que sirven imágenes cacheables
const RE_IMG = /^\/(get_manga_preview|get_manga_page|mangas)\//;


self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_APP)
      // addAll falla entero si un recurso falla; los agregamos de a uno
      .then(c => Promise.allSettled(SHELL.map(u => c.add(u))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  const vigentes = [CACHE_APP, CACHE_IMG];
  event.waitUntil(
    caches.keys()
      .then(claves => Promise.all(
        claves.filter(k => !vigentes.includes(k)).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', e => {
  if (e.data === 'skipWaiting') self.skipWaiting();
});


self.addEventListener('fetch', event => {
  const req = event.request;

  // Solo GET del mismo origen; nada de rangos parciales.
  if (req.method !== 'GET') return;
  if (req.headers.has('range')) return;

  let url;
  try { url = new URL(req.url); } catch { return; }
  if (url.origin !== self.location.origin) return;

  if (RE_IMG.test(url.pathname)) {
    event.respondWith(cachePrimero(req, CACHE_IMG, MAX_IMG));
    return;
  }

  if (url.pathname.startsWith('/static/')) {
    event.respondWith(revalidarEnSegundoPlano(req, CACHE_APP));
    return;
  }

  if (req.mode === 'navigate') {
    event.respondWith(navegacion(req));
    return;
  }
  // Todo lo demás (APIs de escritura, listados que cambian) va directo a la red.
});


// ── Estrategias ───────────────────────────────────────────────────────────────

async function cachePrimero(req, nombreCache, techo) {
  const cache = await caches.open(nombreCache);
  const hit = await cache.match(req);
  if (hit) return hit;
  try {
    const res = await fetch(req);
    if (res.ok && res.status === 200) {
      cache.put(req, res.clone()).then(() => recortar(nombreCache, techo));
    }
    return res;
  } catch (e) {
    // Sin red y sin caché: que el <img> dispare su onerror
    return new Response('', { status: 504, statusText: 'sin conexión' });
  }
}

async function revalidarEnSegundoPlano(req, nombreCache) {
  const cache = await caches.open(nombreCache);
  const hit = await cache.match(req);
  const red = fetch(req)
    .then(res => {
      if (res.ok) cache.put(req, res.clone());
      return res;
    })
    .catch(() => hit || new Response('', { status: 504 }));
  return hit || red;
}

async function navegacion(req) {
  try {
    return await fetch(req);
  } catch (e) {
    const cache = await caches.open(CACHE_APP);
    return (await cache.match(req)) || (await cache.match('/manga')) ||
      new Response('<h1>Sin conexión</h1><p>El servidor no responde.</p>',
                   { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }
}

/** Caché de imágenes acotado: las claves salen en orden de inserción, así que
 *  borrar desde el principio equivale a tirar lo más viejo. */
async function recortar(nombreCache, techo) {
  const cache = await caches.open(nombreCache);
  const claves = await cache.keys();
  if (claves.length <= techo) return;
  await Promise.all(claves.slice(0, claves.length - techo).map(k => cache.delete(k)));
}
