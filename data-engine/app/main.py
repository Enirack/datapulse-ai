from fastapi import FastAPI

from app.config import settings

app = FastAPI(title="DataPulse AI - Data Engine")


@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "service": "datapulse-data-engine",
        "environment": settings.environment,
    }
