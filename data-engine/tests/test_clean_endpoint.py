"""
HTTP-level + real-file tests: CSV/XLSX/JSON through /internal/clean and
/internal/clean/preview, including the "changed=false" / "file too
large" / cross-format cases that only make sense end to end.
"""

import os

import pandas as pd
from fastapi.testclient import TestClient

from app.config import settings
from app.main import app
from tests.support import write_csv_fixture, write_json_fixture, write_xlsx_fixture

client = TestClient(app)


def _clean(identifier: str, extension: str, operations: list[dict]) -> dict:
    response = client.post(
        "/internal/clean",
        json={"datasetVersionId": "v1", "storageIdentifier": identifier, "extension": extension, "operations": operations},
    )
    return response


def test_clean_csv_with_duplicates_and_whitespace() -> None:
    identifier = write_csv_fixture("name,city\nAlice, Paris \nBob,Dakar\nAlice,Paris\n")

    response = _clean(identifier, "csv", [
        {"type": "trim_whitespace", "column": "city"},
        {"type": "remove_duplicate_rows"},
    ])

    assert response.status_code == 200
    body = response.json()
    assert body["changed"] is True
    assert body["report"]["before"]["dataset"]["rows"] == 3
    assert body["report"]["after"]["dataset"]["rows"] == 2
    assert body["checksum"] is not None
    assert body["tempStorageIdentifier"] is not None

    # The tmp file genuinely exists and contains the cleaned content.
    tmp_path = os.path.join(settings.tmp_dir, body["tempStorageIdentifier"])
    assert os.path.isfile(tmp_path)
    with open(tmp_path, "r", encoding="utf-8") as handle:
        content = handle.read()
    assert "Paris" in content
    assert content.count("Alice") == 1


def test_clean_csv_that_produces_no_change_reports_changed_false() -> None:
    identifier = write_csv_fixture("name,city\nAlice,Paris\nBob,Dakar\n")

    response = _clean(identifier, "csv", [{"type": "trim_whitespace", "column": "city"}])

    assert response.status_code == 200
    body = response.json()
    assert body["changed"] is False
    assert body["tempStorageIdentifier"] is None
    assert body["checksum"] is None
    # before/after must be identical when nothing changed.
    assert body["report"]["before"] == body["report"]["after"]


def test_clean_xlsx_real_workbook() -> None:
    df = pd.DataFrame({"age": [18, None, 40], "city": [" Paris", "Dakar", "Dakar"]})
    identifier = write_xlsx_fixture(df)

    response = _clean(identifier, "xlsx", [
        {"type": "fill_median", "column": "age"},
        {"type": "trim_whitespace", "column": "city"},
    ])

    assert response.status_code == 200
    body = response.json()
    assert body["changed"] is True
    assert body["mimeType"] == "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"

    tmp_path = os.path.join(settings.tmp_dir, body["tempStorageIdentifier"])
    result_df = pd.read_excel(tmp_path, engine="openpyxl")
    assert result_df["age"].isna().sum() == 0
    assert result_df["city"].iloc[0] == "Paris"


def test_clean_json_real_file() -> None:
    identifier = write_json_fixture([
        {"name": "Alice", "age": 30},
        {"name": "Alice", "age": 30},
        {"name": "Bob", "age": None},
    ])

    response = _clean(identifier, "json", [
        {"type": "remove_duplicate_rows"},
        {"type": "fill_constant", "column": "age", "value": 0},
    ])

    assert response.status_code == 200
    body = response.json()
    assert body["changed"] is True
    assert body["report"]["after"]["dataset"]["rows"] == 2


def test_preview_does_not_write_anything_or_change_source() -> None:
    identifier = write_csv_fixture("name,city\nAlice, Paris \nAlice,Paris\n")
    before_files = set(os.listdir(settings.tmp_dir)) if os.path.isdir(settings.tmp_dir) else set()

    response = client.post(
        "/internal/clean/preview",
        json={
            "datasetVersionId": "v1",
            "storageIdentifier": identifier,
            "extension": "csv",
            "operations": [{"type": "trim_whitespace", "column": "city"}, {"type": "remove_duplicate_rows"}],
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["estimatedAffectedRows"] >= 1
    assert "tempStorageIdentifier" not in body or body.get("tempStorageIdentifier") is None

    after_files = set(os.listdir(settings.tmp_dir)) if os.path.isdir(settings.tmp_dir) else set()
    assert before_files == after_files  # nothing was written

    # Source untouched.
    source_path = os.path.join(settings.storage_dir, identifier)
    with open(source_path, "r", encoding="utf-8") as handle:
        assert " Paris " in handle.read()


def test_clean_unknown_storage_identifier_returns_404() -> None:
    response = _clean("ab/00000000-0000-0000-0000-000000000000.csv", "csv", [{"type": "remove_duplicate_rows"}])

    assert response.status_code == 404
    assert response.json()["status"] == "error"


def test_clean_nonexistent_column_returns_422_without_leaking_paths() -> None:
    identifier = write_csv_fixture("a,b\n1,2\n")

    response = _clean(identifier, "csv", [{"type": "trim_whitespace", "column": "not_a_column"}])

    assert response.status_code == 422
    body = response.json()
    assert settings.storage_dir not in body["message"]


def test_clean_unknown_operation_returns_400() -> None:
    identifier = write_csv_fixture("a,b\n1,2\n")

    response = _clean(identifier, "csv", [{"type": "teleport_data", "column": "a"}])

    assert response.status_code == 400


def test_clean_wrong_type_operation_returns_422() -> None:
    identifier = write_csv_fixture("city\nParis\nDakar\n")

    response = _clean(identifier, "csv", [{"type": "fill_mean", "column": "city"}])

    assert response.status_code == 422


def test_clean_invalid_conversion_values_returns_422() -> None:
    identifier = write_csv_fixture("age\n18\nabc\n30\n")

    response = _clean(identifier, "csv", [{"type": "convert_to_integer", "column": "age"}])

    assert response.status_code == 422


def test_clean_oversized_file_returns_413() -> None:
    big_content = "a\n" + "\n".join(str(i) for i in range(1, 2_000_000))
    identifier = write_csv_fixture(big_content)

    response = _clean(identifier, "csv", [{"type": "remove_duplicate_rows"}])

    assert response.status_code == 413


def test_clean_empty_json_array_dataset_is_a_safe_no_op() -> None:
    identifier = write_json_fixture([])

    response = _clean(identifier, "json", [{"type": "remove_duplicate_rows"}])

    assert response.status_code == 200
    body = response.json()
    assert body["changed"] is False
