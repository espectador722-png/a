# routes/descargas.py — descarga de galerías de imágenes (hitomi, 3hentai)
# a la biblioteca de manga. Solo admins (ver routes/auth.py).
import ipaddress
import logging
from urllib.parse import urlsplit

from flask import Blueprint, jsonify, request, render_template, Response

from routes import scraper_3hentai
from routes import scraper_hitomi

logger = logging.getLogger(__name__)
descargas_bp = Blueprint("descargas", __name__)


# ── Página ──────────────────────────────────────────────────────────────────────

@descargas_bp.route("/descargas")
@descargas_bp.route("/descargas.html")
def pagina_descargas():
    return render_template("descargas.html")


# ── Proxy de imágenes ───────────────────────────────────────────────────────────
# hitomi.la rechaza (404) los pedidos de imagen cuyo Referer no sea el propio
# sitio — un <img>/CSS background del navegador manda como Referer nuestro
# dominio (localhost:5000), así que las miniaturas quedan en blanco a menos
# que las pasemos por este proxy, que sí manda el Referer correcto.
_HITOMI_CDN = "gold-usergeneratedcontent.net"


def _es_url_cdn_hitomi(url: str) -> bool:
    """True solo si la URL apunta por HTTPS a un subdominio del CDN de hitomi.
    Antes se buscaba el dominio como subcadena en toda la URL, así que
    https://otro-sitio/?gold-usergeneratedcontent.net pasaba y el proxy
    podía usarse para pedir cualquier URL (SSRF)."""
    try:
        partes = urlsplit(url)
    except ValueError:
        return False
    host = (partes.hostname or "").lower()
    if partes.scheme != "https" or partes.username or partes.password or partes.port not in (None, 443):
        return False
    try:
        ipaddress.ip_address(host)
        return False
    except ValueError:
        pass
    return host == _HITOMI_CDN or host.endswith("." + _HITOMI_CDN)


@descargas_bp.route("/api/descargas/hitomi/imagen")
def api_hitomi_imagen():
    url = request.args.get("url", "")
    if not _es_url_cdn_hitomi(url):
        return "", 400
    try:
        r = scraper_hitomi.proxy_imagen(url)
        # hitomi a veces devuelve 404 (hash de miniatura vencido/subdominio
        # equivocado) con un body HTML de error — reenviarlo tal cual con
        # status 200 (bug real: pasaba antes) hacía que el navegador recibiera
        # "una imagen" con Content-Type text/html, que <img> no puede
        # decodificar y queda en gris silenciosamente, sin ningún error
        # visible en Network. Propagar el status real deja que el <img> dispare
        # su evento onerror en vez de fallar mudo.
        if r.status_code != 200:
            return "", r.status_code
        return Response(r.content, mimetype=r.headers.get("Content-Type", "image/webp"),
                         headers={"Cache-Control": "public, max-age=86400"})
    except Exception as e:
        logger.warning("Error en proxy de imagen hitomi: %s", e)
        return "", 502


# ── Scraping ────────────────────────────────────────────────────────────────────

@descargas_bp.route("/api/descargas/hitomi/sugerencias")
def api_hitomi_sugerencias():
    """Autocompletado de tags para el buscador de hitomi (dropdown al escribir)."""
    q = request.args.get("q", "").strip()
    if not q:
        return jsonify({"items": []})
    # Solo autocompletar el último término que se está escribiendo (los
    # anteriores, separados por espacio, ya están "cerrados").
    ultimo_termino = q.split(" ")[-1]
    negativo = ultimo_termino.startswith("-")
    if negativo:
        ultimo_termino = ultimo_termino[1:]
    if not ultimo_termino:
        return jsonify({"items": []})
    try:
        items = scraper_hitomi.sugerencias(ultimo_termino)
        return jsonify({"items": items, "negativo": negativo})
    except Exception as e:
        logger.warning("Error en sugerencias hitomi: %s", e)
        return jsonify({"items": []})


@descargas_bp.route("/api/descargas/buscar")
def api_buscar():
    site = request.args.get("site", "hitomi")
    if site == "3hentai":
        q = request.args.get("q", "").strip()
        try:
            page = max(1, int(request.args.get("page", 1)))
        except (ValueError, TypeError):
            page = 1
        try:
            return jsonify(scraper_3hentai.buscar(query=q, page=page))
        except Exception as e:
            logger.exception("Error en buscar (3hentai)")
            return jsonify({"error": str(e), "items": [], "total": 0, "pages": 1, "page": 1}), 200
    if site == "hitomi":
        q = request.args.get("q", "").strip()
        try:
            page = max(1, int(request.args.get("page", 1)))
        except (ValueError, TypeError):
            page = 1
        languages = [lang for lang in request.args.getlist("language") if lang.strip()]
        if not q and not languages:
            return jsonify({"error": "escribí un tag para buscar (ej: artist:nombre, female:milf, milf)",
                            "items": [], "total": 0, "pages": 1, "page": 1}), 200
        sort_pop = request.args.get("orden") == "popularidad"
        min_pages = request.args.get("min_pages", "").strip()
        max_pages = request.args.get("max_pages", "").strip()
        try:
            min_pages = int(min_pages) if min_pages else None
        except ValueError:
            min_pages = None
        try:
            max_pages = int(max_pages) if max_pages else None
        except ValueError:
            max_pages = None
        modo_or = request.args.get("modo") == "or"
        try:
            return jsonify(scraper_hitomi.buscar_por_tag(
                q, page=page, sort_by_popularity=sort_pop,
                min_pages=min_pages, max_pages=max_pages, languages=languages,
                modo_or=modo_or))
        except Exception as e:
            logger.exception("Error en buscar (hitomi)")
            return jsonify({"error": str(e), "items": [], "total": 0, "pages": 1, "page": 1}), 200
    return jsonify({"error": "sitio no soportado", "items": [],
                    "total": 0, "pages": 1, "page": 1}), 400


@descargas_bp.route("/api/descargas/detalle")
def api_detalle():
    site = request.args.get("site", "hitomi")
    slug = request.args.get("slug", "").strip()
    if not slug:
        return jsonify({"error": "slug requerido"}), 400
    if site not in ("3hentai", "hitomi"):
        return jsonify({"error": "sitio no soportado"}), 400
    try:
        if site == "3hentai":
            d = scraper_3hentai.detalle(slug)
        else:
            d = scraper_hitomi.detalle(slug)
        if not d:
            return jsonify({"error": "no encontrado"}), 404
        return jsonify(d)
    except Exception as e:
        logger.exception("Error en detalle")
        return jsonify({"error": str(e)}), 500


@descargas_bp.route("/api/descargas/3hentai/existe")
def api_3hentai_existe():
    """
    Verifica si una galería ya fue descargada (por título normalizado) antes
    de encolar la descarga. Body/query: slug o url.
    """
    slug = request.args.get("slug", "").strip()
    if not slug:
        return jsonify({"error": "slug o url requerido"}), 400
    try:
        meta = scraper_3hentai.detalle(slug)
        if not meta:
            return jsonify({"error": "galería no encontrada"}), 404
        existente = scraper_3hentai.buscar_existente(meta.get("titulo", ""))
        return jsonify({"existe": bool(existente), "manga": existente, "titulo": meta.get("titulo", "")})
    except Exception as e:
        logger.exception("Error verificando existencia 3hentai")
        return jsonify({"error": str(e)}), 500


@descargas_bp.route("/api/descargas/3hentai/descargar", methods=["POST"])
def api_3hentai_descargar():
    """
    Descarga una galería completa de 3hentai.net de forma síncrona (son
    imágenes, no hay cola de video que justifique background): crea la
    carpeta en Mangas Largos/Cortos (según cantidad de páginas), guarda
    todas las páginas, tags y preview.
    Body: {"slug": "609191"} o {"url": "https://es.3hentai.net/d/609191"}
    Si el título ya existe en la biblioteca, no descarga y avisa (a menos
    que se mande "forzar": true).
    """
    data = request.json or {}
    slug = (data.get("slug") or data.get("url") or "").strip()
    forzar = bool(data.get("forzar"))
    if not slug:
        return jsonify({"success": False, "error": "slug o url requerido"}), 400
    try:
        meta = scraper_3hentai.detalle(slug)
        if not meta:
            return jsonify({"success": False, "error": "galería no encontrada"}), 404

        if not forzar:
            existente = scraper_3hentai.buscar_existente(meta.get("titulo", ""))
            if existente:
                return jsonify({
                    "success": False, "ya_existe": True,
                    "manga": existente, "titulo": meta.get("titulo", ""),
                    "error": "Este manga ya está en la biblioteca",
                }), 200

        info = scraper_3hentai.descargar_galeria(slug, meta)
        from routes.helpers import invalidate_cache
        invalidate_cache("all_tags")
        return jsonify({"success": True, **info, "titulo": meta.get("titulo", "")})
    except Exception as e:
        logger.exception("Error descargando galería 3hentai")
        return jsonify({"success": False, "error": str(e)}), 500


@descargas_bp.route("/api/descargas/hitomi/existe")
def api_hitomi_existe():
    slug = request.args.get("slug", "").strip()
    if not slug:
        return jsonify({"error": "slug o url requerido"}), 400
    try:
        meta = scraper_hitomi.detalle(slug)
        if not meta:
            return jsonify({"error": "galería no encontrada"}), 404
        existente = scraper_hitomi.buscar_existente(meta.get("titulo", ""))
        return jsonify({"existe": bool(existente), "manga": existente, "titulo": meta.get("titulo", "")})
    except Exception as e:
        logger.exception("Error verificando existencia hitomi")
        return jsonify({"error": str(e)}), 500


@descargas_bp.route("/api/descargas/hitomi/descargar", methods=["POST"])
def api_hitomi_descargar():
    """
    Descarga una galería completa de hitomi.la. Body: {"slug": "123456"} o
    {"url": "https://hitomi.la/galleries/123456.html"}.
    """
    data = request.json or {}
    slug = (data.get("slug") or data.get("url") or "").strip()
    forzar = bool(data.get("forzar"))
    if not slug:
        return jsonify({"success": False, "error": "slug o url requerido"}), 400
    try:
        meta = scraper_hitomi.detalle(slug)
        if not meta:
            return jsonify({"success": False, "error": "galería no encontrada"}), 404

        if not forzar:
            existente = scraper_hitomi.buscar_existente(meta.get("titulo", ""))
            if existente:
                return jsonify({
                    "success": False, "ya_existe": True,
                    "manga": existente, "titulo": meta.get("titulo", ""),
                    "error": "Este manga ya está en la biblioteca",
                }), 200

        info = scraper_hitomi.descargar_galeria(slug, meta)
        from routes.helpers import invalidate_cache
        invalidate_cache("all_tags")
        return jsonify({"success": True, **info, "titulo": meta.get("titulo", "")})
    except Exception as e:
        logger.exception("Error descargando galería hitomi")
        return jsonify({"success": False, "error": str(e)}), 500
