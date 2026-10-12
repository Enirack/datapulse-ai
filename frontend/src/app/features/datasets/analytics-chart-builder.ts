import { ChartConfiguration } from 'chart.js';

import { BarChart, HistogramChart, ScatterChart, TimeSeriesChart } from '../../core/models';

const AXIS_COLOR = '#64748b';
const GRID_COLOR = '#e2e8f0';
const PRIMARY_COLOR = '#2563eb';
const PRIMARY_FILL = 'rgba(37, 99, 235, 0.55)';

const BASE_SCALES = {
  x: { ticks: { color: AXIS_COLOR }, grid: { color: GRID_COLOR } },
  y: { ticks: { color: AXIS_COLOR }, grid: { color: GRID_COLOR }, beginAtZero: true },
};

export function buildHistogramConfig(chart: HistogramChart): ChartConfiguration {
  const labels = chart.counts.map((_, i) => `${round(chart.bins[i])} – ${round(chart.bins[i + 1])}`);

  return {
    type: 'bar',
    data: {
      labels,
      datasets: [{ label: chart.column, data: chart.counts, backgroundColor: PRIMARY_FILL, borderColor: PRIMARY_COLOR, borderWidth: 1 }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: BASE_SCALES,
    },
  };
}

export function buildBarConfig(chart: BarChart): ChartConfiguration {
  return {
    type: 'bar',
    data: {
      labels: chart.labels,
      datasets: [{ label: chart.column, data: chart.values, backgroundColor: PRIMARY_FILL, borderColor: PRIMARY_COLOR, borderWidth: 1 }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      indexAxis: 'y',
      plugins: { legend: { display: false } },
      scales: BASE_SCALES,
    },
  };
}

export function buildTimeSeriesConfig(chart: TimeSeriesChart): ChartConfiguration {
  return {
    type: 'line',
    data: {
      labels: chart.x,
      datasets: [
        {
          label: chart.column,
          data: chart.y,
          borderColor: PRIMARY_COLOR,
          backgroundColor: PRIMARY_FILL,
          tension: 0.2,
          pointRadius: chart.x.length > 40 ? 0 : 3,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: BASE_SCALES,
    },
  };
}

export function buildScatterConfig(chart: ScatterChart): ChartConfiguration {
  return {
    type: 'scatter',
    data: {
      datasets: [
        {
          label: `${chart.xColumn} vs ${chart.yColumn}`,
          data: chart.points,
          backgroundColor: PRIMARY_FILL,
          pointRadius: 3,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { ...BASE_SCALES.x, title: { display: true, text: chart.xColumn, color: AXIS_COLOR }, beginAtZero: false },
        y: { ...BASE_SCALES.y, title: { display: true, text: chart.yColumn, color: AXIS_COLOR }, beginAtZero: false },
      },
    },
  };
}

function round(value: number): string {
  return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
}
