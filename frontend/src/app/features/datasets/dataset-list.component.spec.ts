import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';

import { ApiError } from '../../core/api-error';
import { AuthService } from '../../core/auth.service';
import { DatasetService } from '../../core/dataset.service';
import { Dataset } from '../../core/models';
import { DatasetListComponent } from './dataset-list.component';

const sampleDataset: Dataset = {
  id: 'ds-1',
  name: 'Customers Data',
  description: 'Clean customer demographic info',
  status: 'ready',
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  versions: [
    {
      id: 'ver-1',
      versionNumber: 1,
      kind: 'raw',
      originalFilename: 'customers.csv',
      mimeType: 'text/csv',
      fileSize: 1024,
      checksum: 'hash1',
      hasFormulaInjectionRisk: false,
      createdAt: '2026-01-01T00:00:00Z',
    },
  ],
};

const sampleDataset2: Dataset = {
  id: 'ds-2',
  name: 'Orders Log',
  description: 'Transactions list',
  status: 'ready',
  createdAt: '2026-01-02T00:00:00Z',
  updatedAt: '2026-01-02T00:00:00Z',
  versions: [
    {
      id: 'ver-2',
      versionNumber: 1,
      kind: 'raw',
      originalFilename: 'orders.csv',
      mimeType: 'text/csv',
      fileSize: 2048,
      checksum: 'hash2',
      hasFormulaInjectionRisk: false,
      createdAt: '2026-01-02T00:00:00Z',
    },
  ],
};

describe('DatasetListComponent', () => {
  let fixture: ComponentFixture<DatasetListComponent>;
  let component: DatasetListComponent;
  let datasetServiceSpy: { list: ReturnType<typeof vi.fn>; upload: ReturnType<typeof vi.fn> };
  let authServiceSpy: {
    logout: ReturnType<typeof vi.fn>;
    isAuthenticated: ReturnType<typeof vi.fn>;
    isPro: ReturnType<typeof vi.fn>;
    plan: ReturnType<typeof vi.fn>;
    limits: ReturnType<typeof vi.fn>;
    upgrade: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    datasetServiceSpy = {
      list: vi.fn().mockReturnValue(of({ items: [sampleDataset, sampleDataset2], total: 2 })),
      upload: vi.fn().mockReturnValue(of(sampleDataset)),
    };
    authServiceSpy = {
      logout: vi.fn(),
      isAuthenticated: vi.fn().mockReturnValue(true),
      isPro: vi.fn().mockReturnValue(true),
      plan: vi.fn().mockReturnValue('pro'),
      limits: vi.fn().mockReturnValue({
        maxDatasets: 100,
        maxUploadSizeBytes: 52428800,
        canUseAiAnalyst: true,
        canApplyCleaning: true,
      }),
      upgrade: vi.fn().mockReturnValue(of({ plan: 'pro' })),
    };

    await TestBed.configureTestingModule({
      imports: [DatasetListComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: DatasetService, useValue: datasetServiceSpy },
        { provide: AuthService, useValue: authServiceSpy },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(DatasetListComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('loads and displays datasets on init', () => {
    expect(datasetServiceSpy.list).toHaveBeenCalled();
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Customers Data');
    expect(compiled.textContent).toContain('Orders Log');
  });

  it('filters datasets by search query', () => {
    // Set search query to 'orders'
    (component as unknown as { searchQuery: { set: (v: string) => void } }).searchQuery.set('orders');
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).not.toContain('Customers Data');
    expect(compiled.textContent).toContain('Orders Log');
  });

  it('shows no results message when query does not match any dataset', () => {
    (component as unknown as { searchQuery: { set: (v: string) => void } }).searchQuery.set('nonexistent query xyz');
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('No datasets found matching "nonexistent query xyz"');
  });

  it('uploads a selected file and refreshes dataset list', () => {
    const file = new File(['content'], 'test.csv', { type: 'text/csv' });
    (component as unknown as { selectedFile: File | null }).selectedFile = file;

    component.upload();
    expect(datasetServiceSpy.upload).toHaveBeenCalledWith(file);
    // list was called on init, and called again after successful upload
    expect(datasetServiceSpy.list).toHaveBeenCalledTimes(2);
  });

  it('handles upload errors gracefully', () => {
    const file = new File(['content'], 'test.csv', { type: 'text/csv' });
    (component as unknown as { selectedFile: File | null }).selectedFile = file;
    datasetServiceSpy.upload.mockReturnValue(throwError(() => ({ status: 400, message: 'Invalid CSV header' } satisfies ApiError)));

    component.upload();
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Invalid CSV header');
  });
});
