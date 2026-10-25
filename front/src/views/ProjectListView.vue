<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>Proyectos</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="openCreateModal">
            <ion-icon slot="icon-only" :icon="addOutline" />
          </ion-button>
          <ion-button @click="handleLogout">
            <ion-icon slot="icon-only" :icon="logOutOutline" />
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content>
      <div v-if="projectsStore.loading" class="ion-text-center ion-padding">
        <ion-spinner name="crescent" />
        <p>Cargando proyectos...</p>
      </div>

      <div v-else-if="projectsStore.error" class="ion-text-center ion-padding">
        <ion-icon name="alert-circle-outline" size="large" color="danger" />
        <p>{{ projectsStore.error }}</p>
        <ion-button fill="outline" @click="loadProjects">Reintentar</ion-button>
      </div>

      <ion-list v-else-if="projectsStore.list.length === 0" lines="none">
        <ion-item>
          <ion-label class="ion-text-center">
            <h2>No hay proyectos aún</h2>
            <p>Creá tu primer proyecto para empezar</p>
          </ion-label>
        </ion-item>
      </ion-list>

      <ion-list v-else lines="full">
        <ion-item
          v-for="project in projectsStore.list"
          :key="project.id"
          button
          detail
          @click="navigateToProject(project)"
        >
          <ion-icon slot="start" :icon="folderOutline" color="primary" />
          <ion-label>
            <h2>{{ project.name }}</h2>
            <p>{{ formatDate(project.created_at) }}</p>
          </ion-label>
        </ion-item>
      </ion-list>

      <ProjectCreateModal @created="onProjectCreated" ref="createModalRef" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonButton,
  IonButtons,
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
import { onMounted, ref } from 'vue';
import { useIonRouter } from '@ionic/vue';
import { addOutline, folderOutline, logOutOutline } from 'ionicons/icons';
import { useAuthStore } from '@/stores/auth';
import { useProjectsStore } from '@/stores/projects';
import ProjectCreateModal from '@/components/ProjectCreateModal.vue';

const ionRouter = useIonRouter();
const projectsStore = useProjectsStore();
const authStore = useAuthStore();
const createModalRef = ref<InstanceType<typeof ProjectCreateModal> | null>(null);

onMounted(() => {
  loadProjects();
});

async function loadProjects(): Promise<void> {
  await projectsStore.fetchAll();
}

function navigateToProject(project: { id: number }): void {
  ionRouter.push(`/projects/${project.id}`);
}

function openCreateModal(): void {
  createModalRef.value?.open();
}

async function onProjectCreated(projectId: number): Promise<void> {
  await loadProjects();
  ionRouter.push(`/projects/${projectId}`);
}

async function handleLogout(): Promise<void> {
  await authStore.logout();
  ionRouter.push('/login');
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString();
}
</script>
