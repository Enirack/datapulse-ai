import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';

import { ApiError } from './api-error';
import { ToastService } from './toast.service';

export const httpErrorInterceptor: HttpInterceptorFn = (req, next) => {
  const toastService = inject(ToastService);

  return next(req).pipe(
    catchError((error: HttpErrorResponse) => {
      const message = isKnownErrorBody(error.error)
        ? error.error.message
        : error.statusText || 'Unexpected network error';

      console.error(`[API error] ${req.method} ${req.url} -> ${error.status} ${message}`);

      if (error.status === 0) {
        toastService.error('Network error: unable to reach the backend server.');
      } else if (error.status >= 500) {
        toastService.error(`Server error (${error.status}): please try again later.`);
      }

      const apiError: ApiError = { status: error.status, message };
      return throwError(() => apiError);
    }),
  );
};

function isKnownErrorBody(body: unknown): body is { message: string } {
  return typeof body === 'object' && body !== null && typeof (body as { message?: unknown }).message === 'string';
}
