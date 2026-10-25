<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>Errores</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content>
      <ErrorFilters v-model="filters" @update:modelValue="handleFiltersChange" />

      <div v-if="errorsStore.loading && errorsStore.list.length === 0" class="ion-text-center ion-padding">
        <ion-spinner name="crescent" />
        <p>Cargando errores...</p>
      </div>

      <div v-else-if="errorsStore.error" class="ion-text-center ion-padding">
        <ion-icon name="alert-circle-outline" size="large" color="danger" />
        <p>{{ errorsStore.error }}</p>
        <ion-button fill="outline" @click="loadPage">
          Reintentar
        </ion-button>
      </div>

      <ion-list v-else-if="errorsStore.list.length === 0" lines="none">
        <ion-item>
          <ion-label class="ion-text-center">
            <ion-icon name="bug-outline" size="large" color="medium" />
            <h2>No hay errores</h2>
            <p>{{ filters.project_id ? 'Para este proyecto' : 'En todos los proyectos' }}</p>
          </ion-label>
        </ion-item>
      </ion-list>

      <ion-list v-else lines="full">
        <ErrorListItem
          v-for="error in errorsStore.list"
          :key="error.id"
          :error="error"
        />
      </ion-list>

      <div v-if="errorsStore.nextCursor || errorsStore.prevCursor" class="ion-text-center ion-padding">
        <ion-button
          fill="clear"
          @click="loadPage(errorsStore.prevCursor ?? undefined)"
          :disabled="!errorsStore.prevCursor || errorsStore.loading"
        >
          <ion-icon slot="start" :icon="chevronBackOutline" />
          Anterior
        </ion-button>
        <span class="ion-padding-horizontal">{{ errorsStore.list.length }} errores</span>
        <ion-button
          fill="clear"
          @click="loadPage(errorsStore.nextCursor ?? undefined)"
          :disabled="!errorsStore.nextCursor || errorsStore.loading"
        >
          Siguiente
          <ion-icon slot="end" :icon="chevronForwardOutline" />
        </ion-button>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonButton,
  IonContent,
  IonHeader,
  IonIcon,
  IonItem,
  IonLabel,
  IonList,
  IonPage,
  IonSpinner,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { onMounted, watch, ref } from 'vue';
import { chevronBackOutline, chevronForwardOutline } from 'ionicons/icons';
import { useErrorsStore } from '@/stores/errors';
import ErrorListItem from '@/components/ErrorListItem.vue';
import ErrorFilters from '@/components/ErrorFilters.vue';
import type { ErrorFilters as ErrorFiltersType } from '@/types';

const errorsStore = useErrorsStore();

const filters = ref<ErrorFiltersType>({
  level: undefined,
  status: undefined,
  search: undefined,
  project_id: undefined,
});

function handleFiltersChange(newFilters: ErrorFiltersType): void {
  filters.value = newFilters;
  loadPage();
}

interface Props {
  projectId?: number;
}

const props = defineProps<Props>();

onMounted(() => {
  if (props.projectId) {
    errorsStore.setFilters({ project_id: props.projectId });
  }
  loadPage();
});

watch(() => props.projectId, (newId) => {
  if (newId) {
    errorsStore.setFilters({ project_id: newId });
    loadPage();
  }
});

async function loadPage(cursor?: string): Promise<void> {
  await errorsStore.fetchPage(cursor);
}
</script>
