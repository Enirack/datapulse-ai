from app.profiling.errors import ProfilingError


class CleaningError(ProfilingError):
    """
    Base for cleaning failures. Subclasses ProfilingError deliberately —
    it's a generic "computation error with an HTTP status" and main.py's
    already-registered exception handler for ProfilingError catches
    subclasses too (Starlette matches by class hierarchy), so no extra
    wiring is needed for cleaning errors to get the same JSON error shape.
    """


class ColumnNotFoundError(CleaningError):
    def __init__(self, column: str) -> None:
        super().__init__(f'Column "{column}" does not exist in this dataset.', status_code=422)


class UnknownOperationError(CleaningError):
    def __init__(self, operation_type: str) -> None:
        super().__init__(f'Unknown operation type "{operation_type}".', status_code=400)


class IncompatibleOperationError(CleaningError):
    """The operation is well-formed but not valid for this column's actual data."""

    def __init__(self, message: str) -> None:
        super().__init__(message, status_code=422)
