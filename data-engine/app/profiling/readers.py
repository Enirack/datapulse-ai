"""
Reads a stored dataset file into a bounded pandas DataFrame.

Size policy: the file is rejected outright (DatasetTooLargeError) if it's
bigger than `max_profilable_bytes` on disk, or if it still has more than
`max_rows` rows / `max_columns` columns once read. We never silently
profile a truncated slice of a dataset and call it the whole thing —
that would misrepresent the result, which conflicts with the project's
"never fabricate" principle applied to a subtler case: a partial profile
presented as a complete one.

CSV is assumed to have a header row (pandas' default) — this phase does
not reimplement Phase 2's best-effort CSV header heuristic; documented in
docs/data-profiling.md.
"""

import csv
import json
import os

import pandas as pd

from app.profiling.errors import DatasetTooLargeError, UnprofilableFormatError

Extension = str  # "csv" | "xlsx" | "json"

_CANDIDATE_DELIMITERS = [",", ";", "\t", "|"]
_DELIMITER_SAMPLE_LINES = 5


def read_dataframe(path: str, extension: Extension, max_rows: int, max_columns: int, max_profilable_bytes: int) -> pd.DataFrame:
    size = os.path.getsize(path)
    if size > max_profilable_bytes:
        raise DatasetTooLargeError(
            f"File is {size} bytes, which exceeds the maximum profilable size of {max_profilable_bytes} bytes.",
        )

    if extension == "csv":
        df = _read_csv(path, max_rows)
    elif extension == "xlsx":
        df = _read_xlsx(path, max_rows)
    elif extension == "json":
        df = _read_json(path, max_rows)
    else:  # pragma: no cover - guarded by the request schema upstream
        raise UnprofilableFormatError(f"Unsupported extension: {extension}")

    if len(df) > max_rows:
        raise DatasetTooLargeError(f"Dataset has more than {max_rows} rows; refusing to profile a truncated slice.")

    if len(df.columns) > max_columns:
        raise DatasetTooLargeError(f"Dataset has more than {max_columns} columns; refusing to profile a truncated slice.")

    return df


def _read_csv(path: str, max_rows: int) -> pd.DataFrame:
    delimiter = _detect_delimiter(path)

    try:
        # nrows caps memory use while still letting us detect "too many rows".
        return pd.read_csv(path, sep=delimiter, nrows=max_rows + 1)
    except (pd.errors.EmptyDataError, pd.errors.ParserError, UnicodeDecodeError) as exc:
        raise UnprofilableFormatError(f"Unable to parse CSV file: {exc}") from exc


def _detect_delimiter(path: str) -> str:
    """
    pandas' own sep=None sniffer is unreliable on single-column files (it
    can pick a letter that happens to appear in the data as the
    "delimiter"). Same conservative approach as the PHP-side CsvValidator
    from Phase 2: try each candidate, keep the one that yields a
    consistent column count > 1 across the first few sampled lines;
    default to "," (i.e. effectively single-column) otherwise.
    """
    with open(path, "r", encoding="utf-8", errors="replace") as handle:
        lines = [next(handle, "") for _ in range(_DELIMITER_SAMPLE_LINES)]
    lines = [line for line in lines if line.strip() != ""]

    best_delimiter = ","
    best_score = 0

    for candidate in _CANDIDATE_DELIMITERS:
        counts = [len(next(csv.reader([line], delimiter=candidate))) for line in lines]
        if not counts:
            continue
        score = min(counts) if min(counts) > 1 else 0
        if score > best_score:
            best_score = score
            best_delimiter = candidate

    return best_delimiter


def _read_xlsx(path: str, max_rows: int) -> pd.DataFrame:
    try:
        # Only the first sheet is profiled — documented limitation.
        return pd.read_excel(path, sheet_name=0, engine="openpyxl", nrows=max_rows + 1)
    except (ValueError, KeyError) as exc:
        raise UnprofilableFormatError(f"Unable to parse XLSX workbook: {exc}") from exc


def _read_json(path: str, max_rows: int) -> pd.DataFrame:
    try:
        with open(path, "r", encoding="utf-8") as handle:
            data = json.load(handle)
    except (json.JSONDecodeError, UnicodeDecodeError) as exc:
        raise UnprofilableFormatError(f"Unable to parse JSON file: {exc}") from exc

    records = _to_tabular_records(data)

    try:
        return pd.DataFrame.from_records(records[: max_rows + 1])
    except (ValueError, TypeError) as exc:
        raise UnprofilableFormatError(f"Unable to convert JSON to tabular form: {exc}") from exc


def _to_tabular_records(data: object) -> list[dict]:
    """
    Converts parsed JSON into a list of row-records, or raises
    UnprofilableFormatError if the shape isn't tabular-convertible.

    Supported shapes:
      - a top-level array of objects            -> used directly as rows
      - a top-level array of scalars             -> wrapped as {"value": ...} rows
      - an object with exactly one property      -> that property's value is
        whose value is a qualifying array           unwrapped and processed
                                                     the same way (common
                                                     `{"data": [...]}` wrapper)

    Anything else (a bare scalar, a dict without a single qualifying array
    property, deeply nested non-tabular structures) is rejected — this
    phase does not attempt to flatten arbitrary JSON.
    """
    if isinstance(data, list):
        if len(data) == 0:
            return []
        if all(isinstance(item, dict) for item in data):
            return data
        if all(not isinstance(item, (dict, list)) for item in data):
            return [{"value": item} for item in data]
        raise UnprofilableFormatError("JSON array must contain either all objects or all scalar values.")

    if isinstance(data, dict):
        array_properties = [v for v in data.values() if isinstance(v, list)]
        if len(array_properties) == 1:
            return _to_tabular_records(array_properties[0])
        raise UnprofilableFormatError(
            "JSON object must have exactly one top-level array property to be treated as tabular data.",
        )

    raise UnprofilableFormatError("JSON must be an array of objects (or an object wrapping one) to be profiled.")
