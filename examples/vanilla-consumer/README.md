# Vanilla consumer example

A small HTML+JS demo that wires the [`glitchvy`](../../packages/sdk) SDK without any framework. Useful as a copy-paste template for your own setup.

## Run

```bash
pnpm install
pnpm dev   # opens on http://localhost:5180
```

Then open the page and:

- Click **Report a bug (silent)** to trigger a manual `reportError(...)`.
- Click **Trigger a window error** to fire an unhandled error that the SDK auto-captures via `window.onerror`.

## What this exercises

- `initErrorReporter({...})` with `ingestionUrl`, `apiKey`, `appVersion`, `environment`, `scrubRules`.
- `setUserContext({ userId })`.
- `addBreadcrumb(message)` — manual trail.
- `reportError(error, context)` — manual reporting.
- Auto-capture of `window.onerror` (the second button triggers an unhandled exception).

## License

GNU Affero General Public License v3.0.
Copyright (C) 2026 DowarDev. SPDX-License-Identifier: AGPL-3.0-only.
