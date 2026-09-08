"""
Computes the statistics for one column. Every numeric value that could be
NaN or +/-Infinity is passed through `_safe_float` before it can reach a
Pydantic model — the API must never emit NaN/Infinity in JSON.
"""

import math

import numpy as np
import pandas as pd

from app.profiling.contract import (
    CategoricalStatistics,
    ColumnProfile,
    NumericStatistics,
    OutlierStatistics,
    StringStatistics,
    TopValue,
)
from app.profiling.type_detection import detect_column_type

MAX_TOP_VALUE_STRING_LENGTH = 200
MIN_VALUES_FOR_OUTLIER_DETECTION = 4


def profile_column(series: pd.Series, total_rows: int, top_values_limit: int) -> ColumnProfile:
    non_null = series.dropna()
    null_count = total_rows - len(non_null)
    unique_count = int(non_null.nunique())

    detection = detect_column_type(series)
    detected_type = detection.detected_type

    numeric_source = None
    if detected_type in ("integer", "float"):
        numeric_source = pd.to_numeric(non_null, errors="coerce").replace([np.inf, -np.inf], np.nan).dropna()
    elif detected_type in ("date", "datetime") and detection.parsed_dates is not None:
        numeric_source = detection.parsed_dates.dropna().astype("int64") / 1e9  # seconds since epoch, for min/max only

    statistics = _numeric_statistics(numeric_source) if detected_type in ("integer", "float") else None
    string_statistics = _string_statistics(non_null) if detected_type in ("string", "categorical") else None
    categorical = _categorical_statistics(non_null, total_rows, top_values_limit) if detected_type == "categorical" else None
    outliers = _outlier_statistics(numeric_source, total_rows) if detected_type in ("integer", "float") else None

    return ColumnProfile(
        name=str(series.name),
        pandasType=str(series.dtype),
        detectedType=detected_type,
        nullable=null_count > 0,
        nullCount=int(null_count),
        nullPercentage=_percentage(null_count, total_rows),
        uniqueCount=unique_count,
        uniquePercentage=_percentage(unique_count, total_rows),
        duplicateCount=max(0, total_rows - unique_count),
        statistics=statistics,
        stringStatistics=string_statistics,
        categorical=categorical,
        outliers=outliers,
    )


def _numeric_statistics(values: "pd.Series | None") -> NumericStatistics:
    if values is None or len(values) == 0:
        return NumericStatistics()

    return NumericStatistics(
        min=_safe_float(values.min()),
        max=_safe_float(values.max()),
        mean=_safe_float(values.mean()),
        median=_safe_float(values.median()),
        std=_safe_float(values.std(ddof=1)) if len(values) > 1 else None,
        variance=_safe_float(values.var(ddof=1)) if len(values) > 1 else None,
        q25=_safe_float(values.quantile(0.25)),
        q50=_safe_float(values.quantile(0.50)),
        q75=_safe_float(values.quantile(0.75)),
    )


def _string_statistics(non_null: pd.Series) -> StringStatistics:
    as_str = non_null.astype(str)
    lengths = as_str.str.len()

    empty_count = int((as_str == "").sum())
    whitespace_only_count = int(((as_str != "") & (as_str.str.strip() == "")).sum())

    if len(lengths) == 0:
        return StringStatistics(emptyCount=empty_count, whitespaceOnlyCount=whitespace_only_count)

    return StringStatistics(
        minLength=int(lengths.min()),
        maxLength=int(lengths.max()),
        meanLength=_safe_float(lengths.mean()),
        emptyCount=empty_count,
        whitespaceOnlyCount=whitespace_only_count,
    )


def _categorical_statistics(non_null: pd.Series, total_rows: int, top_values_limit: int) -> CategoricalStatistics:
    counts = non_null.astype(str).value_counts()

    top_values = [
        TopValue(
            value=_truncate(str(value)),
            count=int(count),
            percentage=_percentage(int(count), total_rows),
        )
        for value, count in counts.head(top_values_limit).items()
    ]

    most_frequent = counts.index[0] if len(counts) > 0 else None
    most_frequent_count = int(counts.iloc[0]) if len(counts) > 0 else None

    return CategoricalStatistics(
        mostFrequentValue=_truncate(str(most_frequent)) if most_frequent is not None else None,
        mostFrequentCount=most_frequent_count,
        topValues=top_values,
        truncated=len(counts) > top_values_limit,
    )


def _outlier_statistics(values: "pd.Series | None", total_rows: int) -> "OutlierStatistics | None":
    if values is None or len(values) < MIN_VALUES_FOR_OUTLIER_DETECTION:
        return None

    q1 = values.quantile(0.25)
    q3 = values.quantile(0.75)
    iqr = q3 - q1

    if iqr == 0 or not math.isfinite(iqr):
        return OutlierStatistics(outlierCount=0, outlierPercentage=0.0)

    lower = q1 - 1.5 * iqr
    upper = q3 + 1.5 * iqr
    outlier_count = int(((values < lower) | (values > upper)).sum())

    return OutlierStatistics(outlierCount=outlier_count, outlierPercentage=_percentage(outlier_count, total_rows))


def _safe_float(value: object) -> "float | None":
    try:
        as_float = float(value)  # type: ignore[arg-type]
    except (TypeError, ValueError):
        return None

    if math.isnan(as_float) or math.isinf(as_float):
        return None

    return as_float


def _percentage(part: int, total: int) -> float:
    if total <= 0:
        return 0.0
    return round((part / total) * 100, 4)


def _truncate(value: str) -> str:
    if len(value) <= MAX_TOP_VALUE_STRING_LENGTH:
        return value
    return value[:MAX_TOP_VALUE_STRING_LENGTH] + "…"
