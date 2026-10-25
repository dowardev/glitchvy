# Changelog

All notable changes to the `glitchvy` SDK are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial release of the framework-agnostic TypeScript SDK.
  - `initErrorReporter(config)` — bootstraps the reporter. Idempotent.
  - `reportError(err, context?, level?)` — reports a thrown value. Never throws: on a terminal failure the event is queued instead.
  - `setUserContext(partial)` / `clearUserContext()` — user context attached to every subsequent report.
  - `addBreadcrumb(message, data?)` — appends to the trail carried by the next reports.
  - `flush()` — force-flushes the offline queue. `getQueueSize()` — number of queued events.
  - `shutdown()` — tears down the auto-capture hooks and resets the state.
- Auto-capture of `window.onerror`, `unhandledrejection` and `console.error`, each individually toggleable through `capture`.
- Two-layer PII scrub:
  - A global set of default keys (passwords, tokens, Authorization headers and similar) redacted wherever they appear.
  - Consumer-supplied `redactFields`, applied only inside the subtrees named by `redactContexts`.
  - Bearer tokens found in string values are masked.
- Offline queue backed by IndexedDB, flushed on the `online` event, with exponential-backoff retry (`maxRetries`, `retryBaseMs`).
- Dual ESM + CJS build with TypeScript declarations shipped in the package.

### Changed

- `ReportEvent.route` is now typed `string` rather than `string | null`. `readRoute()` always returns a string, falling back to `'/'` when there is no window, no location, or no pathname — the nullable type described a case the runtime cannot produce.

### Removed

- `ScreenshotMeta`. It was exported from the public API surface but referenced nowhere in the source or the tests, and described a screenshot-upload feature the SDK does not implement.

## [0.0.0]

> Not released.
