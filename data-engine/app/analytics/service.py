import os

import pandas as pd

from app.analytics.categorical import analyze_categorical_column
from app.analytics.contract import AnalyticsResult, AnalyticsSummary, Chart
from app.analytics.correlation import compute_correlation_matrix
from app.analytics.numeric import analyze_numeric_column
from app.analytics.scatter import build_scatter_chart, select_scatter_pairs
from app.analytics.selection import select_columns
from app.analytics.temporal import analyze_temporal_column
from app.config import Settings
from app.profiling.contract import DatasetSummary
from app.profiling.readers import read_dataframe
from app.storage import DatasetStorageResolver


class AnalyticsService:
    """
    Reuses profiling's I/O (read_dataframe) and type-detection utilities,
    but every statistical/chart computation here is analytics' own — kept
    deliberately separate from app/profiling and app/cleaning (see module
    docstrings in app/analytics/*.py).
    """

    def __init__(self, storage: DatasetStorageResolver, settings: Settings) -> None:
        self.storage = storage
        self.settings = settings

    def analyze(self, storage_identifier: str, extension: str) -> tuple[AnalyticsResult, int]:
        """Returns (result, bytes_on_disk) — the byte count is for logging only."""
        path = self.storage.resolve(storage_identifier)
        size = os.path.getsize(path)

        df = read_dataframe(
            path,
            extension,
            max_rows=self.settings.max_rows,
            max_columns=self.settings.max_columns,
            max_profilable_bytes=self.settings.max_profilable_bytes,
        )

        return self._analyze_dataframe(df), size

    def _analyze_dataframe(self, df: pd.DataFrame) -> AnalyticsResult:
        rows, columns = df.shape
        total_cells = rows * columns
        total_null_cells = int(df.isna().sum().sum()) if total_cells > 0 else 0
        duplicate_row_count = int(df.duplicated(keep="first").sum()) if rows > 0 else 0

        dataset_summary = DatasetSummary(
            rows=rows,
            columns=columns,
            totalCells=total_cells,
            totalNullCells=total_null_cells,
            nullPercentage=_percentage(total_null_cells, total_cells),
            duplicateRowCount=duplicate_row_count,
            duplicateRowPercentage=_percentage(duplicate_row_count, rows),
        )

        selection = select_columns(df)

        numeric_analyses = [
            analyze_numeric_column(df[name], rows, self.settings.histogram_max_bins) for name in selection.numeric
        ]
        categorical_analyses = [
            analyze_categorical_column(df[name], rows, self.settings.top_values_limit) for name in selection.categorical
        ]
        temporal_analyses = [
            analyze_temporal_column(name, dates, rows) for name, dates in selection.temporal.items()
        ]

        correlations = None
        scatter_charts: list = []
        if len(selection.numeric) >= 2:
            correlation_df = df[selection.numeric].apply(pd.to_numeric, errors="coerce")
            correlations = compute_correlation_matrix(correlation_df, self.settings.max_correlation_columns)

            if correlations is not None:
                pairs = select_scatter_pairs(correlations.columns, correlations.matrix, self.settings.max_scatter_pairs)
                scatter_charts = [
                    build_scatter_chart(correlation_df, x_col, y_col, corr, self.settings.max_scatter_points)
                    for x_col, y_col, corr in pairs
                ]

        charts: list[Chart] = []
        for numeric_analysis in numeric_analyses:
            if numeric_analysis.histogram is not None:
                charts.append(numeric_analysis.histogram)
            if numeric_analysis.boxplot is not None:
                charts.append(numeric_analysis.boxplot)
        charts.extend(categorical_analysis.chart for categorical_analysis in categorical_analyses)
        charts.extend(temporal_analysis.chart for temporal_analysis in temporal_analyses if temporal_analysis.chart is not None)
        charts.extend(scatter_charts)

        summary = AnalyticsSummary(
            numericColumnCount=len(numeric_analyses),
            categoricalColumnCount=len(categorical_analyses),
            temporalColumnCount=len(temporal_analyses),
            unanalyzedColumnCount=len(selection.unanalyzed),
            chartCount=len(charts),
        )

        return AnalyticsResult(
            schemaVersion=self.settings.analytics_schema_version,
            dataset=dataset_summary,
            summary=summary,
            numeric=numeric_analyses,
            categorical=categorical_analyses,
            temporal=temporal_analyses,
            correlations=correlations,
            scatter=scatter_charts,
            charts=charts,
        )


def _percentage(part: int, total: int) -> float:
    if total <= 0:
        return 0.0
    return round((part / total) * 100, 4)
