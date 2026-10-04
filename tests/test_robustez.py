import os

from config import Config
from routes.helpers import save_json, load_json


def test_save_json_atomico_conserva_el_archivo_si_falla(tmp_path):
    ruta = tmp_path / "datos.json"
    assert save_json(str(ruta), {"usuarios": ["a"]})
    # Un objeto que no se puede serializar falla a mitad de la escritura
    assert not save_json(str(ruta), {"usuarios": [object()]})
    assert load_json(str(ruta)) == {"usuarios": ["a"]}
    assert os.listdir(tmp_path) == ["datos.json"]   # sin temporales sueltos


def test_no_se_sirven_archivos_internos_de_la_biblioteca(admin):
    with open(os.path.join(Config.BASE_DIR, "colecciones.json"), "w") as f:
        f.write("{}")
    assert admin.get("/mangas/colecciones.json").status_code == 404
