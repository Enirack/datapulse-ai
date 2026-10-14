import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';

import { ApiError } from '../../core/api-error';
import { AuthService } from '../../core/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss',
})
export class LoginComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected email = '';
  protected password = '';
  protected accessKey = '';
  protected mode = signal<'login' | 'register'>('login');
  protected submitting = signal(false);
  protected errorMessage = signal<string | null>(null);

  toggleMode(): void {
    this.mode.set(this.mode() === 'login' ? 'register' : 'login');
    this.errorMessage.set(null);
  }

  submit(): void {
    if (this.submitting()) {
      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);

    if (this.mode() === 'register') {
      if (!this.accessKey.trim()) {
        this.submitting.set(false);
        this.errorMessage.set("Une clé d'accès est requise pour créer un compte. Contactez Enirack sur GitHub ou par email pour demander un accès.");
        return;
      }

      this.auth.register(this.email, this.password, this.accessKey.trim()).subscribe({
        next: () => this.performLogin(),
        error: (error: ApiError) => this.handleError(error),
      });
    } else {
      this.performLogin();
    }
  }

  private performLogin(): void {
    this.auth.login(this.email, this.password).subscribe({
      next: () => {
        this.submitting.set(false);
        void this.router.navigate(['/datasets']);
      },
      error: (error: ApiError) => this.handleError(error),
    });
  }

  private handleError(error: ApiError): void {
    this.submitting.set(false);
    this.errorMessage.set(error.message || 'Something went wrong.');
  }
}
