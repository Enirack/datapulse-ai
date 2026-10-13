import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from './api.service';
import {
  Analysis,
  AnalyticsResult,
  AiInsightsResult,
  AiQuestionResponse,
  CleaningOperation,
  CleaningPreviewResult,
  CleaningRecommendationsResponse,
  CleaningResult,
  Dataset,
  DatasetListResponse,
} from './models';

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

  getCleaningRecommendations(datasetId: string, versionId: string): Observable<CleaningRecommendationsResponse> {
    return this.api.get<CleaningRecommendationsResponse>(`/datasets/${datasetId}/versions/${versionId}/cleaning-recommendations`);
  }

  previewClean(datasetId: string, versionId: string, operations: CleaningOperation[]): Observable<CleaningPreviewResult> {
    return this.api.post<CleaningPreviewResult>(`/datasets/${datasetId}/versions/${versionId}/clean/preview`, { operations });
  }

  applyClean(datasetId: string, versionId: string, operations: CleaningOperation[], force = false): Observable<CleaningResult> {
    return this.api.post<CleaningResult>(`/datasets/${datasetId}/versions/${versionId}/clean${force ? '?force=true' : ''}`, { operations });
  }

  triggerAnalytics(datasetId: string, versionId: string, force = false): Observable<Analysis<AnalyticsResult>> {
    return this.api.post<Analysis<AnalyticsResult>>(`/datasets/${datasetId}/versions/${versionId}/analytics${force ? '?force=true' : ''}`, {});
  }

  getAnalytics(datasetId: string, versionId: string): Observable<Analysis<AnalyticsResult>> {
    return this.api.get<Analysis<AnalyticsResult>>(`/datasets/${datasetId}/versions/${versionId}/analytics`);
  }

  triggerAiInsights(datasetId: string, versionId: string, force = false): Observable<Analysis<AiInsightsResult>> {
    return this.api.post<Analysis<AiInsightsResult>>(`/datasets/${datasetId}/versions/${versionId}/insights${force ? '?force=true' : ''}`, {});
  }

  getAiInsights(datasetId: string, versionId: string): Observable<Analysis<AiInsightsResult>> {
    return this.api.get<Analysis<AiInsightsResult>>(`/datasets/${datasetId}/versions/${versionId}/insights`);
  }

  askAiAnalyst(datasetId: string, versionId: string, question: string): Observable<AiQuestionResponse> {
    return this.api.post<AiQuestionResponse>(`/datasets/${datasetId}/versions/${versionId}/insights/ask`, { question });
  }
}
