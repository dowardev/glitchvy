<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>glitchvy · login</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <h2>Sign in</h2>
      <p>
        Paste the user token your Laravel app returned when you authenticated.
        The SDK forwards this on every admin API call as the
        <code>X-Glitchvy-User-Token</code> header.
      </p>
      <ion-list>
        <ion-item>
          <ion-input
            v-model="token"
            label="User token"
            label-placement="stacked"
            type="password"
            placeholder="(opaque token)"
            data-testid="token-input"
            autocomplete="off"
          />
        </ion-item>
      </ion-list>
      <ion-button
        expand="block"
        :disabled="!token.trim()"
        @click="onSubmit"
        data-testid="login-submit"
      >
        Sign in
      </ion-button>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonList,
  IonItem,
  IonInput,
  IonButton,
} from '@ionic/vue';
import { useAuthStore } from '../stores/auth.js';

const auth = useAuthStore();
const router = useRouter();
const token = ref<string>('');

async function onSubmit(): Promise<void> {
  const value = token.value.trim();
  if (!value) return;
  auth.setToken(value);
  token.value = '';
  await router.push({ name: 'errors' });
}
</script>
