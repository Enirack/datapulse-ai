import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { UpperCasePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { ApiError } from '../../core/api-error';
import { AuthService } from '../../core/auth.service';
import { DatasetService } from '../../core/dataset.service';
import { ToastService } from '../../core/toast.service';
import { Dataset } from '../../core/models';
import { FileSizePipe } from '../../shared/file-size.pipe';
import { SkeletonLoaderComponent } from '../../shared/skeleton-loader.component';

@Component({
  selector: 'app-dataset-list',
  standalone: true,
  imports: [RouterLink, FormsModule, UpperCasePipe, FileSizePipe, SkeletonLoaderComponent],
  templateUrl: './dataset-list.component.html',
  styleUrl: './dataset-list.component.scss',
})
export class DatasetListComponent implements OnInit {
  private readonly datasetService = inject(DatasetService);
  protected readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);

  protected datasets = signal<Dataset[]>([]);
  protected loading = signal(true);
  protected errorMessage = signal<string | null>(null);
  protected uploading = signal(false);
  protected selectedFile: File | null = null;
  protected searchQuery = signal<string>('');

  protected readonly isQuotaExceeded = computed<boolean>(() => {
    return !this.auth.isPro() && this.datasets().length >= this.auth.limits().maxDatasets;
  });

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

    if (this.isQuotaExceeded()) {
      this.errorMessage.set('Limite de 2 datasets atteinte pour le plan Gratuit. Passez au Plan Pro pour ajouter des datasets supplémentaires.');
      return;
    }

    if (this.selectedFile.size > this.auth.limits().maxUploadSizeBytes) {
      const maxMb = Math.round(this.auth.limits().maxUploadSizeBytes / (1024 * 1024));
      this.errorMessage.set(`Le fichier dépasse la taille maximale autorisée (${maxMb} Mo pour votre plan). Passez au Plan Pro pour uploader jusqu'à 50 Mo.`);
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

  upgradeToPro(): void {
    this.auth.upgrade('pro').subscribe({
      next: () => {
        this.toast.success('⭐ Compte passé au Plan Pro ! Quotas de datasets illimités.');
        this.refresh();
      },
      error: () => {
        this.toast.error("Impossible d'activer le Plan Pro pour le moment.");
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
