import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { ApiService } from './api.service';

const TOKEN_STORAGE_KEY = 'datapulse.auth.token';

interface LoginResponse {
  token: string;
}

interface RegisterResponse {
  id: string;
  email: string;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiService);

  private readonly tokenSignal = signal<string | null>(this.readStoredToken());
  readonly isAuthenticated = computed(() => this.tokenSignal() !== null);

  token(): string | null {
    return this.tokenSignal();
  }

  login(email: string, password: string): Observable<LoginResponse> {
    return this.api.post<LoginResponse>('/auth/login', { email, password }).pipe(
      tap((response) => this.setToken(response.token)),
    );
  }

  register(email: string, password: string, accessKey?: string): Observable<RegisterResponse> {
    const payload: { email: string; password: string; access_key?: string } = { email, password };
    if (accessKey) {
      payload.access_key = accessKey;
    }
    return this.api.post<RegisterResponse>('/auth/register', payload);
  }

  logout(): void {
    this.setToken(null);
  }

  private setToken(token: string | null): void {
    this.tokenSignal.set(token);
    try {
      if (token) {
        localStorage.setItem(TOKEN_STORAGE_KEY, token);
      } else {
        localStorage.removeItem(TOKEN_STORAGE_KEY);
      }
    } catch {
      // Storage unavailable (private browsing, etc.) — the in-memory
      // signal still works for the current page load.
    }
  }

  private readStoredToken(): string | null {
    try {
      return localStorage.getItem(TOKEN_STORAGE_KEY);
    } catch {
      return null;
    }
  }
}
