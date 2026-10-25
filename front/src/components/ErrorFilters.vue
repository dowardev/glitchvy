<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <!--
    ion-toolbar is page chrome (header/footer), not a content wrapper,
    so the filter controls live in a plain div with the stock
    `ion-padding` utility.
  -->
  <div class="ion-padding">
    <ion-row>
      <ion-col size="12" size-md="4">
        <ion-select v-model="localFilters.level" placeholder="Nivel" @ionChange="emitFilters">
          <ion-select-option value="debug">Debug</ion-select-option>
          <ion-select-option value="info">Info</ion-select-option>
          <ion-select-option value="warning">Warning</ion-select-option>
          <ion-select-option value="error">Error</ion-select-option>
          <ion-select-option value="critical">Critical</ion-select-option>
        </ion-select>
      </ion-col>

      <ion-col size="12" size-md="4">
        <ion-select v-model="localFilters.status" placeholder="Estado" @ionChange="emitFilters">
          <ion-select-option value="open">Abierto</ion-select-option>
          <ion-select-option value="in_progress">En progreso</ion-select-option>
          <ion-select-option value="resolved">Resuelto</ion-select-option>
          <ion-select-option value="ignored">Ignorado</ion-select-option>
        </ion-select>
      </ion-col>

      <ion-col size="12" size-md="4">
        <ion-input
          v-model="localFilters.search"
          placeholder="Buscar mensaje o tipo..."
          clear-input
          @ionInput="debouncedEmit"
        />
      </ion-col>
    </ion-row>
  </div>
</template>

<script setup lang="ts">
import { IonCol, IonInput, IonRow, IonSelect, IonSelectOption } from '@ionic/vue';
import { ref, watch } from 'vue';
import type { ErrorFilters } from '@/types';

interface Props {
  modelValue: ErrorFilters;
}

interface Emits {
  'update:modelValue': [value: ErrorFilters];
}

const props = defineProps<Props>();
const emit = defineEmits<Emits>();

const localFilters = ref<ErrorFilters>({ ...props.modelValue });

const debouncedEmit = debounce(() => {
  emit('update:modelValue', { ...localFilters.value });
}, 300);

function emitFilters(): void {
  emit('update:modelValue', { ...localFilters.value });
}

watch(() => props.modelValue, (newVal) => {
  localFilters.value = { ...newVal };
}, { deep: true });

function debounce<T extends (...args: unknown[]) => void>(fn: T, delay: number): T {
  let timeoutId: ReturnType<typeof setTimeout>;
  return ((...args: unknown[]) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => fn(...args), delay);
  }) as T;
}
</script>
