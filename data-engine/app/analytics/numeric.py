"""
Numeric column analytics: descriptive statistics, histogram, boxplot.
Every float that could be NaN/+-Infinity goes through `_safe_float` before
reaching a Pydantic model, same discipline as app/profiling/column_profiler.py.
"""

import math

import numpy as np
import pandas as pd

from app.analytics.contract import BoxplotChart, HistogramChart, NumericAnalysis, NumericAnalyticsStatistics


def analyze_numeric_column(series: pd.Series, total_rows: int, max_bins: int) -> NumericAnalysis:
    column = str(series.name)
    non_null = pd.to_numeric(series.dropna(), errors="coerce").replace([np.inf, -np.inf], np.nan).dropna()
    count = len(non_null)
    missing_count = total_rows - count
    unique_count = int(non_null.nunique())

    min_v = _safe_float(non_null.min()) if count > 0 else None
    max_v = _safe_float(non_null.max()) if count > 0 else None
    q1 = _safe_float(non_null.quantile(0.25)) if count > 0 else None
    q3 = _safe_float(non_null.quantile(0.75)) if count > 0 else None
    iqr = round(q3 - q1, 10) if (q1 is not None and q3 is not None) else None
    range_v = round(max_v - min_v, 10) if (min_v is not None and max_v is not None) else None

    statistics = NumericAnalyticsStatistics(
        count=count,
        missingCount=int(missing_count),
        missingPercentage=_percentage(missing_count, total_rows),
        uniqueCount=unique_count,
        min=min_v,
        max=max_v,
        mean=_safe_float(non_null.mean()) if count > 0 else None,
        median=_safe_float(non_null.median()) if count > 0 else None,
        standardDeviation=_safe_float(non_null.std(ddof=1)) if count > 1 else None,
        variance=_safe_float(non_null.var(ddof=1)) if count > 1 else None,
        q1=q1,
        q3=q3,
        iqr=iqr,
        sum=_safe_float(non_null.sum()) if count > 0 else None,
        range=range_v,
    )

    histogram = _build_histogram(non_null, column, max_bins) if count > 0 else None
    boxplot = _build_boxplot(non_null, column, q1, q3, iqr, min_v, max_v) if count > 0 else None

    return NumericAnalysis(column=column, statistics=statistics, histogram=histogram, boxplot=boxplot)


def _build_histogram(values: pd.Series, column: str, max_bins: int) -> HistogramChart:
    v_min = float(values.min())
    v_max = float(values.max())

    if v_min == v_max:
        # A constant column still gets a valid (single-bin) histogram
        # rather than being silently omitted.
        return HistogramChart(column=column, bins=[v_min, v_max], counts=[len(values)])

    unique_count = int(values.nunique())
    bin_count = max(1, min(max_bins, unique_count))
    counts, edges = np.histogram(values.to_numpy(), bins=bin_count)

    return HistogramChart(
        column=column,
        bins=[_safe_float(edge) or 0.0 for edge in edges],
        counts=[int(c) for c in counts],
    )


def _build_boxplot(
    values: pd.Series,
    column: str,
    q1: "float | None",
    q3: "float | None",
    iqr: "float | None",
    min_v: "float | None",
    max_v: "float | None",
) -> "BoxplotChart | None":
    if q1 is None or q3 is None or iqr is None:
        return None

    median = _safe_float(values.median())

    # Same policy as profiling's outlier detection (column_profiler.py):
    # an IQR of exactly 0 (heavily repeated values) is treated as "no
    # outliers" rather than flagging every non-identical value.
    if iqr == 0:
        lower_fence, upper_fence, outlier_count = q1, q3, 0
    else:
        lower_fence = round(q1 - 1.5 * iqr, 10)
        upper_fence = round(q3 + 1.5 * iqr, 10)
        outlier_count = int(((values < lower_fence) | (values > upper_fence)).sum())

    return BoxplotChart(
        column=column,
        min=min_v,
        q1=q1,
        median=median,
        q3=q3,
        max=max_v,
        lowerFence=_safe_float(lower_fence),
        upperFence=_safe_float(upper_fence),
        outlierCount=outlier_count,
    )


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
