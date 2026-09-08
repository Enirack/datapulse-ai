import os

from app.config import Settings
from app.profiling.contract import ProfileResult
from app.profiling.dataset_profiler import profile_dataframe
from app.profiling.readers import read_dataframe
from app.storage import DatasetStorageResolver


class ProfilingService:
    def __init__(self, storage: DatasetStorageResolver, settings: Settings) -> None:
        self.storage = storage
        self.settings = settings

    def profile(self, storage_identifier: str, extension: str) -> tuple[ProfileResult, int]:
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

        result = profile_dataframe(df, self.settings.profile_schema_version, self.settings.top_values_limit)

        return result, size
