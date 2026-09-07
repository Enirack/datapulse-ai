import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from './api.service';

export interface HealthStatus {
  status: string;
  service: string;
  database?: string;
}

@Injectable({ providedIn: 'root' })
export class PlatformStatusService {
  private readonly api = inject(ApiService);

  checkBackendHealth(): Observable<HealthStatus> {
    return this.api.get<HealthStatus>('/health');
  }
}
