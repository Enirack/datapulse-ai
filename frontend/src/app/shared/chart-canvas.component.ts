import { Component, ElementRef, effect, input, viewChild } from '@angular/core';
import { Chart, ChartConfiguration, registerables } from 'chart.js';

Chart.register(...registerables);

/**
 * Thin, generic wrapper around Chart.js — the one charting library this
 * project uses (see docs/data-analytics.md "Chart library choice"). One
 * reusable canvas instead of a component per chart type: callers build a
 * `ChartConfiguration` (histogram/bar/line/scatter all just differ by
 * `type` + `data`) and this component owns the Chart.js instance
 * lifecycle. Boxplots and the correlation matrix render as plain
 * SVG/HTML instead — see boxplot-chart.component.ts and
 * correlation-matrix.component.ts — since Chart.js core has no boxplot
 * support and a matrix is just a table.
 */
@Component({
  selector: 'app-chart-canvas',
  standalone: true,
  template: `<canvas #canvasRef></canvas>`,
  styles: [':host { display: block; position: relative; }'],
})
export class ChartCanvasComponent {
  config = input<ChartConfiguration | null>(null);

  private readonly canvasRef = viewChild.required<ElementRef<HTMLCanvasElement>>('canvasRef');

  constructor() {
    effect((onCleanup) => {
      const config = this.config();
      if (!config) {
        return;
      }

      const chart = new Chart(this.canvasRef().nativeElement, config);
      onCleanup(() => chart.destroy());
    });
  }
}
