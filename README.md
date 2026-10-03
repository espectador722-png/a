# Lector de Manga

Lector de manga web (HTML + CSS + JS, sin build). Abre `index.html` o sirve la carpeta con `python3 -m http.server`.

- Biblioteca con portadas, búsqueda y filtro de favoritos
- Modo página a página y modo cascada (tecla `M`)
- Dirección derecha→izquierda o izquierda→derecha (tecla `D`)
- Teclado: ←/→, espacio, PgUp/PgDn, Home/End, `F` favorito, `Esc` volver
- Carga de CBZ/ZIP, imágenes sueltas o carpetas (también arrastrando y soltando)
- Progreso, favoritos y capítulos guardados en el navegador (IndexedDB)

Nota: la lectura de CBZ usa JSZip desde cdnjs, por lo que requiere conexión la primera vez.
