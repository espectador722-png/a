# routes/actividad.py — registro de quién hizo qué
#
# Guarda en Config.DATA_DIR/actividad.jsonl (una línea JSON por evento):
#   - cada acción de administración que salió bien (borrar, mover, renombrar,
#     descargar, traducir, usuarios...),
#   - los inicios de sesión, correctos y fallidos,
#   - los cambios de contraseña propios.
# Las acciones personales de lectura (progreso, favoritos) no se registran:
# serían ruido y no cambian nada de los demás.
#
# Del cuerpo del pedido solo se copian los campos de CAMPOS_DETALLE, así que
# una contraseña nunca llega al registro.
import os
import json
import logging
import threading
from datetime import datetime

from flask import Blueprint, jsonify, request, has_request_context

from config import Config

logger = logging.getLogger(__name__)
actividad_bp = Blueprint("actividad", __name__)

CAMPOS_DETALLE = (
    "manga_name", "new_name", "nombres", "nombre", "mangas", "categoria",
    "origen", "destino", "accion", "serie", "slug", "url", "usuario", "rol",
    "category", "tag", "titulo", "label",
)
MAX_BYTES = 2 * 1024 * 1024   # al pasar este tamaño se recorta…
CONSERVAR = 3000              # …a los últimos CONSERVAR eventos

_lock = threading.Lock()


def _path() -> str:
    return os.path.join(Config.DATA_DIR, "actividad.jsonl")


def _resumir(valor):
    if isinstance(valor, list):
        return [_resumir(v) for v in valor[:10]] + (["…"] if len(valor) > 10 else [])
    if isinstance(valor, str):
        return valor[:200]
    if isinstance(valor, (int, float, bool)) or valor is None:
        return valor
    return str(valor)[:200]


def detalle_del_pedido() -> dict:
    data = request.get_json(silent=True, force=True)
    if not isinstance(data, dict):
        return {}
    return {k: _resumir(data[k]) for k in CAMPOS_DETALLE if k in data}


def registrar(usuario: str, accion: str, detalle: dict | None = None,
              ok: bool = True) -> None:
    evento = {
        "fecha":   datetime.now().isoformat(timespec="seconds"),
        "usuario": usuario,
        "accion":  accion,
        "ok":      ok,
        "ip":      (request.remote_addr or "") if has_request_context() else "",
    }
    if detalle:
        evento["detalle"] = detalle
    linea = json.dumps(evento, ensure_ascii=False) + "\n"
    with _lock:
        try:
            with open(_path(), "a", encoding="utf-8") as f:
                f.write(linea)
            if os.path.getsize(_path()) > MAX_BYTES:
                _recortar()
        except OSError as e:
            logger.error("No se pudo escribir el registro de actividad: %s", e)


def _recortar() -> None:
    with open(_path(), encoding="utf-8") as f:
        lineas = f.readlines()[-CONSERVAR:]
    tmp = _path() + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        f.writelines(lineas)
    os.replace(tmp, _path())


def ultimos(limite: int = 200, usuario: str = "") -> list[dict]:
    """Eventos más recientes primero."""
    try:
        with open(_path(), encoding="utf-8") as f:
            lineas = f.readlines()
    except OSError:
        return []
    eventos = []
    for linea in reversed(lineas):
        try:
            e = json.loads(linea)
        except ValueError:
            continue
        if usuario and e.get("usuario", "").lower() != usuario.lower():
            continue
        eventos.append(e)
        if len(eventos) >= limite:
            break
    return eventos


@actividad_bp.route("/api/actividad")
def api_actividad():
    """Solo admin (ver PREFIJOS_ADMIN en routes/auth.py).
    Query: limite (1-1000, por defecto 200), usuario (filtra por cuenta)."""
    try:
        limite = max(1, min(1000, int(request.args.get("limite", 200))))
    except ValueError:
        limite = 200
    return jsonify({"eventos": ultimos(limite, request.args.get("usuario", "").strip())})
