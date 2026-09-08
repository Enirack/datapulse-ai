import logging
import time
from typing import Literal

from fastapi import APIRouter
from pydantic import BaseModel

from app.config import settings
from app.profiling.contract import ProfileResult
from app.profiling.errors import ProfilingError
from app.profiling.service import ProfilingService
from app.storage import DatasetStorageResolver, StorageError

logger = logging.getLogger("data_engine.profiling")

router = APIRouter()

_storage = DatasetStorageResolver(settings.storage_dir)
_service = ProfilingService(_storage, settings)


class ProfileRequest(BaseModel):
    datasetVersionId: str
    storageIdentifier: str
    extension: Literal["csv", "xlsx", "json"]


@router.post("/internal/profile", response_model=ProfileResult)
def profile(request: ProfileRequest) -> ProfileResult:
    # Never log dataset content, only identifiers and metadata.
    logger.info("profiling.start dataset_version_id=%s extension=%s", request.datasetVersionId, request.extension)
    started = time.monotonic()

    try:
        result, size_bytes = _service.profile(request.storageIdentifier, request.extension)
    except StorageError as exc:
        logger.warning("profiling.failed dataset_version_id=%s reason=storage_not_found", request.datasetVersionId)
        raise ProfilingError("Stored dataset file not found.", status_code=404) from exc
    except ProfilingError:
        logger.warning("profiling.failed dataset_version_id=%s reason=%s", request.datasetVersionId, "rejected")
        raise
    except Exception as exc:  # noqa: BLE001 - deliberately broad: never leak internals to the client
        logger.exception("profiling.failed dataset_version_id=%s reason=unexpected", request.datasetVersionId)
        raise ProfilingError("Internal error while profiling the dataset.", status_code=500) from exc

    duration_ms = int((time.monotonic() - started) * 1000)
    logger.info(
        "profiling.completed dataset_version_id=%s duration_ms=%d size_bytes=%d rows=%d columns=%d",
        request.datasetVersionId,
        duration_ms,
        size_bytes,
        result.dataset.rows,
        result.dataset.columns,
    )

    return result
