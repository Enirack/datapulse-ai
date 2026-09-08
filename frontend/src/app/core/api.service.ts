import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { environment } from '../../environments/environment';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiBaseUrl;

  get<T>(path: string, params?: Record<string, string | number>): Observable<T> {
    return this.http.get<T>(this.buildUrl(path), { params: this.buildParams(params) });
  }

  post<T>(path: string, body: unknown): Observable<T> {
    return this.http.post<T>(this.buildUrl(path), body);
  }

  postForm<T>(path: string, formData: FormData, params?: Record<string, string | number>): Observable<T> {
    return this.http.post<T>(this.buildUrl(path), formData, { params: this.buildParams(params) });
  }

  delete<T>(path: string): Observable<T> {
    return this.http.delete<T>(this.buildUrl(path));
  }

  private buildUrl(path: string): string {
    return `${this.baseUrl}${path.startsWith('/') ? path : `/${path}`}`;
  }

  private buildParams(params?: Record<string, string | number>): HttpParams | undefined {
    if (!params) {
      return undefined;
    }

    let httpParams = new HttpParams();
    for (const [key, value] of Object.entries(params)) {
      httpParams = httpParams.set(key, value);
    }

    return httpParams;
  }
}
