import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { ApiError } from '../../core/api-error';
import { AuthService } from '../../core/auth.service';
import { DatasetService } from '../../core/dataset.service';
import { Dataset } from '../../core/models';
import { FileSizePipe } from '../../shared/file-size.pipe';
import { SkeletonLoaderComponent } from '../../shared/skeleton-loader.component';

@Component({
  selector: 'app-dataset-list',
  standalone: true,
  imports: [RouterLink, FormsModule, FileSizePipe, SkeletonLoaderComponent],
  templateUrl: './dataset-list.component.html',
  styleUrl: './dataset-list.component.scss',
})
export class DatasetListComponent implements OnInit {
  private readonly datasetService = inject(DatasetService);
  protected readonly auth = inject(AuthService);

  protected datasets = signal<Dataset[]>([]);
  protected loading = signal(true);
  protected errorMessage = signal<string | null>(null);
  protected uploading = signal(false);
  protected selectedFile: File | null = null;
  protected searchQuery = signal<string>('');

  protected readonly filteredDatasets = computed<Dataset[]>(() => {
    const query = this.searchQuery().toLowerCase().trim();
    const list = this.datasets();
    if (!query) {
      return list;
    }
    return list.filter((ds) => {
      const matchName = ds.name.toLowerCase().includes(query);
      const matchFile = ds.versions[0]?.originalFilename.toLowerCase().includes(query) ?? false;
      const matchDesc = ds.description?.toLowerCase().includes(query) ?? false;
      return matchName || matchFile || matchDesc;
    });
  });

  ngOnInit(): void {
    this.refresh();
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.selectedFile = input.files?.[0] ?? null;
  }

  upload(): void {
    if (!this.selectedFile || this.uploading()) {
      return;
    }

    this.uploading.set(true);
    this.errorMessage.set(null);

    this.datasetService.upload(this.selectedFile).subscribe({
      next: () => {
        this.uploading.set(false);
        this.selectedFile = null;
        this.refresh();
      },
      error: (error: ApiError) => {
        this.uploading.set(false);
        this.errorMessage.set(error.message || 'Upload failed.');
      },
    });
  }

  logout(): void {
    this.auth.logout();
  }

  private refresh(): void {
    this.loading.set(true);
    this.datasetService.list().subscribe({
      next: (response) => {
        this.datasets.set(response.items);
        this.loading.set(false);
      },
      error: (error: ApiError) => {
        this.loading.set(false);
        this.errorMessage.set(error.message || 'Unable to load datasets.');
      },
    });
  }
}
