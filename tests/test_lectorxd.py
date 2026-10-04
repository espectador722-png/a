import html
import json
import os
import time

import pytest

from config import Config
from routes import scraper_lectorxd as lxd
from tests.conftest import ORIGEN

CDN = "https://s1.cdnlxd.xyz/abc123"


def _capitulo_html(num, paginas, siguiente=None, titulo="Serie de Prueba"):
    imgs = "".join(
        f'<div class="page-container"><img class="page-image" src="data:image/gif;base64,R0lG" '
        f'data-original-src="{CDN}/{num}/{i}.webp"></div>' for i in range(1, paginas + 1))
    ld = {"@type": "BreadcrumbList", "itemListElement": [
        {"position": 1, "name": "Inicio"}, {"position": 2, "name": "Manga"},
        {"position": 3, "name": titulo}, {"position": 4, "name": f"Capítulo {num}"}]}
    nxt = f'const nextChapterUrl = "{siguiente}";' if siguiente else "const nextChapterUrl = null;"
    return (f'<script type="application/ld+json">{json.dumps(ld)}</script>{imgs}'
            f'<img class="otra" data-original-src="https://malicioso.example/x.webp">'
            f"<script>{nxt}</script>")


def _catalogo_html():
    props = {"initialTotal": [0, 2], "initialMangas": [1, [
        [0, {"slug": [0, "serie-uno"], "title": [0, "Serie Uno"], "type": [0, "MANHWA"],
             "coverImage": [0, f"{CDN}/cover.webp"], "status": [0, "ongoing"], "adult": [0, False],
             "releaseYear": [0, 2024], "description": [0, "Sinopsis"],
             "tags": [1, [[0, {"tag": [0, {"name": [0, "Acción"]}]}]]]}],
        [0, {"slug": [0, "rara"], "title": [0, "Tipo raro"], "type": [0, "novela"]}],
    ]]}
    return (f'<astro-island component-url="/CatalogGrid.js" '
            f'props="{html.escape(json.dumps(props))}"></astro-island>')


def test_analizar_url_solo_acepta_lectorxd():
    assert lxd.analizar_url("https://lectorxd.com/manga/mi-serie") == {
        "tipo": "manga", "slug": "mi-serie", "capitulo": None}
    assert lxd.analizar_url("https://www.lectorxd.com/manhwa/otra/leer/12.5")["capitulo"] == "12.5"
    assert lxd.analizar_url("https://lectorxd.com.evil.com/manga/x") is None
    assert lxd.analizar_url("https://otro.com/manga/x") is None
    assert lxd.analizar_url("") is None


def test_cdn_restringido():
    assert lxd.es_imagen_del_cdn(f"{CDN}/1/1.webp")
    assert not lxd.es_imagen_del_cdn("https://s1.cdnlxd.xyz.evil.com/1.webp")
    assert not lxd.es_imagen_del_cdn("http://127.0.0.1/1.webp")


def test_parsear_capitulo():
    url = "https://lectorxd.com/manga/serie/leer/1"
    cap = lxd.parsear_capitulo(_capitulo_html("1", 3, "/manga/serie/leer/2"), url)
    assert cap["titulo"] == "Serie de Prueba"
    assert cap["capitulo"] == "1"
    assert cap["paginas"] == [f"{CDN}/1/{i}.webp" for i in (1, 2, 3)]
    assert cap["siguiente"] == "https://lectorxd.com/manga/serie/leer/2"


def test_siguiente_fuera_del_sitio_se_ignora():
    cap = lxd.parsear_capitulo(_capitulo_html("1", 1, "https://evil.com/manga/x/leer/2"),
                               "https://lectorxd.com/manga/serie/leer/1")
    assert cap["siguiente"] is None


def test_parsear_catalogo():
    d = lxd.parsear_catalogo(_catalogo_html())
    assert d["total"] == 2
    assert len(d["items"]) == 1
    it = d["items"][0]
    assert it["slug"] == "https://lectorxd.com/manhwa/serie-uno"
    assert it["tipo"] == "manhwa" and it["generos"] == ["Acción"] and it["year"] == 2024


def test_descarga_completa_sigue_la_cadena(monkeypatch):
    base = "https://lectorxd.com/manga/serie"
    paginas = {
        base: '<a href="/manga/serie/leer/1">1</a><a href="/manga/serie/leer/2">2</a>',
        f"{base}/leer/1": _capitulo_html("1", 2, "/manga/serie/leer/2"),
        f"{base}/leer/2": _capitulo_html("2", 2, "/manga/serie/leer/3"),
        f"{base}/leer/3": _capitulo_html("3", 2),
    }
    monkeypatch.setattr(lxd, "_get_html", lambda url: paginas[url])
    monkeypatch.setattr(lxd, "PAUSA_ENTRE_IMAGENES", 0)
    bajadas = []

    def falsa(url, dest):
        bajadas.append(url)
        with open(dest, "wb") as f:
            f.write(b"x")
        return True
    monkeypatch.setattr(lxd, "_descargar_imagen", falsa)
    monkeypatch.setattr(lxd, "preview_desde_imagen", lambda *a: None)

    job = lxd.iniciar_descarga(base, desde=2, hasta=None)
    for _ in range(200):
        if lxd.estado(job["id"])["estado"] != "descargando":
            break
        time.sleep(0.02)
    final = lxd.estado(job["id"])
    assert final["estado"] == "completado", final
    assert final["capitulos_ok"] == ["2", "3"]
    # Empezó directo en el 2: el capítulo 1 no se tocó.
    assert all("/1/" not in u for u in bajadas)
    carpeta = os.path.join(Config.MANGA_CONTENT_DIRS["cortos"], "Serie de Prueba - Capítulo 2")
    meta = json.load(open(os.path.join(carpeta, "metadata.json"), encoding="utf-8"))
    assert meta["serie"] == "Serie de Prueba" and meta["serie_orden"] == 2.0
    assert meta["source"] == "lectorxd"


@pytest.mark.parametrize("metodo,ruta", [
    ("post", "/api/descargas/lectorxd/descargar"),
    ("get", "/api/descargas/lectorxd/estado"),
    ("post", "/api/descargas/lectorxd/cancelar"),
    ("get", "/api/descargas/lectorxd/imagen?url=x"),
])
def test_rutas_lectorxd_solo_admin(lector, metodo, ruta):
    r = getattr(lector, metodo)(ruta, headers=ORIGEN, json={})
    assert r.status_code == 403


def test_admin_valida_url_y_proxy(admin):
    r = admin.post("/api/descargas/lectorxd/descargar", headers=ORIGEN,
                   json={"url": "https://otro.com/manga/x"})
    assert r.status_code == 400
    assert admin.get("/api/descargas/lectorxd/imagen?url=http://127.0.0.1/x").status_code == 400
    assert isinstance(admin.get("/api/descargas/lectorxd/estado").get_json()["jobs"], list)
