<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <ion-grid style="height: 100%">
        <ion-row class="ion-justify-content-center ion-align-items-center">
          <ion-col size="12" size-md="8" size-lg="5" size-xl="4">
            <div class="ion-text-center ion-padding">
              <h1>Glitchvy</h1>
              <p>Panel de administración</p>
            </div>

            <ion-list inset>
              <ion-item>
                <ion-input
                  v-model="form.email"
                  type="email"
                  label="Email"
                  label-placement="stacked"
                  placeholder="tu@email.com"
                  required
                  autocomplete="email"
                  :disabled="loading"
                  @ionBlur="validateEmail"
                />
              </ion-item>
              <ion-item>
                <ion-input
                  v-model="form.password"
                  type="password"
                  label="Contraseña"
                  label-placement="stacked"
                  required
                  autocomplete="current-password"
                  :disabled="loading"
                />
              </ion-item>
            </ion-list>

            <ion-text v-if="error" color="danger">
              <p class="ion-padding-start">{{ error }}</p>
            </ion-text>

            <div class="ion-padding">
              <ion-button
                type="submit"
                expand="block"
                :disabled="loading || !form.email || !form.password"
                @click="handleLogin"
              >
                <ion-spinner v-if="loading" name="dots" slot="start" />
                {{ loading ? 'Iniciando sesión...' : 'Iniciar sesión' }}
              </ion-button>
            </div>
          </ion-col>
        </ion-row>
      </ion-grid>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import {
  IonButton,
  IonCol,
  IonContent,
  IonGrid,
  IonInput,
  IonItem,
  IonList,
  IonPage,
  IonRow,
  IonSpinner,
  IonText,
} from '@ionic/vue';
import { ref } from 'vue';
import { useIonRouter } from '@ionic/vue';
import { useAuthStore } from '@/stores/auth';

const ionRouter = useIonRouter();
const authStore = useAuthStore();

const form = ref({ email: '', password: '' });
const loading = ref(false);
const error = ref<string | null>(null);

function validateEmail(): void {
  error.value = null;
}

async function handleLogin(): Promise<void> {
  if (loading.value || !form.value.email.trim() || !form.value.password) return;

  loading.value = true;
  error.value = null;

  try {
    await authStore.login({ email: form.value.email.trim(), password: form.value.password });
    await ionRouter.push('/projects');
  } catch (err) {
    if (err instanceof Error) {
      error.value = err.message;
    } else {
      error.value = 'Credenciales inválidas';
    }
  } finally {
    loading.value = false;
  }
}
</script>
