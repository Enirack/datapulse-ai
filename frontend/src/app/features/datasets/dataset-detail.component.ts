import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ApiError } from '../../core/api-error';
import { DatasetService } from '../../core/dataset.service';
import { Analysis, Dataset } from '../../core/models';

@Component({
  selector: 'app-dataset-detail',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './dataset-detail.component.html',
  styleUrl: './dataset-detail.component.scss',
})
export class DatasetDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly datasetService = inject(DatasetService);

  protected dataset = signal<Dataset | null>(null);
  protected loading = signal(true);
  protected loadError = signal<string | null>(null);

  protected analysis = signal<Analysis | null>(null);
  protected analyzing = signal(false);
  protected analysisError = signal<string | null>(null);

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
      },
      error: (error: ApiError) => {
        this.loading.set(false);
        this.loadError.set(error.message || 'Unable to load this dataset.');
      },
    });
  }

  analyze(force = false): void {
    const dataset = this.dataset();
    const version = dataset?.versions[0];
    if (!dataset || !version || this.analyzing()) {
      return;
    }

    this.analyzing.set(true);
    this.analysisError.set(null);

    this.datasetService.triggerProfile(dataset.id, version.id, force).subscribe({
      next: (analysis) => {
        this.analyzing.set(false);
        this.analysis.set(analysis);
      },
      error: (error: ApiError) => {
        this.analyzing.set(false);
        this.analysisError.set(error.message || 'Analysis failed to start.');
      },
    });
  }

  private loadExistingProfile(dataset: Dataset): void {
    const version = dataset.versions[0];
    if (!version) {
      return;
    }

    this.datasetService.getProfile(dataset.id, version.id).subscribe({
      next: (analysis) => this.analysis.set(analysis),
      error: () => {
        // No profile yet — not an error state worth surfacing here.
      },
    });
  }
}
