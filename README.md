# Eclipse Zone en WordPress

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

## Comunidad

- **Comentarios** en juegos, noticias y mangas, con respuestas anidadas. En **Ajustes → Comentarios** conviene marcar *"Los usuarios deben registrarse e iniciar sesión para comentar"* (corta el spam). Los comentarios del equipo llevan la etiqueta "Eclipse Zone".
- **Reacciones** 👍 ❤️ 🔥 😂 😮 😢 en juegos, noticias y mangas. Solo con sesión iniciada; cada uno puede marcar varias y quitarlas con otro clic.
- **Color por traductor**: **Juegos → Traductores → editar** → elegí el color. Sin elegir, cada traductor recibe uno fijo de la paleta.
- A los lectores no se les muestra la barra negra de WordPress; solo a editores y administradores.

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
