class ProfilingError(Exception):
    """Base for all profiling failures that map to a specific HTTP status."""

    def __init__(self, message: str, status_code: int) -> None:
        super().__init__(message)
        self.message = message
        self.status_code = status_code


class DatasetNotFoundError(ProfilingError):
    def __init__(self, message: str = "Stored dataset file not found.") -> None:
        super().__init__(message, status_code=404)


class DatasetTooLargeError(ProfilingError):
    def __init__(self, message: str) -> None:
        super().__init__(message, status_code=413)


class UnprofilableFormatError(ProfilingError):
    def __init__(self, message: str) -> None:
        super().__init__(message, status_code=422)
