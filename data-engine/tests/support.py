import json
import os
import uuid

import pandas as pd

from app.config import settings


def _new_identifier(extension: str) -> str:
    file_uuid = str(uuid.uuid4())
    return f"{file_uuid[:2]}/{file_uuid}.{extension}"


def write_csv_fixture(content: str) -> str:
    identifier = _new_identifier("csv")
    _write(identifier, content, mode="w")
    return identifier


def write_json_fixture(data: object) -> str:
    identifier = _new_identifier("json")
    _write(identifier, json.dumps(data), mode="w")
    return identifier


def write_raw_json_fixture(raw: str) -> str:
    identifier = _new_identifier("json")
    _write(identifier, raw, mode="w")
    return identifier


def write_xlsx_fixture(df: pd.DataFrame) -> str:
    identifier = _new_identifier("xlsx")
    path = os.path.join(settings.storage_dir, identifier)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    df.to_excel(path, index=False, engine="openpyxl")
    return identifier


def _write(identifier: str, content: str, mode: str) -> None:
    path = os.path.join(settings.storage_dir, identifier)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, mode, encoding="utf-8") as handle:
        handle.write(content)
