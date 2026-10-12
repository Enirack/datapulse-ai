"""
Business-type detection. Deliberately conservative — see docs/data-profiling.md
"Type detection rules" for the full rationale. In short:

1. Native pandas bool dtype -> "boolean". We never coerce strings like
   "yes"/"no"/"1"/"0" into boolean — too ambiguous, too aggressive.
2. Native pandas integer dtype -> "integer". A float64 column whose
   non-null values are all mathematically whole numbers is ALSO
   classified "integer" (CSV/Excel with nulls forces pandas to use
   float64 for what is conceptually an integer column) — documented
   heuristic, not a guess about the data's meaning.
3. Otherwise numeric dtype -> "float".
4. Object (string-like) columns are checked against a small set of
   explicit, documented date/datetime regex patterns BEFORE attempting
   any parsing — pandas' own date inference is deliberately not used
   directly on arbitrary strings, since it is too willing to call
   ambiguous text a date.
5. Remaining object columns: "categorical" if low-cardinality relative
   to row count, else "string".
6. An entirely-null column -> "unknown" (there's nothing to detect from).
"""

import re
from dataclasses import dataclass

import pandas as pd

from app.profiling.contract import BusinessType

CATEGORICAL_MAX_UNIQUE_RATIO = 0.5
CATEGORICAL_MAX_UNIQUE_COUNT = 50
DATE_SAMPLE_SIZE = 100
DATE_MATCH_THRESHOLD = 0.9

# Explicit, intentionally narrow date/datetime patterns. Extending this
# list is a deliberate decision, not something to do casually — every
# addition increases the chance of misclassifying an ordinary string
# column as a date.
_DATE_PATTERNS = [
    re.compile(r"^\d{4}-\d{2}-\d{2}$"),  # 2026-09-08
    re.compile(r"^\d{2}/\d{2}/\d{4}$"),  # 08/09/2026 or 09/08/2026 (ambiguous day/month, documented)
]
_DATETIME_PATTERNS = [
    re.compile(r"^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$"),  # ISO 8601
]


@dataclass
class TypeDetectionResult:
    detected_type: BusinessType
    parsed_dates: "pd.Series | None" = None  # populated only for date/datetime columns


def detect_column_type(series: pd.Series) -> TypeDetectionResult:
    non_null = series.dropna()

    if len(non_null) == 0:
        return TypeDetectionResult(detected_type="unknown")

    if pd.api.types.is_bool_dtype(series):
        return TypeDetectionResult(detected_type="boolean")

    if pd.api.types.is_integer_dtype(series):
        return TypeDetectionResult(detected_type="integer")

    if pd.api.types.is_float_dtype(series):
        if _all_whole_numbers(non_null):
            return TypeDetectionResult(detected_type="integer")
        return TypeDetectionResult(detected_type="float")

    if pd.api.types.is_object_dtype(series) or pd.api.types.is_string_dtype(series):
        date_result = _detect_date_or_datetime(non_null)
        if date_result is not None:
            return date_result

        unique_count = non_null.nunique()
        row_count = len(series)
        if unique_count <= CATEGORICAL_MAX_UNIQUE_COUNT and (unique_count / row_count) <= CATEGORICAL_MAX_UNIQUE_RATIO:
            return TypeDetectionResult(detected_type="categorical")

        return TypeDetectionResult(detected_type="string")

    return TypeDetectionResult(detected_type="unknown")


def _all_whole_numbers(non_null: pd.Series) -> bool:
    try:
        return bool((non_null == non_null.round(0)).all())
    except TypeError:
        return False


def _detect_date_or_datetime(non_null: pd.Series) -> "TypeDetectionResult | None":
    sample = non_null.astype(str).head(DATE_SAMPLE_SIZE)
    if len(sample) == 0:
        return None

    datetime_matches = sample.str.match("|".join(p.pattern for p in _DATETIME_PATTERNS))
    if datetime_matches.mean() >= DATE_MATCH_THRESHOLD:
        parsed = pd.to_datetime(non_null.astype(str), errors="coerce", utc=False, format="ISO8601")
        return TypeDetectionResult(detected_type="datetime", parsed_dates=parsed)

    date_matches = sample.str.match("|".join(p.pattern for p in _DATE_PATTERNS))
    if date_matches.mean() >= DATE_MATCH_THRESHOLD:
        parsed = pd.to_datetime(non_null.astype(str), errors="coerce")
        return TypeDetectionResult(detected_type="date", parsed_dates=parsed)

    return None
