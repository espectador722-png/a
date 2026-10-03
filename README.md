# Biblioteca de Manga

Servidor Flask para leer y organizar una biblioteca de manga local desde la PC o el celular (red local). Es la parte de manga del sistema `espectador722-png/e`, separada del resto y con cuentas de usuario.

## Qué incluye

- **Biblioteca**: secciones (Largos, Cortos, Favoritos y las que crees), búsqueda con tags, series, colecciones (carpetas virtuales), recomendaciones e historial.
- **Lector**: modo página y modo cascada, zoom, progreso de lectura, "continuar leyendo".
- **Índice SQLite** en segundo plano para que los listados sean rápidos con miles de mangas.
- **Duplicados** por hash perceptual de portadas.
- **Exportar** a CBZ o PDF.
- **Traductor** (opcional) con [manga-image-translator](https://github.com/zyddnys/manga-image-translator). Solo arranca si está instalado.
- **Descargas** de galerías de hitomi.la y 3hentai directo a la biblioteca.
- **PWA**: se puede instalar en el celular como app.

## Usuarios y permisos

Todos entran con usuario y contraseña. Hay dos roles:

| Acción | Lector | Admin |
|---|:---:|:---:|
| Ver la biblioteca, buscar, leer, guardar progreso | ✅ | ✅ |
| Cambiar su propia contraseña | ✅ | ✅ |
| Descargas (hitomi, 3hentai) | | ✅ |
| Borrar, mover, renombrar, favoritos, tags, series, colecciones, categorías | | ✅ |
| Traductor, exportar, abrir carpeta | | ✅ |
| Duplicados y sorteo | | ✅ |
| Crear y administrar usuarios (`/usuarios`) | | ✅ |

Los permisos se aplican en el servidor (`routes/auth.py`), no solo en la interfaz. Cualquier ruta nueva que modifique algo queda **solo para admin por defecto**. Para que un lector pueda usarla hay que agregarla a `ESCRITURA_LECTOR`.

**Primer arranque:** si no existe ninguna cuenta, se crea un admin con `ADMIN_USER` (por defecto `admin`) y `ADMIN_PASSWORD`. Sin `ADMIN_PASSWORD` no se crea nada y nadie puede entrar. Después, el resto de las cuentas se crean desde `/usuarios`.

Las contraseñas se guardan con hash (nunca en claro). Tras 5 intentos fallidos desde la misma IP, el login se bloquea 5 minutos. Cambiar la contraseña o el rol de alguien cierra sus sesiones abiertas.

## Instalación

```bash
pip install -r requirements.txt
```

En Windows, `run.bat` arranca el servidor.

## Configuración

Todo se configura con variables de entorno. Los valores por defecto son las rutas de la PC original:

| Variable | Por defecto | Qué es |
|---|---|---|
| `MANGA_DIR` | `D:/General/Imagenes/Mangas` | Carpeta raíz de la biblioteca |
| `DATA_DIR` | `D:/General` | Datos de la app: usuarios, índice, historial de sorteo |
| `ADMIN_USER` | `admin` | Usuario del primer admin |
| `ADMIN_PASSWORD` | — | Contraseña del primer admin (solo se usa si no hay cuentas) |
| `SECRET_KEY` | se genera en `DATA_DIR/secret_key` | Firma de las cookies de sesión |
| `PORT` | `5000` | Puerto del servidor |
| `TRADUCTOR_DIR` | `C:\Herramientas\manga-image-translator` | Instalación del traductor |
| `TRADUCTOR_CACHE_DIR` | `E:\` | Caché de páginas traducidas |
| `TRADUCTOR_CACHE_OVERFLOW_DIR` | `D:\_traductor_cache_overflow` | Caché de respaldo si se llena la anterior |

Primer arranque en Windows (PowerShell):

```powershell
$env:ADMIN_PASSWORD = "una-contraseña-larga"
python app.py
```

Al arrancar, la consola muestra la dirección para entrar desde el celular (`http://192.168.x.x:5000`).

## Estructura

```
app.py              punto de entrada
config.py           configuración (rutas por variables de entorno)
routes/
  auth.py           usuarios, login y permisos por rol
  manga.py          biblioteca, lector, progreso, tags, series
  indice.py         índice SQLite incremental
  categorias.py     secciones editables
  colecciones.py    carpetas virtuales
  image_hash.py     duplicados por hash perceptual
  manga_export.py   CBZ / PDF
  manga_traductor*.py, cache_overflow.py   traductor (opcional)
  descargas.py, scraper_hitomi.py, scraper_3hentai.py   descargas (admin)
HTML/               páginas (plantillas Jinja)
static/             JS, CSS, íconos, Bootstrap local
tests/              pruebas de acceso y permisos
```

## Pruebas

```bash
pip install pytest
python -m pytest tests
```
