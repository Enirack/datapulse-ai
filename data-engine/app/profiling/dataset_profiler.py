import pandas as pd

from app.profiling.column_profiler import profile_column
from app.profiling.contract import DatasetSummary, ProfileResult


def profile_dataframe(df: pd.DataFrame, schema_version: str, top_values_limit: int) -> ProfileResult:
    rows, columns = df.shape
    total_cells = rows * columns
    total_null_cells = int(df.isna().sum().sum()) if total_cells > 0 else 0
    duplicate_row_count = int(df.duplicated(keep="first").sum()) if rows > 0 else 0

    summary = DatasetSummary(
        rows=rows,
        columns=columns,
        totalCells=total_cells,
        totalNullCells=total_null_cells,
        nullPercentage=_percentage(total_null_cells, total_cells),
        duplicateRowCount=duplicate_row_count,
        duplicateRowPercentage=_percentage(duplicate_row_count, rows),
    )

    column_profiles = [profile_column(df[name], rows, top_values_limit) for name in df.columns]

    return ProfileResult(schemaVersion=schema_version, dataset=summary, columns=column_profiles)


def _percentage(part: int, total: int) -> float:
    if total <= 0:
        return 0.0
    return round((part / total) * 100, 4)
