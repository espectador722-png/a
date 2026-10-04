import os
import sys
import tempfile

import pytest

# Config lee las rutas de variables de entorno al importarse: hay que
# definirlas antes de importar la app.
_TMP = tempfile.mkdtemp(prefix="manga-test-")
os.environ["MANGA_DIR"] = os.path.join(_TMP, "mangas")
os.environ["DATA_DIR"] = os.path.join(_TMP, "datos")
os.environ["TRADUCTOR_DIR"] = os.path.join(_TMP, "no-instalado")
os.environ["ADMIN_USER"] = "admin"
os.environ["ADMIN_PASSWORD"] = "clave-admin-123"
os.makedirs(os.environ["MANGA_DIR"], exist_ok=True)

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import app as app_module  # noqa: E402
from routes import auth  # noqa: E402

ORIGEN = {"Origin": "http://localhost"}


@pytest.fixture
def app():
    app_module.app.config.update(TESTING=True)
    auth._fallos.clear()
    return app_module.app


@pytest.fixture
def client(app):
    return app.test_client()


def login(client, usuario, password):
    return client.post("/login", json={"usuario": usuario, "password": password},
                       headers=ORIGEN)


@pytest.fixture
def admin(client):
    r = login(client, "admin", "clave-admin-123")
    assert r.status_code == 200, r.get_json()
    return client


@pytest.fixture
def lector(app):
    """Cliente con sesión de un lector (la cuenta se crea una sola vez)."""
    if "lector1" not in auth._cargar():
        ok, error = auth.crear_usuario("lector1", "clave-lector-123", "lector")
        assert ok, error
    c = app.test_client()
    r = login(c, "lector1", "clave-lector-123")
    assert r.status_code == 200, r.get_json()
    return c
