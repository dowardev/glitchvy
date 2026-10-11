// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { createApp } from 'vue';
import { IonicVue } from '@ionic/vue';

import '@ionic/vue/css/core.css';
import '@ionic/vue/css/normalize.css';
import '@ionic/vue/css/structure.css';
import '@ionic/vue/css/typography.css';

import { initErrorReporter, setUserContext } from 'glitchvy';
import App from './App.vue';

initErrorReporter({
  ingestionUrl: import.meta.env.VITE_GLITCHVY_INGESTION_URL ?? '/api/glitchvy/errors',
  apiKey: import.meta.env.VITE_GLITCHVY_API_KEY ?? 'gvy_replace_with_real_key',
  appVersion: '0.1.0-example',
  environment: 'development',
  scrubRules: {
    redactFields: ['name', 'amount', 'balance', 'notes'],
    redactContexts: ['entry', 'supplier', 'account'],
  },
});

setUserContext({ userId: 'demo-user-42' });

const app = createApp(App);
app.use(IonicVue);
app.mount('#app');
