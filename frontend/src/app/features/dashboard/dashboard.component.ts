import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { DatasetService } from '../../core/dataset.service';
import { Dataset } from '../../core/models';
import { PlatformStatusService } from '../../core/platform-status.service';
import { FileSizePipe } from '../../shared/file-size.pipe';
import { SkeletonLoaderComponent } from '../../shared/skeleton-loader.component';

type BackendStatus = 'checking' | 'online' | 'offline';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [RouterLink, FileSizePipe, SkeletonLoaderComponent],
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  private readonly platformStatus = inject(PlatformStatusService);
  private readonly datasetService = inject(DatasetService);

  protected readonly backendStatus = signal<BackendStatus>('checking');
  protected readonly datasets = signal<Dataset[]>([]);
  protected readonly loadingDatasets = signal<boolean>(true);

  protected readonly totalStorageBytes = computed<number>(() => {
    return this.datasets().reduce((total, ds) => {
      const versionSizes = ds.versions.reduce((acc, v) => acc + (v.fileSize || 0), 0);
      return total + versionSizes;
    }, 0);
  });

  protected readonly recentDatasets = computed<Dataset[]>(() => {
    return this.datasets().slice(0, 5);
  });

  protected readonly pipelineSteps = [
    {
      step: '01',
      title: 'Secure Ingestion',
      description: 'Multipart CSV/XLSX/JSON upload with decompression-bomb and formula injection guards.',
      tag: 'Security',
    },
    {
      step: '02',
      title: 'Deterministic Profiling',
      description: 'Deep column type inference, null ratio, uniqueness, duplicates, and IQR outliers.',
      tag: 'Quality',
    },
    {
      step: '03',
      title: 'Reproducible Cleaning',
      description: 'Automated remediation pipeline, dry-run previews, and non-destructive versioning.',
      tag: 'Transformation',
    },
    {
      step: '04',
      title: 'Exploratory Analytics',
      description: 'Histograms, SVG boxplots, categorical distributions, Pearson correlation matrices.',
      tag: 'Statistics',
    },
    {
      step: '05',
      title: 'AI Analyst',
      description: 'Executive summaries, hygiene scoring (0-100), and interactive grounded Q&A with 0 hallucination.',
      tag: 'Intelligence',
    },
  ];

  ngOnInit(): void {
    this.platformStatus.checkBackendHealth().subscribe({
      next: () => this.backendStatus.set('online'),
      error: () => this.backendStatus.set('offline'),
    });

    this.datasetService.list().subscribe({
      next: (response) => {
        this.datasets.set(response.items ?? []);
        this.loadingDatasets.set(false);
      },
      error: () => {
        this.loadingDatasets.set(false);
      },
    });
  }
}
