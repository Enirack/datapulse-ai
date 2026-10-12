import math

import pandas as pd
import pytest

from app.analytics.categorical import analyze_categorical_column
from app.analytics.correlation import compute_correlation_matrix
from app.analytics.numeric import analyze_numeric_column
from app.analytics.scatter import build_scatter_chart, select_scatter_pairs
from app.analytics.selection import select_columns
from app.analytics.service import AnalyticsService
from app.analytics.temporal import analyze_temporal_column
from app.config import settings
from app.storage import DatasetStorageResolver
from tests.support import write_csv_fixture

# ---------------------------------------------------------------------------
# Selection
# ---------------------------------------------------------------------------


def test_select_columns_buckets_by_detected_type() -> None:
    # 8 rows so "city" (3 distinct values) clears Phase 3's categorical
    # ratio threshold (unique/total <= 0.5) — see type_detection.py.
    df = pd.DataFrame(
        {
            "age": [25, 30, 41, 25, 30, 41, 25, 30],
            "city": ["Paris", "Dakar", "Paris", "Lyon", "Paris", "Dakar", "Lyon", "Paris"],
            "created_at": ["2026-01-01", "2026-01-05", "2026-02-01", "2026-03-01"] * 2,
            "bio": [f"free text number {i} that is fairly unique" for i in range(8)],
        },
    )

    selection = select_columns(df)

    assert selection.numeric == ["age"]
    assert selection.categorical == ["city"]
    assert list(selection.temporal.keys()) == ["created_at"]
    assert selection.unanalyzed == ["bio"]


# ---------------------------------------------------------------------------
# Numeric
# ---------------------------------------------------------------------------


def test_numeric_statistics_are_correct() -> None:
    series = pd.Series([1, 2, 3, 4, 5], name="value")

    result = analyze_numeric_column(series, total_rows=5, max_bins=20)

    stats = result.statistics
    assert stats.count == 5
    assert stats.missingCount == 0
    assert stats.min == 1
    assert stats.max == 5
    assert stats.mean == 3
    assert stats.median == 3
    assert stats.sum == 15
    assert stats.range == 4
    assert stats.q1 == 2
    assert stats.q3 == 4
    assert stats.iqr == 2


def test_numeric_histogram_bins_and_counts_are_consistent() -> None:
    series = pd.Series(range(1, 101), name="value")

    result = analyze_numeric_column(series, total_rows=100, max_bins=10)

    assert result.histogram is not None
    assert len(result.histogram.bins) == 11  # 10 bins -> 11 edges
    assert sum(result.histogram.counts) == 100


def test_numeric_boxplot_reports_fences_and_outliers() -> None:
    series = pd.Series([1, 2, 3, 4, 5, 6, 7, 8, 9, 1000], name="value")

    result = analyze_numeric_column(series, total_rows=10, max_bins=20)

    assert result.boxplot is not None
    assert result.boxplot.outlierCount == 1
    assert result.boxplot.upperFence is not None
    assert result.boxplot.max == 1000


def test_numeric_column_with_missing_values() -> None:
    series = pd.Series([1, None, 3, None, 5], name="value")

    result = analyze_numeric_column(series, total_rows=5, max_bins=20)

    assert result.statistics.count == 3
    assert result.statistics.missingCount == 2
    assert result.statistics.missingPercentage == 40.0


def test_constant_numeric_column_does_not_crash_and_has_no_outliers() -> None:
    series = pd.Series([7, 7, 7, 7], name="value")

    result = analyze_numeric_column(series, total_rows=4, max_bins=20)

    assert result.statistics.iqr == 0
    assert result.histogram is not None
    assert result.histogram.bins == [7.0, 7.0]
    assert result.histogram.counts == [4]
    assert result.boxplot is not None
    assert result.boxplot.outlierCount == 0


def test_entirely_null_numeric_column_produces_no_charts() -> None:
    series = pd.Series([None, None, None], name="value", dtype="float64")

    result = analyze_numeric_column(series, total_rows=3, max_bins=20)

    assert result.statistics.count == 0
    assert result.histogram is None
    assert result.boxplot is None


# ---------------------------------------------------------------------------
# Categorical
# ---------------------------------------------------------------------------


def test_categorical_top_values_are_correct() -> None:
    series = pd.Series(["a", "a", "a", "b", "b", "c"], name="letter")

    result = analyze_categorical_column(series, total_rows=6, top_n=10)

    assert result.statistics.uniqueCount == 3
    assert result.statistics.topValues[0].value == "a"
    assert result.statistics.topValues[0].count == 3
    assert result.chart.labels == ["a", "b", "c"]
    assert result.chart.values == [3, 2, 1]


def test_categorical_high_cardinality_is_bounded_with_other_bucket() -> None:
    series = pd.Series([f"category-{i}" for i in range(1000)], name="id")

    result = analyze_categorical_column(series, total_rows=1000, top_n=10)

    assert len(result.chart.labels) == 11  # top 10 + "Other"
    assert result.chart.labels[-1] == "Other"
    assert sum(result.chart.values) == 1000
    assert len(result.statistics.topValues) == 10


def test_categorical_column_with_missing_values() -> None:
    series = pd.Series(["a", None, "b", None], name="letter")

    result = analyze_categorical_column(series, total_rows=4, top_n=10)

    assert result.statistics.count == 2
    assert result.statistics.missingCount == 2
    assert result.statistics.missingPercentage == 50.0


# ---------------------------------------------------------------------------
# Temporal
# ---------------------------------------------------------------------------


def test_temporal_aggregation_picks_a_granularity_and_buckets_correctly() -> None:
    dates = pd.to_datetime(pd.Series(["2026-01-01", "2026-01-02", "2026-01-03", "2026-01-10"]))

    result = analyze_temporal_column("created_at", dates, total_rows=4)

    assert result.statistics.count == 4
    assert result.statistics.minDate == "2026-01-01T00:00:00"
    assert result.statistics.maxDate == "2026-01-10T00:00:00"
    assert result.chart is not None
    assert result.chart.granularity == "day"
    assert sum(result.chart.y) == 4


def test_temporal_wide_span_uses_a_coarser_granularity() -> None:
    dates = pd.to_datetime(pd.Series(["2015-01-01", "2020-06-15", "2026-01-01"]))

    result = analyze_temporal_column("created_at", dates, total_rows=3)

    assert result.chart is not None
    assert result.chart.granularity == "year"


def test_temporal_column_with_invalid_dates_coerced_to_nat() -> None:
    # Simulates what type_detection produces when some values fail to parse:
    # pd.to_datetime(..., errors="coerce") turns them into NaT.
    raw = pd.Series(["2026-01-01", "not-a-date", "2026-01-03"])
    parsed = pd.to_datetime(raw, errors="coerce")

    result = analyze_temporal_column("created_at", parsed, total_rows=3)

    assert result.statistics.count == 2
    assert result.statistics.missingCount == 1


def test_temporal_column_with_a_single_date_still_produces_a_chart() -> None:
    dates = pd.to_datetime(pd.Series(["2026-01-01"]))

    result = analyze_temporal_column("created_at", dates, total_rows=1)

    assert result.chart is not None
    assert result.chart.x == ["2026-01-01"]
    assert result.chart.y == [1]


def test_temporal_column_entirely_missing_has_no_chart() -> None:
    parsed = pd.to_datetime(pd.Series(["not-a-date", "also-not"]), errors="coerce", format="%Y-%m-%d")

    result = analyze_temporal_column("created_at", parsed, total_rows=2)

    assert result.statistics.count == 0
    assert result.statistics.minDate is None
    assert result.chart is None


# ---------------------------------------------------------------------------
# Correlation
# ---------------------------------------------------------------------------


def test_correlation_matrix_is_computed_correctly_for_perfectly_correlated_columns() -> None:
    df = pd.DataFrame({"a": [1, 2, 3, 4, 5], "b": [2, 4, 6, 8, 10]})

    matrix = compute_correlation_matrix(df, max_columns=15)

    assert matrix is not None
    assert matrix.columns == ["a", "b"]
    assert matrix.matrix[0][1] == pytest.approx(1.0)
    assert matrix.matrix[1][0] == pytest.approx(1.0)


def test_correlation_matrix_handles_constant_column_as_null() -> None:
    df = pd.DataFrame({"a": [1, 2, 3, 4], "b": [5, 5, 5, 5]})

    matrix = compute_correlation_matrix(df, max_columns=15)

    assert matrix is not None
    b_index = matrix.columns.index("b")
    a_index = matrix.columns.index("a")
    assert matrix.matrix[a_index][b_index] is None


def test_correlation_matrix_returns_none_with_fewer_than_two_numeric_columns() -> None:
    df = pd.DataFrame({"a": [1, 2, 3]})

    assert compute_correlation_matrix(df, max_columns=15) is None


def test_correlation_matrix_is_capped_to_max_columns() -> None:
    df = pd.DataFrame({f"c{i}": range(10) for i in range(20)})

    matrix = compute_correlation_matrix(df, max_columns=5)

    assert matrix is not None
    assert len(matrix.columns) == 5


def test_correlation_on_small_dataset_does_not_crash() -> None:
    df = pd.DataFrame({"a": [1, 2], "b": [2, 1]})

    matrix = compute_correlation_matrix(df, max_columns=15)

    assert matrix is not None
    assert matrix.matrix[0][1] == pytest.approx(-1.0)


# ---------------------------------------------------------------------------
# Scatter
# ---------------------------------------------------------------------------


def test_select_scatter_pairs_ranks_by_absolute_correlation_descending() -> None:
    columns = ["a", "b", "c"]
    matrix = [
        [1.0, 0.1, 0.9],
        [0.1, 1.0, -0.95],
        [0.9, -0.95, 1.0],
    ]

    pairs = select_scatter_pairs(columns, matrix, max_pairs=5)

    assert pairs[0][:2] == ("b", "c")  # |-0.95| is the strongest
    assert pairs[1][:2] == ("a", "c")


def test_select_scatter_pairs_puts_null_correlation_last() -> None:
    columns = ["a", "b", "c"]
    matrix = [
        [1.0, 0.5, None],
        [0.5, 1.0, None],
        [None, None, 1.0],
    ]

    pairs = select_scatter_pairs(columns, matrix, max_pairs=5)

    assert pairs[0][:2] == ("a", "b")
    assert pairs[-1][2] is None


def test_select_scatter_pairs_respects_max_pairs() -> None:
    columns = [f"c{i}" for i in range(6)]
    matrix = [[1.0 if i == j else 0.5 for j in range(6)] for i in range(6)]

    pairs = select_scatter_pairs(columns, matrix, max_pairs=3)

    assert len(pairs) == 3


def test_build_scatter_chart_generates_correct_points() -> None:
    df = pd.DataFrame({"a": [1, 2, 3], "b": [4, 5, 6]})

    chart = build_scatter_chart(df, "a", "b", correlation=1.0, max_points=100)

    assert chart.totalPoints == 3
    assert chart.sampled is False
    assert chart.sampleSize == 3
    assert {p.x for p in chart.points} == {1.0, 2.0, 3.0}


def test_build_scatter_chart_limits_the_number_of_points_deterministically() -> None:
    df = pd.DataFrame({"a": range(1000), "b": range(1000)})

    chart_1 = build_scatter_chart(df, "a", "b", correlation=1.0, max_points=50)
    chart_2 = build_scatter_chart(df, "a", "b", correlation=1.0, max_points=50)

    assert chart_1.sampled is True
    assert chart_1.totalPoints == 1000
    assert chart_1.sampleSize == 50
    # Same random_state -> same sample, every time (reproducibility requirement).
    assert [p.x for p in chart_1.points] == [p.x for p in chart_2.points]


def test_build_scatter_chart_ignores_rows_with_missing_values() -> None:
    df = pd.DataFrame({"a": [1, 2, None, 4], "b": [1, None, 3, 4]})

    chart = build_scatter_chart(df, "a", "b", correlation=None, max_points=100)

    assert chart.totalPoints == 2  # only rows 0 and 3 have both values


# ---------------------------------------------------------------------------
# Serialization (no NaN/Infinity, JSON-valid, null used correctly)
# ---------------------------------------------------------------------------


def test_analytics_result_serializes_without_nan_or_infinity() -> None:
    identifier = write_csv_fixture("a,b\n1,inf\n2,-inf\n3,3\n,\n")
    storage = DatasetStorageResolver(settings.storage_dir)
    service = AnalyticsService(storage, settings)

    result, _ = service.analyze(identifier, "csv")
    raw = result.model_dump_json()

    assert "NaN" not in raw
    assert "Infinity" not in raw


def test_analytics_result_is_deterministic_across_runs() -> None:
    identifier = write_csv_fixture("a,b\n" + "\n".join(f"{i},{i * 2}" for i in range(50)))
    storage = DatasetStorageResolver(settings.storage_dir)
    service = AnalyticsService(storage, settings)

    result_1, _ = service.analyze(identifier, "csv")
    result_2, _ = service.analyze(identifier, "csv")

    assert result_1.model_dump() == result_2.model_dump()


# ---------------------------------------------------------------------------
# Limits
# ---------------------------------------------------------------------------


def test_histogram_never_exceeds_configured_max_bins() -> None:
    series = pd.Series(range(1, 1001), name="value")

    result = analyze_numeric_column(series, total_rows=1000, max_bins=5)

    assert result.histogram is not None
    assert len(result.histogram.counts) <= 5


def test_correlation_matrix_column_cap_is_enforced_on_a_wide_dataset() -> None:
    df = pd.DataFrame({f"c{i}": range(5) for i in range(50)})

    matrix = compute_correlation_matrix(df, max_columns=settings.max_correlation_columns)

    assert matrix is not None
    assert len(matrix.columns) == settings.max_correlation_columns


def test_scatter_points_never_exceed_configured_max() -> None:
    df = pd.DataFrame({"a": range(2000), "b": range(2000)})

    chart = build_scatter_chart(df, "a", "b", correlation=1.0, max_points=settings.max_scatter_points)

    assert chart.sampleSize <= settings.max_scatter_points
