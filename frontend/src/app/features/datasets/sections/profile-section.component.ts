import { Component, input, output } from '@angular/core';
import { Analysis } from '../../../core/models';

@Component({
  selector: 'app-profile-section',
  standalone: true,
  templateUrl: './profile-section.component.html',
  styleUrl: './profile-section.component.scss',
})
export class ProfileSectionComponent {
  readonly analysis = input<Analysis | null>(null);
  readonly analyzing = input<boolean>(false);
  readonly analysisError = input<string | null>(null);

  readonly analyzeRequested = output<boolean>();

  onAnalyze(force = false): void {
    this.analyzeRequested.emit(force);
  }
}
