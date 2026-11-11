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
      <!--
        One stable list, with the state inside it. The branches used to be
        siblings (v-if / v-else-if / v-else on separate elements), and that
        broke the page: opening the create-project modal makes Ionic move
        the <ion-modal> out of the <ion-content> and into the <ion-app>
        overlay container, so the element Vue would insert the new list
        before is no longer a child of the <ion-content>. The patch then
        died with "insertBefore: the node before which the new node is to
        be inserted is not a child of this node", and the project could not
        be created. Nothing is anchored to the moved overlay now.
      -->
      <ion-list lines="full">
        <ion-item v-if="projectsStore.loading">
          <ion-spinner name="crescent" slot="start" />
          <ion-label class="ion-text-center">
            <p>Cargando proyectos...</p>
          </ion-label>
        </ion-item>

        <ion-item v-else-if="projectsStore.error">
          <ion-icon :icon="alertCircleOutline" slot="start" color="danger" />
          <ion-label>
            <h2>{{ projectsStore.error }}</h2>
            <ion-button fill="outline" @click="loadProjects">Reintentar</ion-button>
          </ion-label>
        </ion-item>

        <ion-item v-else-if="projectsStore.list.length === 0">
          <ion-label class="ion-text-center">
            <h2>No hay proyectos aún</h2>
            <p>Creá tu primer proyecto para empezar</p>
          </ion-label>
        </ion-item>

        <template v-else>
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
        </template>
      </ion-list>
    </ion-content>

    <!--
      The modal lives outside the <ion-content>: opening it makes Ionic
      move the <ion-modal> into the <ion-app> overlay container, and a
      content that Vue still has to patch would then anchor an insertion
      to an element that is no longer its child.
    -->
    <ProjectCreateModal @created="onProjectCreated" ref="createModalRef" />
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
import { addOutline, alertCircleOutline, folderOutline, logOutOutline } from 'ionicons/icons';
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
