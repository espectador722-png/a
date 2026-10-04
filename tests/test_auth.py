import pytest

from routes import auth
from routes.descargas import _es_url_cdn_hitomi
from conftest import ORIGEN, login


# ── Sin sesión ────────────────────────────────────────────────────────────────

def test_pagina_sin_sesion_redirige_al_login(client):
    r = client.get("/manga")
    assert r.status_code == 302
    assert r.headers["Location"].startswith("/login?next=/manga")


def test_api_sin_sesion_da_401(client):
    assert client.get("/api/mangas/largos").status_code == 401


def test_login_y_archivos_pwa_son_publicos(client):
    assert client.get("/login").status_code == 200
    assert client.get("/manifest.webmanifest").status_code == 200
    assert client.get("/static/js/sesion.js").status_code == 200


# ── Login ─────────────────────────────────────────────────────────────────────

def test_admin_inicial_creado_desde_la_config(admin):
    me = admin.get("/api/me").get_json()
    assert me == {"usuario": "admin", "rol": "admin", "creado": me["creado"]}


def test_contraseña_incorrecta(client):
    r = login(client, "admin", "otra-cosa")
    assert r.status_code == 401
    assert client.get("/api/me").status_code == 401


def test_bloqueo_tras_intentos_fallidos(client):
    for _ in range(auth.MAX_FALLOS):
        assert login(client, "admin", "mal").status_code == 401
    # Bloqueado aunque ahora la contraseña sea la correcta
    assert login(client, "admin", "clave-admin-123").status_code == 429


@pytest.mark.parametrize("destino,esperado", [
    ("/manga?abrir=x", "/manga?abrir=x"),
    ("//evil.example", "/manga"),
    ("https://evil.example", "/manga"),
    ("/\\evil.example", "/manga"),
])
def test_next_solo_acepta_rutas_locales(client, destino, esperado):
    r = client.post("/login", headers=ORIGEN, json={
        "usuario": "admin", "password": "clave-admin-123", "next": destino})
    assert r.get_json()["next"] == esperado


def test_logout(admin):
    assert admin.post("/logout", headers=ORIGEN).status_code == 200
    assert admin.get("/api/me").status_code == 401


# ── CSRF ──────────────────────────────────────────────────────────────────────

def test_escritura_sin_origin_rechazada(admin):
    r = admin.post("/api/usuarios", json={"usuario": "x1", "password": "12345678"})
    assert r.status_code == 403


def test_escritura_desde_otro_origen_rechazada(admin):
    r = admin.post("/api/usuarios", headers={"Origin": "http://evil.example"},
                   json={"usuario": "x1", "password": "12345678"})
    assert r.status_code == 403


# ── Permisos por rol ──────────────────────────────────────────────────────────

def test_lector_puede_leer(lector):
    assert lector.get("/manga").status_code == 200
    assert lector.get("/api/mangas/largos").status_code == 200
    assert lector.get("/get_reading_progress").status_code == 200


def test_lector_puede_guardar_progreso(lector):
    r = lector.post("/save_reading_progress", headers=ORIGEN,
                    json={"manga_name": "x", "page": 1})
    assert r.status_code != 403


@pytest.mark.parametrize("metodo,ruta", [
    ("post", "/delete_manga"),
    ("post", "/api/manga/mover"),
    ("post", "/api/manga/rename"),
    ("post", "/api/manga/serie"),
    ("post", "/api/manga/abrir-carpeta"),
    ("post", "/api/manga/exportar"),
    ("post", "/api/manga/traductor/lote"),
    ("post", "/api/colecciones/manga"),
    ("post", "/api/categorias/manga"),
    ("post", "/api/descargas/hitomi/descargar"),
    ("get", "/api/descargas/buscar?site=hitomi&q=x"),
    ("get", "/api/descargas/hitomi/imagen?url=https://a.gold-usergeneratedcontent.net/x.webp"),
    ("get", "/api/manga/duplicados"),
    ("get", "/api/usuarios"),
])
def test_lector_no_puede_hacer_acciones_de_admin(lector, metodo, ruta):
    r = getattr(lector, metodo)(ruta, headers=ORIGEN, json={})
    assert r.status_code == 403


@pytest.mark.parametrize("ruta", ["/descargas", "/manga-duplicados", "/manga-sorteo", "/usuarios"])
def test_paginas_de_admin_redirigen_al_lector(lector, ruta):
    r = lector.get(ruta)
    assert r.status_code == 302
    assert r.headers["Location"] == "/manga"


@pytest.mark.parametrize("ruta", ["/manga", "/descargas", "/manga-duplicados", "/manga-sorteo", "/usuarios"])
def test_admin_ve_todas_las_paginas(admin, ruta):
    r = admin.get(ruta)
    assert r.status_code == 200
    assert b'data-rol="admin"' in r.data


def test_rol_en_la_pagina_del_lector(lector):
    assert b'data-rol="lector" data-usuario="lector1"' in lector.get("/manga").data


def test_politica_por_defecto_protege_escrituras_nuevas():
    # Una ruta que no está en ninguna lista: lectura libre, escritura solo admin.
    assert not auth.requiere_admin("/api/algo-nuevo", "GET")
    assert auth.requiere_admin("/api/algo-nuevo", "POST")
    assert auth.requiere_admin("/api/algo-nuevo", "DELETE")


# ── Administración de usuarios ────────────────────────────────────────────────

def test_admin_crea_lector_y_valida_datos(admin):
    r = admin.post("/api/usuarios", headers=ORIGEN,
                   json={"usuario": "nuevo1", "password": "12345678", "rol": "lector"})
    assert r.status_code == 200
    r = admin.post("/api/usuarios", headers=ORIGEN,
                   json={"usuario": "nuevo1", "password": "12345678"})
    assert r.status_code == 400  # duplicado
    r = admin.post("/api/usuarios", headers=ORIGEN,
                   json={"usuario": "corto", "password": "123"})
    assert r.status_code == 400  # contraseña corta
    r = admin.post("/api/usuarios", headers=ORIGEN,
                   json={"usuario": "a b/c", "password": "12345678"})
    assert r.status_code == 400  # nombre inválido
    usuarios = {u["usuario"] for u in admin.get("/api/usuarios").get_json()["usuarios"]}
    assert "nuevo1" in usuarios


def test_no_se_guarda_la_contraseña_en_claro(admin):
    admin.post("/api/usuarios", headers=ORIGEN,
               json={"usuario": "secreto1", "password": "mi-clave-secreta"})
    from config import Config
    with open(Config.USUARIOS_FILE, encoding="utf-8") as f:
        assert "mi-clave-secreta" not in f.read()


def test_no_se_puede_quitar_el_ultimo_admin(admin):
    r = admin.patch("/api/usuarios/admin", headers=ORIGEN, json={"rol": "lector"})
    assert r.status_code == 400
    r = admin.delete("/api/usuarios/admin", headers=ORIGEN)
    assert r.status_code == 400


def test_cambiar_contraseña_cierra_las_otras_sesiones(app, admin):
    admin.post("/api/usuarios", headers=ORIGEN,
               json={"usuario": "victima1", "password": "clave-vieja-1"})
    otro = app.test_client()
    assert login(otro, "victima1", "clave-vieja-1").status_code == 200
    assert otro.get("/api/me").status_code == 200

    r = admin.patch("/api/usuarios/victima1", headers=ORIGEN, json={"password": "clave-nueva-1"})
    assert r.status_code == 200
    assert otro.get("/api/me").status_code == 401
    assert login(otro, "victima1", "clave-nueva-1").status_code == 200


def test_borrar_usuario_cierra_su_sesion(app, admin):
    admin.post("/api/usuarios", headers=ORIGEN,
               json={"usuario": "borrable1", "password": "12345678"})
    otro = app.test_client()
    login(otro, "borrable1", "12345678")
    assert admin.delete("/api/usuarios/borrable1", headers=ORIGEN).status_code == 200
    assert otro.get("/api/me").status_code == 401


def test_degradar_a_lector_quita_permisos_al_instante(app, admin):
    admin.post("/api/usuarios", headers=ORIGEN,
               json={"usuario": "admin2", "password": "12345678", "rol": "admin"})
    otro = app.test_client()
    login(otro, "admin2", "12345678")
    assert otro.get("/api/usuarios").status_code == 200
    admin.patch("/api/usuarios/admin2", headers=ORIGEN, json={"rol": "lector"})
    # La sesión vieja quedó invalidada; al volver a entrar ya es lector.
    assert otro.get("/api/usuarios").status_code == 401
    login(otro, "admin2", "12345678")
    assert otro.get("/api/usuarios").status_code == 403


def test_usuario_cambia_su_propia_contraseña(lector):
    r = lector.post("/api/me/password", headers=ORIGEN,
                    json={"actual": "incorrecta", "nueva": "otra-clave-123"})
    assert r.status_code == 400
    r = lector.post("/api/me/password", headers=ORIGEN,
                    json={"actual": "clave-lector-123", "nueva": "otra-clave-123"})
    assert r.status_code == 200
    # La sesión actual sigue válida
    assert lector.get("/api/me").status_code == 200
    # Restaurar para los demás tests
    lector.post("/api/me/password", headers=ORIGEN,
                json={"actual": "otra-clave-123", "nueva": "clave-lector-123"})


# ── Proxy de imágenes de hitomi (SSRF) ────────────────────────────────────────

@pytest.mark.parametrize("url,valida", [
    ("https://a.gold-usergeneratedcontent.net/abc.webp", True),
    ("https://gold-usergeneratedcontent.net/abc.webp", True),
    ("https://evil.example/?gold-usergeneratedcontent.net", False),
    ("https://evil.example/gold-usergeneratedcontent.net/x", False),
    ("https://gold-usergeneratedcontent.net.evil.example/x", False),
    ("https://evilgold-usergeneratedcontent.net/x", False),
    ("http://a.gold-usergeneratedcontent.net/x", False),
    ("https://user@a.gold-usergeneratedcontent.net/x", False),
    ("https://a.gold-usergeneratedcontent.net:8080/x", False),
    ("https://127.0.0.1/x", False),
    ("", False),
])
def test_proxy_hitomi_solo_acepta_el_cdn(url, valida):
    assert _es_url_cdn_hitomi(url) is valida


def test_proxy_hitomi_rechaza_url_ajena(admin):
    r = admin.get("/api/descargas/hitomi/imagen?url=https://evil.example/?gold-usergeneratedcontent.net")
    assert r.status_code == 400
