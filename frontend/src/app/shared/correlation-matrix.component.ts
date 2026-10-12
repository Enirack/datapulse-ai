import { Component, input } from '@angular/core';

import { CorrelationMatrix } from '../core/models';

/**
 * A correlation matrix is just a table with colored cells — no charting
 * library needed for it (see docs/data-analytics.md "Chart library choice").
 */
@Component({
  selector: 'app-correlation-matrix',
  standalone: true,
  templateUrl: './correlation-matrix.component.html',
  styleUrl: './correlation-matrix.component.scss',
})
export class CorrelationMatrixComponent {
  matrix = input.required<CorrelationMatrix>();

  cellStyle(value: number | null): Record<string, string> {
    if (value === null) {
      return { background: '#f1f5f9', color: '#94a3b8' };
    }

    const intensity = Math.min(1, Math.abs(value));
    const hue = value >= 0 ? 217 : 0; // blue for positive, red for negative
    return {
      background: `hsl(${hue} 85% ${96 - intensity * 46}%)`,
      color: intensity > 0.6 ? '#fff' : '#1e293b',
    };
  }

  label(value: number | null): string {
    return value === null ? '—' : value.toFixed(2);
  }
}
