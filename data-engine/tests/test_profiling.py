"""
Deterministic profiling tests. Each test writes a small fixture file into
the (temp, per-test-session) storage dir and profiles it through the real
read_dataframe -> profile_dataframe pipeline — no mocking of pandas.
"""

import math

import pandas as pd
import pytest

from app.config import settings
from app.profiling.dataset_profiler import profile_dataframe
from app.profiling.readers import read_dataframe
from app.storage import DatasetStorageResolver
from tests.support import write_csv_fixture, write_json_fixture, write_raw_json_fixture, write_xlsx_fixture

storage = DatasetStorageResolver(settings.storage_dir)


def _profile(identifier: str, extension: str):
    path = storage.resolve(identifier)
    df = read_dataframe(path, extension, settings.max_rows, settings.max_columns, settings.max_profilable_bytes)
    return profile_dataframe(df, settings.profile_schema_version, settings.top_values_limit)


# 1. CSV numérique
def test_numeric_csv() -> None:
    identifier = write_csv_fixture("age\n18\n25\n30\n45\n74\n")
    result = _profile(identifier, "csv")

    assert result.dataset.rows == 5
    assert result.dataset.columns == 1
    col = result.columns[0]
    assert col.detectedType == "integer"
    assert col.nullCount == 0
    assert col.statistics.min == 18
    assert col.statistics.max == 74
    assert col.statistics.mean == pytest.approx(38.4)
    assert col.statistics.median == 30
    assert col.statistics.std == pytest.approx(22.2337, rel=1e-4)
    assert col.statistics.q25 == 25
    assert col.statistics.q75 == 45


# 2. CSV texte (high-cardinality free text -> "string", not "categorical")
def test_text_csv() -> None:
    identifier = write_csv_fixture("bio\nLoves hiking\nEnjoys jazz\nCollects stamps\nPlays chess\n")
    result = _profile(identifier, "csv")

    col = result.columns[0]
    assert col.detectedType == "string"
    assert col.uniqueCount == 4
    assert col.stringStatistics is not None
    assert col.stringStatistics.minLength == len("Plays chess")
    assert col.stringStatistics.maxLength == len("Collects stamps")


# 3. CSV avec valeurs nulles
# (a truly blank line is ambiguous — pandas' default, and the more common
# real-world convention, is to skip it entirely rather than treat it as a
# one-column null row; a null cell is instead represented the ordinary
# CSV way, as an empty field next to other populated fields on the line)
def test_csv_with_nulls() -> None:
    identifier = write_csv_fixture("name,age\nAlice,18\nBob,\nCarol,30\nDave,\nEve,74\n")
    result = _profile(identifier, "csv")

    col = next(c for c in result.columns if c.name == "age")
    assert result.dataset.rows == 5
    assert col.nullCount == 2
    assert col.nullPercentage == pytest.approx(40.0)
    assert col.nullable is True
    assert col.statistics.min == 18
    assert col.statistics.max == 74
    assert col.statistics.mean == pytest.approx((18 + 30 + 74) / 3)


# 4. CSV avec doublons (duplicate rows)
def test_csv_with_duplicate_rows() -> None:
    identifier = write_csv_fixture("name,age\nAlice,30\nBob,25\nAlice,30\nAlice,30\n")
    result = _profile(identifier, "csv")

    assert result.dataset.duplicateRowCount == 2
    assert result.dataset.duplicateRowPercentage == pytest.approx(50.0)


# 5. CSV mixte (several business types together, incl. categorical)
def test_mixed_csv() -> None:
    rows = ["id,city,note"]
    cities = ["Paris"] * 4 + ["Dakar"] * 3 + ["Lyon"] * 3
    for i, city in enumerate(cities, start=1):
        rows.append(f"{i},{city},note-{i}")
    identifier = write_csv_fixture("\n".join(rows) + "\n")

    result = _profile(identifier, "csv")
    by_name = {c.name: c for c in result.columns}

    assert by_name["id"].detectedType == "integer"
    assert by_name["city"].detectedType == "categorical"
    assert by_name["city"].categorical.mostFrequentValue == "Paris"
    assert by_name["city"].categorical.mostFrequentCount == 4
    assert by_name["note"].detectedType == "string"  # all-unique free text


# 6. XLSX
def test_xlsx() -> None:
    df = pd.DataFrame({"score": [1.5, 2.5, 3.5], "label": ["a", "b", "c"]})
    identifier = write_xlsx_fixture(df)

    result = _profile(identifier, "xlsx")

    assert result.dataset.rows == 3
    assert result.dataset.columns == 2
    by_name = {c.name: c for c in result.columns}
    assert by_name["score"].detectedType == "float"
    assert by_name["score"].statistics.mean == pytest.approx(2.5)


# 7. JSON (array of objects, including a native boolean)
def test_json_array_of_objects() -> None:
    identifier = write_json_fixture(
        [
            {"name": "Alice", "active": True, "age": 30},
            {"name": "Bob", "active": False, "age": 25},
        ],
    )

    result = _profile(identifier, "json")
    by_name = {c.name: c for c in result.columns}

    assert result.dataset.rows == 2
    assert by_name["active"].detectedType == "boolean"
    assert by_name["age"].detectedType == "integer"


# 8. Colonne entièrement vide
def test_entirely_empty_column() -> None:
    identifier = write_csv_fixture("a,b\n1,\n2,\n3,\n")
    result = _profile(identifier, "csv")
    by_name = {c.name: c for c in result.columns}

    assert by_name["b"].detectedType == "unknown"
    assert by_name["b"].nullCount == 3
    assert by_name["b"].nullPercentage == pytest.approx(100.0)
    assert by_name["b"].uniqueCount == 0


# 9. Colonne avec une seule valeur
def test_single_value_column() -> None:
    identifier = write_csv_fixture("a\n5\n5\n5\n5\n")
    result = _profile(identifier, "csv")
    col = result.columns[0]

    assert col.uniqueCount == 1
    assert col.statistics.std == 0.0
    assert col.outliers.outlierCount == 0


# 10a. Valeurs infinies -> jamais exposées, jamais NaN
def test_infinite_values_are_sanitized_to_null() -> None:
    identifier = write_csv_fixture("a\n1\ninf\n-inf\n3.2\n")
    result = _profile(identifier, "csv")
    col = result.columns[0]

    assert col.statistics.max is not None and math.isfinite(col.statistics.max)
    assert col.statistics.min is not None and math.isfinite(col.statistics.min)
    assert col.statistics.mean == pytest.approx((1 + 3.2) / 2)


# 10b. Valeurs numériques invalides mélangées à du texte -> reste honnête (string), pas de coercition agressive
def test_garbage_numeric_values_fall_back_to_string_type() -> None:
    identifier = write_csv_fixture("a\n1\n2\nabc\n4\n")
    result = _profile(identifier, "csv")
    col = result.columns[0]

    assert col.detectedType == "string"
    assert col.statistics is None


# 11. Dates ambiguës
def test_clean_iso_dates_are_detected() -> None:
    identifier = write_csv_fixture("d\n2026-01-01\n2026-02-15\n2026-03-20\n")
    result = _profile(identifier, "csv")
    assert result.columns[0].detectedType == "date"


def test_iso_datetimes_are_detected() -> None:
    identifier = write_csv_fixture("d\n2026-01-01T10:30:00\n2026-01-02T11:15:00\n")
    result = _profile(identifier, "csv")
    assert result.columns[0].detectedType == "datetime"


def test_mostly_non_date_text_is_not_misclassified_as_date() -> None:
    identifier = write_csv_fixture("d\n2026-01-01\nhello\nworld\nfoo\n")
    result = _profile(identifier, "csv")
    assert result.columns[0].detectedType != "date"
    assert result.columns[0].detectedType != "datetime"


# 12. Dataset vide (JSON empty array — CSV/XLSX empty files are already
# rejected at Phase 2 upload time, so an empty dataset can only reach
# profiling via this shape)
def test_empty_json_array() -> None:
    identifier = write_raw_json_fixture("[]")
    result = _profile(identifier, "json")

    assert result.dataset.rows == 0
    assert result.dataset.columns == 0
    assert result.dataset.nullPercentage == 0.0
    assert result.dataset.duplicateRowPercentage == 0.0
    assert result.columns == []
