"""
Deterministic, rule-based selection of which analytics apply to which
column. No AI/ML is involved — this purely maps Phase 3's existing
`detect_column_type()` result onto one of three analysis buckets, so the
same file always produces the same selection.

Boolean columns are treated as categorical (two categories) for charting
purposes; "string"/"unknown" columns get no chart-ready analysis, same as
Phase 3 leaves them without `statistics`/`categorical` blocks.
"""

from dataclasses import dataclass, field

import pandas as pd

from app.profiling.type_detection import detect_column_type

NUMERIC_TYPES = {"integer", "float"}
CATEGORICAL_TYPES = {"categorical", "boolean"}
TEMPORAL_TYPES = {"date", "datetime"}


@dataclass
class ColumnSelection:
    numeric: list[str] = field(default_factory=list)
    categorical: list[str] = field(default_factory=list)
    # column name -> already-parsed datetime Series (reused from type detection
    # so temporal analysis never re-parses dates itself).
    temporal: dict[str, pd.Series] = field(default_factory=dict)
    unanalyzed: list[str] = field(default_factory=list)


def select_columns(df: pd.DataFrame) -> ColumnSelection:
    selection = ColumnSelection()

    for name in df.columns:
        column_name = str(name)
        detection = detect_column_type(df[name])

        if detection.detected_type in NUMERIC_TYPES:
            selection.numeric.append(column_name)
        elif detection.detected_type in CATEGORICAL_TYPES:
            selection.categorical.append(column_name)
        elif detection.detected_type in TEMPORAL_TYPES and detection.parsed_dates is not None:
            selection.temporal[column_name] = detection.parsed_dates
        else:
            selection.unanalyzed.append(column_name)

    return selection
