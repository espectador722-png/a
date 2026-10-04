# app.py — punto de entrada principal
import logging
import os
import socket
from flask import Flask, send_from_directory
from config import Config
from routes import auth
from routes.manga import manga_bp
from routes.manga_traductor import manga_traductor_bp
from routes.descargas import descargas_bp
from routes.categorias import categorias_bp
from routes.colecciones import colecciones_bp
from routes.favoritos import favoritos_bp
from routes import indice

# Configurar logging ANTES de importar cualquier módulo que use logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
# Silenciar logs muy verbosos de Flask/Werkzeug en producción
logging.getLogger("werkzeug").setLevel(logging.WARNING)

logger = logging.getLogger(__name__)

PORT = int(os.environ.get("PORT", 5000))


def get_local_ip():
    """Detecta automáticamente la IP local de la red (ej: 192.168.x.x)."""
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect(("8.8.8.8", 80))
            return s.getsockname()[0]
    except Exception:
        return "127.0.0.1"


def print_server_info(host: str, port: int):
    """Muestra en consola la URL del servidor de forma clara."""
    separator = "=" * 50
    texto = (
        f"\n{separator}\n"
        f"  🚀  Servidor corriendo en:\n"
        f"      Local:   http://127.0.0.1:{port}\n"
        f"      Red:     http://{host}:{port}   ← usá esta en el celular\n"
        f"{separator}\n"
    )
    try:
        print(texto)
    except UnicodeEncodeError:
        # Consolas cp1252 no soportan emojis — imprimir versión ASCII
        print(texto.encode("ascii", "replace").decode("ascii"))


def create_app():
    app = Flask(__name__, template_folder="HTML")
    app.config.from_object(Config)

    Config.initialize_directories()

    # Acceso: sesión, login y permisos por rol (antes que cualquier ruta)
    auth.configurar(app)

    # Registrar blueprints
    app.register_blueprint(manga_bp)
    app.register_blueprint(manga_traductor_bp)
    app.register_blueprint(descargas_bp)
    app.register_blueprint(categorias_bp)
    app.register_blueprint(colecciones_bp)
    app.register_blueprint(favoritos_bp)

    # Favicon: algunos navegadores lo piden en /favicon.ico aunque haya <link rel="icon">
    @app.route("/favicon.ico")
    def favicon():
        return send_from_directory(
            app.static_folder, "favicon.svg", mimetype="image/svg+xml"
        )

    # PWA: el service worker tiene que servirse desde la raíz para poder
    # controlar todo el sitio (su scope es el directorio donde vive).
    @app.route("/sw.js")
    def service_worker():
        return send_from_directory(
            app.static_folder, "sw.js", mimetype="application/javascript",
            max_age=0,  # que el navegador vea los cambios del SW al toque
        )

    @app.route("/manifest.webmanifest")
    def manifest():
        return send_from_directory(
            app.static_folder, "manifest.webmanifest",
            mimetype="application/manifest+json",
        )

    # Hilos de fondo (índice, traductor): arrancar solo una vez.
    # Con debug=True, Werkzeug crea un proceso hijo (reloader); el padre tiene
    # WERKZEUG_RUN_MAIN sin definir. Arrancamos en el proceso que sirve requests.
    if not app.debug or os.environ.get("WERKZEUG_RUN_MAIN") == "true":
        indice.iniciar()
        # El traductor es opcional: solo arranca si manga-image-translator
        # está instalado en Config.TRADUCTOR_DIR.
        if os.path.isdir(Config.TRADUCTOR_DIR):
            from routes import cache_overflow
            cache_overflow.iniciar()
            from routes.manga_traductor import iniciar_shared_server, iniciar_worker_http, _retomar_cola_persistida
            iniciar_shared_server()
            iniciar_worker_http()
            _retomar_cola_persistida()
        else:
            logger.info("Traductor no instalado (%s) — se omite", Config.TRADUCTOR_DIR)

    logger.info("Aplicación inicializada correctamente")
    return app


app = create_app()

if __name__ == "__main__":
    local_ip = get_local_ip()
    print_server_info(local_ip, PORT)

    if os.environ.get("DEV_MODE") == "1":
        # Modo desarrollo: auto-reload al editar .py, debugger interactivo.
        # Un solo hilo (comportamiento de siempre) — no dejar corriendo así
        # sin supervisión, el debugger expuesto en la LAN es un riesgo.
        app.run(host="0.0.0.0", port=PORT, debug=True)
    else:
        # Uso normal: servidor multihilo real (waitress), sin debugger.
        from waitress import serve
        # threads=32: un escaneo de carpeta grande o una página pesada no
        # debe dejar colgados a los demás clientes.
        serve(app, host="0.0.0.0", port=PORT, threads=32)
