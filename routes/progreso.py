# routes/progreso.py — progreso de lectura e historial de cada usuario
#
# Antes el progreso vivía en el metadata.json de cada manga (paginas_leidas,
# paginas_max, ultima_lectura) y el historial en un reading_progress.json
# global, así que todos los usuarios compartían barras, "continuar leyendo" e
# historial. Ahora cada cuenta tiene su propio registro:
#
#   {"usuarios": {"<usuario>": {"<manga en minúsculas>": {
#       "manga_name", "category", "pagina", "max", "last_read"}}},
#    "migrado": true}
#
# Migración (una sola vez): el progreso compartido que ya existía pasa a
# Config.USUARIO_PRINCIPAL la primera vez que se lee el progreso de esa
# cuenta. manga.py registra la función que lo junta (register_migrador), así
# este módulo no importa manga.py.
import os
import logging
import threading
from datetime import datetime
from typing import Callable

from config import Config
from routes.helpers import load_json, save_json

logger = logging.getLogger(__name__)

_lock = threading.Lock()
_migrador: Callable[[], dict[str, dict]] | None = None


def register_migrador(fn: Callable[[], dict[str, dict]]) -> None:
    """fn() -> {manga_en_minúsculas: entrada} con el progreso compartido viejo."""
    global _migrador
    _migrador = fn


def _path() -> str:
    return os.path.join(Config.DATA_DIR, "progreso_usuarios.json")


def _load() -> dict:
    data = load_json(_path(), {}) or {}
    data.setdefault("usuarios", {})
    return data


def _migrar_si_corresponde(data: dict, clave: str) -> bool:
    if data.get("migrado") or clave != Config.USUARIO_PRINCIPAL.lower() or not _migrador:
        return False
    try:
        viejo = _migrador()
    except Exception:
        logger.exception("No se pudo migrar el progreso compartido")
        return False
    propio = data["usuarios"].setdefault(clave, {})
    for k, entrada in viejo.items():
        propio.setdefault(k, entrada)
    data["migrado"] = True
    logger.info("Progreso: %d mangas del progreso compartido pasados a '%s'",
                len(viejo), Config.USUARIO_PRINCIPAL)
    return True


def de_usuario(usuario: str) -> dict[str, dict]:
    """{manga_en_minúsculas: entrada} del usuario (copia)."""
    clave = usuario.lower()
    with _lock:
        data = _load()
        if _migrar_si_corresponde(data, clave):
            save_json(_path(), data)
        return {k: dict(v) for k, v in data["usuarios"].get(clave, {}).items()}


def guardar(usuario: str, manga_name: str, category: str, pagina: int) -> None:
    clave = usuario.lower()
    with _lock:
        data = _load()
        _migrar_si_corresponde(data, clave)
        propio = data["usuarios"].setdefault(clave, {})
        anterior = propio.get(manga_name.lower(), {})
        propio[manga_name.lower()] = {
            "manga_name": manga_name,
            "category":   category,
            "pagina":     pagina,
            # max = página más lejana alcanzada (el estado "leído" no se
            # pierde al releer desde el principio)
            "max":        max(int(anterior.get("max", 0) or 0), pagina),
            "last_read":  datetime.now().isoformat(),
        }
        save_json(_path(), data)


def renombrar_en_todos(viejo: str, nuevo: str) -> None:
    """Mantiene el progreso al renombrar un manga (lo llama manga.py)."""
    with _lock:
        data = _load()
        cambio = False
        for propio in data["usuarios"].values():
            entrada = propio.pop(viejo.lower(), None)
            if entrada is not None:
                entrada["manga_name"] = nuevo
                propio[nuevo.lower()] = entrada
                cambio = True
        if cambio:
            save_json(_path(), data)


def limpiar(usuario: str, existe: Callable[[str], bool]) -> int:
    """Quita del registro del usuario los mangas que ya no existen."""
    clave = usuario.lower()
    with _lock:
        data = _load()
        propio = data["usuarios"].get(clave, {})
        borrar = [k for k, v in propio.items() if not existe(v.get("manga_name", ""))]
        for k in borrar:
            del propio[k]
        if borrar:
            save_json(_path(), data)
        return len(borrar)
