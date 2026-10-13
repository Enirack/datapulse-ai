import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { TestBed } from '@angular/core/testing';

import { DashboardComponent } from './dashboard.component';
import { environment } from '../../../environments/environment';

describe('DashboardComponent', () => {
  let httpMock: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DashboardComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('shows online status when the backend health check succeeds', () => {
    const fixture = TestBed.createComponent(DashboardComponent);
    fixture.detectChanges();

    httpMock.expectOne(`${environment.apiBaseUrl}/health`).flush({ status: 'ok', service: 'datapulse-backend' });
    httpMock.expectOne(`${environment.apiBaseUrl}/datasets`).flush({
      items: [
        {
          id: 'ds-1',
          name: 'sales.csv',
          description: null,
          status: 'ready',
          createdAt: '2026-01-01T00:00:00Z',
          updatedAt: '2026-01-01T00:00:00Z',
          versions: [
            {
              id: 'v-1',
              versionNumber: 1,
              kind: 'raw',
              originalFilename: 'sales.csv',
              mimeType: 'text/csv',
              fileSize: 1024,
              checksum: 'abc',
              hasFormulaInjectionRisk: false,
              createdAt: '2026-01-01T00:00:00Z',
            },
          ],
        },
      ],
      total: 1,
      page: 1,
      perPage: 20,
    });
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.status-pill')?.textContent).toContain('online');
    expect(compiled.textContent).toContain('sales.csv');
  });

  it('shows offline status when the backend health check fails', () => {
    const fixture = TestBed.createComponent(DashboardComponent);
    fixture.detectChanges();

    httpMock.expectOne(`${environment.apiBaseUrl}/health`).flush('error', { status: 500, statusText: 'Server Error' });
    httpMock.expectOne(`${environment.apiBaseUrl}/datasets`).flush({ items: [], total: 0, page: 1, perPage: 20 });
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.status-pill')?.textContent).toContain('offline');
  });

  it('renders pipeline architecture step cards', () => {
    const fixture = TestBed.createComponent(DashboardComponent);
    fixture.detectChanges();

    httpMock.expectOne(`${environment.apiBaseUrl}/health`).flush({ status: 'ok', service: 'datapulse-backend' });
    httpMock.expectOne(`${environment.apiBaseUrl}/datasets`).flush({ items: [], total: 0, page: 1, perPage: 20 });
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelectorAll('.step-card').length).toBe(5);
  });
});
