import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ApiError } from '../../core/api-error';
import { DatasetService } from '../../core/dataset.service';
import {
  Analysis,
  AnalyticsResult,
  CleaningOperation,
  CleaningPreviewResult,
  CleaningRecommendation,
  CleaningResult,
  Dataset,
  DatasetVersionSummary,
} from '../../core/models';
import { FileSizePipe } from '../../shared/file-size.pipe';
import { AnalyticsSectionComponent } from './sections/analytics-section.component';
import { CleaningSectionComponent } from './sections/cleaning-section.component';
import { ProfileSectionComponent } from './sections/profile-section.component';

@Component({
  selector: 'app-dataset-detail',
  standalone: true,
  imports: [
    RouterLink,
    FileSizePipe,
    ProfileSectionComponent,
    CleaningSectionComponent,
    AnalyticsSectionComponent,
  ],
  templateUrl: './dataset-detail.component.html',
  styleUrl: './dataset-detail.component.scss',
})
export class DatasetDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly datasetService = inject(DatasetService);

  protected dataset = signal<Dataset | null>(null);
  protected loading = signal(true);
  protected loadError = signal<string | null>(null);

  protected currentVersion = computed<DatasetVersionSummary | null>(() => {
    const ds = this.dataset();
    return ds && ds.versions.length > 0 ? ds.versions[0] : null;
  });

  protected analysis = signal<Analysis | null>(null);
  protected analyzing = signal(false);
  protected analysisError = signal<string | null>(null);

  protected recommendations = signal<CleaningRecommendation[]>([]);
  protected loadingRecommendations = signal(false);

  protected selectedOperations = signal<CleaningOperation[]>([]);

  protected previewing = signal(false);
  protected previewResult = signal<CleaningPreviewResult | null>(null);
  protected previewError = signal<string | null>(null);

  protected applying = signal(false);
  protected cleaningResult = signal<CleaningResult | null>(null);
  protected cleaningError = signal<string | null>(null);

  protected analyticsAnalysis = signal<Analysis<AnalyticsResult> | null>(null);
  protected runningAnalytics = signal(false);
  protected analyticsError = signal<string | null>(null);

  ngOnInit(): void {
    const datasetId = this.route.snapshot.paramMap.get('id');
    if (!datasetId) {
      return;
    }

    this.datasetService.get(datasetId).subscribe({
      next: (dataset) => {
        this.dataset.set(dataset);
        this.loading.set(false);
        this.loadExistingProfile(dataset);
        this.loadExistingAnalytics(dataset);
      },
      error: (error: ApiError) => {
        this.loading.set(false);
        this.loadError.set(error.message || 'Unable to load this dataset.');
      },
    });
  }

  analyze(force = false): void {
    const dataset = this.dataset();
    const version = this.currentVersion();
    if (!dataset || !version || this.analyzing()) {
      return;
    }

    this.analyzing.set(true);
    this.analysisError.set(null);

    this.datasetService.triggerProfile(dataset.id, version.id, force).subscribe({
      next: (analysis) => {
        this.analyzing.set(false);
        this.analysis.set(analysis);
        if (analysis.status === 'completed') {
          this.loadRecommendations(dataset.id, version.id);
        }
      },
      error: (error: ApiError) => {
        this.analyzing.set(false);
        this.analysisError.set(error.message || 'Analysis failed to start.');
      },
    });
  }

  isSelected(type: string, column?: string): boolean {
    return this.selectedOperations().some((op) => op.type === type && op.column === column);
  }

  toggleOperation(type: string, column?: string): void {
    if (this.isSelected(type, column)) {
      this.selectedOperations.update((ops) => ops.filter((op) => !(op.type === type && op.column === column)));
    } else {
      this.selectedOperations.update((ops) => [...ops, column ? { type, column } : { type }]);
    }

    this.previewResult.set(null);
    this.cleaningResult.set(null);
  }

  removeSelectedOperation(index: number): void {
    this.selectedOperations.update((ops) => ops.filter((_, i) => i !== index));
    this.previewResult.set(null);
  }

  preview(): void {
    const dataset = this.dataset();
    const version = this.currentVersion();
    const operations = this.selectedOperations();
    if (!dataset || !version || operations.length === 0 || this.previewing()) {
      return;
    }

    this.previewing.set(true);
    this.previewError.set(null);

    this.datasetService.previewClean(dataset.id, version.id, operations).subscribe({
      next: (result) => {
        this.previewing.set(false);
        this.previewResult.set(result);
      },
      error: (error: ApiError) => {
        this.previewing.set(false);
        this.previewError.set(error.message || 'Unable to preview these changes.');
      },
    });
  }

  applyCleaning(): void {
    const dataset = this.dataset();
    const version = this.currentVersion();
    const operations = this.selectedOperations();
    if (!dataset || !version || operations.length === 0 || this.applying()) {
      return;
    }

    this.applying.set(true);
    this.cleaningError.set(null);

    this.datasetService.applyClean(dataset.id, version.id, operations).subscribe({
      next: (result) => {
        this.applying.set(false);
        this.cleaningResult.set(result);
      },
      error: (error: ApiError) => {
        this.applying.set(false);
        this.cleaningError.set(error.message || 'Cleaning failed to start.');
      },
    });
  }

  viewCleanedDataset(): void {
    const dataset = this.dataset();
    if (!dataset) {
      return;
    }

    this.loading.set(true);
    this.cleaningResult.set(null);
    this.selectedOperations.set([]);
    this.previewResult.set(null);
    this.analysis.set(null);
    this.recommendations.set([]);
    this.analyticsAnalysis.set(null);
    this.analyticsError.set(null);

    this.datasetService.get(dataset.id).subscribe({
      next: (refreshed) => {
        this.dataset.set(refreshed);
        this.loading.set(false);
        this.loadExistingProfile(refreshed);
        this.loadExistingAnalytics(refreshed);
      },
      error: (error: ApiError) => {
        this.loading.set(false);
        this.loadError.set(error.message || 'Unable to load this dataset.');
      },
    });
  }

  runAnalytics(force = false): void {
    const dataset = this.dataset();
    const version = this.currentVersion();
    if (!dataset || !version || this.runningAnalytics()) {
      return;
    }

    this.runningAnalytics.set(true);
    this.analyticsError.set(null);

    this.datasetService.triggerAnalytics(dataset.id, version.id, force).subscribe({
      next: (analysis) => {
        this.runningAnalytics.set(false);
        this.analyticsAnalysis.set(analysis);
        if (analysis.status === 'failed') {
          this.analyticsError.set(analysis.errorMessage || 'Analytics failed.');
        }
      },
      error: (error: ApiError) => {
        this.runningAnalytics.set(false);
        this.analyticsError.set(error.message || 'Analytics failed to start.');
      },
    });
  }

  private loadExistingAnalytics(dataset: Dataset): void {
    const version = dataset.versions[0];
    if (!version) {
      return;
    }

    this.datasetService.getAnalytics(dataset.id, version.id).subscribe({
      next: (analysis) => this.analyticsAnalysis.set(analysis),
      error: () => {
        // No analytics run yet
      },
    });
  }

  private loadExistingProfile(dataset: Dataset): void {
    const version = dataset.versions[0];
    if (!version) {
      return;
    }

    this.datasetService.getProfile(dataset.id, version.id).subscribe({
      next: (analysis) => {
        this.analysis.set(analysis);
        if (analysis.status === 'completed') {
          this.loadRecommendations(dataset.id, version.id);
        }
      },
      error: () => {
        // No profile yet
      },
    });
  }

  private loadRecommendations(datasetId: string, versionId: string): void {
    this.loadingRecommendations.set(true);

    this.datasetService.getCleaningRecommendations(datasetId, versionId).subscribe({
      next: (response) => {
        this.loadingRecommendations.set(false);
        this.recommendations.set(response.recommendations);
      },
      error: () => {
        this.loadingRecommendations.set(false);
      },
    });
  }
}
