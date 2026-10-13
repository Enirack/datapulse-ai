import { TestBed } from '@angular/core/testing';

import { ThemeService } from './theme.service';

describe('ThemeService', () => {
  let service: ThemeService;
  let mockStore: Record<string, string> = {};

  const localStorageMock: Storage = {
    length: 0,
    clear: () => {
      mockStore = {};
    },
    getItem: (key: string) => mockStore[key] ?? null,
    key: (index: number) => Object.keys(mockStore)[index] ?? null,
    removeItem: (key: string) => {
      delete mockStore[key];
    },
    setItem: (key: string, value: string) => {
      mockStore[key] = String(value);
    },
  };

  beforeEach(() => {
    mockStore = {};
    Object.defineProperty(window, 'localStorage', {
      value: localStorageMock,
      writable: true,
      configurable: true,
    });
    if (typeof globalThis !== 'undefined') {
      Object.defineProperty(globalThis, 'localStorage', {
        value: localStorageMock,
        writable: true,
        configurable: true,
      });
    }

    if (typeof document !== 'undefined') {
      document.documentElement.classList.remove('dark');
    }

    TestBed.configureTestingModule({});
    service = TestBed.inject(ThemeService);
  });

  afterEach(() => {
    mockStore = {};
    if (typeof document !== 'undefined') {
      document.documentElement.classList.remove('dark');
    }
  });

  it('initializes with a valid theme mode', () => {
    expect(['light', 'dark']).toContain(service.theme());
  });

  it('toggles theme between light and dark', () => {
    service.setTheme('light');
    expect(service.isDark()).toBe(false);
    expect(document.documentElement.classList.contains('dark')).toBe(false);

    service.toggleTheme();
    expect(service.isDark()).toBe(true);
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(mockStore['datapulse-theme']).toBe('dark');

    service.toggleTheme();
    expect(service.isDark()).toBe(false);
    expect(document.documentElement.classList.contains('dark')).toBe(false);
    expect(mockStore['datapulse-theme']).toBe('light');
  });
});
