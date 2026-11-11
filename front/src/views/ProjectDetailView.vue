<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/projects" />
        </ion-buttons>
        <ion-title>{{ project?.name ?? 'Proyecto' }}</ion-title>
        <ion-buttons slot="end">
          <ion-button v-if="project" @click="openCreateApiKeyModal">
            <ion-icon slot="icon-only" :icon="keyOutline" />
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content>
      <div v-if="projectsStore.loading" class="ion-text-center ion-padding">
        <ion-spinner name="crescent" />
      </div>

      <ion-text v-if="projectsStore.error" color="danger">
        <p class="ion-padding">{{ projectsStore.error }}</p>
      </ion-text>

      <template v-else-if="project">
        <ion-card>
          <ion-card-header>
            <ion-card-title>{{ project.name }}</ion-card-title>
            <ion-card-subtitle>ID: {{ project.id }} · {{ formatDate(project.created_at) }}</ion-card-subtitle>
          </ion-card-header>
        </ion-card>

        <ion-card>
          <ion-card-header>
            <ion-card-title>API Keys</ion-card-title>
          </ion-card-header>
          <ion-card-content>
            <ion-button fill="outline" size="small" @click="openCreateApiKeyModal">
              <ion-icon slot="start" :icon="addOutline" />
              Nueva API key
            </ion-button>

            <ion-list v-if="projectsStore.apiKeys.length > 0" lines="full" class="ion-margin-top">
              <ion-item v-for="key in projectsStore.apiKeys" :key="key.id">
                <ion-icon slot="start" :icon="keyOutline" color="primary" />
                <ion-label>
                  <h3>{{ key.name }}</h3>
                  <p>{{ key.prefix }}••••••</p>
                </ion-label>
              </ion-item>
            </ion-list>
          </ion-card-content>
        </ion-card>

        <ErrorListView :projectId="project.id" />
      </template>
    </ion-content>

    <!--
      The modal lives outside the <ion-content>: opening it makes Ionic
      move the <ion-modal> into the <ion-app> overlay container, and the
      API key list that appears below it would then be inserted before an
      element that is no longer a child of the <ion-content>.
    -->
    <ApiKeyCreateModal
      ref="apiKeyModalRef"
      :projectId="project?.id"
      @created="onApiKeyCreated"
    />
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonBackButton,
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
  IonSpinner,
  IonText,
  IonTitle,
  IonToolbar,
} from '@ionic/vue';
import { onMounted, watch, ref, computed } from 'vue';
import { addOutline, keyOutline } from 'ionicons/icons';
import { useProjectsStore } from '@/stores/projects';
import ErrorListView from './ErrorListView.vue';
import ApiKeyCreateModal from '@/components/ApiKeyCreateModal.vue';

interface Props {
  /** Route param, handed over by the router outlet as a prop. */
  id: string;
}

const props = defineProps<Props>();
const projectsStore = useProjectsStore();
const apiKeyModalRef = ref<InstanceType<typeof ApiKeyCreateModal> | null>(null);

const projectId = computed(() => Number(props.id));
const project = computed(() => projectsStore.list.find(p => p.id === projectId.value) ?? null);

onMounted(async () => {
  try {
    if (!projectsStore.list.some(p => p.id === projectId.value)) {
      await projectsStore.fetchAll();
    }
    projectsStore.selectProject(project.value);
    if (projectId.value) {
      await projectsStore.fetchApiKeys(projectId.value);
    }
  } catch {
    // The store records the failure in its error ref, which the template
    // renders. Swallowing it here keeps the rejection from escaping the
    // lifecycle hook.
  }
});

watch(projectId, async (newId) => {
  projectsStore.selectProject(project.value);
  if (newId) {
    try {
      await projectsStore.fetchApiKeys(newId);
    } catch {
      // Same as above: the store already recorded the error.
    }
  }
});

function openCreateApiKeyModal(): void {
  apiKeyModalRef.value?.open();
}

function onApiKeyCreated(): void {
  // The modal already pushed the new key into the store's apiKeys
  // array; nothing else to do here.
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleString();
}
</script>
