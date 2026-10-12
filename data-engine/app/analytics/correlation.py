"""
Pearson correlation matrix across numeric columns. `DataFrame.corr()`
already computes each pair over its own pairwise-complete rows (dropping
only the rows where *that* pair has a NaN) and yields NaN for a constant
column (zero variance) automatically — both get normalized to `null` via
`_safe_float`, since the API must never emit NaN/Infinity in JSON.
"""

import math

import pandas as pd

from app.analytics.contract import CorrelationMatrix


def compute_correlation_matrix(numeric_df: pd.DataFrame, max_columns: int) -> "CorrelationMatrix | None":
    columns = list(numeric_df.columns)[:max_columns]
    if len(columns) < 2:
        return None

    corr = numeric_df[columns].corr(method="pearson")
    matrix = [[_safe_float(value) for value in row] for row in corr.to_numpy()]

    return CorrelationMatrix(columns=columns, matrix=matrix)


def _safe_float(value: object) -> "float | None":
    try:
        as_float = float(value)  # type: ignore[arg-type]
    except (TypeError, ValueError):
        return None

    if math.isnan(as_float) or math.isinf(as_float):
        return None

    return round(as_float, 6)
