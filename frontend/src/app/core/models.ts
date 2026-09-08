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

export interface Analysis {
  id: string;
  datasetVersionId: string;
  type: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  resultSchemaVersion: string | null;
  result: ProfileResult | null;
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
