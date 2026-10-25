<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-modal :is-open="isOpen" @didDismiss="onDismiss">
    <ion-header>
      <ion-toolbar>
        <ion-title>Crear proyecto</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="close">
            <ion-icon slot="icon-only" :icon="closeOutline" />
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content class="ion-padding">
      <form @submit.prevent="submit">
        <ion-list inset>
          <ion-item>
            <ion-input
              v-model="form.name"
              type="text"
              label="Nombre del proyecto"
              label-placement="stacked"
              placeholder="Ej: Mi aplicación"
              required
              :maxlength="100"
              :disabled="loading"
              autocomplete="off"
            />
          </ion-item>
        </ion-list>

        <ion-text v-if="error" color="danger">
          <p class="ion-padding-start">{{ error }}</p>
        </ion-text>

        <div class="ion-padding">
          <ion-button type="button" fill="outline" @click="close" :disabled="loading">
            Cancelar
          </ion-button>
          <ion-button type="submit" :disabled="loading || !form.name.trim()">
            <ion-spinner v-if="loading" name="dots" slot="start" />
            {{ loading ? 'Creando...' : 'Crear' }}
          </ion-button>
        </div>
      </form>
    </ion-content>
  </ion-modal>
</template>

<script setup lang="ts">
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonIcon,
  IonInput,
  IonItem,
  IonList,
  IonModal,
  IonSpinner,
  IonText,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { ref, watch } from 'vue';
import { closeOutline } from 'ionicons/icons';
import { useProjectsStore } from '@/stores/projects';

const emit = defineEmits<{
  (event: 'created', projectId: number): void;
}>();

const isOpen = ref(false);
const form = ref({ name: '' });
const loading = ref(false);
const error = ref<string | null>(null);

const projectsStore = useProjectsStore();

watch(() => isOpen.value, (open) => {
  if (open) {
    form.value.name = '';
    error.value = null;
  }
});

function open(): void {
  isOpen.value = true;
}

function close(): void {
  isOpen.value = false;
}

function onDismiss(): void {
  isOpen.value = false;
}

async function submit(): Promise<void> {
  if (!form.value.name.trim() || loading.value) return;

  loading.value = true;
  error.value = null;

  try {
    const project = await projectsStore.create({ name: form.value.name.trim() });
    close();
    emit('created', project.id);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Error al crear el proyecto';
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>
