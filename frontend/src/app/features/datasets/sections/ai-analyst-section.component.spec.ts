import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentRef } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';

import { environment } from '../../../../environments/environment';
import { AuthService } from '../../../core/auth.service';
import { httpErrorInterceptor } from '../../../core/http-error.interceptor';
import { AiInsightsResult, Analysis } from '../../../core/models';
import { AiAnalystSectionComponent } from './ai-analyst-section.component';

const DATASET_ID = 'ds-42';
const VERSION_ID = 'ver-99';

function baseUrl(path: string): string {
  return `${environment.apiBaseUrl}${path}`;
}

const sampleInsightsResult: AiInsightsResult = {
  schemaVersion: '1.0',
  healthScore: 84,
  executiveSummary: 'This dataset has 1,200 rows with 98% completeness and good data hygiene.',
  highlights: {
    totalRows: 1200,
    totalColumns: 6,
    completenessPercentage: 98,
    dominantCategory: 'country: FR (64%)',
    topCorrelation: 'age vs spend (r = 0.58)',
  },
  insights: [
    {
      id: 'i-1',
      kind: 'computed_fact',
      title: 'Dataset Completeness',
      description: 'Overall completeness is 98% across 6 columns.',
      category: 'quality',
      metrics: { completenessPercentage: 98 },
    },
    {
      id: 'i-2',
      kind: 'statistical_interpretation',
      title: 'Linear Relationship Detected',
      description: 'Moderate positive correlation between age and spend.',
      category: 'correlation',
      metrics: { r: 0.58 },
    },
    {
      id: 'i-3',
      kind: 'recommendation',
      title: 'Impute Missing Age Values',
      description: 'Use median imputation to prevent distortion from skewed extremes.',
      category: 'quality',
      metrics: { column: 'age', strategy: 'median imputation' },
    },
  ],
  suggestedQuestions: [
    'What are the strongest relationships between variables in this dataset?',
    'What is the overall data quality and missing value distribution?',
  ],
};

const sampleAnalysis: Analysis<AiInsightsResult> = {
  id: 'ana-ai-1',
  datasetVersionId: VERSION_ID,
  type: 'ai_insights',
  status: 'completed',
  resultSchemaVersion: '1.0',
  result: sampleInsightsResult,
  errorMessage: null,
  createdAt: '2026-01-01T00:00:00Z',
  startedAt: '2026-01-01T00:00:00Z',
  completedAt: '2026-01-01T00:00:00Z',
};

describe('AiAnalystSectionComponent', () => {
  let fixture: ComponentFixture<AiAnalystSectionComponent>;
  let component: AiAnalystSectionComponent;
  let componentRef: ComponentRef<AiAnalystSectionComponent>;
  let httpMock: HttpTestingController;
  let authServiceSpy: { isPro: ReturnType<typeof vi.fn>; plan: ReturnType<typeof vi.fn>; upgrade: ReturnType<typeof vi.fn> };

  beforeEach(async () => {
    authServiceSpy = {
      isPro: vi.fn().mockReturnValue(true),
      plan: vi.fn().mockReturnValue('pro'),
      upgrade: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [AiAnalystSectionComponent],
      providers: [
        provideHttpClient(withInterceptors([httpErrorInterceptor])),
        provideHttpClientTesting(),
        { provide: AuthService, useValue: authServiceSpy },
      ],
    }).compileComponents();

    httpMock = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(AiAnalystSectionComponent);
    component = fixture.componentInstance;
    componentRef = fixture.componentRef;
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('renders paywall banner when user is on free plan', () => {
    authServiceSpy.isPro.mockReturnValue(false);
    authServiceSpy.plan.mockReturnValue('free');
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('PLAN PRO REQUIS');
    expect(compiled.textContent).toContain("Débloquez l'Analyste IA Exécutif");
  });

  it('renders prerequisite notice when canGenerate is false and no insights exist', () => {
    componentRef.setInput('canGenerate', false);
    componentRef.setInput('insightsAnalysis', null);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Profiling and Analytics must be completed first');
  });

  it('renders ready notice and emits generateRequested when button is clicked', () => {
    componentRef.setInput('canGenerate', true);
    componentRef.setInput('insightsAnalysis', null);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Profiling and exploratory analytics are ready');

    let emitted = false;
    component.generateRequested.subscribe(() => {
      emitted = true;
    });

    const btn = compiled.querySelector<HTMLButtonElement>('.btn-primary');
    expect(btn).not.toBeNull();
    btn?.click();
    expect(emitted).toBe(true);
  });

  it('renders executive summary, health score, and structured insights when completed', () => {
    componentRef.setInput('datasetId', DATASET_ID);
    componentRef.setInput('versionId', VERSION_ID);
    componentRef.setInput('insightsAnalysis', sampleAnalysis);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.gauge-value')?.textContent).toContain('84');
    expect(compiled.querySelector('.executive-narrative')?.textContent).toContain('This dataset has 1,200 rows');
    expect(compiled.querySelectorAll('.insight-card').length).toBe(3);
  });

  it('filters insights when kind tab is selected', () => {
    componentRef.setInput('datasetId', DATASET_ID);
    componentRef.setInput('versionId', VERSION_ID);
    componentRef.setInput('insightsAnalysis', sampleAnalysis);
    fixture.detectChanges();

    component.setFilter('recommendation');
    fixture.detectChanges();

    const cards = fixture.nativeElement.querySelectorAll('.insight-card');
    expect(cards.length).toBe(1);
    expect(cards[0].textContent).toContain('Impute Missing Age Values');
  });

  it('submits question and renders grounded answer with referenced metrics', () => {
    componentRef.setInput('datasetId', DATASET_ID);
    componentRef.setInput('versionId', VERSION_ID);
    componentRef.setInput('insightsAnalysis', sampleAnalysis);
    fixture.detectChanges();

    component.submitQuestion('What is the completeness?');
    fixture.detectChanges();

    const req = httpMock.expectOne(baseUrl(`/datasets/${DATASET_ID}/versions/${VERSION_ID}/insights/ask`));
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ question: 'What is the completeness?' });

    req.flush({
      schemaVersion: '1.0',
      question: 'What is the completeness?',
      answer: 'Completeness is 98.00% with no duplicate rows detected.',
      grounded: true,
      referencedMetrics: { completenessPercentage: 98 },
    });
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.qa-answer-text')?.textContent).toContain('Completeness is 98.00%');
    expect(compiled.querySelector('.badge-grounded')?.textContent).toContain('Grounded');
  });
});
