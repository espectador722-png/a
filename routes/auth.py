# routes/auth.py — usuarios, inicio de sesión y permisos por rol
#
# Roles:
#   lector : entra con su cuenta, navega la biblioteca, lee y marca favoritos
#            (el progreso, el historial y los favoritos son de cada usuario).
#   admin  : además descarga, borra/mueve/renombra, traduce, exporta,
#            revisa duplicados y administra usuarios.
#
# La política vive entera en `politica()` (before_request de toda la app) en
# vez de decorar ruta por ruta, así un blueprint nuevo queda protegido por
# defecto: cualquier método que no sea GET exige admin salvo los pocos que
# están en ESCRITURA_LECTOR.
#
# Las cuentas se guardan en Config.USUARIOS_FILE con el hash de la contraseña
# (werkzeug, PBKDF2/scrypt); nunca la contraseña en claro.
import os
import re
import time
import secrets
import logging
import threading
from datetime import datetime, timedelta
from urllib.parse import urlsplit

from flask import (
    Blueprint, jsonify, request, session, redirect, render_template, g,
)
from werkzeug.security import generate_password_hash, check_password_hash

from config import Config
from routes.helpers import load_json, save_json

logger = logging.getLogger(__name__)
auth_bp = Blueprint("auth", __name__)

ROLES = ("admin", "lector")
_USUARIO_RE = re.compile(r"^[A-Za-z0-9_.-]{3,32}$")
MIN_PASSWORD = 8

# ── Política de acceso ────────────────────────────────────────────────────────

# Endpoints que no requieren sesión (los archivos de la PWA y el login).
PUBLICOS = {"auth.login", "auth.login_post", "static", "favicon",
            "service_worker", "manifest"}

# Rutas solo para admin, con cualquier método (incluido GET).
PREFIJOS_ADMIN = (
    "/descargas", "/api/descargas/",
    "/manga-duplicados", "/api/manga/duplicados",
    "/api/manga/exportar",
    "/manga-sorteo",
    "/usuarios", "/api/usuarios",
)

# Únicas escrituras (POST/PUT/PATCH/DELETE) permitidas a un lector.
ESCRITURA_LECTOR = {"/save_reading_progress", "/cleanup_reading_progress",
                    "/logout", "/api/me/password", "/api/favoritos"}

_METODOS_LECTURA = {"GET", "HEAD", "OPTIONS"}


def requiere_admin(path: str, method: str) -> bool:
    if path.startswith(PREFIJOS_ADMIN):
        return True
    if method in _METODOS_LECTURA:
        return False
    return path not in ESCRITURA_LECTOR


def _mismo_origen() -> bool:
    """Protección CSRF: una escritura tiene que venir de una página de este
    mismo servidor. Los navegadores mandan Origin en todo POST/DELETE de
    fetch(); si falta, se acepta Referer como respaldo."""
    origen = request.headers.get("Origin") or request.headers.get("Referer")
    if not origen:
        return False
    try:
        return urlsplit(origen).netloc == request.host
    except ValueError:
        return False


def _es_api() -> bool:
    return request.path.startswith("/api/") or request.is_json or \
        "application/json" in request.headers.get("Accept", "")


def politica():
    """before_request global (registrado en app.py)."""
    if request.method not in _METODOS_LECTURA and not _mismo_origen():
        return jsonify({"error": "origen no permitido"}), 403

    if request.endpoint in PUBLICOS:
        return None

    usuario = usuario_actual()
    if usuario is None:
        if _es_api():
            return jsonify({"error": "sesión requerida"}), 401
        destino = request.full_path if request.query_string else request.path
        return redirect(f"/login?next={_url_segura(destino)}")

    g.usuario = usuario
    if requiere_admin(request.path, request.method) and usuario["rol"] != "admin":
        if _es_api() or request.method not in _METODOS_LECTURA:
            return jsonify({"error": "solo administradores"}), 403
        return redirect("/manga")
    return None


def _url_segura(destino: str | None) -> str:
    """Solo rutas locales ("/algo"), nunca "//otro-sitio" ni URLs absolutas."""
    if not destino or not destino.startswith("/") or destino.startswith("//") \
            or "\\" in destino:
        return "/manga"
    return destino


# ── Almacenamiento de cuentas ─────────────────────────────────────────────────

_lock = threading.Lock()
_cache: dict = {"mtime": None, "usuarios": {}}


def _cargar() -> dict[str, dict]:
    """{nombre_en_minúsculas: cuenta}. Se relee solo si el archivo cambió."""
    try:
        mtime = os.path.getmtime(Config.USUARIOS_FILE)
    except OSError:
        mtime = None
    if mtime != _cache["mtime"]:
        data = load_json(Config.USUARIOS_FILE, {"usuarios": []}) or {}
        _cache["usuarios"] = {
            u["usuario"].lower(): u for u in data.get("usuarios", [])
            if u.get("usuario") and u.get("hash") and u.get("rol") in ROLES
        }
        _cache["mtime"] = mtime
    return _cache["usuarios"]


def _guardar(usuarios: dict[str, dict]) -> bool:
    ok = save_json(Config.USUARIOS_FILE, {"usuarios": list(usuarios.values())})
    _cache["mtime"] = None  # forzar relectura
    return ok


def _publico(u: dict) -> dict:
    return {"usuario": u["usuario"], "rol": u["rol"], "creado": u.get("creado", "")}


def usuario_actual() -> dict | None:
    nombre = session.get("usuario")
    if not nombre:
        return None
    u = _cargar().get(nombre.lower())
    # Cambiar la contraseña o el rol invalida las sesiones abiertas de esa cuenta.
    if u is None or session.get("version") != u.get("version", 0):
        session.clear()
        return None
    return u


def crear_usuario(usuario: str, password: str, rol: str) -> tuple[bool, str]:
    usuario = (usuario or "").strip()
    if not _USUARIO_RE.match(usuario):
        return False, "usuario inválido (3-32 letras, números, _ . -)"
    if rol not in ROLES:
        return False, "rol inválido"
    if len(password or "") < MIN_PASSWORD:
        return False, f"la contraseña debe tener al menos {MIN_PASSWORD} caracteres"
    with _lock:
        usuarios = dict(_cargar())
        if usuario.lower() in usuarios:
            return False, "ese usuario ya existe"
        usuarios[usuario.lower()] = {
            "usuario": usuario,
            "hash":    generate_password_hash(password),
            "rol":     rol,
            "version": 0,
            "creado":  datetime.now().isoformat(timespec="seconds"),
        }
        if not _guardar(usuarios):
            return False, "no se pudo guardar"
    logger.info("Usuario creado: %s (%s)", usuario, rol)
    return True, ""


def _admins(usuarios: dict[str, dict]) -> int:
    return sum(1 for u in usuarios.values() if u["rol"] == "admin")


def asegurar_admin_inicial() -> None:
    """Si no hay ninguna cuenta, crea el admin con ADMIN_USER/ADMIN_PASSWORD."""
    if _cargar():
        return
    if not Config.ADMIN_PASSWORD:
        logger.warning(
            "No hay usuarios creados. Definí ADMIN_PASSWORD (y opcionalmente "
            "ADMIN_USER) y reiniciá para crear la cuenta de administrador."
        )
        return
    ok, error = crear_usuario(Config.ADMIN_USER, Config.ADMIN_PASSWORD, "admin")
    if ok:
        logger.info("Cuenta de administrador inicial creada: %s", Config.ADMIN_USER)
    else:
        logger.error("No se pudo crear el admin inicial: %s", error)


def cargar_secret_key() -> str:
    """SECRET_KEY por variable de entorno, o una generada y guardada en disco
    para que las sesiones sobrevivan a un reinicio."""
    if os.environ.get("SECRET_KEY"):
        return os.environ["SECRET_KEY"]
    try:
        with open(Config.SECRET_KEY_FILE, encoding="utf-8") as f:
            clave = f.read().strip()
        if clave:
            return clave
    except OSError:
        pass
    clave = secrets.token_hex(32)
    os.makedirs(os.path.dirname(Config.SECRET_KEY_FILE) or ".", exist_ok=True)
    with open(Config.SECRET_KEY_FILE, "w", encoding="utf-8") as f:
        f.write(clave)
    return clave


# ── Freno a fuerza bruta ──────────────────────────────────────────────────────
# Por IP: tras MAX_FALLOS intentos fallidos seguidos, bloqueo de BLOQUEO_SEG.

MAX_FALLOS = 5
BLOQUEO_SEG = 300
_fallos: dict[str, tuple[int, float]] = {}


def _bloqueado(ip: str) -> int:
    n, desde = _fallos.get(ip, (0, 0.0))
    if n < MAX_FALLOS:
        return 0
    resta = int(desde + BLOQUEO_SEG - time.time())
    if resta <= 0:
        _fallos.pop(ip, None)
        return 0
    return resta


def _registrar_fallo(ip: str) -> None:
    n, _ = _fallos.get(ip, (0, 0.0))
    _fallos[ip] = (n + 1, time.time())


# ── Login / logout ────────────────────────────────────────────────────────────

@auth_bp.route("/login", methods=["GET"])
def login():
    if usuario_actual():
        return redirect(_url_segura(request.args.get("next")))
    return render_template("login.html", sin_usuarios=not _cargar())


@auth_bp.route("/login", methods=["POST"])
def login_post():
    ip = request.remote_addr or "?"
    espera = _bloqueado(ip)
    if espera:
        return jsonify({"error": f"demasiados intentos, esperá {espera} s"}), 429

    data = request.get_json(silent=True) or request.form
    nombre = (data.get("usuario") or "").strip()
    password = data.get("password") or ""
    u = _cargar().get(nombre.lower())
    if not u or not check_password_hash(u["hash"], password):
        _registrar_fallo(ip)
        logger.warning("Login fallido para '%s' desde %s", nombre, ip)
        return jsonify({"error": "usuario o contraseña incorrectos"}), 401

    _fallos.pop(ip, None)
    session.clear()
    session.permanent = True
    session["usuario"] = u["usuario"]
    session["version"] = u.get("version", 0)
    return jsonify({"success": True, "usuario": _publico(u),
                    "next": _url_segura(data.get("next"))})


@auth_bp.route("/logout", methods=["POST"])
def logout():
    session.clear()
    return jsonify({"success": True})


@auth_bp.route("/api/me")
def api_me():
    return jsonify(_publico(g.usuario))


@auth_bp.route("/api/me/password", methods=["POST"])
def api_me_password():
    data = request.get_json(silent=True) or {}
    actual, nueva = data.get("actual") or "", data.get("nueva") or ""
    if not check_password_hash(g.usuario["hash"], actual):
        return jsonify({"error": "la contraseña actual no es correcta"}), 400
    ok, error = _cambiar(g.usuario["usuario"], password=nueva)
    if not ok:
        return jsonify({"error": error}), 400
    session["version"] = _cargar()[g.usuario["usuario"].lower()]["version"]
    return jsonify({"success": True})


# ── Administración de usuarios (solo admin, ver PREFIJOS_ADMIN) ───────────────

def _cambiar(nombre: str, password: str | None = None,
             rol: str | None = None) -> tuple[bool, str]:
    with _lock:
        usuarios = {k: dict(v) for k, v in _cargar().items()}
        u = usuarios.get((nombre or "").lower())
        if not u:
            return False, "usuario no encontrado"
        if password is not None:
            if len(password) < MIN_PASSWORD:
                return False, f"la contraseña debe tener al menos {MIN_PASSWORD} caracteres"
            u["hash"] = generate_password_hash(password)
        if rol is not None:
            if rol not in ROLES:
                return False, "rol inválido"
            if u["rol"] == "admin" and rol != "admin" and _admins(usuarios) <= 1:
                return False, "tiene que quedar al menos un administrador"
            u["rol"] = rol
        u["version"] = u.get("version", 0) + 1
        usuarios[nombre.lower()] = u
        if not _guardar(usuarios):
            return False, "no se pudo guardar"
    return True, ""


@auth_bp.route("/usuarios")
def pagina_usuarios():
    return render_template("usuarios.html")


@auth_bp.route("/api/usuarios")
def api_usuarios():
    return jsonify({"usuarios": [_publico(u) for u in _cargar().values()]})


@auth_bp.route("/api/usuarios", methods=["POST"])
def api_usuarios_crear():
    data = request.get_json(silent=True) or {}
    ok, error = crear_usuario(data.get("usuario"), data.get("password"),
                              data.get("rol", "lector"))
    if not ok:
        return jsonify({"error": error}), 400
    return jsonify({"success": True})


@auth_bp.route("/api/usuarios/<nombre>", methods=["PATCH"])
def api_usuarios_editar(nombre):
    data = request.get_json(silent=True) or {}
    ok, error = _cambiar(nombre, password=data.get("password"), rol=data.get("rol"))
    if not ok:
        return jsonify({"error": error}), 400
    if nombre.lower() == g.usuario["usuario"].lower():
        session["version"] = _cargar()[nombre.lower()]["version"]
    return jsonify({"success": True})


@auth_bp.route("/api/usuarios/<nombre>", methods=["DELETE"])
def api_usuarios_borrar(nombre):
    if nombre.lower() == g.usuario["usuario"].lower():
        return jsonify({"error": "no podés borrar tu propia cuenta"}), 400
    with _lock:
        usuarios = dict(_cargar())
        u = usuarios.get(nombre.lower())
        if not u:
            return jsonify({"error": "usuario no encontrado"}), 404
        if u["rol"] == "admin" and _admins(usuarios) <= 1:
            return jsonify({"error": "tiene que quedar al menos un administrador"}), 400
        del usuarios[nombre.lower()]
        if not _guardar(usuarios):
            return jsonify({"error": "no se pudo guardar"}), 500
    logger.info("Usuario borrado: %s (por %s)", nombre, g.usuario["usuario"])
    return jsonify({"success": True})


def configurar(app) -> None:
    """Conecta todo lo de acceso a la app (llamado desde create_app)."""
    app.secret_key = cargar_secret_key()
    app.config.update(
        SESSION_COOKIE_HTTPONLY=True,
        SESSION_COOKIE_SAMESITE="Lax",
        PERMANENT_SESSION_LIFETIME=timedelta(days=Config.SESSION_DIAS),
    )
    app.register_blueprint(auth_bp)
    app.before_request(politica)
    asegurar_admin_inicial()
