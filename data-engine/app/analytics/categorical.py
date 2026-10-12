"""
Categorical (and boolean) column analytics: top-value counts and a
bar-chart-ready structure. Always bounded to `top_n` categories plus a
single synthetic "Other" bucket, regardless of how many distinct values
the column actually has — this is what keeps the response small even for
a high-cardinality column (see docs/data-analytics.md "Limits").
"""

import pandas as pd

from app.analytics.contract import BarChart, CategoricalAnalysis, CategoricalAnalyticsStatistics, CategoricalTopValue

MAX_LABEL_LENGTH = 200


def analyze_categorical_column(series: pd.Series, total_rows: int, top_n: int) -> CategoricalAnalysis:
    column = str(series.name)
    non_null = series.dropna().astype(str)
    count = len(non_null)
    missing_count = total_rows - count

    counts = non_null.value_counts()
    unique_count = int(len(counts))

    top = counts.head(top_n)
    other_count = int(counts.iloc[top_n:].sum()) if len(counts) > top_n else 0

    top_values = [
        CategoricalTopValue(value=_truncate(str(value)), count=int(value_count), percentage=_percentage(int(value_count), total_rows))
        for value, value_count in top.items()
    ]

    labels = [_truncate(str(value)) for value in top.index]
    values = [int(value_count) for value_count in top.values]
    if other_count > 0:
        labels.append("Other")
        values.append(other_count)

    statistics = CategoricalAnalyticsStatistics(
        count=count,
        missingCount=int(missing_count),
        missingPercentage=_percentage(missing_count, total_rows),
        uniqueCount=unique_count,
        topValues=top_values,
    )
    chart = BarChart(column=column, labels=labels, values=values)

    return CategoricalAnalysis(column=column, statistics=statistics, chart=chart)


def _truncate(value: str) -> str:
    if len(value) <= MAX_LABEL_LENGTH:
        return value
    return value[:MAX_LABEL_LENGTH] + "…"


def _percentage(part: int, total: int) -> float:
    if total <= 0:
        return 0.0
    return round((part / total) * 100, 4)
