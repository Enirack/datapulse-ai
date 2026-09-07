import { Component, OnInit, inject, signal } from '@angular/core';

import { PlatformStatusService } from '../../core/platform-status.service';

type BackendStatus = 'checking' | 'online' | 'offline';

interface PlaceholderCard {
  title: string;
  description: string;
}

@Component({
  selector: 'app-dashboard',
  standalone: true,
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent implements OnInit {
  private readonly platformStatus = inject(PlatformStatusService);

  protected readonly backendStatus = signal<BackendStatus>('checking');

  protected readonly placeholderCards: PlaceholderCard[] = [
    { title: 'Datasets', description: 'Uploaded datasets will appear here once ingestion is implemented.' },
    { title: 'Analyses', description: 'Statistical analysis runs will be tracked here.' },
    { title: 'Insights', description: 'AI-interpreted, fact-grounded insights will surface here.' },
    { title: 'Reports', description: 'Generated reports will be listed here.' },
  ];

  ngOnInit(): void {
    this.platformStatus.checkBackendHealth().subscribe({
      next: () => this.backendStatus.set('online'),
      error: () => this.backendStatus.set('offline'),
    });
  }
}
