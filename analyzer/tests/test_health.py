from fastapi.testclient import TestClient

from app import __version__
from app.main import app

client = TestClient(app)


def test_health_reports_status_and_version() -> None:
    response = client.get("/internal/v1/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "versions": {"analyzer": __version__}}


def test_interactive_docs_are_disabled() -> None:
    for path in ("/docs", "/redoc", "/openapi.json"):
        assert client.get(path).status_code == 404
