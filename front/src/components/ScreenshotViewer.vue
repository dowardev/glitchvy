<!-- SPDX-FileCopyrightText: 2026 DowarDev -->
<!-- SPDX-License-Identifier: AGPL-3.0-only -->

<template>
  <div class="screenshot">
    <div v-if="state === 'loading'" data-testid="screenshot-loading">Loading…</div>
    <div v-else-if="state === 'error'" data-testid="screenshot-error">
      Failed to load screenshot: {{ error }}
    </div>
    <img
      v-else-if="state === 'ready' && blobUrl"
      :src="blobUrl"
      :alt="`Screenshot for error ${errorId}`"
      data-testid="screenshot-img"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { api } from '../api/client.js';
import { useAuthStore } from '../stores/auth.js';

const props = defineProps<{
  errorId: number;
}>();

const auth = useAuthStore();

const blobUrl = ref<string | null>(null);
const state = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
const error = ref<string>('');

async function load(): Promise<void> {
  state.value = 'loading';
  try {
    const res = await fetch(api.screenshotUrl(props.errorId), {
      headers: {
        'X-Glitchvy-User-Token': auth.token ?? '',
      },
    });
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`);
    }
    const blob = await res.blob();
    if (blobUrl.value) URL.revokeObjectURL(blobUrl.value);
    blobUrl.value = URL.createObjectURL(blob);
    state.value = 'ready';
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err);
    state.value = 'error';
  }
}

onMounted(async () => {
  await load();
});

onUnmounted(() => {
  if (blobUrl.value) {
    URL.revokeObjectURL(blobUrl.value);
    blobUrl.value = null;
  }
});
</script>

<style scoped>
.screenshot img {
  max-width: 100%;
  border: 1px solid var(--ion-color-medium);
  border-radius: 6px;
  display: block;
  margin: 8px 0;
}
.screenshot {
  margin: 8px 0;
}
</style>
