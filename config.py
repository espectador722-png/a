# config.py — fuente única de verdad para toda la configuración
#
# Las rutas se pueden cambiar con variables de entorno (ver README.md) sin
# tocar este archivo; los valores por defecto son los de la PC original.
import os
import logging

logger = logging.getLogger(__name__)


def _env(nombre: str, defecto: str) -> str:
    return os.environ.get(nombre) or defecto


class Config:
    # ── Mangas ────────────────────────────────────────────────────────────────
    BASE_DIR = _env("MANGA_DIR", "D:/General/Imagenes/Mangas")
    LARGOS_DIR = os.path.join(BASE_DIR, "Mangas Largos")
    CORTOS_DIR = os.path.join(BASE_DIR, "Mangas Cortos")
    FAVORITOS_DIR = os.path.join(BASE_DIR, "Favoritos")
    PREVIEW_LARGOS_DIR = os.path.join(BASE_DIR, "Preview Mangas Largos")
    PREVIEW_CORTOS_DIR = os.path.join(BASE_DIR, "Preview Mangas Cortos")
    PREVIEW_FAVORITOS_DIR = os.path.join(BASE_DIR, "Preview Favoritos")
    ORDENAR_DIR = os.path.join(BASE_DIR, "ordenar")
    CONFLICTO_DIR = os.path.join(BASE_DIR, "Conflicto")
    UMBRAL_CORTOS = 100  # < 100 imágenes = manga corto
    PREVIEWS_POR_PAGINA = 15

    # Valores por defecto de arranque — routes/categorias.py los usa para
    # crear categorias.json la primera vez. Una vez creado ese archivo, las
    # categorías reales (incluida renombrada/agregadas) viven ahí; estos dos
    # dicts quedan sincronizados en runtime por manga.py (ver _reload_section_dirs).
    MANGA_CONTENT_DIRS: dict = {
        "favoritos": FAVORITOS_DIR,
        "largos":    LARGOS_DIR,
        "cortos":    CORTOS_DIR,
    }
    MANGA_PREVIEW_DIRS: dict = {
        "favoritos": PREVIEW_FAVORITOS_DIR,
        "largos":    PREVIEW_LARGOS_DIR,
        "cortos":    PREVIEW_CORTOS_DIR,
    }

    # ── Datos de la app (usuarios, índice, historial) ─────────────────────────
    DATA_DIR = _env("DATA_DIR", "D:/General")
    MANGA_SORTEO_HISTORIAL_FILE = os.path.join(DATA_DIR, "manga_sorteo_historial.json")

    # ── Usuarios y acceso ─────────────────────────────────────────────────────
    # USUARIOS_FILE guarda las cuentas (contraseñas con hash, nunca en claro).
    # Si no existe ninguna cuenta, al arrancar se crea un admin con
    # ADMIN_USER / ADMIN_PASSWORD; sin ADMIN_PASSWORD la app no deja entrar
    # a nadie hasta que se defina.
    USUARIOS_FILE = os.path.join(DATA_DIR, "usuarios.json")
    SECRET_KEY_FILE = os.path.join(DATA_DIR, "secret_key")
    ADMIN_USER = _env("ADMIN_USER", "admin")
    ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD", "")
    # Cuenta que recibe (una sola vez) los mangas de la carpeta Favoritos
    # cuando los favoritos pasaron a ser personales (routes/favoritos.py).
    FAVORITOS_CARPETA_USUARIO = _env("FAVORITOS_CARPETA_USUARIO", "senpai1940")
    SESSION_DIAS = 30

    # ── Índice de la biblioteca (SQLite) ──────────────────────────────────────
    # Capa aditiva: si se borra, se reconstruye solo en el próximo arranque.
    INDICE_DB = os.path.join(DATA_DIR, "biblioteca.db")
    INDICE_INTERVALO = 300  # segundos entre escaneos incrementales

    # Cache-Control de previews e imágenes: el nombre del archivo identifica el
    # contenido, así que el navegador puede quedárselas mucho tiempo. Esto es lo
    # que evita que el celular revalide cada miniatura en cada scroll.
    PREVIEW_MAX_AGE = 60 * 60 * 24 * 30   # 30 días
    MEDIA_MAX_AGE   = 60 * 60 * 24 * 7    # 7 días (páginas de manga)

    # ── Extensiones permitidas ────────────────────────────────────────────────
    IMAGE_EXTENSIONS = (".png", ".jpg", ".jpeg", ".webp", ".jfif")
    PREVIEW_EXTENSIONS = (".jpg", ".png", ".jpeg", ".webp")

    # ── Cache TTLs (segundos) ─────────────────────────────────────────────────
    CACHE_TTL_SHORT  = 60    # listas que cambian frecuentemente
    CACHE_TTL_MEDIUM = 300   # tags globales
    CACHE_TTL_LONG   = 600

    # ── Traducción de mangas (manga-image-translator, subprocess) ──────────────
    # Dos vías: "shared" (server persistente con modelos ya cargados en memoria,
    # rápido) con fallback automático a "local" (CLI clásico, recarga modelos
    # en cada página, lento pero no depende de que el server esté vivo).
    TRADUCTOR_DIR = _env("TRADUCTOR_DIR", r"C:\Herramientas\manga-image-translator")
    TRADUCTOR_PYTHON = os.path.join(TRADUCTOR_DIR, "venv", "Scripts", "python.exe")
    TRADUCTOR_TIMEOUT = 300  # segundos por página (páginas con mucho texto pueden tardar varios minutos)
    # Dedicated 10 GB partition (E:, label "cache mangas") so the regenerable
    # cache can never fill the USB drive D: again.
    TRADUCTOR_CACHE_DIR = _env("TRADUCTOR_CACHE_DIR", "E:\\")
    # When E: is nearly full, new translated pages are written here (Kingston,
    # D:) and a background thread (routes/cache_overflow.py) moves them back to
    # E: once it has room again. Hysteresis: overflow below MIN_FREE_MB,
    # restore above RESTORE_FREE_GB, so the two states don't flap.
    TRADUCTOR_CACHE_OVERFLOW_DIR = _env("TRADUCTOR_CACHE_OVERFLOW_DIR", "D:\\_traductor_cache_overflow")
    TRADUCTOR_CACHE_MIN_FREE_MB = 300
    TRADUCTOR_CACHE_RESTORE_FREE_GB = 1.5
    TRADUCTOR_CACHE_RESTORE_INTERVALO = 300  # seconds between restore passes
    TRADUCTOR_SHARED_HOST = "127.0.0.1"
    TRADUCTOR_SHARED_PORT = 5003
    TRADUCTOR_SHARED_CLIENT = os.path.join(TRADUCTOR_DIR, "shared_client.py")
    # worker_server.py: proceso HTTP persistente (mismo venv) que reemplaza
    # el patrón "subprocess nuevo por página" — cada subprocess reimportaba
    # el framework completo (~15-25s de overhead SOLO en import, medido en
    # vivo 2026-09-21) antes de hacer el trabajo real. Con 37+ páginas x 2
    # fases eso eran minutos perdidos en puro arranque, causa real de
    # "demora mucho y deja páginas sin traducir sin supervisión". Puerto
    # separado del server 'shared' (5003) a propósito: si el post-proceso de
    # fase 2 (heurística/Yandex/render) crashea, no se lleva puesto el
    # server con los modelos ya cargados en VRAM (ese sí sale caro
    # reiniciarlo). _traducir_imagen_shared/_traducir_pagina_no_render caen
    # a subprocess (shared_client.py) si este server no responde.
    TRADUCTOR_WORKER_HOST = "127.0.0.1"
    TRADUCTOR_WORKER_PORT = 5004
    TRADUCTOR_WORKER_SCRIPT = os.path.join(TRADUCTOR_DIR, "worker_server.py")
    # Config validada empíricamente: sugoi no soporta ESP. lama_large corre sin
    # OOM en 4GB VRAM (margen ~212 MiB) pero no mostró mejora de calidad medible
    # sobre lama_mpe en este material (diff <0.1% de píxeles en páginas de
    # prueba) — se mantiene lama_mpe por más margen de VRAM en páginas pesadas.
    # nllb_big (1.3B) sí corre estable en 4GB VRAM una vez arreglado el bug
    # de models_ttl=0 del server 'shared' (ver iniciar_shared_server en
    # routes/manga_traductor.py) — pero produjo los MISMOS errores de
    # modismos/falsos amigos que nllb (600M) en el material de prueba
    # ("tejidos" en vez de "pañuelos", etc.). El tamaño del modelo no era la
    # causa; se vuelve a nllb (más liviano) y se corrige con post_dict
    # (dict_post_esp.txt) en vez de cargar el modelo grande sin beneficio.
    # detection_size subido a 2048 (default oficial del framework) el
    # 2026-09-23: estaba en 1536, por debajo de lo recomendado por el propio
    # README ("When the image resolution is low, lower detection_size,
    # otherwise it may cause some sentences to be missed") - una de las dos
    # causas raíz confirmadas del bug de residuo de texto original visible
    # tras el inpainting (la otra es inpainting_size, ver abajo). Verificado
    # en CPU (_debug_run.py) contra una página real: la detección capturó la
    # oración completa que antes se perdía. 2048 no tiene costo de VRAM (solo
    # afecta al detector, no al inpainter), así que no hace falta el mismo
    # cuidado que con inpainting_size.
    #
    # inpainting_size: el propio README también documenta esto como causa de
    # "source text leakage" ("increase inpainting_size, otherwise it may not
    # completely cover the mask"). El default oficial (2048) y el intermedio
    # (1536) dan CUDA OOM real en la GPU de 4GB de producción (confirmado
    # contra el server 'shared' real con --use-gpu: 2048 pide 17.42 GiB,
    # 1536 pide 9.76 GiB - ninguno cabe).
    #
    # 1280 se probó primero como "mejor valor alcanzable" (un test de una sola
    # imagen dejaba ~287 MiB libres de los 4096) pero un batch real de 35
    # páginas de un manga de prueba (_debug_batch.py, GPU limpia sin otros
    # procesos) lo desmintió: 32-34 de 35 páginas dieron CUDA OOM (probado 2
    # veces, misma GPU limpia ambas veces) - el margen de una imagen aislada
    # no era representativo de páginas reales con más regiones de texto
    # simultáneas. 1280 quedó descartado como valor BASE.
    #
    # 1152 corrió el mismo batch de 35 páginas sin un solo OOM (28/28 páginas
    # procesadas hasta que se cortó la verificación por evidencia suficiente).
    # Es el valor final: el margen real que deja (~220 MiB libres en el test
    # de una imagen) resultó SÍ sostenerse en un lote completo, a diferencia
    # de 1280.
    #
    # Margen de VRAM sigue ajustado: una página excepcionalmente pesada
    # todavía podría dar OOM - ver _es_error_cuda_oom/_INPAINTING_SIZE_FALLBACK_OOM
    # en routes/manga_traductor.py, que reintenta automáticamente esa página
    # con inpainting_size=1024 (el valor viejo, confirmado sin problema de
    # memoria) si el server devuelve CUDA OOM.
    TRADUCTOR_CONFIG = {
        "detector": {"detector": "default", "detection_size": 2048},
        "inpainter": {"inpainter": "lama_mpe", "inpainting_size": 1152},
        "translator": {"translator": "nllb", "target_lang": "ESP"},
        "render": {"renderer": "default", "font_size_offset": 0},
    }

    # ── Pipeline de post-proceso (fase 2, corrección por consenso + tamaño de fuente) ──
    # Fase 2 corrige el texto por consenso de 3 traductores (NLLB + Yandex +
    # MyMemory, sin LLM/Ollama — ver shared_client.py:_corregir_por_consenso).
    # Se mantiene el diseño en 2 fases separadas ("cinta de trabajo") por
    # robustez operativa ya validada, no por gestión de VRAM (el consenso no
    # usa GPU). Carpeta flat (mismo esquema que TRADUCTOR_CACHE_DIR) para los
    # pickles intermedios de fase 1 (text_regions + img_inpainted +
    # render_mask) que la fase 2 consume y borra al terminar cada página.
    TRADUCTOR_LLM_PENDIENTES_DIR = os.path.join(TRADUCTOR_CACHE_DIR, "_pendiente_llm")

    @classmethod
    def get_all_manga_dirs(cls) -> list[str]:
        return list(cls.MANGA_CONTENT_DIRS.values())

    @classmethod
    def initialize_directories(cls) -> None:
        """Crea todos los directorios necesarios si no existen."""
        dirs = [
            cls.ORDENAR_DIR, cls.LARGOS_DIR, cls.CORTOS_DIR,
            cls.FAVORITOS_DIR, cls.PREVIEW_LARGOS_DIR, cls.PREVIEW_CORTOS_DIR,
            cls.PREVIEW_FAVORITOS_DIR, cls.CONFLICTO_DIR,
            cls.DATA_DIR,
        ]
        # El caché del traductor solo hace falta si el traductor está instalado.
        if os.path.isdir(cls.TRADUCTOR_DIR):
            dirs += [cls.TRADUCTOR_CACHE_DIR, cls.TRADUCTOR_LLM_PENDIENTES_DIR]
        for d in dirs:
            os.makedirs(d, exist_ok=True)
        logger.info("Directorios inicializados (%d rutas)", len(dirs))
