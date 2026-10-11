<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <ion-app>
    <ion-header>
      <ion-toolbar>
        <ion-title>glitchvy · Vue 3 + Ionic demo</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <p>
        Tap the button below to send a manually reported error to glitchvy.
        The SDK has <em>no UI of its own</em> — this <code>&lt;ion-button&gt;</code> is
        what wraps it.
      </p>
      <ion-button expand="block" @click="onReport" data-testid="report-button">
        Reportar este error
      </ion-button>
      <ion-button expand="block" fill="outline" @click="onThrow">Trigger unhandled</ion-button>
    </ion-content>
  </ion-app>
</template>

<script setup lang="ts">
import {
  IonApp,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonButton,
} from '@ionic/vue';
import { reportError } from 'glitchvy';

async function onReport(): Promise<void> {
  try {
    throw new Error('Manually reported from the demo button');
  } catch (e) {
    await reportError(e, {
      entityType: 'demo',
      operation: 'report-button',
    });
  }
}

function onThrow(): void {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (window as any).doesNotExist.goBoom();
}
</script>
