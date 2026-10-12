"""
The versioned JSON analytics result contract. See docs/data-analytics.md for
the full documented shape, field-by-field, with examples.

Bump SCHEMA_VERSION (and document the change) whenever a field is added,
removed, or changes meaning — same policy as app/profiling/contract.py.

`DatasetSummary` is imported from the profiling contract rather than
redeclared — it's a plain shape (rows/columns/null counts) shared by both
reports, not profiling *logic*, so reusing it isn't a cross-responsibility
mix.
"""

from typing import Literal, Optional, Union

from pydantic import BaseModel

from app.profiling.contract import DatasetSummary

Granularity = Literal["day", "week", "month", "quarter", "year"]


class NumericAnalyticsStatistics(BaseModel):
    count: int
    missingCount: int
    missingPercentage: float
    uniqueCount: int
    min: Optional[float] = None
    max: Optional[float] = None
    mean: Optional[float] = None
    median: Optional[float] = None
    standardDeviation: Optional[float] = None
    variance: Optional[float] = None
    q1: Optional[float] = None
    q3: Optional[float] = None
    iqr: Optional[float] = None
    sum: Optional[float] = None
    range: Optional[float] = None


class HistogramChart(BaseModel):
    type: Literal["histogram"] = "histogram"
    column: str
    bins: list[float]
    counts: list[int]


class BoxplotChart(BaseModel):
    type: Literal["boxplot"] = "boxplot"
    column: str
    min: Optional[float] = None
    q1: Optional[float] = None
    median: Optional[float] = None
    q3: Optional[float] = None
    max: Optional[float] = None
    lowerFence: Optional[float] = None
    upperFence: Optional[float] = None
    outlierCount: int = 0


class NumericAnalysis(BaseModel):
    column: str
    statistics: NumericAnalyticsStatistics
    histogram: Optional[HistogramChart] = None
    boxplot: Optional[BoxplotChart] = None


class CategoricalTopValue(BaseModel):
    value: str
    count: int
    percentage: float


class CategoricalAnalyticsStatistics(BaseModel):
    count: int
    missingCount: int
    missingPercentage: float
    uniqueCount: int
    topValues: list[CategoricalTopValue] = []


class BarChart(BaseModel):
    type: Literal["bar"] = "bar"
    column: str
    labels: list[str]
    values: list[int]


class CategoricalAnalysis(BaseModel):
    column: str
    statistics: CategoricalAnalyticsStatistics
    chart: BarChart


class TemporalStatistics(BaseModel):
    count: int
    missingCount: int
    missingPercentage: float
    minDate: Optional[str] = None
    maxDate: Optional[str] = None


class TimeSeriesChart(BaseModel):
    type: Literal["time_series"] = "time_series"
    column: str
    granularity: Granularity
    x: list[str]
    y: list[int]


class TemporalAnalysis(BaseModel):
    column: str
    statistics: TemporalStatistics
    chart: Optional[TimeSeriesChart] = None


class CorrelationMatrix(BaseModel):
    type: Literal["correlation_matrix"] = "correlation_matrix"
    method: Literal["pearson"] = "pearson"
    columns: list[str]
    matrix: list[list[Optional[float]]]


class ScatterPoint(BaseModel):
    x: float
    y: float


class ScatterChart(BaseModel):
    type: Literal["scatter"] = "scatter"
    xColumn: str
    yColumn: str
    correlation: Optional[float] = None
    points: list[ScatterPoint]
    totalPoints: int
    sampled: bool
    sampleSize: int


Chart = Union[HistogramChart, BoxplotChart, BarChart, TimeSeriesChart, ScatterChart]


class AnalyticsSummary(BaseModel):
    numericColumnCount: int
    categoricalColumnCount: int
    temporalColumnCount: int
    unanalyzedColumnCount: int
    chartCount: int


class AnalyticsResult(BaseModel):
    schemaVersion: str
    dataset: DatasetSummary
    summary: AnalyticsSummary
    numeric: list[NumericAnalysis] = []
    categorical: list[CategoricalAnalysis] = []
    temporal: list[TemporalAnalysis] = []
    correlations: Optional[CorrelationMatrix] = None
    scatter: list[ScatterChart] = []
    charts: list[Chart] = []
