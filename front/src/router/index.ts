// SPDX-License-Identifier: MIT
// Copyright (c) 2024 Glitchvy

import { createRouter, createWebHistory } from '@ionic/vue-router'
import { RouteLocationNormalized, RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes: Array<RouteRecordRaw> = [
  {
    path: '/',
    redirect: '/projects'
  },
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true }
  },
  {
    path: '/projects',
    name: 'Projects',
    component: () => import('@/views/ProjectListView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/projects/:id(\\d+)',
    name: 'ProjectDetail',
    component: () => import('@/views/ProjectDetailView.vue'),
    // The router outlet hands the route params to the view as props.
    // Reading them with useRoute() does not work here: the view is
    // rendered inside the outlet, where vue-router's injection is not
    // in scope.
    props: true,
    meta: { requiresAuth: true }
  },
  {
    path: '/errors',
    name: 'Errors',
    component: () => import('@/views/ErrorListView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/errors/:id(\\d+)',
    name: 'ErrorDetail',
    component: () => import('@/views/ErrorDetailView.vue'),
    // Same as ProjectDetail: the params arrive as props.
    props: true,
    meta: { requiresAuth: true }
  }
]

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes
})

router.beforeEach(async (to: RouteLocationNormalized, from: RouteLocationNormalized, next: (to?: string | false | void) => void) => {
  const authStore = useAuthStore()

  // The session lives in an httpOnly cookie, so the SPA can only learn
  // whether it is logged in by asking the API. Wait for that answer
  // before deciding where to send the user.
  await authStore.restore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login')
    return
  }

  if (to.meta.public && authStore.isAuthenticated) {
    next('/projects')
    return
  }

  next()
})