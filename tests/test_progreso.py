import json
import os

from config import Config
from routes.helpers import load_json
from conftest import ORIGEN
from test_favoritos import _crear_manga, _cliente


def _guardar(c, nombre, seccion, pagina):
    return c.post("/save_reading_progress", headers=ORIGEN,
                  json={"manga_name": nombre, "category": seccion, "current_page": pagina})


def _item(c, seccion, nombre):
    d = c.get(f"/api/mangas/{seccion}?per_page=0").get_json()
    return next(m for m in d["mangas"] if m["nombre"] == nombre)


def test_cada_usuario_tiene_su_progreso_e_historial(app):
    _crear_manga("Mangas Cortos", "Preview Mangas Cortos", "Prog Uno")
    ana = _cliente(app, "ana1")
    beto = _cliente(app, "beto1")

    assert _guardar(ana, "Prog Uno", "cortos", 1).status_code == 200

    assert _item(ana, "cortos", "Prog Uno")["paginas_leidas"] == 1
    assert _item(beto, "cortos", "Prog Uno")["paginas_leidas"] == 0

    assert "cortos_Prog Uno" in ana.get("/get_reading_progress").get_json()
    assert "cortos_Prog Uno" not in beto.get("/get_reading_progress").get_json()

    hist_ana = [m["nombre"] for m in ana.get("/api/manga/historial").get_json()["mangas"]]
    hist_beto = [m["nombre"] for m in beto.get("/api/manga/historial").get_json()["mangas"]]
    assert "Prog Uno" in hist_ana and "Prog Uno" not in hist_beto

    ultimo = ana.get("/get_last_read_manga").get_json()
    assert ultimo["manga_name"] == "Prog Uno" and ultimo["current_page"] == 1

    info = beto.get("/get_manga_info/cortos/Prog%20Uno.jpg").get_json()
    assert info["metadata"]["paginas_leidas"] == 0


def test_guardar_no_toca_el_metadata_compartido(app):
    _crear_manga("Mangas Cortos", "Preview Mangas Cortos", "Prog Dos")
    ana = _cliente(app, "ana1")
    _guardar(ana, "Prog Dos", "cortos", 1)
    meta = os.path.join(Config.BASE_DIR, "Mangas Cortos", "Prog Dos", "metadata.json")
    assert "paginas_leidas" not in load_json(meta, {})


def test_maximo_no_se_pierde_al_releer(app):
    _crear_manga("Mangas Cortos", "Preview Mangas Cortos", "Prog Tres")
    ana = _cliente(app, "ana1")
    _guardar(ana, "Prog Tres", "cortos", 5)
    _guardar(ana, "Prog Tres", "cortos", 1)
    m = _item(ana, "cortos", "Prog Tres")
    assert m["paginas_leidas"] == 1 and m["paginas_max"] == 5


def test_progreso_sigue_al_manga_si_cambia_de_seccion(app, admin):
    _crear_manga("Mangas Cortos", "Preview Mangas Cortos", "Prog Mover")
    ana = _cliente(app, "ana1")
    _guardar(ana, "Prog Mover", "cortos", 1)
    r = admin.post("/api/manga/mover", headers=ORIGEN,
                   json={"manga_name": "Prog Mover", "origen": "cortos", "destino": "largos"})
    assert r.get_json()["success"], r.get_json()
    prog = ana.get("/get_reading_progress").get_json()
    assert "largos_Prog Mover" in prog
    assert prog["largos_Prog Mover"]["current_page"] == 1


def test_renombrar_conserva_el_progreso(app, admin):
    _crear_manga("Mangas Largos", "Preview Mangas Largos", "Prog Viejo")
    ana = _cliente(app, "ana1")
    _guardar(ana, "Prog Viejo", "largos", 1)
    r = admin.post("/api/manga/rename", headers=ORIGEN,
                   json={"manga_name": "Prog Viejo", "new_name": "Prog Nuevo", "categoria": "largos"})
    assert r.get_json()["success"]
    assert _item(ana, "largos", "Prog Nuevo")["paginas_leidas"] == 1


def test_lector_puede_limpiar_su_progreso(app):
    ana = _cliente(app, "ana1")
    r = ana.post("/cleanup_reading_progress", headers=ORIGEN, json={})
    assert r.status_code == 200


def test_migracion_del_progreso_compartido(app):
    # Estado "de antes": progreso en el metadata.json del manga + historial global
    _crear_manga("Mangas Largos", "Preview Mangas Largos", "Leido Antes")
    carpeta = os.path.join(Config.BASE_DIR, "Mangas Largos", "Leido Antes")
    with open(os.path.join(carpeta, "metadata.json"), "w", encoding="utf-8") as f:
        json.dump({"paginas_leidas": 7, "paginas_max": 9, "paginas_total": 20,
                   "ultima_lectura": "2026-09-01T10:00:00"}, f)
    # Empezar sin migración hecha (otros tests ya leyeron progreso)
    ruta = os.path.join(Config.DATA_DIR, "progreso_usuarios.json")
    if os.path.exists(ruta):
        os.remove(ruta)

    otro = _cliente(app, "ana1")
    assert _item(otro, "largos", "Leido Antes")["paginas_leidas"] == 0

    duenio = _cliente(app, Config.USUARIO_PRINCIPAL, rol="admin")
    m = _item(duenio, "largos", "Leido Antes")
    assert (m["paginas_leidas"], m["paginas_max"]) == (7, 9)
    hist = [x["nombre"] for x in duenio.get("/api/manga/historial").get_json()["mangas"]]
    assert "Leido Antes" in hist

    # Una sola vez: lo que lea después no se pisa con lo viejo
    _guardar(duenio, "Leido Antes", "largos", 2)
    assert _item(duenio, "largos", "Leido Antes")["paginas_leidas"] == 2


def _estado(c, nombre, seccion, estado):
    return c.post("/api/progreso/estado", headers=ORIGEN,
                  json={"manga_name": nombre, "category": seccion, "estado": estado})


def test_marcar_leido_y_sin_leer(app):
    _crear_manga("Mangas Cortos", "Preview Mangas Cortos", "Prog Marcar")
    ana = _cliente(app, "ana1")
    beto = _cliente(app, "beto1")

    r = _estado(ana, "Prog Marcar", "cortos", "leido")
    assert r.status_code == 200
    d = r.get_json()
    assert d["paginas_leidas"] == d["paginas_max"] == d["paginas_total"] == 1

    m = _item(ana, "cortos", "Prog Marcar")
    assert m["paginas_leidas"] == m["paginas_total"] == 1
    assert _item(beto, "cortos", "Prog Marcar")["paginas_leidas"] == 0

    assert _estado(ana, "Prog Marcar", "cortos", "sin_leer").status_code == 200
    m = _item(ana, "cortos", "Prog Marcar")
    assert (m["paginas_leidas"], m["paginas_max"]) == (0, 0)
    hist = [x["nombre"] for x in ana.get("/api/manga/historial").get_json()["mangas"]]
    assert "Prog Marcar" not in hist


def test_marcar_estado_valida_datos(app):
    ana = _cliente(app, "ana1")
    assert _estado(ana, "Prog Marcar", "cortos", "otro").status_code == 400
    assert _estado(ana, "Prog Marcar", "no-existe", "leido").status_code == 400
    assert _estado(ana, "No Hay Tal Manga", "cortos", "leido").status_code == 404
