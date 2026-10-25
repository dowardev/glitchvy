<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-item button @click="navigateToDetail">
    <ion-label>
      <h2>{{ error.message }}</h2>
      <p>{{ error.type }}</p>
      <p>
        <ion-badge :color="levelColor" class="ion-margin-end">{{ error.level }}</ion-badge>
        <ion-badge :color="statusColor">{{ error.status }}</ion-badge>
        <ion-text color="medium" class="ion-margin-start">
          {{ error.count }} ocurrencias · visto {{ formatDate(error.last_seen_at) }}
        </ion-text>
      </p>
    </ion-label>
  </ion-item>
</template>

<script setup lang="ts">
import { IonBadge, IonItem, IonLabel, IonText, useIonRouter } from '@ionic/vue';
import { computed } from 'vue';
import type { ErrorReportSummary } from '@/types';

interface Props {
  error: ErrorReportSummary;
}

const props = defineProps<Props>();
const ionRouter = useIonRouter();

const levelColor = computed(() => {
  switch (props.error.level) {
    case 'critical':
    case 'error':
      return 'danger';
    case 'warning':
      return 'warning';
    case 'info':
      return 'primary';
    default:
      return 'medium';
  }
});

const statusColor = computed(() => {
  switch (props.error.status) {
    case 'open':
      return 'danger';
    case 'in_progress':
      return 'warning';
    case 'resolved':
      return 'success';
    default:
      return 'medium';
  }
});

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleString();
}

function navigateToDetail(): void {
  ionRouter.push(`/errors/${props.error.id}`);
}
</script>
