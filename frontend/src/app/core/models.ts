export interface DatasetVersionSummary {
  id: string;
  versionNumber: number;
  kind: string;
  originalFilename: string;
  mimeType: string;
  fileSize: number;
  checksum: string;
  hasFormulaInjectionRisk: boolean;
  createdAt: string;
}

export interface Dataset {
  id: string;
  name: string;
  description: string | null;
  status: string;
  createdAt: string;
  updatedAt: string;
  versions: DatasetVersionSummary[];
}

export interface DatasetListResponse {
  items: Dataset[];
  pagination: { page: number; limit: number; total: number; totalPages: number };
}

export interface NumericStatistics {
  min: number | null;
  max: number | null;
  mean: number | null;
  median: number | null;
  std: number | null;
  variance: number | null;
  q25: number | null;
  q50: number | null;
  q75: number | null;
}

export interface StringStatistics {
  minLength: number | null;
  maxLength: number | null;
  meanLength: number | null;
  emptyCount: number;
  whitespaceOnlyCount: number;
}

export interface TopValue {
  value: string;
  count: number;
  percentage: number;
}

export interface CategoricalStatistics {
  mostFrequentValue: string | null;
  mostFrequentCount: number | null;
  topValues: TopValue[];
  truncated: boolean;
}

export interface OutlierStatistics {
  method: string;
  outlierCount: number;
  outlierPercentage: number;
}

export interface ColumnProfile {
  name: string;
  pandasType: string;
  detectedType: string;
  nullable: boolean;
  nullCount: number;
  nullPercentage: number;
  uniqueCount: number;
  uniquePercentage: number;
  duplicateCount: number;
  statistics: NumericStatistics | null;
  stringStatistics: StringStatistics | null;
  categorical: CategoricalStatistics | null;
  outliers: OutlierStatistics | null;
}

export interface ProfileResult {
  schemaVersion: string;
  dataset: {
    rows: number;
    columns: number;
    totalCells: number;
    totalNullCells: number;
    nullPercentage: number;
    duplicateRowCount: number;
    duplicateRowPercentage: number;
  };
  columns: ColumnProfile[];
}

export interface Analysis<TResult = ProfileResult> {
  id: string;
  datasetVersionId: string;
  type: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  resultSchemaVersion: string | null;
  result: TResult | null;
  errorMessage: string | null;
  createdAt: string;
  startedAt: string | null;
  completedAt: string | null;
}

export interface CleaningRecommendation {
  type: string;
  column?: string;
  issue?: string;
  method?: string;
  outlierCount?: number;
  outlierPercentage?: number;
  recommendedActions: string[];
}

export interface CleaningRecommendationsResponse {
  datasetVersionId: string;
  recommendations: CleaningRecommendation[];
}

export interface CleaningOperation {
  type: string;
  column?: string;
  value?: string | number | boolean;
}

export interface OperationReport {
  operation: string;
  column?: string | null;
  affectedRows: number;
  beforeRows?: number | null;
  afterRows?: number | null;
  details?: string | null;
}

export interface CleaningReport {
  operations: OperationReport[];
  before: ProfileResult;
  after: ProfileResult;
}

export interface CleaningResult {
  analysisId: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  sourceVersionId: string;
  resultVersionId: string | null;
  changed: boolean;
  report: CleaningReport | null;
  errorMessage: string | null;
  operations: CleaningOperation[] | null;
  createdAt: string;
  startedAt: string | null;
  completedAt: string | null;
}

export interface CleaningPreviewResult {
  estimatedAffectedRows: number;
  operations: OperationReport[];
  before: ProfileResult;
  after: ProfileResult;
}

// --- Analytics (Phase 5) ---------------------------------------------------

export interface NumericAnalyticsStatistics {
  count: number;
  missingCount: number;
  missingPercentage: number;
  uniqueCount: number;
  min: number | null;
  max: number | null;
  mean: number | null;
  median: number | null;
  standardDeviation: number | null;
  variance: number | null;
  q1: number | null;
  q3: number | null;
  iqr: number | null;
  sum: number | null;
  range: number | null;
}

export interface HistogramChart {
  type: 'histogram';
  column: string;
  bins: number[];
  counts: number[];
}

export interface BoxplotChart {
  type: 'boxplot';
  column: string;
  min: number | null;
  q1: number | null;
  median: number | null;
  q3: number | null;
  max: number | null;
  lowerFence: number | null;
  upperFence: number | null;
  outlierCount: number;
}

export interface NumericAnalysis {
  column: string;
  statistics: NumericAnalyticsStatistics;
  histogram: HistogramChart | null;
  boxplot: BoxplotChart | null;
}

export interface CategoricalTopValue {
  value: string;
  count: number;
  percentage: number;
}

export interface CategoricalAnalyticsStatistics {
  count: number;
  missingCount: number;
  missingPercentage: number;
  uniqueCount: number;
  topValues: CategoricalTopValue[];
}

export interface BarChart {
  type: 'bar';
  column: string;
  labels: string[];
  values: number[];
}

export interface CategoricalAnalysis {
  column: string;
  statistics: CategoricalAnalyticsStatistics;
  chart: BarChart;
}

export type Granularity = 'day' | 'week' | 'month' | 'quarter' | 'year';

export interface TemporalStatistics {
  count: number;
  missingCount: number;
  missingPercentage: number;
  minDate: string | null;
  maxDate: string | null;
}

export interface TimeSeriesChart {
  type: 'time_series';
  column: string;
  granularity: Granularity;
  x: string[];
  y: number[];
}

export interface TemporalAnalysis {
  column: string;
  statistics: TemporalStatistics;
  chart: TimeSeriesChart | null;
}

export interface CorrelationMatrix {
  type: 'correlation_matrix';
  method: 'pearson';
  columns: string[];
  matrix: (number | null)[][];
}

export interface ScatterPoint {
  x: number;
  y: number;
}

export interface ScatterChart {
  type: 'scatter';
  xColumn: string;
  yColumn: string;
  correlation: number | null;
  points: ScatterPoint[];
  totalPoints: number;
  sampled: boolean;
  sampleSize: number;
}

export interface AnalyticsSummary {
  numericColumnCount: number;
  categoricalColumnCount: number;
  temporalColumnCount: number;
  unanalyzedColumnCount: number;
  chartCount: number;
}

export interface AnalyticsDatasetSummary {
  rows: number;
  columns: number;
  totalCells: number;
  totalNullCells: number;
  nullPercentage: number;
  duplicateRowCount: number;
  duplicateRowPercentage: number;
}

export interface AnalyticsResult {
  schemaVersion: string;
  dataset: AnalyticsDatasetSummary;
  summary: AnalyticsSummary;
  numeric: NumericAnalysis[];
  categorical: CategoricalAnalysis[];
  temporal: TemporalAnalysis[];
  correlations: CorrelationMatrix | null;
  scatter: ScatterChart[];
}

export type InsightKind = 'computed_fact' | 'statistical_interpretation' | 'recommendation';

export type AiInsightMetrics = Record<string, unknown>;

export interface AiInsight {
  id: string;
  kind: InsightKind;
  title: string;
  description: string;
  category: string;
  metrics: AiInsightMetrics;
}

export interface AiHighlights {
  totalRows: number;
  totalColumns: number;
  completenessPercentage: number;
  dominantCategory: string | null;
  topCorrelation: string | null;
}

export interface AiInsightsResult {
  schemaVersion: string;
  healthScore: number;
  executiveSummary: string;
  highlights: AiHighlights;
  insights: AiInsight[];
  suggestedQuestions: string[];
}

export interface AiQuestionResponse {
  schemaVersion: string;
  question: string;
  answer: string;
  grounded: boolean;
  referencedMetrics: Record<string, unknown>;
}
