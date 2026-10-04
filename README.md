# Eclipse Zone en WordPress

Este repositorio tiene dos proyectos:

- **Eclipse Zone en WordPress** (`wp-content/`): el sitio público.
- **Biblioteca de manga local** (app Flask en la raíz: `app.py`, `routes/`, `HTML/`, `static/`): la biblioteca privada para la PC y el celular. Ver [más abajo](#biblioteca-de-manga-local-app-flask).

Versión nueva de eclipsezonehub.com hecha sobre WordPress.org: **Inicio, Juegos, Noticias y Mangas** (con un sistema de mangas propio, sin MangaDex).

Hay dos piezas, y las dos van dentro de `wp-content/` de tu WordPress:

| Carpeta | Qué es |
|---|---|
| `wp-content/plugins/eclipse-zone-core/` | Los **datos**: tipos de contenido (juego, noticia, manga), géneros/plataformas/traductores, campos, importador, favoritos y progreso, SEO. |
| `wp-content/themes/eclipse-zone/` | El **diseño**: plantillas y CSS. Se puede cambiar sin perder datos. |

## Por qué esto arregla la indexación

El sitio anterior enlazaba solo 100 de ~590 juegos desde `/juegos/` y 12 desde el inicio, así que Google tenía 565 URLs "descubiertas, sin indexar". Acá:

- `/juegos/`, `/juegos/page/2/`, `/juegos/page/3/`… con 48 juegos cada una y enlaces `<a href>` reales entre páginas. El inicio enlaza a todas las páginas del catálogo: **cualquier juego está a 2 clics del inicio**.
- Páginas por género, plataforma, traductor y estado (`/genero/rpg/`, `/plataforma/android/`…), también paginadas.
- 12 juegos relacionados en cada ficha.
- Todo el contenido llega en el HTML del servidor. Solo se carga JavaScript para favoritos y para el lector de mangas.
- Sitemap automático en `/wp-sitemap.xml`, canonical, meta description, Open Graph y JSON-LD (`VideoGame`, `NewsArticle`).
- Mismas URLs que antes: `/juego/<slug>/`, `/noticia/<slug>/`, `/juegos/`, `/noticias/`. Las páginas que ya están indexadas no se pierden.

## Instalación

1. Contratá un hosting con PHP 7.4+ y MySQL (cualquiera con "WordPress en un clic" sirve) e instalá WordPress.
2. En **Ajustes → Generales**, poné el idioma en Español.
3. En **Ajustes → Enlaces permanentes**, elegí **"Nombre de la entrada"** (`/%postname%/`). Es obligatorio para que existan `/juego/...` y `/juegos/page/2/`.
4. Subí las dos carpetas de este repo a `wp-content/plugins/` y `wp-content/themes/` (por FTP o por el administrador de archivos del hosting).
5. Activá el plugin **Eclipse Zone Core** y después el tema **Eclipse Zone**. Al activar el tema se crea sola la página `/mi-cuenta/`.
6. **Herramientas → Importar Eclipse Zone** → "Importar juegos" y "Importar noticias". Por defecto lee `juegos.json` y `noticias.json` de GitLab. Se puede repetir cuando quieras: actualiza sin duplicar.
   Con WP-CLI: `wp eclipse importar juegos` / `wp eclipse importar noticias`.
7. Para que la gente pueda crear cuentas: **Ajustes → Generales → "Cualquiera puede registrarse"**, con rol "Suscriptor".

## Inicio y tarjetas

- **Carrusel "Destacados"**: los juegos con la casilla **Destacado** marcada (en "Datos del juego"). Si no hay ninguno, muestra los más vistos del mes.
- **Top** con pestañas **Semana / Mes / Año**, según las vistas reales.
- **Tarjetas de juego**: traductor y bandera arriba; motor (Ren'Py, Unity…, con su color), estado y versión sobre la imagen; descripción corta; "hace X"; vistas; puntuación ★ con cantidad de votos. **Al pasar el mouse** se despliegan el desarrollador y los géneros.
- **Vistas**: se cuentan con un pedido JS al abrir la ficha (los bots no suman), una por visitante cada 6 horas.
- **Votos**: de 1 a 5 estrellas en la ficha, solo con sesión iniciada; un voto por usuario, que se puede cambiar.
- **Motor y desarrollador**: si `juegos.json` trae `motor` o `desarrollador`, se importan. Si no, el motor se detecta cuando aparece entre las categorías ("Ren'Py", "Unity"…). También se pueden cargar a mano.
- **Franja de Discord**: **Apariencia → Personalizar → Eclipse Zone** → pegá la invitación. Quien la cierra no la vuelve a ver.

## Mangas

- **Serie con capítulos**: creá el manga (título, sinopsis, portada, etiquetas) sin páginas. Después creá cada capítulo como otro manga, con la serie elegida en **Atributos → Superior** y el número en **Orden**.
  URL: `/manga/mi-serie/capitulo-1/`.
- **One-shot**: un solo manga con sus páginas.
- **Páginas**: en la caja "Páginas", botón **Elegir / subir páginas**. Se ordenan por nombre de archivo (`001.jpg`, `002.jpg`…). También se pueden pegar URLs de imagen, una por línea.
- **Lector**: modo cascada (todas seguidas) o modo página (clic o flechas ← →). Al terminar pasa al capítulo siguiente.
- **Usuarios con sesión**: favoritos ★ (juegos y mangas), progreso de lectura guardado solo, "Seguir leyendo", marcar capítulos como leídos / sin leer, todo en `/mi-cuenta/`.
- **Admin / lector**: los permisos son los de WordPress. Solo los administradores y editores pueden subir o borrar contenido; los "Suscriptores" solo leen y guardan sus cosas personales.

## Qué falta (siguientes pasos)

- Importar la biblioteca local de mangas (la app Flask) de forma masiva: un comando que suba cada carpeta como manga o capítulo.
- Comentarios, chat o Discord: lo que haga falta, como plugins aparte.
- Plugin de caché (LiteSpeed Cache o WP Super Cache, según el hosting).

## Probado

Probado en local con WordPress 7.2-alpha + SQLite, PHP 8.3 y datos de prueba con el mismo formato que `juegos.json` (títulos duplicados, plataforma como texto o como lista, juegos exclusivos):
importación (y reimportación sin duplicados), slugs `single-again` / `single-again-2`, paginación, taxonomías, sitemap, JSON-LD, inicio de sesión, favoritos, progreso, leído/sin leer, lector en modo página con teclado, y sin scroll horizontal en móvil (390 px).

---

## Biblioteca de manga local (app Flask)

Servidor Flask para leer y organizar una biblioteca de manga local desde la PC o el celular (red local). Es la parte de manga del sistema `espectador722-png/e`, separada del resto y con cuentas de usuario.

### Qué incluye

- **Biblioteca**: secciones (Largos, Cortos, Favoritos y las que crees), búsqueda con tags, series, colecciones (carpetas virtuales), recomendaciones e historial.
- **Lector**: modo página y modo cascada, zoom, progreso de lectura, "continuar leyendo".
- **Índice SQLite** en segundo plano para que los listados sean rápidos con miles de mangas.
- **Duplicados** por hash perceptual de portadas.
- **Exportar** a CBZ o PDF.
- **Traductor** (opcional) con [manga-image-translator](https://github.com/zyddnys/manga-image-translator). Solo arranca si está instalado.
- **Descargas** de galerías de hitomi.la y 3hentai directo a la biblioteca, y de
  series completas de lectorxd.com (manga/manhwa/manhua, capítulo por capítulo,
  en segundo plano, con rango "desde/hasta" y botón para cancelar).
- **PWA**: se puede instalar en el celular como app.

### Usuarios y permisos

Todos entran con usuario y contraseña. Hay dos roles:

| Acción | Lector | Admin |
|---|:---:|:---:|
| Ver la biblioteca, buscar, leer, guardar progreso | ✅ | ✅ |
| Sus propios favoritos, progreso e historial (incluido marcar leído / sin leer) | ✅ | ✅ |
| Cambiar su propia contraseña | ✅ | ✅ |
| Descargas (hitomi, 3hentai, lectorxd) | | ✅ |
| Borrar, mover, renombrar, tags, series, colecciones, categorías | | ✅ |
| Traductor, exportar, abrir carpeta | | ✅ |
| Duplicados y sorteo | | ✅ |
| Crear y administrar usuarios (`/usuarios`) | | ✅ |
| Ver el registro de actividad (`/usuarios`) | | ✅ |

Los permisos se aplican en el servidor (`routes/auth.py`), no solo en la interfaz. Cualquier ruta nueva que modifique algo queda **solo para admin por defecto**. Para que un lector pueda usarla hay que agregarla a `ESCRITURA_LECTOR`.

**Primer arranque:** si no existe ninguna cuenta, se crea un admin con `ADMIN_USER` (por defecto `admin`) y `ADMIN_PASSWORD`. Sin `ADMIN_PASSWORD` no se crea nada y nadie puede entrar. Después, el resto de las cuentas se crean desde `/usuarios`.

**Personal de cada usuario:** los favoritos ("Mis favoritos"), el progreso de lectura (barras, filtros "sin leer / en progreso / leído", "seguir leyendo") y el historial ("Últimos vistos"). Marcar un favorito no mueve ningún archivo: la carpeta física `Favoritos` quedó como una sección más ("Carpeta Favoritos").

La primera vez que entra la cuenta `USUARIO_PRINCIPAL` (por defecto `senpai1940`), recibe lo que antes era compartido: los mangas de la carpeta Favoritos como favoritos, y el progreso y el historial de lectura. Pasa una sola vez.

**Registro de actividad:** cada acción de administración (borrar, mover, renombrar, descargar, traducir, cuentas…), los inicios de sesión y los intentos fallidos quedan anotados con fecha, usuario e IP en `DATA_DIR/actividad.jsonl`. Se consulta desde `/usuarios`. Nunca guarda contraseñas: del pedido solo se copia una lista cerrada de campos.

Las contraseñas se guardan con hash (nunca en claro). Tras 5 intentos fallidos desde la misma IP, el login se bloquea 5 minutos. Cambiar la contraseña o el rol de alguien cierra sus sesiones abiertas.

### Instalación

```bash
pip install -r requirements.txt
```

En Windows, `run.bat` arranca el servidor.

### Configuración

Todo se configura con variables de entorno. Los valores por defecto son las rutas de la PC original:

| Variable | Por defecto | Qué es |
|---|---|---|
| `MANGA_DIR` | `D:/General/Imagenes/Mangas` | Carpeta raíz de la biblioteca |
| `DATA_DIR` | `D:/General` | Datos de la app: usuarios, índice, historial de sorteo |
| `ADMIN_USER` | `admin` | Usuario del primer admin |
| `ADMIN_PASSWORD` | — | Contraseña del primer admin (solo se usa si no hay cuentas) |
| `USUARIO_PRINCIPAL` | `senpai1940` | Cuenta que recibe los favoritos, el progreso y el historial que antes eran compartidos |
| `SECRET_KEY` | se genera en `DATA_DIR/secret_key` | Firma de las cookies de sesión |
| `PORT` | `5000` | Puerto del servidor |
| `TRADUCTOR_DIR` | `C:\Herramientas\manga-image-translator` | Instalación del traductor |
| `TRADUCTOR_CACHE_DIR` | `E:\` | Caché de páginas traducidas |
| `TRADUCTOR_CACHE_OVERFLOW_DIR` | `D:\_traductor_cache_overflow` | Caché de respaldo si se llena la anterior |

Primer arranque en Windows (PowerShell):

```powershell
$env:ADMIN_USER = "senpai1940"
$env:ADMIN_PASSWORD = "una-contraseña-larga"
python app.py
```

Al arrancar, la consola muestra la dirección para entrar desde el celular (`http://192.168.x.x:5000`).

### Estructura

```
app.py              punto de entrada
config.py           configuración (rutas por variables de entorno)
routes/
  auth.py           usuarios, login y permisos por rol
  manga.py          biblioteca, lector, progreso, tags, series
  indice.py         índice SQLite incremental
  categorias.py     secciones editables
  colecciones.py    carpetas virtuales
  favoritos.py      favoritos personales de cada usuario
  progreso.py       progreso de lectura e historial de cada usuario
  actividad.py      registro de quién hizo qué
  image_hash.py     duplicados por hash perceptual
  manga_export.py   CBZ / PDF
  manga_traductor*.py, cache_overflow.py   traductor (opcional)
  descargas.py, scraper_hitomi.py, scraper_3hentai.py,
  scraper_lectorxd.py                                     descargas (admin)
HTML/               páginas (plantillas Jinja)
static/             JS, CSS, íconos, Bootstrap local
tests/              pruebas de acceso y permisos
```

### Pruebas

```bash
pip install pytest
python -m pytest tests
```
