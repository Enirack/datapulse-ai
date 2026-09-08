"""
Applies an ordered list of operations to a DataFrame, one at a time, each
operating on the output of the previous one — order matters (e.g.
trimming whitespace before removing duplicates can merge rows that
wouldn't otherwise be considered duplicates; the reverse order would not).
See docs/data-cleaning.md "Operation order" for the full explanation.
"""

import pandas as pd

from app.cleaning import operations as ops
from app.cleaning.contract import OperationReport
from app.cleaning.errors import CleaningError, UnknownOperationError

_COLUMN_REQUIRED_OPS = {
    "trim_whitespace", "normalize_internal_whitespace", "drop_rows", "fill_constant",
    "fill_mean", "fill_median", "fill_mode", "convert_to_integer", "convert_to_float",
    "convert_to_date", "convert_to_datetime", "drop_column", "remove_iqr_outliers",
}
_VALUE_REQUIRED_OPS = {"fill_constant"}


def run_pipeline(df: pd.DataFrame, raw_operations: list[dict]) -> tuple[pd.DataFrame, list[OperationReport]]:
    current = df
    reports: list[OperationReport] = []

    for raw in raw_operations:
        op_type = raw.get("type")
        column = raw.get("column")
        value = raw.get("value")

        current, report = _apply_one(current, op_type, column, value)
        reports.append(report)

    return current, reports


def _apply_one(df: pd.DataFrame, op_type: str, column: str | None, value: object) -> tuple[pd.DataFrame, OperationReport]:
    if op_type in _COLUMN_REQUIRED_OPS and not column:
        raise CleaningError(f'Operation "{op_type}" requires a "column".', status_code=400)
    if op_type in _VALUE_REQUIRED_OPS and value is None:
        raise CleaningError(f'Operation "{op_type}" requires a "value".', status_code=400)

    if op_type == "remove_duplicate_rows":
        return ops.remove_duplicate_rows(df)
    if op_type == "trim_whitespace":
        return ops.trim_whitespace(df, column)
    if op_type == "normalize_internal_whitespace":
        return ops.normalize_internal_whitespace(df, column)
    if op_type == "drop_rows":
        return ops.drop_rows(df, column)
    if op_type == "fill_constant":
        return ops.fill_constant(df, column, value)
    if op_type == "fill_mean":
        return ops.fill_mean(df, column)
    if op_type == "fill_median":
        return ops.fill_median(df, column)
    if op_type == "fill_mode":
        return ops.fill_mode(df, column)
    if op_type == "convert_to_integer":
        return ops.convert_to_integer(df, column)
    if op_type == "convert_to_float":
        return ops.convert_to_float(df, column)
    if op_type == "convert_to_date":
        return ops.convert_to_date(df, column)
    if op_type == "convert_to_datetime":
        return ops.convert_to_datetime(df, column)
    if op_type == "drop_column":
        return ops.drop_column(df, column)
    if op_type == "remove_iqr_outliers":
        return ops.remove_iqr_outliers(df, column)

    raise UnknownOperationError(str(op_type))
