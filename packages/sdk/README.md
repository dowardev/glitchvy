# `glitchvy` (SDK)

> Minimalist error reporter SDK. Framework-agnostic. POSTs errors to your glitchvy SaaS ingestion URL.
> You wire it into your app's UI — it has **no UI of its own**.

[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

## What it does

- **Auto-capture** of `window.onerror`, `unhandledrejection`, and a `console.error` patch.
- **Manual report API**: `reportError(error, context)` from any `catch` site.
- **PII scrubbing** before the event leaves the device (configurable rules).
- **Offline queue** in IndexedDB — if your backend or the network is down, the error is persisted and flushed when connectivity returns.
- **Exponential-backoff retry** with bounded attempts before queueing.

This package has **no UI**. Wrapping these primitives in your framework (Vue 3 / Ionic, React, vanilla) is the consumer's job.

## Install (v0.x, via git+https)

```bash
pnpm add "git+https://gitlab.com/dowardev/glitchvy.git#v0.1.0" -w packages/sdk
# or, in the monorepo during dev:
pnpm add "workspace:*" --filter <your-app>
```

## Quick start

```ts
// main.ts (or wherever you bootstrap the app)
import { initErrorReporter, reportError, setUserContext } from 'glitchvy';

initErrorReporter({
  ingestionUrl: 'https://errors.dowar.xyz/api/glitchvy/errors', // SaaS URL
  apiKey: 'gvy_proj_xxx...',                                     // per-project
  appVersion: '0.78.0',
  environment: 'production',
  scrubRules: {
    redactFields: ['name', 'amount', 'balance', 'notes'],
    redactContexts: ['entry', 'supplier', 'account'],
  },
});

setUserContext({ userId: '42' });

try {
  await saveBudget(data);
} catch (err) {
  await reportError(err, {
    entityType: 'budget_entry',
    entityId: 42,
    operation: 'create',
  });
}
```

In a Vue 3 + Ionic app:

```vue
<template>
  <ion-button @click="onReport">Reportar este error</ion-button>
</template>

<script setup lang="ts">
import { reportError } from 'glitchvy';
const onReport = async () => {
  try {
    throw new Error('Manually reported from the demo button');
  } catch (e) {
    await reportError(e, { entityType: 'budget_entry' });
  }
};
</script>
```

## Public API

| Symbol | Kind | Description |
|---|---|---|
| `initErrorReporter(config)` | fn | Bootstraps the SDK. Idempotent; safe to call once. |
| `reportError(errorOrMessage, context?)` | fn (async) | Reports an error and returns a `Promise`. Failures are queued, not thrown. |
| `setUserContext(partial)` | fn | Adds/overrides user context fields. |
| `clearUserContext()` | fn | Clears the user context. |
| `addBreadcrumb(message, data?)` | fn | Trail of events leading to an error (manual). |
| `flush()` | fn | Forces an immediate drain of the offline queue. |
| `getQueueSize()` | fn | Number of events currently persisted offline. |
| `shutdown()` | fn | Tears down auto-capture hooks and flushes. Mainly for tests / HMR. |

## Configuration

```ts
interface InitConfig {
  /** Required. The SaaS ingestion endpoint. */
  ingestionUrl: string;
  /** Required. Per-project API key (Bearer token). */
  apiKey: string;
  /** Required. The consumer app's version. */
  appVersion: string;
  /** Required. Free-form environment label. */
  environment: string;

  /** Optional. Scrub rule set; defaults redact common PII. */
  scrubRules?: ScrubRules;

  /** Optional. Max in-memory + IDB-queued events retained. Default 100. */
  maxQueueSize?: number;
  /** Optional. Per-event retry budget. Default 3. */
  maxRetries?: number;
  /** Optional. Backoff base in ms. Default 1000 (doubles each attempt). */
  retryBaseMs?: number;

  /** Optional. Suppress auto-capture. Default: capture everything. */
  capture?: {
    onerror?: boolean;
    unhandledrejection?: boolean;
    consoleError?: boolean;
  };

  /** Optional. Screenshot upload endpoint (used by future SDK helpers). */
  screenshotUploadUrl?: string;
}

interface ErrorContext {
  entityType?: string;
  entityId?: string | number;
  operation?: string;
  tags?: Record<string, string>;
  data?: Record<string, unknown>;
}

interface ScrubRules {
  /** Keys to redact **anywhere inside** the listed contexts. */
  redactFields?: string[];
  /** Top-level subtrees where redactFields apply. */
  redactContexts?: string[];
}
```

### Scrub rules

By default, the following are always redacted (you don't need to configure them):

- Headers: `cookie`, `authorization`, `set-cookie`, `x-api-key`, `x-csrf-token`.
- Field names (case-insensitive): `password`, `passwd`, `secret`, `token`, `api_key`, `apikey`, `credit_card`, `creditcard`, `card_number`, `cvv`, `ssn`, `tax_id`.
- Anything matching `/Bearer\s+[A-Za-z0-9._-]+/gi` in `string` fields.

Configured rules look like:

```ts
scrubRules: {
  redactFields:   ['name', 'amount', 'balance', 'notes'],
  redactContexts: ['entry', 'supplier', 'account'],
}
```

Given input `{ entry: { name: 'foo' }, balance: 100 }`, the output is `{ entry: { name: '[REDACTED]' }, balance: 100 }`. The root `balance` is left alone because it isn't inside a configured context.

## Failure semantics

- `reportError(...)` **never throws**. A failing POST retries with exponential backoff; after the budget is exhausted, the event is persisted in the IndexedDB queue and the SDK keeps trying on the browser `online` event.
- The SDK does **not** modify your original error object.

## Browser support

Modern evergreen browsers + Node 18+ for SSR consumers (TypeScript types only — the runtime needs `window`, `navigator`, `fetch`, and `IndexedDB`).

## License

GNU Affero General Public License v3.0 (`AGPL-3.0-only`). See [LICENSE](../../LICENSE).

Copyright (C) 2026 DowarDev. SPDX-License-Identifier: AGPL-3.0-only.
