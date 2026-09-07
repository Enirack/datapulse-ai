import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { catchError, throwError } from 'rxjs';

import { ApiError } from './api-error';

export const httpErrorInterceptor: HttpInterceptorFn = (req, next) =>
  next(req).pipe(
    catchError((error: HttpErrorResponse) => {
      const message = isKnownErrorBody(error.error)
        ? error.error.message
        : error.statusText || 'Unexpected network error';

      console.error(`[API error] ${req.method} ${req.url} -> ${error.status} ${message}`);

      const apiError: ApiError = { status: error.status, message };
      return throwError(() => apiError);
    }),
  );

function isKnownErrorBody(body: unknown): body is { message: string } {
  return typeof body === 'object' && body !== null && typeof (body as { message?: unknown }).message === 'string';
}
