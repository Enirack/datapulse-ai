import { Injectable, signal } from '@angular/core';

export interface ToastMessage {
  id: string;
  message: string;
  type: 'error' | 'success' | 'info';
}

@Injectable({
  providedIn: 'root',
})
export class ToastService {
  readonly toasts = signal<ToastMessage[]>([]);

  show(message: string, type: 'error' | 'success' | 'info' = 'info', durationMs = 4000): string {
    const id = `toast-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
    const newToast: ToastMessage = { id, message, type };

    this.toasts.update((current) => [...current, newToast]);

    if (durationMs > 0) {
      setTimeout(() => {
        this.remove(id);
      }, durationMs);
    }

    return id;
  }

  error(message: string, durationMs = 5000): string {
    return this.show(message, 'error', durationMs);
  }

  success(message: string, durationMs = 4000): string {
    return this.show(message, 'success', durationMs);
  }

  info(message: string, durationMs = 4000): string {
    return this.show(message, 'info', durationMs);
  }

  remove(id: string): void {
    this.toasts.update((current) => current.filter((t) => t.id !== id));
  }
}
