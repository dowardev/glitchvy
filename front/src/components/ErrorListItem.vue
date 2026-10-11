<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-card
    button
    @click="emit('open', report.id)"
    :data-testid="`error-${report.id}`"
  >
    <ion-card-header>
      <ion-card-subtitle>
        <ion-badge :color="levelBadge(report.level)">{{ report.level }}</ion-badge>
        <ion-badge
          :color="statusBadge(report.status)"
          class="status-badge"
          data-testid="status-badge"
        >
          {{ report.status }}
        </ion-badge>
        <span class="error-name" data-testid="error-name">{{ report.error_name }}</span>
      </ion-card-subtitle>
      <ion-card-title data-testid="error-message">{{ report.error_message }}</ion-card-title>
    </ion-card-header>
    <ion-card-content>
      <p>
        {{ report.environment }} · v{{ report.app_version }} · {{ report.created_at }}
      </p>
      <p v-if="report.entity_type">
        {{ report.entity_type }}#{{ report.entity_id ?? '?' }}
        <template v-if="report.operation">· {{ report.operation }}</template>
      </p>
    </ion-card-content>
  </ion-card>
</template>

<script setup lang="ts">
import {
  IonCard,
  IonCardHeader,
  IonCardTitle,
  IonCardSubtitle,
  IonCardContent,
  IonBadge,
} from '@ionic/vue';
import type { Level, Status } from '../types.js';

defineProps<{
  report: {
    id: number;
    level: Level;
    status: Status;
    error_name: string;
    error_message: string;
    environment: string;
    app_version: string;
    created_at: string | null;
    entity_type: string | null;
    entity_id: string | null;
    operation: string | null;
  };
}>();

const emit = defineEmits<{ open: [id: number] }>();

function levelBadge(level: Level): string {
  switch (level) {
    case 'error':
      return 'danger';
    case 'warn':
      return 'warning';
    case 'info':
      return 'primary';
  }
}

function statusBadge(status: Status): string {
  switch (status) {
    case 'open':
      return 'medium';
    case 'triaged':
      return 'tertiary';
    case 'resolved':
      return 'success';
    case 'closed':
      return 'dark';
  }
}
</script>

<style scoped>
.status-badge {
  margin-left: 4px;
  margin-right: 4px;
}
.error-name {
  margin-left: 6px;
  font-weight: 500;
}
</style>
