import { Component, input } from '@angular/core';

@Component({
  selector: 'app-skeleton-loader',
  standalone: true,
  template: `
    <div class="skeleton-container" [class]="'variant--' + variant()">
      @for (item of items(); track $index) {
        <div
          class="skeleton-shimmer"
          [style.width]="width()"
          [style.height]="height()"
        ></div>
      }
    </div>
  `,
  styles: [`
    .skeleton-container {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      width: 100%;
    }

    .skeleton-shimmer {
      background: linear-gradient(
        90deg,
        var(--bg-surface, #f1f5f9) 25%,
        var(--bg-surface-hover, #e2e8f0) 50%,
        var(--bg-surface, #f1f5f9) 75%
      );
      background-size: 200% 100%;
      animation: shimmer 1.5s infinite;
      border-radius: 6px;
    }

    .variant--card {
      .skeleton-shimmer {
        border-radius: 10px;
        min-height: 80px;
      }
    }

    .variant--circle {
      .skeleton-shimmer {
        border-radius: 50%;
      }
    }

    @keyframes shimmer {
      0% {
        background-position: 200% 0;
      }
      100% {
        background-position: -200% 0;
      }
    }
  `],
})
export class SkeletonLoaderComponent {
  readonly variant = input<'text' | 'card' | 'circle'>('text');
  readonly count = input<number>(1);
  readonly width = input<string>('100%');
  readonly height = input<string>('1.25rem');

  protected get items(): () => number[] {
    return () => Array.from({ length: Math.max(1, this.count()) }, (_, i) => i);
  }
}
