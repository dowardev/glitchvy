<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <div class="filters">
    <ion-row>
      <ion-col>
        <ion-item>
          <ion-select v-model="level" placeholder="All levels" data-testid="filter-level">
            <ion-select-option value="error">error</ion-select-option>
            <ion-select-option value="warn">warn</ion-select-option>
            <ion-select-option value="info">info</ion-select-option>
          </ion-select>
        </ion-item>
      </ion-col>
      <ion-col>
        <ion-item>
          <ion-select v-model="status" placeholder="All statuses" data-testid="filter-status">
            <ion-select-option value="open">open</ion-select-option>
            <ion-select-option value="triaged">triaged</ion-select-option>
            <ion-select-option value="resolved">resolved</ion-select-option>
            <ion-select-option value="closed">closed</ion-select-option>
          </ion-select>
        </ion-item>
      </ion-col>
      <ion-col>
        <ion-item>
          <ion-input v-model="search" placeholder="Search messages" data-testid="filter-search" />
        </ion-item>
      </ion-col>
    </ion-row>
    <ion-row class="filter-actions">
      <ion-col>
        <ion-button fill="outline" @click="emit('apply')" data-testid="filter-apply">Apply</ion-button>
        <ion-button fill="clear" @click="emit('reset')" data-testid="filter-reset">Reset</ion-button>
      </ion-col>
    </ion-row>
  </div>
</template>

<script setup lang="ts">
import {
  IonRow,
  IonCol,
  IonItem,
  IonInput,
  IonSelect,
  IonSelectOption,
  IonButton,
} from '@ionic/vue';

const emit = defineEmits<{ apply: []; reset: [] }>();

defineProps<{
  level: string;
  status: string;
  search: string;
}>();

const level = defineModel<string>('level', { default: '' });
const status = defineModel<string>('status', { default: '' });
const search = defineModel<string>('search', { default: '' });
</script>

<style scoped>
.filters {
  display: block;
  padding-bottom: 16px;
}
.filter-actions {
  margin-top: 4px;
}
</style>
