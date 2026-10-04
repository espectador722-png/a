# routes/manga.py
import os
import re
import json
import shutil
import logging
import unicodedata
from difflib import SequenceMatcher
from datetime import datetime
from flask import (
    Blueprint, jsonify, request, send_from_directory,
    render_template, redirect, g, has_request_context,
)
from config import Config
from routes import categorias
from routes import colecciones
from routes import favoritos
from routes import progreso
from routes import indice
from routes.helpers import (
    get_cached, invalidate_cache, find_content_dir, load_json, save_json,
    list_previews, move_content_with_preview, safe_basename,
)

logger = logging.getLogger(__name__)
manga_bp = Blueprint("manga", __name__)

# Secciones (categorías base) — antes hardcodeadas acá, ahora vienen de
# categorias.json (routes/categorias.py) y pueden renombrarse/agregarse/
# eliminarse desde /api/categorias/manga. Este dict se mantiene con la MISMA
# forma de siempre ({id: (content_dir, preview_dir)}) para no tocar el resto
# del archivo — solo cambia de dónde sale el contenido.
MANGA_SECTION_DIRS: dict[str, tuple[str, str]] = {}


def _reload_section_dirs(_dirs=None) -> None:
    dirs = categorias.get_section_dirs("manga")
    Config.MANGA_CONTENT_DIRS = {k: v[0] for k, v in dirs.items()}
    Config.MANGA_PREVIEW_DIRS = {k: v[1] for k, v in dirs.items()}
    MANGA_SECTION_DIRS.clear()
    MANGA_SECTION_DIRS.update(dirs)


_reload_section_dirs()
categorias.on_change("manga", _reload_section_dirs)

METADATA_FILE = "metadata.json"


# ── Helpers de tags ───────────────────────────────────────────────────────────

def _tag_name(t) -> str:
    if isinstance(t, dict):
        return t.get("tag", "")
    return str(t)


def _tags_as_names(tags: list) -> list[str]:
    return [n for t in tags if (n := _tag_name(t).strip())]


def _tags_with_ns(tags: list) -> list[dict]:
    """Tags con su marca de género de Hitomi (female/male), para que el front
    los clasifique como female:/male:/tag: sin perderla en la lista."""
    return [
        {
            "tag": n,
            "female": 1 if isinstance(t, dict) and t.get("female") else 0,
            "male": 1 if isinstance(t, dict) and t.get("male") else 0,
        }
        for t in tags if (n := _tag_name(t).strip())
    ]


# ── Helpers de metadata por manga ─────────────────────────────────────────────

def _manga_metadata_path(manga_name: str) -> str | None:
    ruta = find_content_dir(Config.get_all_manga_dirs(), manga_name)
    if not ruta:
        return None
    return os.path.join(ruta, METADATA_FILE)


def _get_manga_metadata(manga_name: str) -> dict:
    path = _manga_metadata_path(manga_name)
    if not path:
        return {}
    return load_json(path, {})


def _save_manga_metadata(manga_name: str, data: dict) -> bool:
    path = _manga_metadata_path(manga_name)
    if not path:
        return False
    data.setdefault("tags", [])
    ok = save_json(path, data)
    if ok:
        invalidate_cache("all_tags")
    return ok


def _get_all_tags(with_counts: bool = False):
    """
    Recopila todos los tags únicos de todos los mangas.
    Si with_counts=True devuelve lista de {tag, count} ordenada por frecuencia.
    Si with_counts=False devuelve lista de strings (compatibilidad hacia atrás).
    """
    def _fetch_names():
        tags: set[str] = set()
        for base_dir in Config.get_all_manga_dirs():
            if not os.path.exists(base_dir):
                continue
            for manga in os.listdir(base_dir):
                meta_path = os.path.join(base_dir, manga, METADATA_FILE)
                if os.path.exists(meta_path):
                    meta = load_json(meta_path, {})
                    tags.update(_tags_as_names(meta.get("tags", [])))
        return sorted(tags)

    def _fetch_counts():
        counts: dict[str, int] = {}
        for base_dir in Config.get_all_manga_dirs():
            if not os.path.exists(base_dir):
                continue
            for manga in os.listdir(base_dir):
                meta_path = os.path.join(base_dir, manga, METADATA_FILE)
                if os.path.exists(meta_path):
                    meta = load_json(meta_path, {})
                    for t in _tags_as_names(meta.get("tags", [])):
                        counts[t] = counts.get(t, 0) + 1
        return sorted(
            [{"tag": t, "count": c} for t, c in counts.items()],
            key=lambda x: (-x["count"], x["tag"]),
        )

    if with_counts:
        return get_cached("all_tags_counts", _fetch_counts, ttl=Config.CACHE_TTL_MEDIUM)
    return get_cached("all_tags", _fetch_names, ttl=Config.CACHE_TTL_MEDIUM)


# ── Helper: detectar nombre base y capítulo ────────────────────────────────────

# Decoradores típicos en nombres: [grupo scan], (evento), {…}
# Se preservan [N] y (N) con número corto porque pueden ser el capítulo.
_BRACKET_RE = re.compile(r'(\[(?!\d{1,3}(?:\.\d+)?\])[^\]]*\]|\{[^}]*\})')
_PAREN_NON_CHAPTER_RE = re.compile(r'\((?!\d{1,3}(?:\.\d+)?\))[^)]*\)')

# Sufijos sueltos que no forman parte del título (fuera de corchetes)
_TRAILING_JUNK_RE = re.compile(
    r'\s*(?:MTL|decensored|uncensored|sin\s+censura)\s*$', re.IGNORECASE
)

def _strip_decorations(name: str) -> str:
    """Quita [grupos], {tags}, (notas) y sufijos tipo MTL/decensored."""
    cleaned = _BRACKET_RE.sub(' ', name)
    cleaned = _PAREN_NON_CHAPTER_RE.sub(' ', cleaned)
    for _ in range(3):
        nuevo = _TRAILING_JUNK_RE.sub('', cleaned)
        if nuevo == cleaned:
            break
        cleaned = nuevo
    cleaned = re.sub(r'\s+', ' ', cleaned).strip(' -_~·.')
    return cleaned if cleaned else name.strip()


def _norm_key(s: str) -> str:
    """
    Clave de agrupación tolerante a diferencias menores:
    sin tildes, sin mayúsculas, sin puntuación, espacios colapsados.
    """
    s = unicodedata.normalize('NFKD', s)
    s = ''.join(c for c in s if not unicodedata.combining(c))
    s = s.casefold()
    s = re.sub(r'[^a-z0-9]+', ' ', s)
    return s.strip()


# Palabras que marcan capítulo/parte/volumen (es/en)
_CHAP_WORD = r'(?:cap(?:[íi]tulo)?|ch(?:apter)?|chap|episodio|ep|parte|part|pt|vol(?:umen)?|tomo)'

# Patrones para detectar capítulos: "Obra - Cap 3", "Obra Ch.5", "Obra #2", etc.
# Se aplican sobre el nombre ya sin decoraciones.
_CHAPTER_PATTERNS = [
    # "Obra - Cap 3", "Obra: Chapter 5", "Obra parte 2", "Obra Vol. 3"
    re.compile(rf'^(.+?)(?:\s*[-–—:~]\s*|\s+){_CHAP_WORD}\.?\s*(\d+(?:\.\d+)?)\s*$', re.IGNORECASE),
    # "Obra #2" / "Obra - #2"
    re.compile(r'^(.+?)\s*[-–—:~]?\s*#(\d+(?:\.\d+)?)\s*$'),
    # "Obra [2]"
    re.compile(r'^(.+?)\s*\[(\d+(?:\.\d+)?)\]\s*$'),
    # "Obra (2)"  — 1-3 dígitos para no confundir con años tipo (2020)
    re.compile(r'^(.+?)\s*\((\d{1,3}(?:\.\d+)?)\)\s*$'),
    # "Obra 1-7"  — rango de compilación: agrupa por el capítulo inicial
    re.compile(r'^(.+?)\s+(\d{1,3})\s*[-–~]\s*\d{1,3}\s*$'),
    # "Obra - 2"  — separador explícito, 1-3 dígitos
    re.compile(r'^(.+?)\s*[-–—~]\s*(\d{1,3}(?:\.\d+)?)\s*$'),
    # "Obra 2"    — número al final sin separador (el requisito de ≥2 capítulos
    #               evita que títulos que terminan en número formen series falsas)
    re.compile(r'^(.+?)\s+(\d{1,3}(?:\.\d+)?)\s*$'),
]

def _parse_chapter(name: str) -> tuple[str, str | None]:
    """
    Intenta extraer la serie base y el número de capítulo.
    Retorna (base, chapter_str) o (nombre_limpio, None) si no se detecta.
    """
    cleaned = _strip_decorations(name)
    for pat in _CHAPTER_PATTERNS:
        m = pat.match(cleaned)
        if m:
            base = m.group(1).strip(' -_~·.:')
            chap = m.group(2).strip()
            if base and _norm_key(base):
                return base, chap
    return cleaned, None


def _build_manga_item(nombre: str, section: str, archivo: str, meta: dict) -> dict:
    """Construye el dict estándar de un manga para la lista."""
    return {
        "nombre":            nombre,
        "preview":           f"/get_manga_preview/{section}/{archivo}",
        "tipo":              section,
        "tags":              _tags_as_names(meta.get("tags", [])),
        "tags_ns":           _tags_with_ns(meta.get("tags", [])),
        "num_tags":          len(_tags_as_names(meta.get("tags", []))),
        # El progreso es de cada usuario: lo completa _con_progreso_del_usuario
        "paginas_leidas":    0,
        "paginas_max":       0,
        "paginas_total":     meta.get("paginas_total", 0),
        "paginas_traducidas": meta.get("paginas_traducidas", 0),
        "idioma_traducido":  meta.get("idioma_traducido", ""),
        "ultima_lectura":    "",
        "title":             meta.get("title", ""),
        "fecha_clasificado": meta.get("fecha_clasificado", ""),
        "date":              str(meta.get("date", ""))[:10],
        "artists":           meta.get("artists", []),
        "groups":            meta.get("groups", []),
        "parodys":           meta.get("parodys", []),
        "characters":        meta.get("characters", []),
        "language":          meta.get("language", ""),
        "type":              meta.get("type", ""),
        "id":                meta.get("id", None),
        # Override manual de serie (asignado por el usuario)
        "serie":             meta.get("serie", ""),
        "serie_orden":       meta.get("serie_orden", None),
        "serie_excluir":     bool(meta.get("serie_excluir", False)),
    }


def _get_manga_list(section: str) -> list[dict]:
    """
    Lista completa (cacheada) de una sección. Única fuente de verdad para
    manga_list, buscar y series — evita cachear listas vacías por error.
    """
    base_dir, preview_dir = MANGA_SECTION_DIRS[section]

    def _fetch():
        result = []
        existing_dirs: dict[str, str] = {}
        if os.path.exists(base_dir):
            for entry in os.scandir(base_dir):
                if entry.is_dir():
                    existing_dirs[entry.name.lower()] = entry.path

        for archivo in list_previews(preview_dir, Config.PREVIEW_EXTENSIONS):
            nombre = os.path.splitext(archivo)[0]
            meta = {}
            manga_path = existing_dirs.get(nombre.lower())
            if manga_path and os.path.isdir(manga_path):
                meta = load_json(os.path.join(manga_path, METADATA_FILE), {})
            result.append(_build_manga_item(nombre, section, archivo, meta))

        logger.info("Mangas %s: %d ítems", section, len(result))
        return result

    return _con_progreso_del_usuario(
        get_cached(f"manga_list_{section}", _fetch, ttl=Config.CACHE_TTL_SHORT)
    )


def _progreso_actual() -> dict[str, dict]:
    """Progreso del usuario de este request (leído una vez por request)."""
    if not has_request_context() or not getattr(g, "usuario", None):
        return {}
    if "progreso" not in g:
        g.progreso = progreso.de_usuario(g.usuario["usuario"])
    return g.progreso


def _con_progreso_del_usuario(items: list[dict]) -> list[dict]:
    """Copia de la lista con paginas_leidas/paginas_max/ultima_lectura del
    usuario actual. Solo se copian los ítems que tienen progreso; el resto se
    comparte con el caché (que nunca se modifica)."""
    prog = _progreso_actual()
    if not prog:
        return items
    out = []
    for m in items:
        p = prog.get(m["nombre"].lower())
        if p:
            m = {
                **m,
                "paginas_leidas": p.get("pagina", 0),
                "paginas_max":    max(p.get("max", 0), p.get("pagina", 0)),
                "ultima_lectura": p.get("last_read", ""),
            }
        out.append(m)
    return out


def _lista_mis_favoritos() -> list[dict]:
    """Favoritos personales del usuario actual, buscados en todas las
    secciones. Cada ítem conserva su sección real en "tipo" (las URLs de
    páginas y previews dependen de ella)."""
    favs = {n.lower() for n in favoritos.nombres(g.usuario["usuario"])}
    if not favs:
        return []
    items, vistos = [], set()
    for section in MANGA_SECTION_DIRS:
        for m in _get_manga_list(section):
            clave = m["nombre"].lower()
            if clave in favs and clave not in vistos:
                vistos.add(clave)
                items.append(m)
    return items


def _lista_de_seccion(section: str) -> list[dict] | None:
    """Ítems de la grilla de una sección (o de los favoritos del usuario).
    None si la sección no existe."""
    if section == favoritos.SECCION:
        return _lista_mis_favoritos()
    if section not in MANGA_SECTION_DIRS:
        return None
    return _ocultar_archivados_en_carpeta(_get_manga_list(section), section)


def _ocultar_archivados_en_carpeta(items: list[dict], section: str) -> list[dict]:
    """
    Oculta de la grilla de sección los mangas que están archivados en ≥1
    carpeta de usuario (colección) — reduce ruido visual. No se aplica a la
    carpeta Favoritos (comportamiento de siempre), a los favoritos personales,
    a búsqueda global, recomendaciones, historial ni a la vista de la propia
    colección — solo a la grilla plana de una sección.
    """
    if section == "favoritos":
        return items
    ocultos = set(colecciones.reverse_index("manga").keys())
    if not ocultos:
        return items
    return [m for m in items if m["nombre"].lower() not in ocultos]


# ── Páginas ───────────────────────────────────────────────────────────────────

@manga_bp.route("/manga")
@manga_bp.route("/manga.html")
def index():
    return render_template("index.html")


@manga_bp.route("/")
def inicio():
    return redirect("/manga")


@manga_bp.route("/manga-sorteo")
@manga_bp.route("/manga-sorteo.html")
def manga_sorteo_page():
    return render_template("manga-sorteo.html")


# ── APIs de listado ───────────────────────────────────────────────────────────

@manga_bp.route("/api/buscar")
def api_buscar_indice():
    """Búsqueda por texto sobre el índice SQLite (routes/indice.py).
    Query: q (texto), limite (1-300, por defecto 60)."""
    q = request.args.get("q", "").strip()
    try:
        limite = max(1, min(300, int(request.args.get("limite", 60))))
    except ValueError:
        limite = 60
    if not q:
        return jsonify({"q": "", "total": 0, "resultados": []})
    resultados = [
        {
            "nombre":  it["nombre"],
            "seccion": it["seccion"],
            "titulo":  it.get("titulo") or it["nombre"],
            "preview": it.get("preview", ""),
        }
        for it in indice.buscar(q, ("manga",), limite)
    ]
    return jsonify({
        "q":          q,
        "total":      len(resultados),
        "resultados": resultados,
        "listo":      indice.estado["ultimo_scan"] > 0,
    })


@manga_bp.route("/api/mangas/<section>")
def manga_list(section):
    """
    Lista mangas de una sección con filtrado, búsqueda y paginación server-side.

    Query params opcionales:
      q          — busca en nombre, title, artists, parodys, characters, tags
      tags       — tags separados por coma (AND lógico)
      sort       — nombre|fecha|paginas|progreso|tags_asc|tags_desc (default: nombre)
      order      — asc|desc (default: asc)
      page       — número de página (default: 1)
      per_page   — ítems por página (default: Config.PREVIEWS_POR_PAGINA; 0 = sin límite)
      tags_min   — filtrar mangas con al menos N tags (int)
      tags_max   — filtrar mangas con como máximo N tags (int); 0 = sin tags

    section puede ser "mis_favoritos": los favoritos del usuario actual.
    """
    # ── Lista completa (cacheada) ─────────────────────────────────────────────
    todos = _lista_de_seccion(section)
    if todos is None:
        return jsonify({"error": "sección inválida"}), 400

    # ── Filtrado ──────────────────────────────────────────────────────────────
    q = request.args.get("q", "").strip().lower()
    tags_filter = [t.strip() for t in request.args.get("tags", "").split(",") if t.strip()]
    sort_by = request.args.get("sort", "nombre")
    order = request.args.get("order", "asc")

    # Filtro por cantidad de tags
    try:
        tags_min = int(request.args.get("tags_min", -1))
    except (ValueError, TypeError):
        tags_min = -1
    try:
        tags_max = int(request.args.get("tags_max", -1))
    except (ValueError, TypeError):
        tags_max = -1

    filtered = todos

    if q:
        def _matches(m):
            fields = [
                m["nombre"].lower(),
                m["title"].lower(),
                " ".join(m["artists"]).lower(),
                " ".join(m["parodys"]).lower(),
                " ".join(m["characters"]).lower(),
                " ".join(m["tags"]).lower(),
            ]
            return any(q in f for f in fields)
        filtered = [m for m in filtered if _matches(m)]

    if tags_filter:
        filtered = [
            m for m in filtered
            if all(t in m["tags"] for t in tags_filter)
        ]

    # Filtro por rango de tags
    if tags_min >= 0:
        filtered = [m for m in filtered if m["num_tags"] >= tags_min]
    if tags_max >= 0:
        filtered = [m for m in filtered if m["num_tags"] <= tags_max]

    # ── Ordenamiento ──────────────────────────────────────────────────────────
    sort_key_map = {
        "nombre":    lambda m: m["nombre"].lower(),
        "fecha":     lambda m: m["fecha_clasificado"] or m["date"] or "",
        "paginas":   lambda m: m["paginas_total"],
        "progreso":  lambda m: (
            m["paginas_leidas"] / m["paginas_total"]
            if m["paginas_total"] else 0
        ),
        "lectura":   lambda m: m["ultima_lectura"] or "",
        "tags_asc":  lambda m: m["num_tags"],
        "tags_desc": lambda m: m["num_tags"],
    }
    key_fn = sort_key_map.get(sort_by, sort_key_map["nombre"])
    # tags_desc invierte el orden por defecto
    reverse = (order == "desc") or (sort_by == "tags_desc")
    if sort_by == "tags_asc":
        reverse = (order == "desc")
    filtered = sorted(filtered, key=key_fn, reverse=reverse)

    # ── Paginación ────────────────────────────────────────────────────────────
    try:
        page = max(1, int(request.args.get("page", 1)))
    except (ValueError, TypeError):
        page = 1
    try:
        per_page = int(request.args.get("per_page", Config.PREVIEWS_POR_PAGINA))
    except (ValueError, TypeError):
        per_page = Config.PREVIEWS_POR_PAGINA

    total = len(filtered)
    if per_page > 0:
        pages = max(1, (total + per_page - 1) // per_page)
        start = (page - 1) * per_page
        paginated = filtered[start: start + per_page]
    else:
        pages = 1
        paginated = filtered

    return jsonify({
        "mangas":   paginated,
        "total":    total,
        "page":     page,
        "pages":    pages,
        "per_page": per_page,
    })


@manga_bp.route("/api/mangas/buscar")
def manga_buscar():
    """
    Búsqueda global a través de todas las secciones.
    Query params: q, tags, sort, order, page, per_page
    """
    q = request.args.get("q", "").strip().lower()
    tags_filter = [t.strip() for t in request.args.get("tags", "").split(",") if t.strip()]
    sections_param = request.args.get("section", "")
    sections = (
        [s for s in sections_param.split(",") if s in MANGA_SECTION_DIRS]
        if sections_param else list(MANGA_SECTION_DIRS.keys())
    )
    sort_by = request.args.get("sort", "nombre")
    order = request.args.get("order", "asc")

    all_mangas = []
    for section in sections:
        all_mangas.extend(_get_manga_list(section))

    if q:
        def _matches(m):
            return any(q in f.lower() for f in [
                m["nombre"], m["title"],
                " ".join(m["artists"]),
                " ".join(m["parodys"]),
                " ".join(m["characters"]),
                " ".join(m["tags"]),
            ])
        all_mangas = [m for m in all_mangas if _matches(m)]

    if tags_filter:
        all_mangas = [m for m in all_mangas if all(t in m["tags"] for t in tags_filter)]

    sort_key_map = {
        "nombre":    lambda m: m["nombre"].lower(),
        "fecha":     lambda m: m["fecha_clasificado"] or m["date"] or "",
        "paginas":   lambda m: m["paginas_total"],
        "lectura":   lambda m: m["ultima_lectura"] or "",
        "tags_asc":  lambda m: m["num_tags"],
        "tags_desc": lambda m: m["num_tags"],
    }
    key_fn = sort_key_map.get(sort_by, sort_key_map["nombre"])
    reverse = (order == "desc") or (sort_by == "tags_desc")
    all_mangas = sorted(all_mangas, key=key_fn, reverse=reverse)

    try:
        page = max(1, int(request.args.get("page", 1)))
        per_page = int(request.args.get("per_page", Config.PREVIEWS_POR_PAGINA))
    except (ValueError, TypeError):
        page, per_page = 1, Config.PREVIEWS_POR_PAGINA

    total = len(all_mangas)
    pages = max(1, (total + per_page - 1) // per_page) if per_page > 0 else 1
    start = (page - 1) * per_page
    paginated = all_mangas[start: start + per_page] if per_page > 0 else all_mangas

    return jsonify({"mangas": paginated, "total": total, "page": page, "pages": pages})


# ── API: Sorteo (manga al azar para decidir si conservar o borrar) ────────────

def _leer_historial_sorteo() -> list[dict]:
    """Lista de {nombre, tipo, fecha} de mangas ya aprobados (conservados) en el sorteo."""
    return load_json(Config.MANGA_SORTEO_HISTORIAL_FILE, [])


def _guardar_historial_sorteo(historial: list[dict]) -> None:
    save_json(Config.MANGA_SORTEO_HISTORIAL_FILE, historial)


@manga_bp.route("/api/manga/random")
def manga_random():
    """
    Devuelve un manga al azar de una o varias secciones, para la página de
    sorteo (/manga-sorteo) donde el usuario decide si lo borra o lo conserva.

    Query params:
      section — secciones separadas por coma (default: todas)
      exclude — nombres separados por coma a excluir (evita repetir en la
                misma sesión de sorteo mientras aún queden otros mangas)
    """
    import random

    sections_param = request.args.get("section", "")
    sections = (
        [s for s in sections_param.split(",") if s in MANGA_SECTION_DIRS]
        if sections_param else list(MANGA_SECTION_DIRS.keys())
    )
    if not sections:
        return jsonify({"error": "sección inválida"}), 400

    excluir = {n.strip().lower() for n in request.args.get("exclude", "").split(",") if n.strip()}
    aprobados = {h["nombre"].lower() for h in _leer_historial_sorteo()}
    excluir |= aprobados

    candidatos = []
    for section in sections:
        candidatos.extend(_ocultar_archivados_en_carpeta(_get_manga_list(section), section))

    disponibles = [m for m in candidatos if m["nombre"].lower() not in excluir]
    if not disponibles:
        # Se acabaron sin repetir en esta sesión — reiniciar el ciclo, pero
        # sin volver a mostrar los ya aprobados (historial persistente).
        disponibles = [m for m in candidatos if m["nombre"].lower() not in aprobados]
    if not disponibles:
        return jsonify({"manga": None, "total": 0})

    elegido = random.choice(disponibles)
    return jsonify({"manga": elegido, "total": len(candidatos)})


# ── API: Historial de aprobados en el sorteo ───────────────────────────────────

@manga_bp.route("/api/manga/sorteo/historial")
def sorteo_historial_get():
    """Lista el historial de mangas aprobados (conservados) en el sorteo, más recientes primero."""
    historial = _leer_historial_sorteo()
    return jsonify({"historial": list(reversed(historial)), "total": len(historial)})


@manga_bp.route("/api/manga/sorteo/historial", methods=["POST"])
def sorteo_historial_add():
    """Registra un manga como aprobado (conservado) para no volver a mostrarlo."""
    data = request.get_json(force=True, silent=True) or {}
    nombre = (data.get("nombre") or "").strip()
    tipo = (data.get("tipo") or "").strip()
    if not nombre:
        return jsonify({"error": "falta nombre"}), 400

    historial = _leer_historial_sorteo()
    historial = [h for h in historial if h["nombre"].lower() != nombre.lower()]
    historial.append({"nombre": nombre, "tipo": tipo, "fecha": datetime.now().isoformat()})
    _guardar_historial_sorteo(historial)
    return jsonify({"success": True, "total": len(historial)})


@manga_bp.route("/api/manga/sorteo/historial/undo", methods=["POST"])
def sorteo_historial_undo():
    """
    Deshace las últimas N aprobaciones del historial (por defecto 1), para que
    vuelvan a aparecer en el sorteo.
    """
    data = request.get_json(force=True, silent=True) or {}
    cantidad = max(1, int(data.get("cantidad", 1)))

    historial = _leer_historial_sorteo()
    deshechos = historial[-cantidad:]
    historial = historial[:-cantidad] if cantidad < len(historial) else []
    _guardar_historial_sorteo(historial)
    return jsonify({"success": True, "deshechos": deshechos, "total": len(historial)})


@manga_bp.route("/api/manga/sorteo/historial/reset", methods=["POST"])
def sorteo_historial_reset():
    """Borra todo el historial de aprobados del sorteo."""
    _guardar_historial_sorteo([])
    return jsonify({"success": True})


# ── API: Agrupación de capítulos ──────────────────────────────────────────────

@manga_bp.route("/api/mangas/<section>/series")
def manga_series(section):
    """
    Detecta mangas que forman parte de la misma serie (por nombre base similar)
    y los agrupa.

    Retorna:
      {
        "series": [
          {
            "base":      "Nombre de la serie",
            "capitulos": [ { ...manga_item..., "capitulo": "1" }, ... ],
            "preview":   "/url/preview_primer_cap",
            "total_capitulos": 3,
            "total_paginas": 456
          }
        ],
        "sueltos": [ ...mangas sin serie... ]
      }
    """
    todos = _lista_de_seccion(section)
    if todos is None:
        return jsonify({"error": "sección inválida"}), 400

    # ── Fase 1: agrupar ───────────────────────────────────────────────────────
    # groups: key → {"base": str, "items": [...], "manual": bool}
    groups: dict[str, dict] = {}
    sueltos = []

    def _add_to_group(key: str, base: str, item: dict, manual: bool = False):
        if key not in groups:
            groups[key] = {"base": base, "items": [], "manual": manual}
        groups[key]["manual"] = groups[key]["manual"] or manual
        groups[key]["items"].append(item)

    for m in todos:
        # 1. Override manual del usuario (metadata "serie")
        if m.get("serie"):
            base, chap = _parse_chapter(m["nombre"])
            orden = m.get("serie_orden")
            capitulo = str(orden) if orden is not None else (chap or "")
            _add_to_group("manual:" + _norm_key(m["serie"]), m["serie"],
                          {**m, "capitulo": capitulo}, manual=True)
            continue

        # 2. Excluido explícitamente de la agrupación automática
        if m.get("serie_excluir"):
            sueltos.append(m)
            continue

        # 3. Detección automática por nombre
        base, chap = _parse_chapter(m["nombre"])
        if chap is not None:
            _add_to_group(_norm_key(base), base, {**m, "capitulo": chap})
        else:
            sueltos.append(m)

    # ── Fase 2: sueltos cuyo nombre coincide con la base de una serie ─────────
    # ("Obra" + "Obra 2" + "Obra 3" → "Obra" es el primer capítulo sin número)
    auto_keys = {k for k in groups if not k.startswith("manual:")}
    restantes = []
    for m in sueltos:
        if m.get("serie_excluir"):
            restantes.append(m)
            continue
        key = _norm_key(_strip_decorations(m["nombre"]))
        if key in auto_keys:
            _add_to_group(key, groups[key]["base"], {**m, "capitulo": ""})
        else:
            restantes.append(m)
    sueltos = restantes

    # ── Fase 3: fusionar grupos automáticos con claves casi idénticas ─────────
    # (tolera errores tipográficos pequeños entre nombres de capítulos)
    auto_keys_sorted = sorted(
        (k for k in groups if not k.startswith("manual:")),
        key=lambda k: -len(groups[k]["items"]),
    )
    merged_into: dict[str, str] = {}
    for i, k1 in enumerate(auto_keys_sorted):
        if k1 in merged_into:
            continue
        for k2 in auto_keys_sorted[i + 1:]:
            if k2 in merged_into:
                continue
            # comparar solo claves de longitud parecida (rápido) y muy similares
            if abs(len(k1) - len(k2)) > 6:
                continue
            if SequenceMatcher(None, k1, k2).ratio() >= 0.92:
                groups[k1]["items"].extend(groups[k2]["items"])
                merged_into[k2] = k1
    for k in merged_into:
        del groups[k]

    # ── Fase 4: construir respuesta ───────────────────────────────────────────
    series = []
    for key, grp in groups.items():
        items = sorted(grp["items"], key=lambda x: _chap_sort_key(x.get("capitulo")))
        # Grupos manuales siempre se muestran; automáticos requieren ≥2 capítulos
        if grp["manual"] or len(items) >= 2:
            total_pg = sum(i.get("paginas_total", 0) for i in items)
            leidos = sum(
                1 for i in items
                if i.get("paginas_total", 0) > 0
                and i.get("paginas_leidas", 0) >= i["paginas_total"]
            )
            series.append({
                "base":            grp["base"],
                "manual":          grp["manual"],
                "capitulos":       items,
                "preview":         items[0]["preview"],
                "total_capitulos": len(items),
                "total_paginas":   total_pg,
                "leidos":          leidos,
            })
        else:
            sueltos.extend(grp["items"])

    series.sort(key=lambda s: s["base"].lower())
    sueltos.sort(key=lambda m: m["nombre"].lower())

    return jsonify({"series": series, "sueltos": sueltos})


def _chap_sort_key(chap_str):
    try:
        return float(chap_str)
    except (ValueError, TypeError):
        return -1.0  # sin número → primero (suele ser el capítulo inicial)


# ── API: Asignación manual de series ──────────────────────────────────────────

@manga_bp.route("/api/manga/serie", methods=["POST"])
def asignar_serie():
    """
    Asigna, excluye o resetea la serie de uno o varios mangas (override manual
    guardado en metadata.json — la detección automática lo respeta siempre).

    Body:
    {
      "nombres": ["Manga A", "Manga B"],
      "accion":  "asignar" | "excluir" | "auto",
      "serie":   "Nombre de la serie"   (solo para accion=asignar)
    }
    """
    data = request.json or {}
    nombres = [safe_basename(n) for n in data.get("nombres", []) if n]
    accion = data.get("accion", "asignar")
    serie = str(data.get("serie", "") or "").strip()

    if not nombres or accion not in ("asignar", "excluir", "auto"):
        return jsonify({"success": False, "error": "Datos inválidos"}), 400
    if accion == "asignar" and not serie:
        return jsonify({"success": False, "error": "Falta el nombre de la serie"}), 400

    ok, fallidos = 0, []
    for nombre in nombres:
        meta = _get_manga_metadata(nombre)
        if accion == "asignar":
            meta["serie"] = serie
            meta.pop("serie_excluir", None)
        elif accion == "excluir":
            meta.pop("serie", None)
            meta.pop("serie_orden", None)
            meta["serie_excluir"] = True
        else:  # auto
            meta.pop("serie", None)
            meta.pop("serie_orden", None)
            meta.pop("serie_excluir", None)
        if _save_manga_metadata(nombre, meta):
            ok += 1
        else:
            fallidos.append(nombre)

    invalidate_cache("manga_list_")
    logger.info("Serie %s: %d ok, %d fallidos (accion=%s)", serie or "-", ok, len(fallidos), accion)
    return jsonify({"success": not fallidos, "ok": ok, "fallidos": fallidos})


# ── API: Agrupar capítulos físicamente en carpeta de serie ────────────────────

@manga_bp.route("/api/mangas/agrupar_serie", methods=["POST"])
def agrupar_serie():
    """
    Mueve varios capítulos sueltos a una subcarpeta de serie en el mismo directorio.

    Body:
    {
      "nombres": ["Obra - Cap 1", "Obra - Cap 2"],
      "serie":   "Nombre de la serie",
      "section": "largos"
    }

    Resultado: crea BASE_DIR/serie_name/ y mueve las carpetas dentro.
    Las previews siguen en su directorio de previews (no se mueven porque
    el visor las busca por nombre de carpeta).

    NOTA: Esta operación reagrupa en el sistema de archivos.
    Tras ejecutarla, llama a invalidar_cache.
    """
    data = request.json or {}
    nombres = [safe_basename(n) for n in data.get("nombres", []) if n]
    serie = safe_basename(data.get("serie", ""))
    section = data.get("section", "")

    if not nombres or not serie or section not in MANGA_SECTION_DIRS:
        return jsonify({"success": False, "error": "Datos inválidos"}), 400

    base_dir, _ = MANGA_SECTION_DIRS[section]
    serie_path = os.path.join(base_dir, serie)

    try:
        os.makedirs(serie_path, exist_ok=True)
        movidos = []
        errores = []

        for nombre in nombres:
            src = os.path.join(base_dir, nombre)
            dst = os.path.join(serie_path, nombre)
            if not os.path.exists(src):
                errores.append(f"No encontrado: {nombre}")
                continue
            if os.path.exists(dst):
                errores.append(f"Ya existe en serie: {nombre}")
                continue
            shutil.move(src, dst)
            movidos.append(nombre)

        invalidate_cache("manga_list_")
        logger.info("Serie '%s': movidos %d/%d capítulos", serie, len(movidos), len(nombres))
        return jsonify({"success": True, "movidos": movidos, "errores": errores})
    except Exception as e:
        logger.exception("Error en agrupar_serie")
        return jsonify({"success": False, "error": str(e)}), 500


# ── Colecciones (carpetas virtuales) ──────────────────────────────────────────
# La lógica genérica (crear/renombrar/eliminar/agregar/quitar/portada) vive en
# routes/colecciones.py — acá solo registramos cómo resolver item_ids (nombres
# de manga) a ítems mostrables. El archivo colecciones.json de manga
# (Config.BASE_DIR/colecciones.json) no cambió de lugar ni de formato.

def _resolve_colecciones_items(item_ids: list[str]) -> dict:
    por_nombre: dict[str, dict] = {}
    for section in MANGA_SECTION_DIRS:
        for m in _get_manga_list(section):
            key = m["nombre"].lower()
            if key not in por_nombre:
                por_nombre[key] = {**m, "id": m["nombre"]}
    ids_lower = {i.lower() for i in item_ids}
    return {k: v for k, v in por_nombre.items() if k in ids_lower}


colecciones.register_resolver("manga", _resolve_colecciones_items)


# ── API: Recomendaciones ──────────────────────────────────────────────────────

@manga_bp.route("/api/manga/recomendaciones/<manga_name>")
def manga_recomendaciones(manga_name):
    """
    Devuelve hasta `limit` mangas similares al dado, basándose en:
      1. Similitud de nombre (misma serie detectada o palabras en común)
      2. Tags en común (Jaccard similarity)
      3. Mismos artistas / parodias

    Query params:
      limit   — máximo de resultados (default 8)
      section — secciones a buscar, separadas por coma (default: todas)

    Respuesta:
    {
      "recomendaciones": [
        { ...manga_item..., "score": 0.75, "razon": "Misma serie" }
      ]
    }
    """
    manga_name = safe_basename(manga_name)
    if not manga_name:
        return jsonify({"error": "nombre inválido"}), 400

    try:
        limit = max(1, min(20, int(request.args.get("limit", 8))))
    except (ValueError, TypeError):
        limit = 8

    sections_param = request.args.get("section", "")
    sections = (
        [s for s in sections_param.split(",") if s in MANGA_SECTION_DIRS]
        if sections_param else list(MANGA_SECTION_DIRS.keys())
    )

    # Obtener metadata del manga fuente
    src_meta = _get_manga_metadata(manga_name)
    src_tags = set(_tags_as_names(src_meta.get("tags", [])))
    src_artists = set(a.lower() for a in src_meta.get("artists", []))
    src_parodys = set(p.lower() for p in src_meta.get("parodys", []))
    src_base, _ = _parse_chapter(manga_name)
    src_base_key = _norm_key(src_base)

    # Palabras del nombre para similitud difusa
    src_words = set(_tokenize_name(manga_name))

    candidates = []
    for section in sections:
        for item in _get_manga_list(section):
            nombre = item["nombre"]
            if nombre.lower() == manga_name.lower():
                continue  # excluir el propio manga

            cand_tags = set(item["tags"])
            cand_artists = set(a.lower() for a in item.get("artists", []))
            cand_parodys = set(p.lower() for p in item.get("parodys", []))
            cand_base, _ = _parse_chapter(nombre)
            cand_words = set(_tokenize_name(nombre))

            score = 0.0
            razon_parts = []

            # 1. Misma serie (nombre base idéntico, tolerante a tildes/puntuación)
            if src_base_key and _norm_key(cand_base) == src_base_key:
                score += 1.0
                razon_parts.append("Misma serie")

            # 2. Similitud de palabras en el nombre (Jaccard sobre tokens)
            name_jaccard = _jaccard(src_words, cand_words)
            if name_jaccard > 0.3:
                score += name_jaccard * 0.6
                if "Misma serie" not in razon_parts:
                    razon_parts.append("Nombre similar")

            # 3. Tags en común (Jaccard)
            if src_tags or cand_tags:
                tag_jaccard = _jaccard(src_tags, cand_tags)
                score += tag_jaccard * 0.5
                if tag_jaccard > 0.25:
                    razon_parts.append(f"{len(src_tags & cand_tags)} tags en común")

            # 4. Mismo artista
            common_artists = src_artists & cand_artists
            if common_artists:
                score += 0.4
                razon_parts.append(f"Artista: {', '.join(list(common_artists)[:2])}")

            # 5. Misma parodia/universo
            common_par = src_parodys & cand_parodys
            if common_par:
                score += 0.3
                razon_parts.append(f"Parodia: {', '.join(list(common_par)[:2])}")

            if score > 0:
                candidates.append({
                    **item,
                    "score": round(score, 3),
                    "razon": " · ".join(razon_parts) if razon_parts else "Similitud general",
                })

    candidates.sort(key=lambda x: -x["score"])
    return jsonify({"recomendaciones": candidates[:limit]})


def _tokenize_name(name: str) -> list[str]:
    """Tokeniza un nombre en palabras significativas (≥3 chars)."""
    words = re.findall(r'[a-záéíóúüñA-ZÁÉÍÓÚÜÑ\w]{3,}', name.lower())
    # Excluir números puros y palabras genéricas
    stopwords = {"cap", "chapter", "the", "los", "las", "una", "del", "para"}
    return [w for w in words if w not in stopwords and not w.isdigit()]


def _jaccard(a: set, b: set) -> float:
    if not a and not b:
        return 0.0
    return len(a & b) / len(a | b)


# ── APIs de detalle / info ────────────────────────────────────────────────────

@manga_bp.route("/get_manga_info/<categoria>/<preview_name>")
def get_manga_info(categoria, preview_name):
    if categoria not in MANGA_SECTION_DIRS:
        return jsonify({"error": "categoría inválida"}), 400

    preview_name = safe_basename(preview_name)
    if not preview_name:
        return jsonify({"error": "nombre de preview inválido"}), 400

    manga_name = os.path.splitext(preview_name)[0]
    base_dir, _ = MANGA_SECTION_DIRS[categoria]
    ruta = find_content_dir([base_dir], manga_name)

    if not ruta:
        return jsonify({"error": f"Manga '{manga_name}' no encontrado"}), 404

    try:
        paginas = sorted(
            [f for f in os.listdir(ruta) if f.lower().endswith(Config.IMAGE_EXTENSIONS)],
            key=str.lower,
        )
        size_mb = sum(
            os.path.getsize(os.path.join(ruta, f))
            for f in os.listdir(ruta) if os.path.isfile(os.path.join(ruta, f))
        ) / (1024 * 1024)

        metadata = load_json(os.path.join(ruta, METADATA_FILE), {})
        tags = metadata.get("tags", [])

        if metadata.get("paginas_total") != len(paginas):
            metadata["paginas_total"] = len(paginas)
            save_json(os.path.join(ruta, METADATA_FILE), metadata)

        tag_names = _tags_as_names(tags)

        # El progreso guardado en metadata.json es el compartido viejo: se
        # reemplaza por el del usuario actual.
        p = _progreso_actual().get(manga_name.lower(), {})
        metadata = {**metadata,
                    "paginas_leidas": p.get("pagina", 0),
                    "paginas_max":    max(p.get("max", 0), p.get("pagina", 0)),
                    "ultima_lectura": p.get("last_read", "")}
        favs = {n.lower() for n in favoritos.nombres(g.usuario["usuario"])}

        return jsonify({
            "nombre":       os.path.basename(ruta),
            "paginas":      len(paginas),
            "paginas_list": paginas,
            "tamaño":       f"{size_mb:.2f} MB",
            "metadata":     metadata,
            "es_favorito":  manga_name.lower() in favs,
            "preview_path": f"/get_manga_preview/{categoria}/{preview_name}",
            "tags":         tag_names,
            "num_tags":     len(tag_names),
        })
    except Exception as e:
        logger.exception("Error en get_manga_info")
        return jsonify({"error": str(e)}), 500


# ── APIs de tags ──────────────────────────────────────────────────────────────

@manga_bp.route("/api/manga/tags/all")
def get_all_tags():
    """
    Devuelve todos los tags únicos.
    ?counts=1 incluye frecuencia: [{"tag": "romance", "count": 47}, ...]
    """
    with_counts = request.args.get("counts", "0") in ("1", "true", "yes")
    return jsonify({"tags": _get_all_tags(with_counts=with_counts)})


@manga_bp.route("/api/manga/tags/stats")
def manga_tags_stats():
    """
    Devuelve estadísticas de distribución de tags:
    - sin_tags: mangas sin ningún tag
    - con_tags: mangas con al menos 1 tag
    - distribucion: histograma { "0": n, "1": n, "2-5": n, "6-10": n, "11+": n }
    - top_tags: los 10 tags más comunes
    """
    def _fetch():
        sin_tags = 0
        con_tags = 0
        distrib = {"0": 0, "1": 0, "2-5": 0, "6-10": 0, "11+": 0}
        counts: dict[str, int] = {}

        for base_dir in Config.get_all_manga_dirs():
            if not os.path.exists(base_dir):
                continue
            for manga in os.listdir(base_dir):
                meta_path = os.path.join(base_dir, manga, METADATA_FILE)
                if not os.path.isdir(os.path.join(base_dir, manga)):
                    continue
                meta = load_json(meta_path, {}) if os.path.exists(meta_path) else {}
                tag_names = _tags_as_names(meta.get("tags", []))
                n = len(tag_names)
                if n == 0:
                    sin_tags += 1
                    distrib["0"] += 1
                else:
                    con_tags += 1
                    if n == 1:
                        distrib["1"] += 1
                    elif n <= 5:
                        distrib["2-5"] += 1
                    elif n <= 10:
                        distrib["6-10"] += 1
                    else:
                        distrib["11+"] += 1
                for t in tag_names:
                    counts[t] = counts.get(t, 0) + 1

        top_tags = sorted(
            [{"tag": t, "count": c} for t, c in counts.items()],
            key=lambda x: -x["count"]
        )[:10]

        return {
            "sin_tags":    sin_tags,
            "con_tags":    con_tags,
            "total":       sin_tags + con_tags,
            "distribucion": distrib,
            "top_tags":    top_tags,
        }

    return jsonify(get_cached("manga_tags_stats", _fetch, ttl=Config.CACHE_TTL_MEDIUM))


@manga_bp.route("/api/manga/tags/<manga_name>", methods=["GET"])
def get_manga_tags(manga_name):
    meta = _get_manga_metadata(manga_name)
    return jsonify({"tags": meta.get("tags", [])})


@manga_bp.route("/api/manga/tags/<manga_name>", methods=["POST"])
def save_manga_tags(manga_name):
    tags_input = (request.json or {}).get("tags", [])
    if not isinstance(tags_input, list):
        return jsonify({"success": False, "error": "tags debe ser una lista"}), 400

    meta = _get_manga_metadata(manga_name)
    existing_by_name = {_tag_name(t): t for t in meta.get("tags", []) if isinstance(t, dict)}
    new_tags = []
    for item in tags_input:
        name = (_tag_name(item) if isinstance(item, dict) else str(item)).strip()
        if not name:
            continue
        new_tags.append(existing_by_name.get(name, {"tag": name, "female": 0, "male": 0}))
    meta["tags"] = new_tags
    ok = _save_manga_metadata(manga_name, meta)
    invalidate_cache("manga_list_")
    invalidate_cache("manga_tags_stats")
    return jsonify({"success": ok})


@manga_bp.route("/api/manga/tags/<manga_name>/add", methods=["POST"])
def add_manga_tag(manga_name):
    tag_name = ((request.json or {}).get("tag", "")).strip()
    if not tag_name:
        return jsonify({"success": False, "error": "tag vacío"}), 400

    meta = _get_manga_metadata(manga_name)
    tags = meta.get("tags", [])
    if tag_name in _tags_as_names(tags):
        return jsonify({"success": False, "error": "El tag ya existe"})
    tags.append({"tag": tag_name, "female": 0, "male": 0})
    meta["tags"] = tags
    ok = _save_manga_metadata(manga_name, meta)
    invalidate_cache("manga_list_")
    invalidate_cache("manga_tags_stats")
    return jsonify({"success": ok, "tags": _tags_as_names(tags)})


@manga_bp.route("/api/manga/tags/<manga_name>/remove", methods=["POST"])
def remove_manga_tag(manga_name):
    tag_name = ((request.json or {}).get("tag", "")).strip()
    if not tag_name:
        return jsonify({"success": False, "error": "tag vacío"}), 400

    meta = _get_manga_metadata(manga_name)
    meta["tags"] = [t for t in meta.get("tags", []) if _tag_name(t) != tag_name]
    ok = _save_manga_metadata(manga_name, meta)
    invalidate_cache("manga_list_")
    invalidate_cache("manga_tags_stats")
    return jsonify({"success": ok, "tags": _tags_as_names(meta["tags"])})


# Compatibilidad hacia atrás
@manga_bp.route("/get_all_categories")
def get_all_categories():
    tags = _get_all_tags()
    manga_tags: dict[str, list] = {}
    for base_dir in Config.get_all_manga_dirs():
        if not os.path.exists(base_dir):
            continue
        for manga in os.listdir(base_dir):
            meta_path = os.path.join(base_dir, manga, METADATA_FILE)
            if os.path.exists(meta_path):
                meta = load_json(meta_path, {})
                t = _tags_as_names(meta.get("tags", []))
                if t:
                    manga_tags[manga] = t
    return jsonify({"categories": tags, "mangaCategories": manga_tags})


@manga_bp.route("/add_category", methods=["POST"])
def add_category():
    return jsonify({"success": True, "info": "Las categorías ahora son tags por manga"})


@manga_bp.route("/add_manga_category", methods=["POST"])
def add_manga_category():
    body = request.json or {}
    manga_name = body.get("manga_name")
    category = body.get("category", "").strip()
    if not manga_name or not category:
        return jsonify({"success": False, "error": "Datos incompletos"}), 400

    meta = _get_manga_metadata(manga_name)
    tags = meta.get("tags", [])
    if category in _tags_as_names(tags):
        return jsonify({"success": False, "error": "El tag ya existe"})
    tags.append({"tag": category, "female": 0, "male": 0})
    meta["tags"] = tags
    ok = _save_manga_metadata(manga_name, meta)
    invalidate_cache("manga_list_")
    invalidate_cache("manga_tags_stats")
    return jsonify({"success": ok})


@manga_bp.route("/remove_manga_category", methods=["POST"])
def remove_manga_category():
    body = request.json or {}
    manga_name = body.get("manga_name")
    category = body.get("category", "").strip()
    if not manga_name or not category:
        return jsonify({"success": False, "error": "Datos incompletos"}), 400

    meta = _get_manga_metadata(manga_name)
    meta["tags"] = [t for t in meta.get("tags", []) if _tag_name(t) != category]
    ok = _save_manga_metadata(manga_name, meta)
    invalidate_cache("manga_list_")
    invalidate_cache("manga_tags_stats")
    return jsonify({"success": ok})


# ── Archivos: previews e imágenes ─────────────────────────────────────────────

@manga_bp.route("/get_manga_preview/<categoria>/<filename>")
def get_manga_preview(categoria, filename):
    if categoria not in MANGA_SECTION_DIRS:
        return "categoría inválida", 400
    filename = safe_basename(filename)
    if not filename:
        return "nombre inválido", 400
    _, preview_dir = MANGA_SECTION_DIRS[categoria]
    # max_age: la preview de un manga no cambia sin cambiar de nombre, así que
    # el navegador puede quedársela. Sin esto el celular revalida cada
    # miniatura en cada scroll de la grilla.
    return send_from_directory(preview_dir, filename, max_age=Config.PREVIEW_MAX_AGE)


@manga_bp.route("/mangas/<path:filename>")
def serve_manga(filename):
    return send_from_directory(Config.BASE_DIR, filename)


@manga_bp.route("/get_manga_page/<categoria>/<manga_name>/<filename>")
def get_manga_page(categoria, manga_name, filename):
    if categoria not in MANGA_SECTION_DIRS:
        return "Categoría inválida", 400
    manga_name = safe_basename(manga_name)
    filename = safe_basename(filename)
    if not manga_name or not filename:
        return "nombre inválido", 400
    base_dir, _ = MANGA_SECTION_DIRS[categoria]
    ruta = find_content_dir([base_dir], manga_name)
    if not ruta:
        return "Manga no encontrado", 404
    return send_from_directory(ruta, filename, max_age=Config.MEDIA_MAX_AGE)


# ── Favoritos ─────────────────────────────────────────────────────────────────

@manga_bp.route("/api/manga/check_favorite")
def check_favorite_manga():
    """Verifica si un manga está en los favoritos del usuario actual."""
    manga_name = safe_basename(request.args.get("manga_name", ""))
    if not manga_name:
        return jsonify({"is_favorite": False})
    favs = {n.lower() for n in favoritos.nombres(g.usuario["usuario"])}
    return jsonify({"is_favorite": manga_name.lower() in favs})


# ── Mover manga entre secciones ──────────────────────────────────────────────

@manga_bp.route("/api/manga/mover", methods=["POST"])
def api_manga_mover():
    """Mueve un manga de una sección a otra (ej: cortos -> largos si quedó
    mal clasificado tras fusionar capítulos en una sola carpeta serie)."""
    data = request.json or {}
    manga_name = safe_basename(data.get("manga_name", ""))
    origen = data.get("origen")
    destino = data.get("destino")

    if not manga_name or origen not in MANGA_SECTION_DIRS or destino not in MANGA_SECTION_DIRS:
        return jsonify({"success": False, "error": "Datos inválidos"}), 400
    if origen == destino:
        return jsonify({"success": False, "error": "origen y destino son iguales"}), 400

    try:
        src_dir, src_prev = MANGA_SECTION_DIRS[origen]
        dest_dir, dest_prev = MANGA_SECTION_DIRS[destino]
        move_content_with_preview(
            src_dir, dest_dir, src_prev, dest_prev,
            manga_name, Config.PREVIEW_EXTENSIONS,
        )
        invalidate_cache("manga_list_")
        invalidate_cache("all_tags")
        return jsonify({"success": True})
    except Exception as e:
        logger.exception("Error en api_manga_mover")
        return jsonify({"success": False, "error": str(e)}), 500


# ── Renombrar manga ───────────────────────────────────────────────────────────

@manga_bp.route("/api/manga/rename", methods=["POST"])
def rename_manga():
    """
    Renombra la carpeta de un manga y su preview.
    Body: {"manga_name": "Nombre Actual", "new_name": "Nombre Nuevo", "categoria": "largos"}
    """
    data = request.json or {}
    manga_name = safe_basename(data.get("manga_name", ""))
    new_name = safe_basename(data.get("new_name", ""))
    categoria = data.get("categoria")

    if not manga_name or not new_name or categoria not in MANGA_SECTION_DIRS:
        return jsonify({"success": False, "error": "Datos inválidos"}), 400
    if manga_name == new_name:
        return jsonify({"success": False, "error": "El nombre es igual al actual"}), 400

    content_dir, preview_dir = MANGA_SECTION_DIRS[categoria]
    src_path = find_content_dir([content_dir], manga_name)
    if not src_path:
        return jsonify({"success": False, "error": "Manga no encontrado"}), 404

    dest_path = os.path.join(content_dir, new_name)
    if os.path.exists(dest_path):
        return jsonify({"success": False, "error": "Ya existe un manga con ese nombre"}), 409

    try:
        # Renombrar carpeta de contenido
        os.rename(src_path, dest_path)

        # Renombrar preview (si existe, con cualquier extensión)
        for ext in Config.PREVIEW_EXTENSIONS:
            old_prev = os.path.join(preview_dir, f"{manga_name}{ext}")
            if os.path.exists(old_prev):
                new_prev = os.path.join(preview_dir, f"{new_name}{ext}")
                os.rename(old_prev, new_prev)
                break

        # Actualizar metadata interna (campo title no se toca, es el título real)
        meta = load_json(os.path.join(dest_path, METADATA_FILE), {})
        save_json(os.path.join(dest_path, METADATA_FILE), meta)

        favoritos.renombrar_en_todos(manga_name, new_name)
        progreso.renombrar_en_todos(manga_name, new_name)

        invalidate_cache("manga_list_")
        invalidate_cache("all_tags")
        invalidate_cache("manga_tags_stats")
        logger.info("Manga renombrado: '%s' → '%s' en %s", manga_name, new_name, categoria)
        return jsonify({"success": True, "new_name": new_name})
    except Exception as e:
        logger.exception("Error en rename_manga")
        return jsonify({"success": False, "error": str(e)}), 500


# ── Eliminar manga ────────────────────────────────────────────────────────────

@manga_bp.route("/delete_manga", methods=["POST"])
def delete_manga():
    data = request.json or {}
    preview_name = safe_basename(data.get("preview_name", ""))
    manga_name = safe_basename(data.get("manga_name", ""))
    categoria = data.get("categoria")

    if not all([preview_name, manga_name, categoria]) or categoria not in MANGA_SECTION_DIRS:
        return jsonify({"success": False, "error": "Datos inválidos"}), 400

    try:
        content_dir, preview_dir = MANGA_SECTION_DIRS[categoria]
        manga_path = os.path.join(content_dir, manga_name)
        if not os.path.exists(manga_path):
            return jsonify({"success": False, "error": "Manga no encontrado"}), 404
        shutil.rmtree(manga_path)
        preview_path = os.path.join(preview_dir, preview_name)
        if os.path.exists(preview_path):
            os.remove(preview_path)
        invalidate_cache("manga_list_")
        invalidate_cache("all_tags")
        invalidate_cache("manga_tags_stats")
        return jsonify({"success": True})
    except Exception as e:
        logger.exception("Error en delete_manga")
        return jsonify({"success": False, "error": str(e)}), 500


# ── Duplicados (por imagen de portada) ────────────────────────────────────────

@manga_bp.route("/manga-duplicados")
def pagina_duplicados():
    return render_template("manga_duplicados.html")


@manga_bp.route("/api/manga/duplicados")
def api_manga_duplicados():
    """
    Escanea las previews de cada sección buscando mangas con nombre distinto
    pero portada casi idéntica (dHash). ?umbral=8 (default) controla la
    tolerancia; ?refresh=1 ignora la caché en memoria de este endpoint (el
    hash por-archivo sigue cacheado en disco vía _dhash_cache.json).
    """
    from routes.image_hash import encontrar_duplicados

    try:
        umbral = int(request.args.get("umbral", 8))
    except (ValueError, TypeError):
        umbral = 8

    def _escanear():
        resultado = []
        for categoria, (_, preview_dir) in MANGA_SECTION_DIRS.items():
            pares = encontrar_duplicados(preview_dir, Config.PREVIEW_EXTENSIONS, umbral=umbral)
            for p in pares:
                p["categoria"] = categoria
            resultado.extend(pares)
        return resultado

    if request.args.get("refresh") in ("1", "true"):
        invalidate_cache(f"manga_duplicados_{umbral}")

    pares = get_cached(f"manga_duplicados_{umbral}", _escanear, ttl=3600)

    def _con_preview(titulo, categoria):
        _, preview_dir = MANGA_SECTION_DIRS[categoria]
        for ext in Config.PREVIEW_EXTENSIONS:
            if os.path.exists(os.path.join(preview_dir, titulo + ext)):
                return titulo + ext
        return None

    items = []
    for p in pares:
        preview_a = _con_preview(p["a"], p["categoria"])
        preview_b = _con_preview(p["b"], p["categoria"])
        if not preview_a or not preview_b:
            continue
        items.append({
            "categoria": p["categoria"],
            "distancia": p["distancia"],
            "a": {"nombre": p["a"], "preview": preview_a},
            "b": {"nombre": p["b"], "preview": preview_b},
        })
    return jsonify({"items": items})


@manga_bp.route("/api/manga/duplicados-id")
def api_manga_duplicados_id():
    """
    Segundo "cortafuegos" de duplicados, independiente del nombre de carpeta
    y de la portada: agrupa por (source, slug) leído de metadata.json — el
    ID real de la galería en el sitio de origen (hitomi, 3hentai, etc.).
    Detecta el mismo manga descargado dos veces con títulos distintos
    (traducción/decensored/etc.) que el matching por nombre y por dHash de
    portada no agarran. Puerto conceptual de get_downloaded_comics() en
    hitomi-downloader (src-tauri/src/commands.rs), que agrupa por
    comic.id para avisar de versiones duplicadas en la biblioteca local.
    """
    def _escanear():
        # clave (source, slug) -> [{categoria, nombre, preview}, ...]
        grupos: dict[tuple[str, str], list[dict]] = {}
        for categoria, (content_dir, preview_dir) in MANGA_SECTION_DIRS.items():
            if not os.path.isdir(content_dir):
                continue
            for nombre in os.listdir(content_dir):
                carpeta = os.path.join(content_dir, nombre)
                if not os.path.isdir(carpeta):
                    continue
                meta = load_json(os.path.join(carpeta, "metadata.json"), {})
                source = (meta.get("source") or "").strip()
                slug = str(meta.get("slug") or "").strip()
                if not source or not slug:
                    continue
                preview = None
                for ext in Config.PREVIEW_EXTENSIONS:
                    if os.path.exists(os.path.join(preview_dir, nombre + ext)):
                        preview = nombre + ext
                        break
                clave = (source, slug)
                grupos.setdefault(clave, []).append({
                    "categoria": categoria, "nombre": nombre, "preview": preview,
                })

        resultado = []
        for (source, slug), items in grupos.items():
            if len(items) < 2:
                continue
            resultado.append({"source": source, "slug": slug, "items": items})
        return resultado

    if request.args.get("refresh") in ("1", "true"):
        invalidate_cache("manga_duplicados_id")

    grupos = get_cached("manga_duplicados_id", _escanear, ttl=3600)
    return jsonify({"items": grupos})


# ── Exportar a PDF / CBZ ─────────────────────────────────────────────────────────
# Puerto de export_pdf/export_cbz de hitomi-downloader (src-tauri/src/export.rs).

EXPORTS_DIR = os.path.join(Config.BASE_DIR, "Exports")


@manga_bp.route("/api/manga/exportar", methods=["POST"])
def api_manga_exportar():
    from routes.manga_export import exportar_cbz, exportar_pdf

    data = request.get_json(force=True, silent=True) or {}
    categoria = data.get("categoria", "")
    manga_name = safe_basename(data.get("manga_name", ""))
    formato = data.get("formato", "cbz")

    if not manga_name or categoria not in MANGA_SECTION_DIRS or formato not in ("cbz", "pdf"):
        return jsonify({"success": False, "error": "parámetros inválidos"}), 400

    content_dir, _ = MANGA_SECTION_DIRS[categoria]
    carpeta = os.path.join(content_dir, manga_name)
    if not os.path.isdir(carpeta):
        return jsonify({"success": False, "error": "manga no encontrado"}), 404

    os.makedirs(EXPORTS_DIR, exist_ok=True)
    destino = os.path.join(EXPORTS_DIR, f"{manga_name}.{formato}")

    try:
        if formato == "cbz":
            info = exportar_cbz(carpeta, destino)
        else:
            info = exportar_pdf(carpeta, destino)
        info.pop("archivo", None)
        return jsonify({"success": True, "archivo": os.path.basename(destino), **info})
    except Exception as e:
        logger.exception("Error exportando manga %s a %s", manga_name, formato)
        return jsonify({"success": False, "error": str(e)}), 500


@manga_bp.route("/api/manga/exportar/descargar/<path:nombre_archivo>")
def api_manga_exportar_descargar(nombre_archivo):
    return send_from_directory(EXPORTS_DIR, safe_basename(nombre_archivo), as_attachment=True)


@manga_bp.route("/api/manga/abrir-carpeta", methods=["POST"])
def api_manga_abrir_carpeta():
    """Abre la carpeta del manga en el explorador de archivos del sistema
    (solo tiene sentido si el server y el navegador corren en la misma
    máquina — igual que show_path_in_file_manager en hitomi-downloader)."""
    data = request.get_json(force=True, silent=True) or {}
    categoria = data.get("categoria", "")
    manga_name = safe_basename(data.get("manga_name", ""))
    if not manga_name or categoria not in MANGA_SECTION_DIRS:
        return jsonify({"success": False, "error": "parámetros inválidos"}), 400

    content_dir, _ = MANGA_SECTION_DIRS[categoria]
    carpeta = os.path.join(content_dir, manga_name)
    if not os.path.isdir(carpeta):
        return jsonify({"success": False, "error": "manga no encontrado"}), 404

    try:
        os.startfile(carpeta)  # Windows-only, igual que el resto de esta app
        return jsonify({"success": True})
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500


# ── Progreso de lectura (de cada usuario, ver routes/progreso.py) ────────────

def _progress_path() -> str:
    """reading_progress.json: historial compartido de antes (solo se lee para
    la migración)."""
    return os.path.join(Config.BASE_DIR, "reading_progress.json")


def _progreso_compartido_legado() -> dict[str, dict]:
    """Progreso compartido viejo (metadata.json de cada manga + historial),
    en el formato de routes/progreso.py. Se usa una sola vez al migrar."""
    historial = {
        v.get("manga_name", "").lower(): v
        for v in load_json(_progress_path(), {}).values() if isinstance(v, dict)
    }
    resultado: dict[str, dict] = {}
    for section, (base_dir, _) in MANGA_SECTION_DIRS.items():
        if not os.path.isdir(base_dir):
            continue
        for entry in os.scandir(base_dir):
            if not entry.is_dir():
                continue
            meta = load_json(os.path.join(entry.path, METADATA_FILE), {})
            pagina = int(meta.get("paginas_leidas", 0) or 0)
            if pagina <= 0:
                continue
            h = historial.get(entry.name.lower(), {})
            resultado[entry.name.lower()] = {
                "manga_name": entry.name,
                "category":   section,
                "pagina":     pagina,
                "max":        max(int(meta.get("paginas_max", 0) or 0), pagina),
                "last_read":  meta.get("ultima_lectura") or h.get("last_read", ""),
            }
    return resultado


progreso.register_migrador(_progreso_compartido_legado)


def _mangas_por_nombre() -> dict[str, dict]:
    """{nombre en minúsculas: ítem} de todas las secciones, con el progreso
    del usuario actual."""
    por_nombre: dict[str, dict] = {}
    for section in MANGA_SECTION_DIRS:
        for m in _get_manga_list(section):
            por_nombre.setdefault(m["nombre"].lower(), m)
    return por_nombre


def _mi_progreso_ordenado() -> list[tuple[dict, dict]]:
    """[(entrada de progreso, ítem actual)] del usuario, del más reciente al
    más viejo. Omite los mangas que ya no existen."""
    items = _mangas_por_nombre()
    pares = [(e, items[k]) for k, e in _progreso_actual().items() if k in items]
    pares.sort(key=lambda par: par[0].get("last_read", ""), reverse=True)
    return pares


@manga_bp.route("/save_reading_progress", methods=["POST"])
def save_reading_progress():
    # force/silent: acepta también sendBeacon (llega como text/plain)
    data = request.get_json(force=True, silent=True) or {}
    manga_name = safe_basename(data.get("manga_name") or "")
    category = data.get("category")
    current_page = data.get("current_page")

    if not all([manga_name, category, current_page is not None]):
        return jsonify({"success": False, "error": "Datos incompletos"}), 400

    try:
        current_page = max(1, int(current_page))
    except (ValueError, TypeError):
        return jsonify({"success": False, "error": "página inválida"}), 400

    progreso.guardar(g.usuario["usuario"], manga_name, str(category), current_page)
    return jsonify({"success": True})


@manga_bp.route("/get_reading_progress")
def get_reading_progress():
    """{"<sección>_<manga>": {...}} con la sección ACTUAL de cada manga (si se
    movió de sección después de leerlo, el progreso lo sigue)."""
    resultado = {}
    for e, item in _mi_progreso_ordenado():
        resultado[f'{item["tipo"]}_{item["nombre"]}'] = {
            "manga_name":    item["nombre"],
            "category":      item["tipo"],
            "last_read":     e.get("last_read", ""),
            "current_page":  e.get("pagina", 0),
            "paginas_total": item.get("paginas_total", 0),
        }
    return jsonify(resultado)


@manga_bp.route("/api/manga/continuar_leyendo")
def continuar_leyendo():
    """
    Devuelve mangas con progreso parcial (0 < leídas < total),
    ordenados por última lectura descendente.
    Query param: limit (default 20)
    """
    try:
        limit = int(request.args.get("limit", 20))
    except (ValueError, TypeError):
        limit = 20

    resultado = []
    for e, item in _mi_progreso_ordenado():
        leidas = e.get("pagina", 0)
        total = item.get("paginas_total", 0)
        if leidas <= 0 or (total > 0 and leidas >= total):
            continue
        resultado.append({
            "manga_name":     item["nombre"],
            "category":       item["tipo"],
            "paginas_leidas": leidas,
            "paginas_total":  total,
            "porcentaje":     round(leidas / total * 100, 1) if total else 0,
            "ultima_lectura": e.get("last_read", ""),
        })
    return jsonify({"mangas": resultado[:limit], "total": len(resultado)})


@manga_bp.route("/api/manga/historial")
def manga_historial():
    """
    Últimos mangas vistos por el usuario (incluye completados, a diferencia
    de continuar_leyendo), enriquecidos con preview y progreso.
    Query param: limit (default 12)
    """
    try:
        limit = max(1, min(30, int(request.args.get("limit", 12))))
    except (ValueError, TypeError):
        limit = 12

    resultado = [
        {**item, "last_read": e.get("last_read", "")}
        for e, item in _mi_progreso_ordenado()[:limit]
    ]
    return jsonify({"mangas": resultado})


@manga_bp.route("/get_last_read_manga")
def get_last_read_manga():
    pares = _mi_progreso_ordenado()
    if not pares:
        return jsonify(None)
    e, item = pares[0]
    return jsonify({
        "manga_name":    item["nombre"],
        "category":      item["tipo"],
        "last_read":     e.get("last_read", ""),
        "current_page":  e.get("pagina", 0),
        "paginas_total": item.get("paginas_total", 0),
    })


@manga_bp.route("/cleanup_reading_progress", methods=["POST"])
def cleanup_reading_progress():
    removed = progreso.limpiar(g.usuario["usuario"], _manga_exists)
    return jsonify({"success": True, "message": f"Se eliminaron {removed} entradas"})


# ── Helpers internos ──────────────────────────────────────────────────────────

def _manga_exists(manga_name: str) -> bool:
    return bool(find_content_dir(Config.get_all_manga_dirs(), manga_name))
