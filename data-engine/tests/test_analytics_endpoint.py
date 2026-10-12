import os

from fastapi.testclient import TestClient

from app.config import settings
from app.main import app
from tests.support import write_csv_fixture

client = TestClient(app)

_VERSION_ID = "11111111-1111-1111-1111-111111111111"


def test_analytics_valid_csv_returns_200_with_contract_shape() -> None:
    # 8 rows so "city" clears Phase 3's categorical ratio threshold.
    rows = "\n".join(["25,Paris", "30,Dakar", "41,Paris", "25,Lyon", "30,Paris", "41,Dakar", "25,Lyon", "30,Paris"])
    identifier = write_csv_fixture("age,city\n" + rows + "\n")

    response = client.post(
        "/internal/analytics",
        json={"datasetVersionId": _VERSION_ID, "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["schemaVersion"] == "1.0"
    assert body["dataset"]["rows"] == 8
    assert body["summary"]["numericColumnCount"] == 1
    assert body["summary"]["categoricalColumnCount"] == 1
    assert len(body["numeric"]) == 1
    assert body["numeric"][0]["column"] == "age"
    assert len(body["categorical"]) == 1
    assert body["categorical"][0]["column"] == "city"


def test_analytics_with_numeric_categorical_and_temporal_columns() -> None:
    rows = "\n".join(f"{20 + i},dept-{i % 3},2026-0{1 + i % 9}-01" for i in range(10))
    identifier = write_csv_fixture("age,department,joined_at\n" + rows + "\n")

    response = client.post(
        "/internal/analytics",
        json={"datasetVersionId": _VERSION_ID, "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["summary"]["numericColumnCount"] == 1
    assert body["summary"]["categoricalColumnCount"] == 1
    assert body["summary"]["temporalColumnCount"] == 1
    assert len(body["temporal"]) == 1
    assert body["temporal"][0]["chart"]["type"] == "time_series"


def test_analytics_two_numeric_columns_produce_correlation_and_scatter() -> None:
    rows = "\n".join(f"{i},{i * 2}" for i in range(20))
    identifier = write_csv_fixture("a,b\n" + rows + "\n")

    response = client.post(
        "/internal/analytics",
        json={"datasetVersionId": _VERSION_ID, "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["correlations"] is not None
    assert body["correlations"]["method"] == "pearson"
    assert len(body["scatter"]) >= 1
    assert body["scatter"][0]["type"] == "scatter"


def test_analytics_unknown_storage_identifier_returns_404_without_leaking_paths() -> None:
    response = client.post(
        "/internal/analytics",
        json={
            "datasetVersionId": _VERSION_ID,
            "storageIdentifier": "ab/00000000-0000-0000-0000-000000000000.csv",
            "extension": "csv",
        },
    )

    assert response.status_code == 404
    body = response.json()
    assert body["status"] == "error"
    assert settings.storage_dir not in body["message"]
    assert "Traceback" not in body["message"]


def test_analytics_path_traversal_attempt_returns_404() -> None:
    response = client.post(
        "/internal/analytics",
        json={"datasetVersionId": _VERSION_ID, "storageIdentifier": "../../etc/passwd", "extension": "csv"},
    )

    assert response.status_code == 404


def test_analytics_oversized_file_returns_413() -> None:
    big_content = "a\n" + "\n".join(str(i) for i in range(1, 2_000_000))
    identifier = write_csv_fixture(big_content)
    actual_size = os.path.getsize(os.path.join(settings.storage_dir, identifier))
    assert actual_size > settings.max_profilable_bytes  # sanity check on the fixture itself

    response = client.post(
        "/internal/analytics",
        json={"datasetVersionId": _VERSION_ID, "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 413
    assert response.json()["status"] == "error"


def test_analytics_too_many_rows_returns_413() -> None:
    rows = "\n".join(str(i) for i in range(settings.max_rows + 10))
    identifier = write_csv_fixture("a\n" + rows + "\n")

    response = client.post(
        "/internal/analytics",
        json={"datasetVersionId": _VERSION_ID, "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 413


def test_analytics_malformed_request_body_returns_400() -> None:
    response = client.post("/internal/analytics", json={"storageIdentifier": "ab/x.csv"})

    assert response.status_code == 400
    assert response.json()["status"] == "error"


def test_analytics_empty_dataset_returns_200_with_no_analyses() -> None:
    identifier = write_csv_fixture("a,b\n")

    response = client.post(
        "/internal/analytics",
        json={"datasetVersionId": _VERSION_ID, "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["dataset"]["rows"] == 0
    assert body["numeric"] == []
    assert body["categorical"] == []
    assert body["charts"] == []


def test_analytics_response_never_contains_nan_or_infinity_literals() -> None:
    identifier = write_csv_fixture("a\n1\ninf\n-inf\n3.2\n")

    response = client.post(
        "/internal/analytics",
        json={"datasetVersionId": _VERSION_ID, "storageIdentifier": identifier, "extension": "csv"},
    )

    assert response.status_code == 200
    raw_text = response.text
    assert "NaN" not in raw_text
    assert "Infinity" not in raw_text
