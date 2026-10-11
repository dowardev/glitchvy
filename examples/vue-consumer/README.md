# Vue 3 + Ionic consumer example

A small Vue 3 + Ionic app wired to the [`glitchvy`](../../packages/sdk) SDK. Use it as a copy-paste template when integrating glitchvy in your own Vue 3 + Ionic app.

## Run

```bash
pnpm install
pnpm dev   # opens on http://localhost:5181
```

The two buttons exercise the two flows:

- **Reportar este error** — manual `reportError(...)` from a `<ion-button>` (the typical "this broke, please ping us" path).
- **Trigger unhandled** — fires an unhandled exception, exercised through the SDK's auto-capture hooks (`window.onerror`).

## What this exercises

- Wiring `initErrorReporter({...})` in the Vue app's entry (`src/main.ts`).
- Calling `reportError(error, context)` from a Vue method, routed through `try { ... } catch (e) { await reportError(e, ctx) }`.
- Using the SDK framework-agnostically: the consumer owns the UI.

## License

GNU Affero General Public License v3.0.
Copyright (C) 2026 DowarDev. SPDX-License-Identifier: AGPL-3.0-only.
