import { Component, inject, signal } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { AuthService } from './core/auth.service';
import { ThemeService } from './core/theme.service';
import { ToastService } from './core/toast.service';
import { ToastContainerComponent } from './shared/toast-container.component';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, ToastContainerComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  protected readonly auth = inject(AuthService);
  protected readonly themeService = inject(ThemeService);
  private readonly toast = inject(ToastService);
  protected readonly title = signal('DataPulse AI');

  toggleTheme(): void {
    this.themeService.toggleTheme();
  }

  upgradeToPro(): void {
    this.auth.upgrade('pro').subscribe({
      next: () => {
        this.toast.success('⭐ Félicitations ! Votre compte est passé au Plan Pro (quotas et IA débloqués).');
      },
      error: () => {
        this.toast.error("Impossible d'activer le Plan Pro pour le moment.");
      },
    });
  }
}
