<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <div class="ion-padding">
    <ion-button fill="outline" size="small" @click="openFullscreen">
      <ion-icon slot="start" :icon="imageOutline" />
      Ver captura de pantalla
    </ion-button>

    <ion-modal :is-open="showFullscreen" @didDismiss="closeFullscreen">
      <!--
        The dark backdrop behind the screenshot has no Ionic utility, so
        the custom property is set inline on the content element. The
        image is centred with the stock `ion-text-center` utility.
      -->
      <ion-content class="ion-text-center" style="--background: #1a1a1a">
        <!--
          Ionic has no utility for capping the size of a raw <img>, so
          the constraints are inline: without them a full-page
          screenshot overflows the modal on both axes.
        -->
        <img
          v-if="blobUrl"
          :src="blobUrl"
          alt="Screenshot"
          style="display: block; max-width: 100%; max-height: 90vh; margin: auto; object-fit: contain"
          @error="onError"
        />
        <ion-text v-if="error" color="danger">
          <p>{{ error }}</p>
        </ion-text>

        <ion-fab vertical="top" horizontal="end" slot="fixed">
          <ion-fab-button @click="closeFullscreen" size="small">
            <ion-icon :icon="closeOutline" />
          </ion-fab-button>
        </ion-fab>

        <ion-fab vertical="bottom" horizontal="end" slot="fixed">
          <ion-fab-button @click="download" size="small">
            <ion-icon :icon="downloadOutline" />
          </ion-fab-button>
        </ion-fab>
      </ion-content>
    </ion-modal>
  </div>
</template>

<script setup lang="ts">
import {
  IonButton,
  IonContent,
  IonFab,
  IonFabButton,
  IonIcon,
  IonModal,
  IonText,
} from '@ionic/vue';
import { ref, onUnmounted } from 'vue';
import { closeOutline, downloadOutline, imageOutline } from 'ionicons/icons';
import { api } from '@/api/client';

interface Props {
  screenshotId: number;
}

const props = defineProps<Props>();

const blobUrl = ref<string | undefined>(undefined);
const showFullscreen = ref(false);
const error = ref<string | null>(null);

onUnmounted(() => {
  if (blobUrl.value) {
    URL.revokeObjectURL(blobUrl.value);
    blobUrl.value = undefined;
  }
});

async function fetchScreenshot(): Promise<void> {
  if (blobUrl.value) return;

  error.value = null;
  try {
    const url = api.screenshotUrl(props.screenshotId);
    const response = await fetch(url, {
      credentials: 'include',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });

    if (!response.ok) {
      throw new Error('No se pudo cargar la captura');
    }

    const blob = await response.blob();
    blobUrl.value = URL.createObjectURL(blob);
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Error desconocido';
  }
}

async function openFullscreen(): Promise<void> {
  await fetchScreenshot();
  if (blobUrl.value) {
    showFullscreen.value = true;
  }
}

function closeFullscreen(): void {
  showFullscreen.value = false;
}

function onError(): void {
  error.value = 'Error al cargar la imagen';
}

function download(): void {
  if (!blobUrl.value) return;

  const a = document.createElement('a');
  a.href = blobUrl.value;
  a.download = `screenshot-${props.screenshotId}.png`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}
</script>
