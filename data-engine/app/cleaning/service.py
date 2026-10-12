"""
Orchestrates one cleaning (or preview) run: read the bounded source
DataFrame, run the pipeline, decide whether anything actually changed,
and (for a real clean) serialize + hand off the result.

"Changed" is decided by comparing the DataFrame *values* before and after
(`DataFrame.equals`), never by comparing raw file bytes — a re-serialized
CSV can legitimately differ byte-for-byte from the source (different
delimiter, quoting, line endings) even when nothing about the data
actually changed, so a byte comparison would produce false positives on
every single run. Comparing the parsed DataFrames is the correct notion
of "did the data change".
"""

import hashlib
import io

import pandas as pd

from app.cleaning.contract import CleaningReport, CleanResult, PreviewResult
from app.cleaning.pipeline import run_pipeline
from app.config import Settings
from app.profiling.dataset_profiler import profile_dataframe
from app.profiling.readers import read_dataframe
from app.storage import DatasetStorageResolver, TempStorageWriter

_MIME_TYPES = {
    "csv": "text/csv",
    "xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "json": "application/json",
}


class CleaningService:
    def __init__(self, storage: DatasetStorageResolver, tmp_writer: TempStorageWriter, settings: Settings) -> None:
        self.storage = storage
        self.tmp_writer = tmp_writer
        self.settings = settings

    def clean(self, storage_identifier: str, extension: str, operations: list[dict]) -> CleanResult:
        source_df, cleaned_df, reports, before_profile = self._run(storage_identifier, extension, operations)

        if source_df.equals(cleaned_df):
            return CleanResult(
                changed=False,
                report=CleaningReport(operations=reports, before=before_profile, after=before_profile),
            )

        after_profile = profile_dataframe(cleaned_df, self.settings.profile_schema_version, self.settings.top_values_limit)
        content = serialize_dataframe(cleaned_df, extension)
        checksum = hashlib.sha256(content).hexdigest()
        identifier = self.tmp_writer.write(content, extension)

        return CleanResult(
            changed=True,
            tempStorageIdentifier=identifier,
            checksum=checksum,
            fileSize=len(content),
            mimeType=_MIME_TYPES[extension],
            report=CleaningReport(operations=reports, before=before_profile, after=after_profile),
        )

    def preview(self, storage_identifier: str, extension: str, operations: list[dict]) -> PreviewResult:
        source_df, cleaned_df, reports, before_profile = self._run(storage_identifier, extension, operations)
        after_profile = profile_dataframe(cleaned_df, self.settings.profile_schema_version, self.settings.top_values_limit)

        # Sum of each operation's own affected-row count. This can double
        # count a row touched by more than one operation in the pipeline —
        # a precise unique-row count across heterogeneous row-adding/
        # -removing/-transforming operations would need substantially more
        # machinery for a number that's explicitly only an estimate.
        estimated_affected = sum(r.affectedRows for r in reports)

        return PreviewResult(
            estimatedAffectedRows=estimated_affected,
            operations=reports,
            before=before_profile,
            after=after_profile,
        )

    def _run(self, storage_identifier: str, extension: str, operations: list[dict]):
        path = self.storage.resolve(storage_identifier)
        source_df = read_dataframe(
            path, extension,
            max_rows=self.settings.max_rows,
            max_columns=self.settings.max_columns,
            max_profilable_bytes=self.settings.max_profilable_bytes,
        )

        before_profile = profile_dataframe(source_df, self.settings.profile_schema_version, self.settings.top_values_limit)
        cleaned_df, reports = run_pipeline(source_df, operations)

        return source_df, cleaned_df, reports, before_profile


def serialize_dataframe(df: pd.DataFrame, extension: str) -> bytes:
    if extension == "csv":
        return df.to_csv(index=False).encode("utf-8")

    if extension == "xlsx":
        buffer = io.BytesIO()
        df.to_excel(buffer, index=False, engine="openpyxl")
        return buffer.getvalue()

    if extension == "json":
        # Always a flat array of row-objects, regardless of the source
        # JSON's original wrapping shape (a top-level {"data": [...]}
        # wrapper, or an array of scalars) — documented in docs/data-cleaning.md.
        return df.to_json(orient="records", date_format="iso").encode("utf-8")

    raise ValueError(f"Unsupported extension: {extension}")
