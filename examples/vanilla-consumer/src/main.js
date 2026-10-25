// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { initErrorReporter, reportError, setUserContext, addBreadcrumb } from 'glitchvy';

// In a real app these come from your build/runtime config.
const API_KEY = import.meta.env?.VITE_GLITCHVY_API_KEY
  ?? 'glitchvy_your_api_key_here';
const INGESTION_URL = '/api/glitchvy/errors';

initErrorReporter({
  ingestionUrl: INGESTION_URL,
  apiKey: API_KEY,
  appVersion: '0.1.0-example',
  environment: 'development',
  scrubRules: {
    redactFields: ['name', 'amount', 'balance', 'notes'],
    redactContexts: ['entry', 'supplier', 'account'],
  },
});

setUserContext({ userId: 'demo-user-42' });
addBreadcrumb('app booted');

const log = document.getElementById('log');
function print(...args) {
  log.textContent = args.map((a) => (typeof a === 'string' ? a : JSON.stringify(a))).join(' ');
}

document.getElementById('report-btn').addEventListener('click', async () => {
  await reportError(new Error('User clicked Report a bug'), {
    entityType: 'demo',
    entityId: 42,
    operation: 'manual-report',
  });
  print('reported; queued if offline.');
});

// Trigger an unhandled error to show the auto-capture path.
document.getElementById('throw-btn').addEventListener('click', () => {
  setTimeout(() => undefined.deeplyMissingFunction());
});
