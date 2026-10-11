<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>glitchvy · errors</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="onSignOut" data-testid="signout">Sign out</ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <ErrorFilters
        v-model:level="filterLevel"
        v-model:status="filterStatus"
        v-model:search="filterSearch"
        @apply="onApply"
        @reset="onResetFilters"
      />
      <ion-loading :is-open="store.loading" message="Loading" />
      <p v-if="store.error" data-testid="load-error">{{ store.error }}</p>
      <ErrorListItem
        v-for="report in store.list"
        :key="report.id"
        :report="report"
        @open="onOpen"
      />
      <div v-if="!store.loading && store.list.length === 0" data-testid="empty">
        <p>No errors match the current filters.</p>
      </div>
      <div class="pagination">
        <ion-button
          fill="outline"
          :disabled="!store.prevCursor"
          @click="onPrev"
          data-testid="prev"
        >
          Prev
        </ion-button>
        <ion-button
          fill="outline"
          :disabled="!store.nextCursor"
          @click="onNext"
          data-testid="next"
        >
          Next
        </ion-button>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonButton,
  IonButtons,
  IonLoading,
} from '@ionic/vue';
import { useAuthStore } from '../stores/auth.js';
import { useErrorsStore } from '../stores/errors.js';
import ErrorFilters from '../components/ErrorFilters.vue';
import ErrorListItem from '../components/ErrorListItem.vue';
import type { Level, Status } from '../types.js';

const auth = useAuthStore();
const store = useErrorsStore();
const router = useRouter();

const filterLevel = ref<Level | ''>('');
const filterStatus = ref<Status | ''>('');
const filterSearch = ref<string>('');

let nextStack: string[] = [];
let prevStack: string[] = [];

function pushCursor(c: string | null): void {
  nextStack.push(c ?? '');
}

async function onApply(): Promise<void> {
  store.setFilters({
    level: (filterLevel.value || undefined) as Level | undefined,
    status: (filterStatus.value || undefined) as Status | undefined,
    search: filterSearch.value || undefined,
  });
  prevStack = [];
  nextStack = [];
  pushCursor(null);
  await store.fetchPage();
}

async function onResetFilters(): Promise<void> {
  filterLevel.value = '';
  filterStatus.value = '';
  filterSearch.value = '';
  await onApply();
}

async function onNext(): Promise<void> {
  prevStack.push(store.nextCursor ?? '');
  pushCursor(store.nextCursor);
  await store.fetchPage(store.nextCursor ?? undefined);
}

async function onPrev(): Promise<void> {
  nextStack.pop();
  prevStack.pop();
  const target = prevStack[prevStack.length - 1] ?? '';
  await store.fetchPage(target || undefined);
}

async function onOpen(id: number): Promise<void> {
  await router.push({ name: 'error-detail', params: { id } });
}

async function onSignOut(): Promise<void> {
  auth.clear();
  await router.push({ name: 'login' });
}

onMounted(async () => {
  await store.fetchPage();
});
</script>

<style scoped>
.pagination {
  display: flex;
  gap: 8px;
  margin-top: 16px;
  justify-content: center;
}
</style>
