import { Injectable, computed, signal } from '@angular/core';

export type ThemeMode = 'light' | 'dark';

@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly storageKey = 'datapulse-theme';
  private readonly currentTheme = signal<ThemeMode>(this.getInitialTheme());

  readonly theme = this.currentTheme.asReadonly();
  readonly isDark = computed(() => this.currentTheme() === 'dark');

  constructor() {
    this.applyTheme(this.currentTheme());
  }

  toggleTheme(): void {
    const nextTheme: ThemeMode = this.currentTheme() === 'light' ? 'dark' : 'light';
    this.setTheme(nextTheme);
  }

  setTheme(theme: ThemeMode): void {
    this.currentTheme.set(theme);
    const storage = this.getStorage();
    if (storage) {
      try {
        storage.setItem(this.storageKey, theme);
      } catch {
        // In private browsing mode or storage disabled
      }
    }
    this.applyTheme(theme);
  }

  private getInitialTheme(): ThemeMode {
    const storage = this.getStorage();
    if (storage) {
      try {
        const stored = storage.getItem(this.storageKey);
        if (stored === 'light' || stored === 'dark') {
          return stored;
        }
      } catch {
        // Fallback
      }
    }

    if (typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      return 'dark';
    }

    return 'light';
  }

  private applyTheme(theme: ThemeMode): void {
    if (typeof document !== 'undefined') {
      const root = document.documentElement;
      if (theme === 'dark') {
        root.classList.add('dark');
      } else {
        root.classList.remove('dark');
      }
    }
  }

  private getStorage(): Storage | null {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        return window.localStorage;
      }
      if (typeof localStorage !== 'undefined') {
        return localStorage;
      }
    } catch {
      return null;
    }
    return null;
  }
}
