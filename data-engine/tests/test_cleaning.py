"""
Operation-level tests: exercise app.cleaning.pipeline.run_pipeline()
directly against small, precise, in-memory DataFrames.
"""

import pandas as pd
import pytest

from app.cleaning.errors import CleaningError, ColumnNotFoundError, IncompatibleOperationError, UnknownOperationError
from app.cleaning.pipeline import run_pipeline


def _run(df: pd.DataFrame, operations: list[dict]):
    return run_pipeline(df, operations)


# --- trim / whitespace ---

def test_trim_whitespace() -> None:
    df = pd.DataFrame({"city": [" Paris ", "Dakar", "  Lyon"]})
    result, reports = _run(df, [{"type": "trim_whitespace", "column": "city"}])

    assert list(result["city"]) == ["Paris", "Dakar", "Lyon"]
    assert reports[0].affectedRows == 2  # "Dakar" was already clean


def test_normalize_internal_whitespace_does_not_strip_outer() -> None:
    df = pd.DataFrame({"note": ["a   b", " c  d "]})
    result, reports = _run(df, [{"type": "normalize_internal_whitespace", "column": "note"}])

    assert list(result["note"]) == ["a b", " c d "]
    assert reports[0].affectedRows == 2


# --- missing values ---

def test_drop_rows_removes_rows_with_null_in_column() -> None:
    df = pd.DataFrame({"age": [18, None, 30, None]})
    result, reports = _run(df, [{"type": "drop_rows", "column": "age"}])

    assert len(result) == 2
    assert reports[0].affectedRows == 2
    assert reports[0].beforeRows == 4
    assert reports[0].afterRows == 2


def test_fill_constant() -> None:
    df = pd.DataFrame({"age": [18, None, 30]})
    result, reports = _run(df, [{"type": "fill_constant", "column": "age", "value": 0}])

    assert list(result["age"]) == [18, 0, 30]
    assert reports[0].affectedRows == 1


def test_fill_constant_rejects_incompatible_value_for_numeric_column() -> None:
    df = pd.DataFrame({"age": [18, None, 30]})
    with pytest.raises(IncompatibleOperationError):
        _run(df, [{"type": "fill_constant", "column": "age", "value": "unknown"}])


def test_fill_mean() -> None:
    df = pd.DataFrame({"age": [10.0, 20.0, None]})
    result, reports = _run(df, [{"type": "fill_mean", "column": "age"}])

    assert result["age"].iloc[2] == pytest.approx(15.0)
    assert reports[0].affectedRows == 1


def test_fill_median() -> None:
    df = pd.DataFrame({"age": [10.0, 20.0, 30.0, None]})
    result, reports = _run(df, [{"type": "fill_median", "column": "age"}])

    assert result["age"].iloc[3] == pytest.approx(20.0)


def test_fill_mean_rejects_non_numeric_column() -> None:
    df = pd.DataFrame({"city": ["Paris", None, "Dakar"]})
    with pytest.raises(IncompatibleOperationError):
        _run(df, [{"type": "fill_mean", "column": "city"}])


def test_fill_mode() -> None:
    df = pd.DataFrame({"city": ["Paris", "Paris", "Dakar", None]})
    result, reports = _run(df, [{"type": "fill_mode", "column": "city"}])

    assert result["city"].iloc[3] == "Paris"
    assert reports[0].affectedRows == 1


def test_fill_on_entirely_null_column_is_a_safe_no_op() -> None:
    df = pd.DataFrame({"age": pd.array([None, None], dtype="Float64")})
    result, reports = _run(df, [{"type": "fill_mean", "column": "age"}])

    assert reports[0].affectedRows == 0
    assert result["age"].isna().all()


# --- duplicates ---

def test_remove_duplicate_rows() -> None:
    df = pd.DataFrame({"name": ["Alice", "Bob", "Alice"], "age": [30, 25, 30]})
    result, reports = _run(df, [{"type": "remove_duplicate_rows"}])

    assert len(result) == 2
    assert reports[0].affectedRows == 1
    assert reports[0].beforeRows == 3
    assert reports[0].afterRows == 2


def test_trim_before_duplicates_can_merge_rows_that_would_otherwise_differ() -> None:
    """Documents the "operation order matters" rule with a concrete case."""
    df = pd.DataFrame({"city": ["Paris", "Paris "]})

    _, no_trim_reports = _run(df, [{"type": "remove_duplicate_rows"}])
    assert no_trim_reports[0].affectedRows == 0  # "Paris" != "Paris " -> not duplicates

    trimmed_result, _ = _run(df, [{"type": "trim_whitespace", "column": "city"}, {"type": "remove_duplicate_rows"}])
    assert len(trimmed_result) == 1  # now they merge


# --- numeric conversion ---

def test_convert_to_integer() -> None:
    df = pd.DataFrame({"age": ["18", "25.0", None]})
    result, reports = _run(df, [{"type": "convert_to_integer", "column": "age"}])

    assert result["age"].iloc[0] == 18
    assert result["age"].iloc[1] == 25
    assert pd.isna(result["age"].iloc[2])
    assert reports[0].affectedRows == 2


def test_convert_to_integer_fails_whole_pipeline_on_invalid_value() -> None:
    df = pd.DataFrame({"age": ["18", "abc", "30"]})
    with pytest.raises(IncompatibleOperationError):
        _run(df, [{"type": "convert_to_integer", "column": "age"}])


def test_convert_to_integer_fails_on_non_whole_numbers() -> None:
    df = pd.DataFrame({"age": ["18", "25.7"]})
    with pytest.raises(IncompatibleOperationError):
        _run(df, [{"type": "convert_to_integer", "column": "age"}])


def test_convert_to_float() -> None:
    df = pd.DataFrame({"score": ["1.5", "2", None]})
    result, reports = _run(df, [{"type": "convert_to_float", "column": "score"}])

    assert result["score"].iloc[0] == pytest.approx(1.5)
    assert result["score"].iloc[1] == pytest.approx(2.0)
    assert reports[0].affectedRows == 2


# --- date conversion ---

def test_convert_to_date_normalizes_format() -> None:
    df = pd.DataFrame({"d": ["2026-01-01", "2026-02-15"]})
    result, reports = _run(df, [{"type": "convert_to_date", "column": "d"}])

    assert list(result["d"]) == ["2026-01-01", "2026-02-15"]
    assert reports[0].affectedRows == 2


def test_convert_to_date_rejects_non_matching_values() -> None:
    df = pd.DataFrame({"d": ["2026-01-01", "not a date"]})
    with pytest.raises(IncompatibleOperationError):
        _run(df, [{"type": "convert_to_date", "column": "d"}])


def test_convert_to_datetime() -> None:
    df = pd.DataFrame({"d": ["2026-01-01T10:30:00"]})
    result, reports = _run(df, [{"type": "convert_to_datetime", "column": "d"}])

    assert result["d"].iloc[0] == "2026-01-01T10:30:00"


# --- drop column ---

def test_drop_column() -> None:
    df = pd.DataFrame({"a": [1, 2], "b": [3, 4]})
    result, reports = _run(df, [{"type": "drop_column", "column": "b"}])

    assert list(result.columns) == ["a"]
    assert reports[0].operation == "drop_column"


# --- outliers ---

def test_remove_iqr_outliers() -> None:
    df = pd.DataFrame({"salary": [10, 11, 12, 13, 14, 1000]})
    result, reports = _run(df, [{"type": "remove_iqr_outliers", "column": "salary"}])

    assert 1000 not in list(result["salary"])
    assert reports[0].affectedRows == 1


def test_remove_iqr_outliers_no_op_on_constant_column() -> None:
    df = pd.DataFrame({"salary": [10, 10, 10, 10]})
    result, reports = _run(df, [{"type": "remove_iqr_outliers", "column": "salary"}])

    assert len(result) == 4
    assert reports[0].affectedRows == 0


# --- validation / edge cases ---

def test_unknown_operation_type_is_rejected() -> None:
    df = pd.DataFrame({"a": [1]})
    with pytest.raises(UnknownOperationError):
        _run(df, [{"type": "delete_everything", "column": "a"}])


def test_nonexistent_column_is_rejected() -> None:
    df = pd.DataFrame({"a": [1]})
    with pytest.raises(ColumnNotFoundError):
        _run(df, [{"type": "trim_whitespace", "column": "does_not_exist"}])


def test_missing_required_column_parameter_is_rejected() -> None:
    df = pd.DataFrame({"a": [1]})
    with pytest.raises(CleaningError):
        _run(df, [{"type": "trim_whitespace"}])


def test_empty_dataset_pipeline_is_a_safe_no_op() -> None:
    df = pd.DataFrame({"a": pd.array([], dtype="Int64")})
    result, reports = _run(df, [{"type": "remove_duplicate_rows"}])

    assert len(result) == 0
    assert reports[0].affectedRows == 0


def test_multiple_operations_apply_in_order() -> None:
    df = pd.DataFrame({"city": [" Paris", "Paris", "Dakar"], "age": [30, 30, None]})
    result, reports = _run(
        df,
        [
            {"type": "trim_whitespace", "column": "city"},
            {"type": "remove_duplicate_rows"},
            {"type": "fill_constant", "column": "age", "value": 0},
        ],
    )

    assert len(result) == 2  # the two "Paris" rows merged after trimming
    assert len(reports) == 3
