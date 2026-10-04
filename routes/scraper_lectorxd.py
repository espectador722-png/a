# routes/scraper_lectorxd.py — descarga de manga, manhwa y manhua de lectorxd.com
#
# A diferencia de hitomi/3hentai (una galería = un manga), acá cada título es
# una SERIE de capítulos. Cada capítulo se guarda como un manga aparte
# ("<Título> - Capítulo N") con "serie" en metadata.json, así la vista de
# series de la biblioteca los agrupa sola (routes/manga.py usa ese campo).
#
# Estructura (verificada sobre páginas guardadas del sitio, 2026-10-04):
#   - /{manga|manhwa|manhua}/{slug}            ficha de la serie
#   - /{manga|manhwa|manhua}/{slug}/leer/{N}   capítulo N (N puede ser 10.5)
#     · las páginas están en el HTML: <img class="page-image"
#       data-original-src="https://s1.cdnlxd.xyz/{id}/{N}/{p}.webp">
#     · el capítulo siguiente: const nextChapterUrl = "/.../leer/2" (o null)
#     · el título de la serie: JSON-LD BreadcrumbList, posición 3
#   - /catalogo?filters=true&search=TEXTO&page=N   búsqueda; los resultados
#     vienen como props JSON de la isla Astro CatalogGrid (initialMangas)
import html as html_lib
import json
import logging
import os
import re
import threading
import time
import uuid
from urllib.parse import urlencode, urljoin, urlsplit

import requests

from config import Config
from routes.helpers import sanitize_folder_name, save_json, load_json, invalidate_cache
from routes.preview_utils import preview_desde_imagen

logger = logging.getLogger(__name__)

BASE = "https://lectorxd.com"
UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/124.0 Safari/537.36")
TIPOS = ("manga", "manhwa", "manhua")
MAX_CAPITULOS_POR_DESCARGA = 300
PAUSA_ENTRE_IMAGENES = 0.15   # segundos: no martillar el CDN

_RE_URL = re.compile(
    r"^https?://(?:www\.)?lectorxd\.com/(manga|manhwa|manhua)/([a-z0-9][a-z0-9-]*)"
    r"(?:/leer/(\d+(?:\.\d+)?))?/?(?:[?#].*)?$", re.IGNORECASE)
_RE_CDN = re.compile(r"^s\d+\.cdnlxd\.xyz$", re.IGNORECASE)


def _session() -> requests.Session:
    s = requests.Session()
    s.headers.update({
        "User-Agent": UA,
        "Accept-Language": "es-ES,es;q=0.9",
        "Referer": BASE + "/",
    })
    return s


_SESSION = _session()


# ── URLs ──────────────────────────────────────────────────────────────────────

def analizar_url(url: str) -> dict | None:
    """{"tipo", "slug", "capitulo" (str o None)} si es una URL de lectorxd.com
    de una serie o un capítulo; None si no. Solo acepta ese dominio."""
    m = _RE_URL.match((url or "").strip())
    if not m:
        return None
    return {"tipo": m.group(1).lower(), "slug": m.group(2).lower(), "capitulo": m.group(3)}


def url_serie(tipo: str, slug: str) -> str:
    return f"{BASE}/{tipo}/{slug}"


def url_capitulo(tipo: str, slug: str, capitulo: str) -> str:
    return f"{BASE}/{tipo}/{slug}/leer/{capitulo}"


def es_imagen_del_cdn(url: str) -> bool:
    try:
        p = urlsplit(url)
    except ValueError:
        return False
    return p.scheme == "https" and bool(_RE_CDN.match(p.hostname or "")) and not p.port


# ── Parseo (funciones puras, testeables con HTML guardado) ────────────────────

def _breadcrumb_titulo(html: str) -> str:
    for bloque in re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S):
        try:
            data = json.loads(bloque)
        except ValueError:
            continue
        if data.get("@type") != "BreadcrumbList":
            continue
        for item in data.get("itemListElement", []):
            if item.get("position") == 3 and item.get("name"):
                return item["name"].strip()
    return ""


def parsear_capitulo(html: str, url: str) -> dict:
    """{"titulo", "capitulo", "paginas": [urls], "siguiente": url|None}."""
    info = analizar_url(url) or {}
    paginas = []
    for tag in re.findall(r"<img\b[^>]*>", html):
        if "page-image" not in tag:
            continue
        m = re.search(r'data-original-src="([^"]+)"', tag) or re.search(r'data-src="([^"]+)"', tag)
        if m:
            src = html_lib.unescape(m.group(1))
            if es_imagen_del_cdn(src) and src not in paginas:
                paginas.append(src)
    siguiente = None
    m = re.search(r'const\s+nextChapterUrl\s*=\s*"([^"]+)"', html)
    if m:
        candidata = urljoin(BASE, html_lib.unescape(m.group(1)))
        if analizar_url(candidata):
            siguiente = candidata
    titulo = _breadcrumb_titulo(html) or (info.get("slug") or "").replace("-", " ").title()
    return {
        "titulo": titulo,
        "capitulo": info.get("capitulo") or "",
        "paginas": paginas,
        "siguiente": siguiente,
    }


def _desempacar(valor):
    """Las props de Astro vienen como [tipo, valor] (0 = valor, 1 = lista)."""
    if isinstance(valor, list) and len(valor) == 2 and isinstance(valor[0], int):
        tipo, v = valor
        if tipo == 1 and isinstance(v, list):
            return [_desempacar(x) for x in v]
        return _desempacar(v)
    if isinstance(valor, dict):
        return {k: _desempacar(v) for k, v in valor.items()}
    return valor


def parsear_catalogo(html: str) -> dict:
    """{"items": [...], "total"} desde las props de la isla CatalogGrid."""
    for attrs in re.findall(r"<astro-island\b([^>]*)>", html):
        if "CatalogGrid" not in attrs:
            continue
        m = re.search(r'props="([^"]*)"', attrs)
        if not m:
            continue
        try:
            props = _desempacar(json.loads(html_lib.unescape(m.group(1))))
        except ValueError:
            continue
        items = []
        for x in props.get("initialMangas") or []:
            tipo = (x.get("type") or "manga").lower()
            slug = x.get("slug") or ""
            if tipo not in TIPOS or not slug:
                continue
            items.append({
                "slug": url_serie(tipo, slug),
                "titulo": x.get("title") or slug,
                "poster": x.get("coverImage") or "",
                "tipo": tipo,
                "estado": x.get("status") or "",
                "adulto": bool(x.get("adult")),
                "year": x.get("releaseYear") or "",
                "generos": [t.get("tag", {}).get("name", "") for t in (x.get("tags") or [])
                            if isinstance(t, dict)][:8],
                "sinopsis": (x.get("description") or "")[:600],
            })
        return {"items": items, "total": int(props.get("initialTotal") or len(items))}
    return {"items": [], "total": 0}


def capitulos_de_la_ficha(html: str, tipo: str, slug: str) -> list[str]:
    """Números de capítulo enlazados desde la ficha de la serie, ordenados."""
    patron = re.compile(rf"/{re.escape(tipo)}/{re.escape(slug)}/leer/(\d+(?:\.\d+)?)", re.IGNORECASE)
    numeros = {m.group(1) for m in patron.finditer(html)}
    return sorted(numeros, key=float)


# ── Red ───────────────────────────────────────────────────────────────────────

def _get_html(url: str) -> str:
    r = _SESSION.get(url, timeout=30)
    r.raise_for_status()
    return r.text


def buscar(query: str = "", page: int = 1) -> dict:
    params = {"filters": "true", "page": max(1, int(page))}
    if query:
        params["search"] = query
    else:
        params["sort"] = "updated"
    datos = parsear_catalogo(_get_html(f"{BASE}/catalogo?{urlencode(params)}"))
    por_pagina = max(len(datos["items"]), 1)
    paginas = max(1, -(-datos["total"] // por_pagina))
    return {"items": datos["items"], "total": datos["total"], "page": page, "pages": paginas}


def detalle(url: str) -> dict | None:
    """Datos de la serie y el primer capítulo desde donde descargar."""
    info = analizar_url(url)
    if not info:
        return None
    tipo, slug = info["tipo"], info["slug"]
    capitulos = []
    if not info["capitulo"]:
        try:
            capitulos = capitulos_de_la_ficha(_get_html(url_serie(tipo, slug)), tipo, slug)
        except requests.RequestException as e:
            logger.warning("No se pudo leer la ficha de %s: %s", slug, e)
    inicio = info["capitulo"] or (capitulos[0] if capitulos else "1")
    primer = parsear_capitulo(_get_html(url_capitulo(tipo, slug, inicio)), url_capitulo(tipo, slug, inicio))
    if not primer["paginas"]:
        return None
    return {
        "titulo": primer["titulo"],
        "tipo": tipo,
        "slug": slug,
        "source_url": url_serie(tipo, slug),
        "capitulo_inicio": inicio,
        "capitulos_en_ficha": capitulos,
        "paginas_primer_capitulo": len(primer["paginas"]),
        "poster": primer["paginas"][0],
    }


def _descargar_imagen(url: str, dest: str) -> bool:
    if not es_imagen_del_cdn(url):
        return False
    try:
        r = _SESSION.get(url, timeout=30)
        r.raise_for_status()
        if not r.headers.get("Content-Type", "image").startswith("image"):
            return False
        tmp = dest + ".part"
        with open(tmp, "wb") as f:
            f.write(r.content)
        os.replace(tmp, dest)
        return True
    except Exception as e:
        logger.warning("Fallo descargando imagen %s: %s", url, e)
        return False


# ── Descarga de una serie (en segundo plano) ─────────────────────────────────

def nombre_carpeta(titulo: str, capitulo: str) -> str:
    return sanitize_folder_name(f"{titulo} - Capítulo {capitulo}")


def _guardar_capitulo(meta_serie: dict, capitulo: str, paginas: list[str], job: dict) -> bool:
    """Descarga un capítulo como un manga de la biblioteca. Idempotente: las
    páginas que ya están en disco no se vuelven a bajar."""
    nombre = nombre_carpeta(meta_serie["titulo"], capitulo)
    seccion = "cortos" if len(paginas) < Config.UMBRAL_CORTOS else "largos"
    carpeta = os.path.join(Config.MANGA_CONTENT_DIRS[seccion], nombre)
    os.makedirs(carpeta, exist_ok=True)
    primera, ok = None, 0
    for i, url in enumerate(paginas, start=1):
        if job.get("cancelado"):
            return False
        ext = os.path.splitext(urlsplit(url).path)[1] or ".webp"
        dest = os.path.join(carpeta, f"{i:03d}{ext}")
        if os.path.exists(dest) or _descargar_imagen(url, dest):
            ok += 1
            primera = primera or dest
        time.sleep(PAUSA_ENTRE_IMAGENES)
    path = os.path.join(carpeta, "metadata.json")
    existente = load_json(path, {})
    try:
        orden = float(capitulo)
    except ValueError:
        orden = None
    existente.update({
        "title": f"{meta_serie['titulo']} - Capítulo {capitulo}",
        "type": meta_serie.get("tipo", "manga"),
        "languageLocalname": "español",
        "serie": meta_serie["titulo"],
        "serie_orden": orden,
        "source": "lectorxd",
        "source_url": url_capitulo(meta_serie["tipo"], meta_serie["slug"], capitulo),
        "paginas_total": len(paginas),
    })
    save_json(path, existente)
    preview_dir = Config.MANGA_PREVIEW_DIRS[seccion]
    os.makedirs(preview_dir, exist_ok=True)
    preview = os.path.join(preview_dir, f"{nombre}.jpg")
    if primera and not os.path.exists(preview):
        preview_desde_imagen(primera, preview)
    job["paginas_ok"] += ok
    job["paginas_total"] += len(paginas)
    return ok == len(paginas)


_JOBS: dict[str, dict] = {}
_JOBS_LOCK = threading.Lock()


def _punto_de_partida(meta: dict, desde: float | None) -> str:
    """Si piden empezar más adelante, se salta directo a ese capítulo en vez de
    recorrer la cadena desde el primero (siempre que figure en la ficha)."""
    inicio = meta["capitulo_inicio"]
    if desde is None:
        return inicio
    for num in meta.get("capitulos_en_ficha") or []:
        try:
            if float(num) == desde:
                return num
        except ValueError:
            continue
    return inicio


def _correr(job: dict, meta: dict, desde: float | None, hasta: float | None) -> None:
    url = url_capitulo(meta["tipo"], meta["slug"], _punto_de_partida(meta, desde))
    vistos = set()
    try:
        while url and len(vistos) < MAX_CAPITULOS_POR_DESCARGA and not job["cancelado"]:
            if url in vistos:
                break
            vistos.add(url)
            cap = parsear_capitulo(_get_html(url), url)
            num = cap["capitulo"]
            try:
                valor = float(num)
            except ValueError:
                valor = None
            if hasta is not None and valor is not None and valor > hasta:
                break
            if (desde is None or valor is None or valor >= desde) and cap["paginas"]:
                job["capitulo_actual"] = num
                if _guardar_capitulo(meta, num, cap["paginas"], job):
                    job["capitulos_ok"].append(num)
                elif not job["cancelado"]:
                    job["capitulos_fallidos"].append(num)
            url = cap["siguiente"]
        job["estado"] = "cancelado" if job["cancelado"] else "completado"
    except Exception as e:
        logger.exception("Error descargando %s de lectorxd", meta.get("slug"))
        job["estado"] = "error"
        job["error"] = str(e)[:300]
    finally:
        job["capitulo_actual"] = None
        job["fin"] = time.time()
        invalidate_cache("manga_list_")
        invalidate_cache("all_tags")


def iniciar_descarga(url: str, desde: float | None = None, hasta: float | None = None) -> dict:
    """Arranca la descarga de una serie (o desde un capítulo) en segundo plano."""
    meta = detalle(url)
    if not meta:
        raise ValueError("No se encontró la serie o el capítulo")
    job = {
        "id": uuid.uuid4().hex[:12], "titulo": meta["titulo"], "estado": "descargando",
        "inicio": time.time(), "fin": None, "capitulo_actual": None,
        "capitulos_ok": [], "capitulos_fallidos": [], "paginas_ok": 0,
        "paginas_total": 0, "cancelado": False, "error": "",
    }
    with _JOBS_LOCK:
        _JOBS[job["id"]] = job
    threading.Thread(target=_correr, args=(job, meta, desde, hasta),
                     name=f"lectorxd-{job['id']}", daemon=True).start()
    return job


def _copia(job: dict) -> dict:
    # Copia con listas propias: el hilo de descarga sigue agregando capítulos.
    c = dict(job)
    c["capitulos_ok"] = list(job["capitulos_ok"])
    c["capitulos_fallidos"] = list(job["capitulos_fallidos"])
    return c


def estado(job_id: str | None = None) -> list[dict] | dict | None:
    with _JOBS_LOCK:
        if job_id:
            job = _JOBS.get(job_id)
            return _copia(job) if job else None
        return sorted((_copia(j) for j in _JOBS.values()), key=lambda j: j["inicio"], reverse=True)


def cancelar(job_id: str) -> bool:
    with _JOBS_LOCK:
        job = _JOBS.get(job_id)
        if not job or job["estado"] != "descargando":
            return False
        job["cancelado"] = True
        return True
