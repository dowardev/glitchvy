<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-modal :is-open="isOpen" @didDismiss="onDismiss">
    <ion-header>
      <ion-toolbar>
        <ion-title>Crear API Key</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="close">
            <ion-icon slot="icon-only" :icon="closeOutline" />
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content class="ion-padding">
      <template v-if="!plaintextKey">
        <form @submit.prevent="submit">
          <ion-list inset>
            <ion-item>
              <ion-input
                v-model="form.name"
                type="text"
                label="Nombre de la key"
                label-placement="stacked"
                placeholder="Ej: Production SDK"
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
      </template>

      <template v-else>
        <div class="ion-text-center ion-padding">
          <ion-icon :icon="checkmarkCircleOutline" size="large" color="success" />
          <h2>API Key creada</h2>
          <p>Guardá esta clave ahora. No se va a volver a mostrar.</p>
        </div>

        <ion-card>
          <ion-card-content>
            <pre>{{ plaintextKey }}</pre>
            <ion-button fill="clear" size="small" @click="copyKey">
              <ion-icon slot="start" :icon="copyOutline" />
              Copiar
            </ion-button>
          </ion-card-content>
        </ion-card>

        <div class="ion-padding">
          <ion-button expand="block" @click="close">Entendido</ion-button>
        </div>
      </template>
    </ion-content>
  </ion-modal>
</template>

<script setup lang="ts">
import {
  IonButton,
  IonButtons,
  IonCard,
  IonCardContent,
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
import { checkmarkCircleOutline, closeOutline, copyOutline } from 'ionicons/icons';
import { useProjectsStore } from '@/stores/projects';

interface Props {
  projectId: number | undefined;
}

const props = defineProps<Props>();
const emit = defineEmits<{
  (event: 'created'): void;
}>();

const isOpen = ref(false);
const form = ref({ name: '' });
const loading = ref(false);
const error = ref<string | null>(null);
const plaintextKey = ref<string | null>(null);

const projectsStore = useProjectsStore();

watch(() => isOpen.value, (open) => {
  if (open) {
    form.value.name = '';
    error.value = null;
    plaintextKey.value = null;
  }
});

function open(): void {
  isOpen.value = true;
}

function close(): void {
  isOpen.value = false;
  plaintextKey.value = null;
  emit('created');
}

function onDismiss(): void {
  isOpen.value = false;
}

async function submit(): Promise<void> {
  const projectId = props.projectId;
  if (!projectId || !form.value.name.trim() || loading.value) return;

  loading.value = true;
  error.value = null;

  try {
    const response = await projectsStore.createApiKey(projectId, { name: form.value.name.trim() });
    plaintextKey.value = response.plaintext;
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Error al crear la API key';
  } finally {
    loading.value = false;
  }
}

async function copyKey(): Promise<void> {
  if (!plaintextKey.value) return;
  await navigator.clipboard.writeText(plaintextKey.value);
}

defineExpose({ open });
</script>
