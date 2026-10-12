"""
Temporal column analytics: min/max/count plus a time series bucketed at an
automatically chosen granularity. The granularity is picked purely from the
column's date span (see `_choose_granularity`) — never from row count or
any heuristic that could misrepresent the actual spread of dates.
"""

import pandas as pd

from app.analytics.contract import TemporalAnalysis, TemporalStatistics, TimeSeriesChart

# (max span in days, granularity) — first matching threshold wins, in
# ascending order. Chosen to keep the resulting number of buckets in a
# readable range (roughly a handful to a few dozen) without collapsing a
# short span into a single misleading bucket. Anything wider than the last
# threshold falls back to "year".
_GRANULARITY_THRESHOLDS: list[tuple[float, str]] = [
    (62, "day"),
    (210, "week"),
    (900, "month"),
    (2600, "quarter"),
]

_PERIOD_ALIAS = {"day": "D", "week": "W", "month": "M", "quarter": "Q", "year": "Y"}


def analyze_temporal_column(column: str, parsed_dates: pd.Series, total_rows: int) -> TemporalAnalysis:
    non_null = parsed_dates.dropna()
    count = len(non_null)
    missing_count = total_rows - count

    if count == 0:
        statistics = TemporalStatistics(
            count=0,
            missingCount=int(missing_count),
            missingPercentage=_percentage(missing_count, total_rows),
        )
        return TemporalAnalysis(column=column, statistics=statistics, chart=None)

    min_date = non_null.min()
    max_date = non_null.max()

    statistics = TemporalStatistics(
        count=count,
        missingCount=int(missing_count),
        missingPercentage=_percentage(missing_count, total_rows),
        minDate=min_date.isoformat(),
        maxDate=max_date.isoformat(),
    )

    return TemporalAnalysis(column=column, statistics=statistics, chart=_build_time_series(column, non_null))


def _choose_granularity(span_days: float) -> str:
    for threshold, granularity in _GRANULARITY_THRESHOLDS:
        if span_days <= threshold:
            return granularity
    return "year"


def _build_time_series(column: str, dates: pd.Series) -> TimeSeriesChart:
    span_days = (dates.max() - dates.min()).total_seconds() / 86400
    granularity = _choose_granularity(span_days)

    grouped = dates.dt.to_period(_PERIOD_ALIAS[granularity]).value_counts().sort_index()

    # A period's own str() (e.g. "2025-12-29/2026-01-04" for a week) is
    # noisy on a chart axis — use the period's start date as a single,
    # consistently formatted ISO label instead.
    return TimeSeriesChart(
        column=column,
        granularity=granularity,  # type: ignore[arg-type]
        x=[period.start_time.date().isoformat() for period in grouped.index],
        y=[int(value) for value in grouped.to_numpy()],
    )


def _percentage(part: int, total: int) -> float:
    if total <= 0:
        return 0.0
    return round((part / total) * 100, 4)
