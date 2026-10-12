import { Component, computed, input } from '@angular/core';

import { BoxplotChart } from '../core/models';

const SVG_WIDTH = 140;
const SVG_HEIGHT = 220;
const BOX_LEFT = 40;
const BOX_RIGHT = 100;
const TOP_MARGIN = 10;
const BOTTOM_MARGIN = 10;

interface BoxplotGeometry {
  whiskerX: number;
  boxLeft: number;
  boxRight: number;
  lowerWhiskerY: number;
  q1Y: number;
  medianY: number;
  q3Y: number;
  upperWhiskerY: number;
  labelFor: (value: number | null) => string;
}

/**
 * Chart.js core has no boxplot support (it would need a third-party
 * plugin) — a box plot is a small, fully deterministic set of five
 * numbers plus two fences, simple enough to render as plain SVG instead
 * of pulling in a second charting dependency. See docs/data-analytics.md
 * "Chart library choice".
 */
@Component({
  selector: 'app-boxplot-chart',
  standalone: true,
  templateUrl: './boxplot-chart.component.html',
  styleUrl: './boxplot-chart.component.scss',
})
export class BoxplotChartComponent {
  boxplot = input.required<BoxplotChart>();

  protected readonly svgWidth = SVG_WIDTH;
  protected readonly svgHeight = SVG_HEIGHT;

  protected geometry = computed<BoxplotGeometry | null>(() => {
    const b = this.boxplot();
    if (b.min === null || b.max === null || b.q1 === null || b.q3 === null || b.median === null) {
      return null;
    }

    const lower = b.lowerFence ?? b.min;
    const upper = b.upperFence ?? b.max;
    const domainMin = Math.min(b.min, lower);
    const domainMax = Math.max(b.max, upper);
    const span = domainMax - domainMin || 1;

    const plotHeight = SVG_HEIGHT - TOP_MARGIN - BOTTOM_MARGIN;
    const toY = (value: number): number => SVG_HEIGHT - BOTTOM_MARGIN - ((value - domainMin) / span) * plotHeight;

    return {
      whiskerX: (BOX_LEFT + BOX_RIGHT) / 2,
      boxLeft: BOX_LEFT,
      boxRight: BOX_RIGHT,
      lowerWhiskerY: toY(Math.max(b.min, lower)),
      q1Y: toY(b.q1),
      medianY: toY(b.median),
      q3Y: toY(b.q3),
      upperWhiskerY: toY(Math.min(b.max, upper)),
      labelFor: (value: number | null) => (value === null ? '—' : value.toLocaleString(undefined, { maximumFractionDigits: 2 })),
    };
  });
}
