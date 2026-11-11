<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/errors" />
        </ion-buttons>
        <ion-title>Detalle del error</ion-title>
        <ion-buttons slot="end" v-if="detail">
          <ion-button @click="refresh">
            <ion-icon slot="icon-only" :icon="refreshOutline" />
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content>
      <div v-if="errorsStore.detailLoading && !detail" class="ion-text-center ion-padding">
        <ion-spinner name="crescent" />
        <p>Cargando detalle...</p>
      </div>

      <div v-else-if="errorsStore.error && !detail" class="ion-text-center ion-padding">
        <ion-icon :icon="alertCircleOutline" size="large" color="danger" />
        <p>{{ errorsStore.error }}</p>
        <ion-button fill="outline" @click="loadDetail">Reintentar</ion-button>
      </div>

      <template v-else-if="detail">
        <ion-card>
          <ion-card-header>
            <ion-card-title>{{ detail.message }}</ion-card-title>
            <ion-card-subtitle>{{ detail.type }}</ion-card-subtitle>
            <div class="ion-margin-top">
              <ion-badge :color="levelColor" class="ion-margin-end">{{ detail.level }}</ion-badge>
              <ion-badge :color="statusColor">{{ detail.status }}</ion-badge>
            </div>
          </ion-card-header>
          <ion-card-content>
            <p><ion-text color="medium">Primera vez:</ion-text> {{ formatDate(detail.first_seen_at) }}</p>
            <p><ion-text color="medium">Última vez:</ion-text> {{ formatDate(detail.last_seen_at) }}</p>
            <p><ion-text color="medium">Ocurrencias:</ion-text> {{ detail.count }}</p>
            <p><ion-text color="medium">ID:</ion-text> {{ detail.id }}</p>

            <ion-list inset class="ion-margin-top">
              <ion-item>
                <ion-select
                  label="Estado"
                  label-placement="stacked"
                  :value="detail.status"
                  @ionChange="handleStatusChange"
                  interface="popover"
                >
                  <ion-select-option value="open">Abierto</ion-select-option>
                  <ion-select-option value="in_progress">En progreso</ion-select-option>
                  <ion-select-option value="resolved">Resuelto</ion-select-option>
                  <ion-select-option value="ignored">Ignorado</ion-select-option>
                </ion-select>
              </ion-item>
            </ion-list>
          </ion-card-content>
        </ion-card>

        <ion-accordion-group value="stack">
          <ion-accordion value="stack">
            <ion-item slot="header">
              <ion-label>Stack Trace</ion-label>
            </ion-item>
            <div slot="content" class="ion-padding">
              <pre v-if="detail.stack_trace" class="stack-trace">{{ detail.stack_trace }}</pre>
              <p v-else class="ion-text-center ion-text-muted">No hay stack trace disponible</p>
            </div>
          </ion-accordion>
        </ion-accordion-group>

        <ion-accordion-group value="breadcrumbs">
          <ion-accordion value="breadcrumbs">
            <ion-item slot="header">
              <ion-label>Breadcrumbs ({{ detail.breadcrumbs.length }})</ion-label>
            </ion-item>
            <div slot="content" class="ion-padding">
              <p v-if="detail.breadcrumbs.length === 0" class="ion-text-center ion-text-muted">No hay breadcrumbs</p>
              <ion-list v-else lines="full">
                <ion-item v-for="(crumb, index) in detail.breadcrumbs" :key="index">
                  <ion-badge :color="breadcrumbLevelColor(crumb.level)" slot="start">{{ crumb.level || 'info' }}</ion-badge>
                  <ion-label>
                    <h3>{{ crumb.message }}</h3>
                    <p>{{ crumb.category }} · {{ formatDate(crumb.timestamp) }}</p>
                  </ion-label>
                </ion-item>
              </ion-list>
            </div>
          </ion-accordion>
        </ion-accordion-group>

        <ion-accordion-group value="context">
          <ion-accordion value="context">
            <ion-item slot="header">
              <ion-label>Contexto</ion-label>
            </ion-item>
            <div slot="content" class="ion-padding">
              <pre v-if="Object.keys(detail.context).length > 0" class="json-display">{{ formatJson(detail.context) }}</pre>
              <p v-else class="ion-text-center ion-text-muted">Sin contexto adicional</p>
            </div>
          </ion-accordion>
        </ion-accordion-group>

        <ion-accordion-group value="tags">
          <ion-accordion value="tags">
            <ion-item slot="header">
              <ion-label>Tags ({{ Object.keys(detail.tags).length }})</ion-label>
            </ion-item>
            <div slot="content" class="ion-padding">
              <p v-if="Object.keys(detail.tags).length === 0" class="ion-text-center ion-text-muted">Sin tags</p>
              <p v-else>
                <ion-badge
                  v-for="(value, key) in detail.tags"
                  :key="key"
                  color="medium"
                  class="ion-margin-end"
                >
                  {{ key }}: {{ value }}
                </ion-badge>
              </p>
            </div>
          </ion-accordion>
        </ion-accordion-group>

        <ion-accordion-group value="screenshots">
          <ion-accordion value="screenshots">
            <ion-item slot="header">
              <ion-label>Capturas ({{ detail.screenshots.length }})</ion-label>
            </ion-item>
            <div slot="content" class="ion-padding">
              <p v-if="detail.screenshots.length === 0" class="ion-text-center ion-text-muted">Sin capturas de pantalla</p>
              <div v-else>
                <ScreenshotViewer
                  v-for="screenshot in detail.screenshots"
                  :key="screenshot.id"
                  :screenshot-id="screenshot.id"
                />
              </div>
            </div>
          </ion-accordion>
        </ion-accordion-group>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonAccordion,
  IonAccordionGroup,
  IonBackButton,
  IonBadge,
  IonButton,
  IonButtons,
  IonCard,
  IonCardContent,
  IonCardHeader,
  IonCardSubtitle,
  IonCardTitle,
  IonContent,
  IonHeader,
  IonIcon,
  IonItem,
  IonLabel,
  IonList,
  IonPage,
  IonSelect,
  IonSelectOption,
  IonSpinner,
  IonText,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { onMounted, watch, ref, computed } from 'vue';
import { alertCircleOutline, refreshOutline } from 'ionicons/icons';
import { useErrorsStore } from '@/stores/errors';
import ScreenshotViewer from '@/components/ScreenshotViewer.vue';

interface Props {
  /** Route param, handed over by the router outlet as a prop. */
  id: string;
}

const props = defineProps<Props>();
const errorsStore = useErrorsStore();

const errorId = computed(() => Number(props.id));
const detail = computed(() => errorsStore.detail);

const levelColor = computed(() => {
  if (!detail.value) return 'medium';
  switch (detail.value.level) {
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
  if (!detail.value) return 'medium';
  switch (detail.value.status) {
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

onMounted(() => {
  loadDetail();
});

watch(errorId, () => {
  loadDetail();
});

async function loadDetail(): Promise<void> {
  if (errorId.value) {
    await errorsStore.loadDetail(errorId.value);
  }
}

async function handleStatusChange(event: CustomEvent): Promise<void> {
  const newStatus = event.detail.value;
  if (detail.value && newStatus !== detail.value.status) {
    try {
      await errorsStore.setStatus(detail.value.id, newStatus);
    } catch {
      loadDetail();
    }
  }
}

function breadcrumbLevelColor(level?: string): string {
  switch (level) {
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
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleString();
}

function formatJson(obj: Record<string, unknown>): string {
  return JSON.stringify(obj, null, 2);
}

async function refresh(): Promise<void> {
  await loadDetail();
}
</script>
