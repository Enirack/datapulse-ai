import logging
import time
from typing import Literal

from fastapi import APIRouter
from pydantic import BaseModel

from app.analytics.contract import AnalyticsResult
from app.analytics.service import AnalyticsService
from app.config import settings
from app.profiling.errors import ProfilingError
from app.storage import DatasetStorageResolver, StorageError

logger = logging.getLogger("data_engine.analytics")

router = APIRouter()

_storage = DatasetStorageResolver(settings.storage_dir)
_service = AnalyticsService(_storage, settings)


class AnalyticsRequest(BaseModel):
    datasetVersionId: str
    storageIdentifier: str
    extension: Literal["csv", "xlsx", "json"]


@router.post("/internal/analytics", response_model=AnalyticsResult)
def analytics(request: AnalyticsRequest) -> AnalyticsResult:
    # Never log dataset content, only identifiers and metadata.
    logger.info("analytics.start dataset_version_id=%s extension=%s", request.datasetVersionId, request.extension)
    started = time.monotonic()

    try:
        result, size_bytes = _service.analyze(request.storageIdentifier, request.extension)
    except StorageError as exc:
        logger.warning("analytics.failed dataset_version_id=%s reason=storage_not_found", request.datasetVersionId)
        raise ProfilingError("Stored dataset file not found.", status_code=404) from exc
    except ProfilingError:
        logger.warning("analytics.failed dataset_version_id=%s reason=%s", request.datasetVersionId, "rejected")
        raise
    except Exception as exc:  # noqa: BLE001 - deliberately broad: never leak internals to the client
        logger.exception("analytics.failed dataset_version_id=%s reason=unexpected", request.datasetVersionId)
        raise ProfilingError("Internal error while analyzing the dataset.", status_code=500) from exc

    duration_ms = int((time.monotonic() - started) * 1000)
    logger.info(
        "analytics.completed dataset_version_id=%s duration_ms=%d size_bytes=%d charts=%d",
        request.datasetVersionId,
        duration_ms,
        size_bytes,
        result.summary.chartCount,
    )

    return result
