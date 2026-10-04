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

## Membresía (Patreon)

Misma lógica que el sitio anterior: **cualquier nivel activo de Patreon** da acceso.

1. En [patreon.com/portal](https://www.patreon.com/portal/registration/register-clients) creá un cliente con la Redirect URI que muestra **Ajustes → Eclipse Zone** (`https://tu-dominio/patreon/callback/`).
2. Pegá ahí el Client ID y el Client Secret. El secreto nunca llega al navegador.
3. Opcional: **Géneros exclusivos** (como `generosExclusivos` de patreon-config.json).

Beneficios para los miembros:
- **Descarga directa sin acortador**: la zona amarilla de la ventana de descargas. Los links directos se cargan en cada juego ("Links directos") o se importan de `exclusivos.json` (Herramientas → Importar → Exclusivos, con el token de GitLab).
- **Juegos exclusivos**: casilla "Exclusivo de Patreon" o géneros exclusivos. A quien no es miembro no se le manda ningún link en el HTML, ni oculto.
- La membresía se vuelve a verificar con Patreon cada 3 días: si alguien cancela, pierde el acceso solo.
- Editores y administradores siempre tienen acceso.

Páginas: `/membresia/` (beneficios) y el recuadro de Patreon en `/mi-cuenta/`.

## Descargas

El botón **⬇ Descargar** abre una ventana con los links agrupados por traductor, el tamaño (PC/APK) y cuántos acortadores tiene cada uno. Abajo, la zona amarilla: links directos para miembros o la invitación a Patreon para el resto. Formato en el admin: `Nombre | URL | acortadores | traductor`.

## Buscar, Tags y Tops

- **Filtros del catálogo** (`/juegos/`): texto, género, motor, estado, plataforma, traductor y orden (actualizados, nuevos, más vistos, mejor puntuados, A-Z). Las páginas filtradas llevan `noindex, follow` para no llenar Google de combinaciones.
- **/tags/**: todas las etiquetas agrupadas, con buscador.
- **/tops/**: más vistos por semana, mes, año o siempre, y mejor puntuados con media ponderada (un 5 con un voto no le gana a un 4,8 con cuarenta).
- Las páginas Tags, Tops, Membresía y Mi cuenta se crean solas.

## Redirecciones del sitio anterior

Solo actúan cuando la página no existe (nunca pisan una URL válida), con 301:
- `/categoria/rpg/` → `/genero/rpg/` (o `/motor/renpy/` si era un motor).
- `/juego/<slug>/` con sufijo viejo (`-2`, `-3`) o `index.html` → la ficha correcta; si no existe, `/juegos/`.
- `/juegos/?q=texto` → búsqueda.
- URLs de mangas de MangaDex → `/mangas/`.

## Velocidad

- **Copiar imágenes al servidor**: Herramientas → Importar Eclipse Zone → *Copiar imágenes al servidor* (de a 5 juegos por minuto en segundo plano) o `wp eclipse imagenes`. La portada pasa a ser la imagen destacada, con miniaturas en varios tamaños. Reimportar no deshace la copia.
- **Caché**: instalá **LiteSpeed Cache** si el hosting es LiteSpeed, o **WP Super Cache** en otro caso. Funciona bien con el tema: las vistas, votos, favoritos y reacciones van por JS/REST, así que las páginas en caché siguen contando y los usuarios con sesión no reciben páginas cacheadas.
- El tema carga un solo JS chico (diferido) y no usa jQuery en el sitio público.

## SEO e indexación

Ya incluido:
- Páginas completas desde el servidor, paginación con enlaces reales, canonical, meta description, Open Graph, `VideoGame`/`NewsArticle` y **migas (BreadcrumbList)** para Google.
- **Sitemap** `/wp-sitemap.xml` con `<lastmod>` = fecha de la última versión del juego (sin usuarios ni entradas normales).
- **Imagen para redes 1200×630** recortada de la portada (Discord, WhatsApp, Facebook, X).
- **IndexNow**: avisa a Bing/Yandex al publicar o actualizar (no durante importaciones masivas ni en copias locales). La clave se sirve sola en `/<clave>.txt`.
- `noindex` en búsquedas, filtros y `/mi-cuenta/`.

Búsquedas tipo "juego X en español" / "juego X APK en español":
- Título: `X v0.1 en Español APK` (solo Android/JoiPlay), `… en Español PC y APK` (ambos) o `… en Español PC`.
- Meta description con versión, plataformas y traductor delante de la sinopsis.
- Párrafo visible al inicio de la ficha con "X en español" y "X APK en español" (Google pesa más lo visible).
- `alternateName` en el schema VideoGame con las variantes de búsqueda.
- Con Rank Math/Yoast se usan el mismo título y descripción, salvo que escribas una a mano.

Lo que hacés vos al publicar:
1. **Ajustes → Lectura**: dejar **desmarcado** "Disuadir a los motores de búsqueda".
2. **Search Console**: agregar el dominio y enviar el sitemap (`/wp-sitemap.xml`, o `/sitemap_index.xml` si usás Rank Math).
3. **Bing Webmaster Tools**: importar el sitio desde Search Console.

## Plugins recomendados

| Plugin | Para qué | Qué ya está preparado |
|---|---|---|
| **Rank Math SEO** (o Yoast) | SEO, sitemap, monitor de 404 | Toma el título "Juego vX en Español", la portada como imagen para redes y se le quita el schema Article genérico en juegos/mangas. Nuestro meta/OG/migas se apaga solo para no duplicar. IndexNow propio se apaga si activás "Instant Indexing". |
| **LiteSpeed Cache** o **WP Super Cache** | Velocidad | `/mi-cuenta/` y `/patreon/*` nunca se cachean; al publicar un juego se vacían el inicio y `/juegos/`. Vistas, votos y reacciones se actualizan por JS. |
| **Wordfence** o **Solid Security** | Seguridad | Los endpoints `/wp-json/ez/v1/*` usan nonce. Si el firewall bloquea la REST API a visitantes, permitir `/wp-json/ez/v1/vista`. |
| **UpdraftPlus** | Copias de seguridad | Incluye las tablas `ez_vistas`, `ez_votos`, `ez_reacciones`. |
| **Complianz** | Cookies y legales (obligatorio con anuncios) | El tema no pone cookies propias (solo localStorage para la franja de Discord). |
| **Burst Statistics** | Estadísticas | Nada que configurar. |
| **Akismet** o **Antispam Bee** | Spam en comentarios | Usa los comentarios estándar de WordPress. |

No instales Elementor, Top 10, Rate My Post, Remoji ni Paid Memberships Pro: el tema y el plugin ya hacen eso y duplicarían funciones.

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
