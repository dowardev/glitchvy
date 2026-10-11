// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { createRouter, createWebHistory } from '@ionic/vue-router';
import { useAuthStore } from './stores/auth.js';

import LoginView from './views/LoginView.vue';
import ErrorListView from './views/ErrorListView.vue';
import ErrorDetailView from './views/ErrorDetailView.vue';

const routes = [
  { path: '/', redirect: '/errors' },
  { path: '/login', name: 'login', component: LoginView },
  { path: '/errors', name: 'errors', component: ErrorListView },
  { path: '/errors/:id(\\d+)', name: 'error-detail', component: ErrorDetailView, props: true },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach((to) => {
  const auth = useAuthStore();
  if (to.name !== 'login' && !auth.isAuthenticated) {
    return { name: 'login' };
  }
  return true;
});
