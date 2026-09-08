import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap } from '@angular/router';

import { environment } from '../../../environments/environment';
import { httpErrorInterceptor } from '../../core/http-error.interceptor';
import { DatasetDetailComponent } from './dataset-detail.component';

const DATASET_ID = 'dataset-1';
const VERSION_ID = 'version-1';

function baseUrl(path: string): string {
  return `${environment.apiBaseUrl}${path}`;
}

const sampleDataset = {
  id: DATASET_ID,
  name: 'people.csv',
  description: null,
  status: 'ready',
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  versions: [
    {
      id: VERSION_ID,
      versionNumber: 1,
      kind: 'raw',
      originalFilename: 'people.csv',
      mimeType: 'text/csv',
      fileSize: 42,
      checksum: 'abc123',
      hasFormulaInjectionRisk: false,
      createdAt: '2026-01-01T00:00:00Z',
    },
  ],
};

const sampleProfile = {
  schemaVersion: '1.0',
  dataset: { rows: 3, columns: 1, totalCells: 3, totalNullCells: 0, nullPercentage: 0, duplicateRowCount: 0, duplicateRowPercentage: 0 },
  columns: [],
};

const sampleAnalysis = {
  id: 'analysis-1',
  datasetVersionId: VERSION_ID,
  type: 'profiling',
  status: 'completed',
  resultSchemaVersion: '1.0',
  result: sampleProfile,
  errorMessage: null,
  createdAt: '2026-01-01T00:00:00Z',
  startedAt: '2026-01-01T00:00:00Z',
  completedAt: '2026-01-01T00:00:00Z',
};

const sampleRecommendations = {
  datasetVersionId: VERSION_ID,
  recommendations: [
    { type: 'duplicates', issue: '2 duplicate rows', recommendedActions: ['remove_duplicate_rows'] },
    { type: 'missing_values', column: 'age', issue: '1 missing value', recommendedActions: ['drop_rows', 'fill_median'] },
  ],
};

describe('DatasetDetailComponent', () => {
  let httpMock: HttpTestingController;

  async function createComponent() {
    await TestBed.configureTestingModule({
      imports: [DatasetDetailComponent],
      providers: [
        provideHttpClient(withInterceptors([httpErrorInterceptor])),
        provideHttpClientTesting(),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: DATASET_ID }) } },
        },
      ],
    }).compileComponents();

    httpMock = TestBed.inject(HttpTestingController);

    const fixture = TestBed.createComponent(DatasetDetailComponent);
    fixture.detectChanges();

    httpMock.expectOne(baseUrl(`/datasets/${DATASET_ID}`)).flush(sampleDataset);
    httpMock.expectOne(baseUrl(`/datasets/${DATASET_ID}/versions/${VERSION_ID}/profile`)).flush(sampleAnalysis);
    fixture.detectChanges();

    httpMock.expectOne(baseUrl(`/datasets/${DATASET_ID}/versions/${VERSION_ID}/cleaning-recommendations`)).flush(sampleRecommendations);
    fixture.detectChanges();

    return fixture;
  }

  afterEach(() => {
    httpMock.verify();
  });

  it('displays cleaning recommendations once a completed profile exists', async () => {
    const fixture = await createComponent();
    const compiled = fixture.nativeElement as HTMLElement;

    const issues = compiled.querySelectorAll('.recommendation-issue');
    expect(issues.length).toBe(2);
    expect(compiled.textContent).toContain('2 duplicate rows');
    expect(compiled.textContent).toContain('1 missing value');
  });

  it('lets the user select and deselect a recommended operation', async () => {
    const fixture = await createComponent();
    const component = fixture.componentInstance;
    const compiled = fixture.nativeElement as HTMLElement;

    component.toggleOperation('remove_duplicate_rows');
    fixture.detectChanges();
    expect(compiled.querySelector('.selected-operations')).toBeTruthy();
    expect(compiled.textContent).toContain('Remove duplicates');

    component.toggleOperation('remove_duplicate_rows');
    fixture.detectChanges();
    expect(compiled.querySelector('.selected-operations')).toBeFalsy();
  });

  it('shows a loading state while previewing, then the estimate on success', async () => {
    const fixture = await createComponent();
    const component = fixture.componentInstance;
    const compiled = fixture.nativeElement as HTMLElement;

    component.toggleOperation('remove_duplicate_rows');
    fixture.detectChanges();

    component.preview();
    fixture.detectChanges();
    expect(compiled.querySelector('.cleaning-actions .secondary')?.textContent).toContain('Previewing…');

    httpMock.expectOne(baseUrl(`/datasets/${DATASET_ID}/versions/${VERSION_ID}/clean/preview`)).flush({
      estimatedAffectedRows: 2,
      operations: [],
      before: sampleProfile,
      after: sampleProfile,
    });
    fixture.detectChanges();

    expect(compiled.querySelector('.cleaning-actions .secondary')?.textContent).toContain('Preview');
    expect(compiled.textContent).toContain('Estimated affected rows');
    expect(compiled.textContent).toContain('2');
  });

  it('surfaces a preview error without crashing', async () => {
    const fixture = await createComponent();
    const component = fixture.componentInstance;

    component.toggleOperation('remove_duplicate_rows');
    fixture.detectChanges();
    component.preview();

    httpMock.expectOne(baseUrl(`/datasets/${DATASET_ID}/versions/${VERSION_ID}/clean/preview`)).flush(
      { message: 'Something went wrong.' },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.error')?.textContent).toContain('Something went wrong.');
  });

  it('applies cleaning and shows the before/after report on success', async () => {
    const fixture = await createComponent();
    const component = fixture.componentInstance;
    const compiled = fixture.nativeElement as HTMLElement;

    component.toggleOperation('remove_duplicate_rows');
    fixture.detectChanges();
    component.applyCleaning();
    fixture.detectChanges();
    expect(compiled.querySelector('.cleaning-actions button:not(.secondary)')?.textContent).toContain('Applying…');

    httpMock.expectOne(baseUrl(`/datasets/${DATASET_ID}/versions/${VERSION_ID}/clean`)).flush({
      analysisId: 'clean-1',
      status: 'completed',
      sourceVersionId: VERSION_ID,
      resultVersionId: 'version-2',
      changed: true,
      report: {
        operations: [{ operation: 'remove_duplicate_rows', affectedRows: 2, beforeRows: 5, afterRows: 3 }],
        before: { ...sampleProfile, dataset: { ...sampleProfile.dataset, rows: 5 } },
        after: { ...sampleProfile, dataset: { ...sampleProfile.dataset, rows: 3 } },
      },
      errorMessage: null,
      operations: [{ type: 'remove_duplicate_rows' }],
      createdAt: '2026-01-01T00:00:00Z',
      startedAt: '2026-01-01T00:00:00Z',
      completedAt: '2026-01-01T00:00:00Z',
    });
    fixture.detectChanges();

    expect(compiled.textContent).toContain('Cleaning completed');
    expect(compiled.textContent).toContain('5 rows');
    expect(compiled.textContent).toContain('3 rows');
    expect(compiled.textContent).toContain('duplicate rows removed');
  });

  it('surfaces a failed cleaning result', async () => {
    const fixture = await createComponent();
    const component = fixture.componentInstance;

    component.toggleOperation('fill_median', 'age');
    fixture.detectChanges();
    component.applyCleaning();

    httpMock.expectOne(baseUrl(`/datasets/${DATASET_ID}/versions/${VERSION_ID}/clean`)).flush({
      analysisId: 'clean-2',
      status: 'failed',
      sourceVersionId: VERSION_ID,
      resultVersionId: null,
      changed: false,
      report: null,
      errorMessage: 'Column "age" does not exist in this dataset.',
      operations: [{ type: 'fill_median', column: 'age' }],
      createdAt: '2026-01-01T00:00:00Z',
      startedAt: '2026-01-01T00:00:00Z',
      completedAt: '2026-01-01T00:00:00Z',
    });
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Cleaning failed');
    expect(compiled.textContent).toContain('Column "age" does not exist');
  });
});
