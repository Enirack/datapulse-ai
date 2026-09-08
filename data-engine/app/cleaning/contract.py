"""
The cleaning result contract. See docs/data-cleaning.md for the full
documented shape with examples. `before`/`after` reuse the Phase 3
profiling contract (ProfileResult) directly — the whole point of
recomputing a profile after cleaning is to let a later consumer (the
future AI layer) state something like "missing values dropped from 4.2%
to 0.8%" from real, freshly-computed numbers, never a guess.
"""

from typing import Any, Optional

from pydantic import BaseModel

from app.profiling.contract import ProfileResult


class OperationReport(BaseModel):
    operation: str
    column: Optional[str] = None
    affectedRows: int = 0
    before: Optional[dict[str, Any]] = None
    after: Optional[dict[str, Any]] = None
    beforeRows: Optional[int] = None
    afterRows: Optional[int] = None
    details: Optional[str] = None


class CleaningReport(BaseModel):
    operations: list[OperationReport]
    before: ProfileResult
    after: ProfileResult


class CleanResult(BaseModel):
    changed: bool
    tempStorageIdentifier: Optional[str] = None
    checksum: Optional[str] = None
    fileSize: Optional[int] = None
    mimeType: Optional[str] = None
    report: CleaningReport


class PreviewResult(BaseModel):
    estimatedAffectedRows: int
    operations: list[OperationReport]
    before: ProfileResult
    after: ProfileResult
