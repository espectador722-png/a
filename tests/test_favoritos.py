import os

from PIL import Image

from config import Config
from routes import auth
from routes.helpers import invalidate_cache
from conftest import ORIGEN, login


def _crear_manga(seccion_dir: str, preview_dir: str, nombre: str) -> None:
    carpeta = os.path.join(Config.BASE_DIR, seccion_dir, nombre)
    os.makedirs(carpeta, exist_ok=True)
    Image.new("RGB", (20, 30)).save(os.path.join(carpeta, "001.jpg"))
    prev = os.path.join(Config.BASE_DIR, preview_dir)
    os.makedirs(prev, exist_ok=True)
    Image.new("RGB", (20, 30)).save(os.path.join(prev, nombre + ".jpg"))
    invalidate_cache("manga_list_")


def _cliente(app, usuario, rol="lector"):
    if usuario.lower() not in auth._cargar():
        ok, error = auth.crear_usuario(usuario, "clave-" + usuario, rol)
        assert ok, error
    c = app.test_client()
    assert login(c, usuario, "clave-" + usuario).status_code == 200
    return c


def _favoritos(c):
    return c.get("/api/favoritos").get_json()["nombres"]


def _marcar(c, nombres, favorito=True):
    return c.post("/api/favoritos", headers=ORIGEN,
                  json={"nombres": nombres, "favorito": favorito})


def test_migracion_de_la_carpeta_favoritos(app):
    # Mangas que ya estaban en la carpeta física Favoritos antes del cambio
    _crear_manga("Favoritos", "Preview Favoritos", "Viejo Favorito A")
    _crear_manga("Favoritos", "Preview Favoritos", "Viejo Favorito B")

    otro = _cliente(app, "alguien1")
    assert _favoritos(otro) == []                  # no es la cuenta destino

    duenio = _cliente(app, Config.USUARIO_PRINCIPAL, rol="admin")
    assert set(_favoritos(duenio)) == {"Viejo Favorito A", "Viejo Favorito B"}

    # Una sola vez: si los quita, no vuelven a aparecer
    _marcar(duenio, ["Viejo Favorito A"], favorito=False)
    assert _favoritos(duenio) == ["Viejo Favorito B"]

    # La carpeta sigue siendo una sección con sus mangas (no se movió nada)
    nombres = [m["nombre"] for m in duenio.get("/api/mangas/favoritos").get_json()["mangas"]]
    assert set(nombres) == {"Viejo Favorito A", "Viejo Favorito B"}


def test_cada_lector_tiene_sus_favoritos(app):
    _crear_manga("Mangas Largos", "Preview Mangas Largos", "Manga Uno")
    _crear_manga("Mangas Cortos", "Preview Mangas Cortos", "Manga Dos")
    ana = _cliente(app, "ana1")
    beto = _cliente(app, "beto1")

    assert _marcar(ana, ["Manga Uno", "Manga Dos"]).status_code == 200
    assert _marcar(beto, ["Manga Dos"]).status_code == 200

    assert set(_favoritos(ana)) == {"Manga Uno", "Manga Dos"}
    assert _favoritos(beto) == ["Manga Dos"]

    # La sección virtual devuelve los de cada uno, con su sección real
    d = ana.get("/api/mangas/mis_favoritos?per_page=0").get_json()
    assert {(m["nombre"], m["tipo"]) for m in d["mangas"]} == {
        ("Manga Uno", "largos"), ("Manga Dos", "cortos")}
    d = beto.get("/api/mangas/mis_favoritos?per_page=0").get_json()
    assert [m["nombre"] for m in d["mangas"]] == ["Manga Dos"]

    assert ana.get("/api/manga/check_favorite?manga_name=Manga%20Uno").get_json()["is_favorite"]
    assert not beto.get("/api/manga/check_favorite?manga_name=Manga%20Uno").get_json()["is_favorite"]

    # Quitar uno no afecta al otro usuario
    _marcar(ana, ["Manga Dos"], favorito=False)
    assert _favoritos(ana) == ["Manga Uno"]
    assert _favoritos(beto) == ["Manga Dos"]

    # Marcar favorito no mueve archivos
    assert os.path.isdir(os.path.join(Config.BASE_DIR, "Mangas Largos", "Manga Uno"))


def test_series_de_mis_favoritos(app):
    c = _cliente(app, "ana1")
    assert c.get("/api/mangas/mis_favoritos/series").status_code == 200


def test_favoritos_valida_el_body(app):
    c = _cliente(app, "ana1")
    assert _marcar(c, []).status_code == 400
    assert c.post("/api/favoritos", headers=ORIGEN, json={"nombres": "x"}).status_code == 400
    assert c.post("/api/favoritos", headers=ORIGEN, json={"nombres": [""]}).status_code == 400


def test_favoritos_requiere_sesion_y_origen(app, client):
    assert client.get("/api/favoritos").status_code == 401
    c = _cliente(app, "ana1")
    assert c.post("/api/favoritos", json={"nombres": ["x"]}).status_code == 403


def test_renombrar_conserva_favoritos(app, admin):
    _crear_manga("Mangas Largos", "Preview Mangas Largos", "Nombre Viejo")
    ana = _cliente(app, "ana1")
    _marcar(ana, ["Nombre Viejo"])
    r = admin.post("/api/manga/rename", headers=ORIGEN,
                   json={"manga_name": "Nombre Viejo", "new_name": "Nombre Nuevo", "categoria": "largos"})
    assert r.get_json()["success"]
    assert "Nombre Nuevo" in _favoritos(ana)
    assert "Nombre Viejo" not in _favoritos(ana)
