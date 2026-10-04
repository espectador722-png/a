import os

from config import Config
from conftest import ORIGEN, login
from test_favoritos import _cliente


def _eventos(c, **params):
    q = "&".join(f"{k}={v}" for k, v in params.items())
    r = c.get("/api/actividad?" + q)
    assert r.status_code == 200
    return r.get_json()["eventos"]


def test_registra_acciones_de_admin_sin_contraseñas(admin):
    admin.post("/api/usuarios", headers=ORIGEN,
               json={"usuario": "auditado1", "password": "secreto-que-no-va", "rol": "lector"})
    e = _eventos(admin, usuario="admin")[0]
    assert e["accion"] == "POST /api/usuarios" and e["ok"]
    assert e["detalle"] == {"usuario": "auditado1", "rol": "lector"}
    with open(os.path.join(Config.DATA_DIR, "actividad.jsonl"), encoding="utf-8") as f:
        assert "secreto-que-no-va" not in f.read()


def test_marca_las_acciones_que_fallan(admin):
    admin.post("/delete_manga", headers=ORIGEN, json={"manga_name": "No Existe", "categoria": "largos"})
    e = _eventos(admin, usuario="admin")[0]
    assert e["accion"] == "POST /delete_manga"
    assert e["detalle"]["manga_name"] == "No Existe"
    assert e["ok"] is False


def test_registra_logins(app, client, admin):
    login(client, "admin", "mal")
    eventos = _eventos(admin, usuario="admin")
    assert any(e["accion"] == "login fallido" and not e["ok"] for e in eventos)
    assert any(e["accion"] == "login" and e["ok"] for e in eventos)


def test_no_registra_lo_personal_del_lector(app, admin):
    ana = _cliente(app, "auditora1")
    ana.post("/api/favoritos", headers=ORIGEN, json={"nombres": ["x"], "favorito": True})
    ana.post("/save_reading_progress", headers=ORIGEN,
             json={"manga_name": "x", "category": "largos", "current_page": 1})
    acciones = [e["accion"] for e in _eventos(admin, usuario="auditora1")]
    assert "POST /api/favoritos" not in acciones
    assert "POST /save_reading_progress" not in acciones


def test_lector_no_ve_la_actividad(app):
    ana = _cliente(app, "ana1")
    assert ana.get("/api/actividad").status_code == 403
