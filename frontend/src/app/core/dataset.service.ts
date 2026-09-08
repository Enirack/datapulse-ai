import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from './api.service';
import { Analysis, Dataset, DatasetListResponse } from './models';

@Injectable({ providedIn: 'root' })
export class DatasetService {
  private readonly api = inject(ApiService);

  list(): Observable<DatasetListResponse> {
    return this.api.get<DatasetListResponse>('/datasets');
  }

  get(datasetId: string): Observable<Dataset> {
    return this.api.get<Dataset>(`/datasets/${datasetId}`);
  }

  upload(file: File, name?: string): Observable<Dataset> {
    const formData = new FormData();
    formData.append('file', file);
    if (name) {
      formData.append('name', name);
    }

    return this.api.postForm<Dataset>('/datasets', formData);
  }

  delete(datasetId: string): Observable<void> {
    return this.api.delete<void>(`/datasets/${datasetId}`);
  }

  triggerProfile(datasetId: string, versionId: string, force = false): Observable<Analysis> {
    return this.api.post<Analysis>(`/datasets/${datasetId}/versions/${versionId}/profile${force ? '?force=true' : ''}`, {});
  }

  getProfile(datasetId: string, versionId: string): Observable<Analysis> {
    return this.api.get<Analysis>(`/datasets/${datasetId}/versions/${versionId}/profile`);
  }
}
