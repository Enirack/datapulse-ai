from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="DATA_ENGINE_")

    environment: str = "dev"

    # Root directory of the shared dataset storage volume (read-only from
    # this service's point of view — only the backend ever writes here).
    storage_dir: str = "/app/storage/datasets"

    # A cleaning result is written here, never directly into storage_dir —
    # the backend is still the only thing that ever writes a *permanent*
    # dataset file; this is a read-write handoff area both sides share.
    tmp_dir: str = "/app/storage/tmp"

    # Deliberately independent from (and expected to be <=) the backend's
    # MAX_UPLOAD_SIZE: a file can be small enough to accept but still too
    # heavy to safely load into memory for profiling.
    max_profilable_bytes: int = 26_214_400  # 25 MiB

    # Rows/columns are capped rather than silently truncated — profiling
    # a partial dataset without saying so would misrepresent the result.
    max_rows: int = 200_000
    max_columns: int = 500

    top_values_limit: int = 10

    profile_schema_version: str = "1.0"


settings = Settings()
