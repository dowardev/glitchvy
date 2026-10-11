<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-back-button slot="start" default-href="/errors" />
        <ion-title>Error #{{ id }}</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding" v-if="store.detail">
      <h2>{{ store.detail.error_name }}</h2>
      <p>{{ store.detail.error_message }}</p>

      <ion-list>
        <ion-item>
          <ion-label>Status</ion-label>
          <ion-select
            :value="store.detail.status"
            @ionChange="onChangeStatus($event.detail.value)"
            data-testid="status-select"
          >
            <ion-select-option value="open">open</ion-select-option>
            <ion-select-option value="triaged">triaged</ion-select-option>
            <ion-select-option value="resolved">resolved</ion-select-option>
            <ion-select-option value="closed">closed</ion-select-option>
          </ion-select>
        </ion-item>
        <ion-item>
          <ion-label>
            <h3>Environment</h3>
            <p>{{ store.detail.environment }} · {{ store.detail.app_version }}</p>
          </ion-label>
        </ion-item>
        <ion-item v-if="store.detail.route">
          <ion-label>
            <h3>Route</h3>
            <p>{{ store.detail.route }}</p>
          </ion-label>
        </ion-item>
      </ion-list>

      <h3 v-if="store.detail.error_stack">Stack</h3>
      <pre v-if="store.detail.error_stack" data-testid="stack">{{ store.detail.error_stack }}</pre>

      <h3 v-if="store.detail.breadcrumbs?.length">Breadcrumbs</h3>
      <ul v-if="store.detail.breadcrumbs?.length">
        <li v-for="(b, i) in store.detail.breadcrumbs" :key="i">
          <code>{{ b.timestamp }}</code> · {{ b.message }}
        </li>
      </ul>

      <h3 v-if="store.detail.screenshots?.length">Screenshots</h3>
      <ScreenshotViewer
        v-if="store.detail.screenshots?.length"
        v-for="s in store.detail.screenshots"
        :key="s.id"
        :error-id="store.detail.id"
      />
    </ion-content>
    <ion-content v-else-if="store.detailLoading" class="ion-padding">
      <p>Loading error...</p>
    </ion-content>
    <ion-content v-else-if="store.detailError" class="ion-padding">
      <p data-testid="detail-error">{{ store.detailError }}</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonBackButton,
  IonList,
  IonItem,
  IonLabel,
  IonSelect,
  IonSelectOption,
} from '@ionic/vue';
import { useErrorsStore } from '../stores/errors.js';
import ScreenshotViewer from '../components/ScreenshotViewer.vue';
import type { Status } from '../types.js';

const route = useRoute();
const store = useErrorsStore();

const id = Number(route.params.id);

async function onChangeStatus(status: Status | string): Promise<void> {
  await store.setStatus(id, status as Status);
}

onMounted(async () => {
  await store.loadDetail(id);
});

watch(
  () => route.params.id,
  async (newId) => {
    const n = Number(newId);
    if (!Number.isFinite(n)) return;
    await store.loadDetail(n);
  },
);
</script>
