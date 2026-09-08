import logging
import time
from typing import Literal

from fastapi import APIRouter
from pydantic import BaseModel

from app.cleaning.contract import CleanResult, PreviewResult
from app.cleaning.errors import CleaningError
from app.cleaning.service import CleaningService
from app.config import settings
from app.profiling.errors import ProfilingError
from app.storage import DatasetStorageResolver, StorageError, TempStorageWriter

logger = logging.getLogger("data_engine.cleaning")

router = APIRouter()

_storage = DatasetStorageResolver(settings.storage_dir)
_tmp_writer = TempStorageWriter(settings.tmp_dir)
_service = CleaningService(_storage, _tmp_writer, settings)


class CleanRequest(BaseModel):
    datasetVersionId: str
    storageIdentifier: str
    extension: Literal["csv", "xlsx", "json"]
    operations: list[dict]


@router.post("/internal/clean", response_model=CleanResult)
def clean(request: CleanRequest) -> CleanResult:
    logger.info(
        "cleaning.start dataset_version_id=%s extension=%s operation_count=%d",
        request.datasetVersionId, request.extension, len(request.operations),
    )
    started = time.monotonic()

    result = _run_or_raise(
        lambda: _service.clean(request.storageIdentifier, request.extension, request.operations),
        request.datasetVersionId,
    )

    duration_ms = int((time.monotonic() - started) * 1000)
    logger.info(
        "cleaning.completed dataset_version_id=%s duration_ms=%d changed=%s",
        request.datasetVersionId, duration_ms, result.changed,
    )

    return result


@router.post("/internal/clean/preview", response_model=PreviewResult)
def preview(request: CleanRequest) -> PreviewResult:
    logger.info(
        "cleaning.preview dataset_version_id=%s extension=%s operation_count=%d",
        request.datasetVersionId, request.extension, len(request.operations),
    )

    return _run_or_raise(
        lambda: _service.preview(request.storageIdentifier, request.extension, request.operations),
        request.datasetVersionId,
    )


def _run_or_raise(action, dataset_version_id: str):
    try:
        return action()
    except StorageError as exc:
        logger.warning("cleaning.failed dataset_version_id=%s reason=storage_not_found", dataset_version_id)
        raise CleaningError("Stored dataset file not found.", status_code=404) from exc
    except ProfilingError:
        # Covers both CleaningError (raised in app.cleaning.*) and the
        # ProfilingError subclasses read_dataframe() (reused from Phase 3)
        # can raise directly, e.g. DatasetTooLargeError -> 413.
        logger.warning("cleaning.failed dataset_version_id=%s reason=rejected", dataset_version_id)
        raise
    except Exception as exc:  # noqa: BLE001 - deliberately broad: never leak internals to the client
        logger.exception("cleaning.failed dataset_version_id=%s reason=unexpected", dataset_version_id)
        raise CleaningError("Internal error while cleaning the dataset.", status_code=500) from exc
