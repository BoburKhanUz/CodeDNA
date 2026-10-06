from fastapi.testclient import TestClient

from app.config import Settings
from app.main import create_app
from app.versions import ANALYZER_VERSION, CONTRACT_VERSION, IR_VERSION


def test_health_reports_only_status_versions_and_limits(settings: Settings) -> None:
    with TestClient(create_app(settings)) as client:
        response = client.get("/internal/v1/health")

    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "versions": {"analyzer": ANALYZER_VERSION, "contract": CONTRACT_VERSION, "ir": IR_VERSION},
        "limits": settings.limits(),
    }
    body = response.text
    for secret_like in (settings.hmac_secret, settings.workspace_root, "minio", "tmp"):
        assert secret_like not in body
    assert response.headers["x-request-id"]


def test_interactive_docs_and_unknown_routes_are_not_exposed(settings: Settings) -> None:
    with TestClient(create_app(settings)) as client:
        for path in ("/docs", "/redoc", "/openapi.json", "/health", "/internal/v1/unknown"):
            response = client.get(path)
            assert response.status_code == 404
            assert response.json()["error"]["code"] == "NOT_FOUND"
        assert client.get("/internal/v1/analyze").json()["error"]["code"] == "METHOD_NOT_ALLOWED"


def test_request_ids_are_preserved_or_generated(settings: Settings) -> None:
    given = "6f1c2a4e-1d3b-4c55-9a7e-2b8f0c9d1e23"
    with TestClient(create_app(settings)) as client:
        assert client.get("/internal/v1/health", headers={"X-Request-ID": given}).headers["x-request-id"] == given
        generated = client.get("/internal/v1/health", headers={"X-Request-ID": "not-a-uuid\r\nx: y"}).headers["x-request-id"]
    assert generated != given and len(generated) == 36
