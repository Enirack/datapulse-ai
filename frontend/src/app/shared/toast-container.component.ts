import { Component, inject } from '@angular/core';
import { ToastService } from '../core/toast.service';

@Component({
  selector: 'app-toast-container',
  standalone: true,
  template: `
    @if (toastService.toasts().length > 0) {
      <div class="toast-container" role="region" aria-label="Notifications" aria-live="polite">
        @for (toast of toastService.toasts(); track toast.id) {
          <div class="toast toast--{{ toast.type }}" role="status">
            <span class="toast__icon">
              @if (toast.type === 'error') { ✕ }
              @else if (toast.type === 'success') { ✓ }
              @else { ℹ }
            </span>
            <span class="toast__message">{{ toast.message }}</span>
            <button
              type="button"
              class="toast__close"
              aria-label="Dismiss notification"
              (click)="toastService.remove(toast.id)"
            >
              ×
            </button>
          </div>
        }
      </div>
    }
  `,
  styles: [`
    .toast-container {
      position: fixed;
      bottom: 1.5rem;
      right: 1.5rem;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      max-width: 400px;
      pointer-events: none;
    }

    .toast {
      pointer-events: auto;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      color: #fff;
      font-size: 0.9rem;
      font-weight: 500;
      animation: slideIn 0.2s ease-out;

      &--error {
        background: #d32f2f;
      }

      &--success {
        background: #2e7d32;
      }

      &--info {
        background: #1976d2;
      }
    }

    .toast__icon {
      font-weight: bold;
      font-size: 1rem;
    }

    .toast__message {
      flex: 1;
    }

    .toast__close {
      background: transparent;
      border: none;
      color: #fff;
      font-size: 1.25rem;
      cursor: pointer;
      line-height: 1;
      padding: 0 0.25rem;
      opacity: 0.8;

      &:hover {
        opacity: 1;
      }
    }

    @keyframes slideIn {
      from {
        transform: translateY(10px);
        opacity: 0;
      }
      to {
        transform: translateY(0);
        opacity: 1;
      }
    }
  `],
})
export class ToastContainerComponent {
  protected readonly toastService = inject(ToastService);
}
