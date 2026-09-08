"""
The versioned JSON profiling result contract. See docs/data-profiling.md
for the full documented shape, field-by-field, with examples.

Bump SCHEMA_VERSION (and document the change) whenever a field is added,
removed, or changes meaning — additive-only changes may keep the same
major version at the project's discretion, but anything that could break
an existing consumer must not reuse a version string.
"""

from typing import Literal, Optional

from pydantic import BaseModel

BusinessType = Literal[
    "integer",
    "float",
    "boolean",
    "string",
    "date",
    "datetime",
    "categorical",
    "unknown",
]


class NumericStatistics(BaseModel):
    min: Optional[float] = None
    max: Optional[float] = None
    mean: Optional[float] = None
    median: Optional[float] = None
    std: Optional[float] = None
    variance: Optional[float] = None
    q25: Optional[float] = None
    q50: Optional[float] = None
    q75: Optional[float] = None


class StringStatistics(BaseModel):
    minLength: Optional[int] = None
    maxLength: Optional[int] = None
    meanLength: Optional[float] = None
    emptyCount: int = 0
    whitespaceOnlyCount: int = 0


class TopValue(BaseModel):
    value: str
    count: int
    percentage: float


class CategoricalStatistics(BaseModel):
    mostFrequentValue: Optional[str] = None
    mostFrequentCount: Optional[int] = None
    topValues: list[TopValue] = []
    truncated: bool = False


class OutlierStatistics(BaseModel):
    method: Literal["iqr"] = "iqr"
    outlierCount: int
    outlierPercentage: float


class ColumnProfile(BaseModel):
    name: str
    pandasType: str
    detectedType: BusinessType
    nullable: bool
    nullCount: int
    nullPercentage: float
    uniqueCount: int
    uniquePercentage: float
    duplicateCount: int
    statistics: Optional[NumericStatistics] = None
    stringStatistics: Optional[StringStatistics] = None
    categorical: Optional[CategoricalStatistics] = None
    outliers: Optional[OutlierStatistics] = None


class DatasetSummary(BaseModel):
    rows: int
    columns: int
    totalCells: int
    totalNullCells: int
    nullPercentage: float
    duplicateRowCount: int
    duplicateRowPercentage: float


class ProfileResult(BaseModel):
    schemaVersion: str
    dataset: DatasetSummary
    columns: list[ColumnProfile]
