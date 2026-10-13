"""
Must run before any `app.*` module is imported anywhere in the test suite
(pytest guarantees conftest.py collection happens first), since
app.config.settings and the router's storage resolver are both built once,
at import time, from these environment variables.
"""

import os
import tempfile

_TEST_STORAGE_DIR = tempfile.mkdtemp(prefix="dp_test_storage_")
_TEST_TMP_DIR = tempfile.mkdtemp(prefix="dp_test_tmp_")
os.environ.setdefault("DATA_ENGINE_STORAGE_DIR", _TEST_STORAGE_DIR)
os.environ.setdefault("DATA_ENGINE_TMP_DIR", _TEST_TMP_DIR)
os.environ.setdefault("DATA_ENGINE_MAX_PROFILABLE_BYTES", "5242880")  # 5 MiB
os.environ.setdefault("DATA_ENGINE_MAX_ROWS", "1000")
os.environ.setdefault("DATA_ENGINE_MAX_COLUMNS", "50")
