"""
Each function applies exactly one operation to a DataFrame and returns
(new_df, report). None of them mutate the input DataFrame in place —
callers always get a new object back, keeping every intermediate pipeline
step inspectable and the whole thing side-effect-free.

Numeric/date conversion strategy (deliberate choice, see docs/data-cleaning.md
"Conversion failure policy"): if ANY non-null value in the column can't be
safely converted, the WHOLE operation fails with a 422 listing a sample of
the offending values — nothing is partially converted. A column that ends
up "converted" always has every non-null value genuinely and safely
converted; there is no silent NaN-ing of bad values and no mixed-type
column left behind.
"""

import re
from typing import Any

import pandas as pd

from app.cleaning.contract import OperationReport
from app.cleaning.errors import ColumnNotFoundError, IncompatibleOperationError
from app.profiling.type_detection import _DATE_PATTERNS, _DATETIME_PATTERNS

MAX_SAMPLE_INVALID_VALUES = 5


def require_column(df: pd.DataFrame, column: str) -> None:
    if column not in df.columns:
        raise ColumnNotFoundError(column)


def require_numeric(df: pd.DataFrame, column: str, operation: str) -> None:
    if not pd.api.types.is_numeric_dtype(df[column]):
        raise IncompatibleOperationError(f'"{operation}" requires a numeric column; "{column}" is not numeric.')


def remove_duplicate_rows(df: pd.DataFrame) -> tuple[pd.DataFrame, OperationReport]:
    before_rows = len(df)
    new_df = df.drop_duplicates(keep="first").reset_index(drop=True)
    after_rows = len(new_df)

    return new_df, OperationReport(
        operation="remove_duplicate_rows",
        affectedRows=before_rows - after_rows,
        beforeRows=before_rows,
        afterRows=after_rows,
    )


def trim_whitespace(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    series = df[column]

    def _trim(value: object) -> object:
        return value.strip() if isinstance(value, str) else value

    new_series = series.map(_trim)
    affected = int(_count_changed(series, new_series))

    new_df = df.copy()
    new_df[column] = new_series

    return new_df, OperationReport(operation="trim_whitespace", column=column, affectedRows=affected)


def normalize_internal_whitespace(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    series = df[column]

    def _normalize(value: object) -> object:
        return re.sub(r"[ \t]+", " ", value) if isinstance(value, str) else value

    new_series = series.map(_normalize)
    affected = int(_count_changed(series, new_series))

    new_df = df.copy()
    new_df[column] = new_series

    return new_df, OperationReport(operation="normalize_internal_whitespace", column=column, affectedRows=affected)


def drop_rows(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    before_rows = len(df)
    before_null = int(df[column].isna().sum())

    new_df = df[df[column].notna()].reset_index(drop=True)

    return new_df, OperationReport(
        operation="drop_rows",
        column=column,
        affectedRows=before_null,
        beforeRows=before_rows,
        afterRows=len(new_df),
        before={"nullCount": before_null},
        after={"nullCount": 0},
    )


def fill_constant(df: pd.DataFrame, column: str, value: Any) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)

    if pd.api.types.is_numeric_dtype(df[column]) and (isinstance(value, bool) or not isinstance(value, (int, float))):
        raise IncompatibleOperationError(f'Column "{column}" is numeric; "{value!r}" is not a compatible constant.')

    before_null = int(df[column].isna().sum())
    new_df = df.copy()
    new_df[column] = df[column].fillna(value)

    return new_df, OperationReport(
        operation="fill_constant",
        column=column,
        affectedRows=before_null,
        before={"nullCount": before_null},
        after={"nullCount": 0},
    )


def fill_mean(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    require_numeric(df, column, "fill_mean")
    return _fill_with(df, column, "fill_mean", df[column].mean())


def fill_median(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    require_numeric(df, column, "fill_median")
    return _fill_with(df, column, "fill_median", df[column].median())


def fill_mode(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    modes = df[column].mode(dropna=True)
    fill_value = modes.iloc[0] if len(modes) > 0 else None
    return _fill_with(df, column, "fill_mode", fill_value)


def _fill_with(df: pd.DataFrame, column: str, operation: str, fill_value: Any) -> tuple[pd.DataFrame, OperationReport]:
    before_null = int(df[column].isna().sum())

    if before_null == 0 or fill_value is None or pd.isna(fill_value):
        # Nothing to fill (no nulls), or nothing computable to fill with
        # (e.g. the column has no non-null values at all) — a safe no-op
        # rather than an error, so an empty/all-null dataset never crashes
        # a pipeline that merely touches it.
        return df.copy(), OperationReport(
            operation=operation, column=column, affectedRows=0,
            before={"nullCount": before_null}, after={"nullCount": before_null},
        )

    new_df = df.copy()
    new_df[column] = df[column].fillna(fill_value)

    return new_df, OperationReport(
        operation=operation,
        column=column,
        affectedRows=before_null,
        before={"nullCount": before_null},
        after={"nullCount": 0},
    )


def convert_to_integer(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    non_null = df[column].dropna()
    numeric = pd.to_numeric(non_null, errors="coerce")

    invalid = non_null[numeric.isna()]
    if len(invalid) > 0:
        raise IncompatibleOperationError(_invalid_values_message(column, "convert_to_integer", invalid))

    non_whole = non_null[numeric != numeric.round(0)]
    if len(non_whole) > 0:
        raise IncompatibleOperationError(_invalid_values_message(column, "convert_to_integer", non_whole))

    # Nullable Int64 (capital I) so existing nulls survive the conversion
    # cleanly regardless of whether any are actually present.
    new_df = df.copy()
    new_df[column] = pd.to_numeric(df[column], errors="coerce").round(0).astype("Int64")

    return new_df, OperationReport(operation="convert_to_integer", column=column, affectedRows=len(non_null))


def convert_to_float(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    non_null = df[column].dropna()
    numeric = pd.to_numeric(non_null, errors="coerce")

    invalid = non_null[numeric.isna()]
    if len(invalid) > 0:
        raise IncompatibleOperationError(_invalid_values_message(column, "convert_to_float", invalid))

    new_df = df.copy()
    new_df[column] = pd.to_numeric(df[column], errors="coerce").astype("float64")

    return new_df, OperationReport(operation="convert_to_float", column=column, affectedRows=len(non_null))


def convert_to_date(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    return _convert_to_temporal(df, column, "convert_to_date", _DATE_PATTERNS, date_only=True)


def convert_to_datetime(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    return _convert_to_temporal(df, column, "convert_to_datetime", _DATE_PATTERNS + _DATETIME_PATTERNS, date_only=False)


def _convert_to_temporal(
    df: pd.DataFrame,
    column: str,
    operation: str,
    patterns: list[re.Pattern],
    date_only: bool,
) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    non_null = df[column].dropna().astype(str)

    combined = re.compile("|".join(p.pattern for p in patterns))
    non_matching = non_null[~non_null.str.match(combined)]
    if len(non_matching) > 0:
        raise IncompatibleOperationError(_invalid_values_message(column, operation, non_matching))

    parsed = pd.to_datetime(non_null, errors="coerce")
    if parsed.isna().any():
        bad = non_null[parsed.isna()]
        raise IncompatibleOperationError(_invalid_values_message(column, operation, bad))

    formatted = parsed.dt.strftime("%Y-%m-%d") if date_only else parsed.dt.strftime("%Y-%m-%dT%H:%M:%S")

    new_column = pd.Series(pd.NA, index=df.index, dtype=object)
    new_column.loc[non_null.index] = formatted
    new_df = df.copy()
    new_df[column] = new_column

    return new_df, OperationReport(operation=operation, column=column, affectedRows=len(non_null))


def drop_column(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    new_df = df.drop(columns=[column])

    return new_df, OperationReport(
        operation="drop_column",
        column=column,
        affectedRows=0,
        details="Column removed from every row.",
    )


def remove_iqr_outliers(df: pd.DataFrame, column: str) -> tuple[pd.DataFrame, OperationReport]:
    require_column(df, column)
    require_numeric(df, column, "remove_iqr_outliers")

    non_null = df[column].dropna()
    if len(non_null) < 4:
        return df.copy(), OperationReport(operation="remove_iqr_outliers", column=column, affectedRows=0, details="Not enough data to compute IQR bounds.")

    q1, q3 = non_null.quantile(0.25), non_null.quantile(0.75)
    iqr = q3 - q1

    if iqr == 0:
        return df.copy(), OperationReport(operation="remove_iqr_outliers", column=column, affectedRows=0)

    lower, upper = q1 - 1.5 * iqr, q3 + 1.5 * iqr
    mask = df[column].notna() & ((df[column] < lower) | (df[column] > upper))
    before_rows = len(df)
    new_df = df[~mask].reset_index(drop=True)

    return new_df, OperationReport(
        operation="remove_iqr_outliers",
        column=column,
        affectedRows=int(mask.sum()),
        beforeRows=before_rows,
        afterRows=len(new_df),
    )


def _count_changed(before: pd.Series, after: pd.Series) -> int:
    both_present = before.notna() & after.notna()
    changed = both_present & (before.astype(str) != after.astype(str))
    return int(changed.sum())


def _invalid_values_message(column: str, operation: str, invalid_values: pd.Series) -> str:
    sample = invalid_values.astype(str).unique()[:MAX_SAMPLE_INVALID_VALUES]
    sample_str = ", ".join(f'"{v}"' for v in sample)
    return (
        f'"{operation}" on column "{column}" failed: {len(invalid_values)} value(s) could not be safely '
        f"converted (e.g. {sample_str}). No changes were applied."
    )
