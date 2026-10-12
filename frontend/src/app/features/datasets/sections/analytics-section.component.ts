import { Component, input, output } from '@angular/core';
import { ChartConfiguration } from 'chart.js';

import {
  Analysis,
  AnalyticsResult,
  BarChart,
  HistogramChart,
  ScatterChart,
  TimeSeriesChart,
} from '../../../core/models';
import { BoxplotChartComponent } from '../../../shared/boxplot-chart.component';
import { ChartCanvasComponent } from '../../../shared/chart-canvas.component';
import { CorrelationMatrixComponent } from '../../../shared/correlation-matrix.component';
import {
  buildBarConfig,
  buildHistogramConfig,
  buildScatterConfig,
  buildTimeSeriesConfig,
} from '../analytics-chart-builder';

@Component({
  selector: 'app-analytics-section',
  standalone: true,
  imports: [ChartCanvasComponent, BoxplotChartComponent, CorrelationMatrixComponent],
  templateUrl: './analytics-section.component.html',
  styleUrl: './analytics-section.component.scss',
})
export class AnalyticsSectionComponent {
  readonly analyticsAnalysis = input<Analysis<AnalyticsResult> | null>(null);
  readonly runningAnalytics = input<boolean>(false);
  readonly analyticsError = input<string | null>(null);

  readonly analyticsRequested = output<boolean>();

  onRunAnalytics(force = false): void {
    this.analyticsRequested.emit(force);
  }

  histogramConfig(chart: HistogramChart): ChartConfiguration {
    return buildHistogramConfig(chart);
  }

  barConfig(chart: BarChart): ChartConfiguration {
    return buildBarConfig(chart);
  }

  timeSeriesConfig(chart: TimeSeriesChart): ChartConfiguration {
    return buildTimeSeriesConfig(chart);
  }

  scatterConfig(chart: ScatterChart): ChartConfiguration {
    return buildScatterConfig(chart);
  }
}
