from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.config import settings
from app.profiling.errors import ProfilingError
from app.routers.profile import router as profile_router

app = FastAPI(title="DataPulse AI - Data Engine")
app.include_router(profile_router)


@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "service": "datapulse-data-engine",
        "environment": settings.environment,
    }


@app.exception_handler(ProfilingError)
def profiling_error_handler(request: Request, exc: ProfilingError) -> JSONResponse:
    return JSONResponse(status_code=exc.status_code, content={"status": "error", "message": exc.message})


@app.exception_handler(RequestValidationError)
def validation_error_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
    # Malformed/incomplete request body -> 400 "invalid request", distinct
    # from 422 which this API reserves for "valid request, unprofilable content".
    return JSONResponse(status_code=400, content={"status": "error", "message": "Invalid request."})
