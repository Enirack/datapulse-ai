import { Component, input, output } from '@angular/core';
import {
  CleaningOperation,
  CleaningPreviewResult,
  CleaningRecommendation,
  CleaningResult,
} from '../../../core/models';

const NON_ACTIONABLE_ACTIONS = new Set(['review']);
const ACTIONS_REQUIRING_EXTRA_INPUT = new Set(['fill_constant']);

const ACTION_LABELS: Record<string, string> = {
  drop_rows: 'Drop rows',
  fill_constant: 'Fill with a value',
  fill_mean: 'Fill mean',
  fill_median: 'Fill median',
  fill_mode: 'Fill mode',
  remove_duplicate_rows: 'Remove duplicates',
  trim_whitespace: 'Trim',
  normalize_internal_whitespace: 'Normalize whitespace',
  convert_to_integer: 'Convert to integer',
  convert_to_float: 'Convert to float',
  convert_to_date: 'Convert to date',
  convert_to_datetime: 'Convert to datetime',
  drop_column: 'Drop column',
  remove_iqr_outliers: 'Remove outliers',
  review: 'Review',
};

const OPERATION_LABELS: Record<string, string> = ACTION_LABELS;

@Component({
  selector: 'app-cleaning-section',
  standalone: true,
  templateUrl: './cleaning-section.component.html',
  styleUrl: './cleaning-section.component.scss',
})
export class CleaningSectionComponent {
  readonly recommendations = input<CleaningRecommendation[]>([]);
  readonly loadingRecommendations = input<boolean>(false);
  readonly selectedOperations = input<CleaningOperation[]>([]);
  readonly previewing = input<boolean>(false);
  readonly previewResult = input<CleaningPreviewResult | null>(null);
  readonly previewError = input<string | null>(null);
  readonly applying = input<boolean>(false);
  readonly cleaningResult = input<CleaningResult | null>(null);
  readonly cleaningError = input<string | null>(null);

  readonly operationToggled = output<{ type: string; column?: string }>();
  readonly operationRemoved = output<number>();
  readonly previewRequested = output<void>();
  readonly applyRequested = output<void>();
  readonly viewCleanedRequested = output<void>();

  isSelected(type: string, column?: string): boolean {
    return this.selectedOperations().some((op) => op.type === type && op.column === column);
  }

  isActionable(action: string): boolean {
    return !NON_ACTIONABLE_ACTIONS.has(action) && !ACTIONS_REQUIRING_EXTRA_INPUT.has(action);
  }

  actionLabel(action: string): string {
    return ACTION_LABELS[action] ?? action;
  }

  operationLabel(operation: CleaningOperation): string {
    const label = OPERATION_LABELS[operation.type] ?? operation.type;
    return operation.column ? `${label} — ${operation.column}` : label;
  }
}
