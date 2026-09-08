import os

from fastapi.testclient import TestClient

from app.config import settings
from app.main import app
from tests.support import write_csv_fixture, write_json_fixture

client = TestClient(app)


def test_profile_valid_csv_returns_200_with_contract_shape() -> None:
    identifier = write_csv_fixture("age\n18\n25\n30\n")

    response = client.post(
        "/internal/profile",
        json={"datasetVersionId": "11111111-1111-1111-1111-111111111111", "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["schemaVersion"] == "1.0"
    assert body["dataset"]["rows"] == 3
    assert body["columns"][0]["name"] == "age"


def test_profile_unknown_storage_identifier_returns_404_without_leaking_paths() -> None:
    response = client.post(
        "/internal/profile",
        json={
            "datasetVersionId": "11111111-1111-1111-1111-111111111111",
            "storageIdentifier": "ab/00000000-0000-0000-0000-000000000000.csv",
            "extension": "csv",
        },
    )

    assert response.status_code == 404
    body = response.json()
    assert body["status"] == "error"
    assert settings.storage_dir not in body["message"]
    assert "Traceback" not in body["message"]


def test_profile_path_traversal_attempt_returns_404() -> None:
    response = client.post(
        "/internal/profile",
        json={
            "datasetVersionId": "11111111-1111-1111-1111-111111111111",
            "storageIdentifier": "../../etc/passwd",
            "extension": "csv",
        },
    )

    assert response.status_code == 404


def test_profile_unparseable_json_shape_returns_422() -> None:
    identifier = write_json_fixture(42)  # a bare scalar isn't tabular

    response = client.post(
        "/internal/profile",
        json={"datasetVersionId": "11111111-1111-1111-1111-111111111111", "storageIdentifier": identifier, "extension": "json"},
    )

    assert response.status_code == 422
    assert response.json()["status"] == "error"


def test_profile_oversized_file_returns_413() -> None:
    big_content = "a\n" + "\n".join(str(i) for i in range(1, 2_000_000))
    identifier = write_csv_fixture(big_content)
    actual_size = os.path.getsize(os.path.join(settings.storage_dir, identifier))
    assert actual_size > settings.max_profilable_bytes  # sanity check on the fixture itself

    response = client.post(
        "/internal/profile",
        json={"datasetVersionId": "11111111-1111-1111-1111-111111111111", "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 413
    assert response.json()["status"] == "error"


def test_profile_too_many_rows_returns_413() -> None:
    rows = "\n".join(str(i) for i in range(settings.max_rows + 10))
    identifier = write_csv_fixture("a\n" + rows + "\n")

    response = client.post(
        "/internal/profile",
        json={"datasetVersionId": "11111111-1111-1111-1111-111111111111", "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 413


def test_profile_malformed_request_body_returns_400() -> None:
    response = client.post("/internal/profile", json={"storageIdentifier": "ab/x.csv"})  # missing required fields

    assert response.status_code == 400
    assert response.json()["status"] == "error"


def test_profile_response_never_contains_nan_or_infinity_literals() -> None:
    identifier = write_csv_fixture("a\n1\ninf\n-inf\n3.2\n")

    response = client.post(
        "/internal/profile",
        json={"datasetVersionId": "11111111-1111-1111-1111-111111111111", "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 200
    raw_text = response.text
    assert "NaN" not in raw_text
    assert "Infinity" not in raw_text
