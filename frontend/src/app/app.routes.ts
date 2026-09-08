import { Routes } from '@angular/router';

import { authGuard } from './core/auth.guard';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent),
  },
  {
    path: 'login',
    loadComponent: () => import('./features/login/login.component').then((m) => m.LoginComponent),
  },
  {
    path: 'datasets',
    canActivate: [authGuard],
    loadComponent: () => import('./features/datasets/dataset-list.component').then((m) => m.DatasetListComponent),
  },
  {
    path: 'datasets/:id',
    canActivate: [authGuard],
    loadComponent: () => import('./features/datasets/dataset-detail.component').then((m) => m.DatasetDetailComponent),
  },
  {
    path: '**',
    loadComponent: () => import('./features/not-found/not-found.component').then((m) => m.NotFoundComponent),
  },
];
