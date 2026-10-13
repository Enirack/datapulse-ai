import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { DatasetService } from '../../../core/dataset.service';
import {
  AiInsight,
  AiInsightsResult,
  AiQuestionResponse,
  Analysis,
  InsightKind,
} from '../../../core/models';

@Component({
  selector: 'app-ai-analyst-section',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './ai-analyst-section.component.html',
  styleUrl: './ai-analyst-section.component.scss',
})
export class AiAnalystSectionComponent {
  private readonly datasetService = inject(DatasetService);

  readonly datasetId = input<string>('');
  readonly versionId = input<string>('');
  readonly insightsAnalysis = input<Analysis<AiInsightsResult> | null>(null);
  readonly loadingInsights = input<boolean>(false);
  readonly insightsError = input<string | null>(null);
  readonly canGenerate = input<boolean>(true);

  readonly generateRequested = output<boolean>();

  protected readonly selectedFilter = signal<'all' | InsightKind>('all');
  protected readonly questionText = signal<string>('');
  protected readonly asking = signal<boolean>(false);
  protected readonly askError = signal<string | null>(null);
  protected readonly qaHistory = signal<AiQuestionResponse[]>([]);

  protected readonly insightsResult = computed<AiInsightsResult | null>(() => {
    const analysis = this.insightsAnalysis();
    return analysis && analysis.status === 'completed' && analysis.result
      ? analysis.result
      : null;
  });

  protected readonly filteredInsights = computed<AiInsight[]>(() => {
    const result = this.insightsResult();
    if (!result) {
      return [];
    }
    const filter = this.selectedFilter();
    if (filter === 'all') {
      return result.insights;
    }
    return result.insights.filter((item) => item.kind === filter);
  });

  protected readonly healthRating = computed<{ label: string; tone: string }>(() => {
    const score = this.insightsResult()?.healthScore ?? 0;
    if (score >= 90) {
      return { label: 'Excellent Health', tone: 'excellent' };
    }
    if (score >= 75) {
      return { label: 'Good Quality', tone: 'good' };
    }
    if (score >= 50) {
      return { label: 'Fair / Action Recommended', tone: 'fair' };
    }
    return { label: 'Critical Attention Needed', tone: 'critical' };
  });

  onGenerate(force = false): void {
    this.generateRequested.emit(force);
  }

  setFilter(filter: 'all' | InsightKind): void {
    this.selectedFilter.set(filter);
  }

  submitQuestion(customQuestion?: string): void {
    const q = (customQuestion ?? this.questionText()).trim();
    const dsId = this.datasetId();
    const vId = this.versionId();

    if (!q || !dsId || !vId || this.asking()) {
      return;
    }

    this.asking.set(true);
    this.askError.set(null);

    this.datasetService.askAiAnalyst(dsId, vId, q).subscribe({
      next: (response) => {
        this.asking.set(false);
        this.qaHistory.update((prev) => [response, ...prev]);
        if (!customQuestion) {
          this.questionText.set('');
        }
      },
      error: (err) => {
        this.asking.set(false);
        this.askError.set(err?.message || 'Failed to get answer from AI Analyst.');
      },
    });
  }
}
