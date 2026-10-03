# routes/favoritos.py — favoritos personales de cada usuario
#
# Cada cuenta tiene su propia lista de favoritos (nombres de manga). Marcar un
# favorito no mueve ningún archivo: la carpeta física "Favoritos" quedó como
# una sección más de la biblioteca.
#
# Migración (una sola vez): los mangas que estaban en la carpeta Favoritos
# pasan a ser favoritos de Config.FAVORITOS_CARPETA_USUARIO la primera vez
# que se leen los favoritos de esa cuenta.
import os
import logging
import threading

from flask import Blueprint, jsonify, request, g

from config import Config
from routes import categorias
from routes.helpers import load_json, save_json, list_previews

logger = logging.getLogger(__name__)
favoritos_bp = Blueprint("favoritos", __name__)

# Id de la "sección virtual" con los favoritos del usuario actual
# (la entiende /api/mangas/<section> en manga.py).
SECCION = "mis_favoritos"

_lock = threading.Lock()


def _path() -> str:
    return os.path.join(Config.DATA_DIR, "favoritos_usuarios.json")


def _load() -> dict:
    data = load_json(_path(), {}) or {}
    data.setdefault("usuarios", {})
    return data


def _nombres_carpeta_favoritos() -> list[str]:
    """Mangas de la carpeta física Favoritos (un ítem por preview, igual que manga.py)."""
    dirs = categorias.get_section_dirs("manga").get("favoritos")
    if not dirs:
        return []
    return sorted(os.path.splitext(a)[0]
                  for a in list_previews(dirs[1], Config.PREVIEW_EXTENSIONS))


def _migrar_si_corresponde(data: dict, clave: str) -> bool:
    """Devuelve True si cambió `data` (hay que guardarlo)."""
    if data.get("migrado_carpeta") or clave != Config.FAVORITOS_CARPETA_USUARIO.lower():
        return False
    actuales = data["usuarios"].setdefault(clave, [])
    vistos = {n.lower() for n in actuales}
    nuevos = [n for n in _nombres_carpeta_favoritos() if n.lower() not in vistos]
    actuales.extend(nuevos)
    data["migrado_carpeta"] = True
    logger.info("Favoritos: %d mangas de la carpeta Favoritos pasados a '%s'",
                len(nuevos), Config.FAVORITOS_CARPETA_USUARIO)
    return True


def nombres(usuario: str) -> list[str]:
    """Favoritos de `usuario`, en el orden en que los fue marcando."""
    clave = usuario.lower()
    with _lock:
        data = _load()
        if _migrar_si_corresponde(data, clave):
            save_json(_path(), data)
        return list(data["usuarios"].get(clave, []))


def actualizar(usuario: str, lista: list[str], favorito: bool) -> list[str]:
    clave = usuario.lower()
    with _lock:
        data = _load()
        _migrar_si_corresponde(data, clave)
        actuales = data["usuarios"].setdefault(clave, [])
        presentes = {n.lower() for n in actuales}
        if favorito:
            for n in lista:
                if n.lower() not in presentes:
                    actuales.append(n)
                    presentes.add(n.lower())
        else:
            quitar = {n.lower() for n in lista}
            actuales[:] = [n for n in actuales if n.lower() not in quitar]
        save_json(_path(), data)
        return list(actuales)


def renombrar_en_todos(viejo: str, nuevo: str) -> None:
    """Mantiene los favoritos al renombrar un manga (lo llama manga.py)."""
    with _lock:
        data = _load()
        cambio = False
        for lista in data["usuarios"].values():
            for i, n in enumerate(lista):
                if n.lower() == viejo.lower():
                    lista[i] = nuevo
                    cambio = True
        if cambio:
            save_json(_path(), data)


@favoritos_bp.route("/api/favoritos")
def api_favoritos():
    return jsonify({"nombres": nombres(g.usuario["usuario"])})


@favoritos_bp.route("/api/favoritos", methods=["POST"])
def api_favoritos_actualizar():
    """Body: {"nombres": ["Manga A", ...], "favorito": true|false}"""
    data = request.get_json(silent=True) or {}
    lista = data.get("nombres")
    if not isinstance(lista, list) or not lista or \
            not all(isinstance(n, str) and n.strip() for n in lista):
        return jsonify({"success": False, "error": "nombres inválidos"}), 400
    favorito = bool(data.get("favorito", True))
    lista = [os.path.basename(n.strip()) for n in lista][:500]
    return jsonify({"success": True,
                    "nombres": actualizar(g.usuario["usuario"], lista, favorito)})
