# Changelog

All notable changes to the `glitchvy` SDK are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-27

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
- `getQueueStatus()` — `{ size, readable, error? }` for the offline queue. `readable: false` means IndexedDB could not be read, something `getQueueSize()` cannot express (it answers `0` for both "empty" and "unreadable").
- Reports made before `initErrorReporter()` are buffered (up to 20) and sent as soon as the SDK is initialized, instead of being dropped.

### Changed

- `ReportEvent.route` is now typed `string` rather than `string | null`. `readRoute()` always returns a string, falling back to `'/'` when there is no window, no location, or no pathname — the nullable type described a case the runtime cannot produce.

### Removed

- `ScreenshotMeta`. It was exported from the public API surface but referenced nowhere in the source or the tests, and described a screenshot-upload feature the SDK does not implement.

### Fixed

- Bearer tokens are redacted again. The redaction pattern only matched `[A-Za-z0-9._-]`, so a real JWT or base64 token (with `+`, `/` and `=`) was not masked and could reach the dashboard; the keyword check was also case-sensitive while the pattern was not.
- The offline queue cannot grow past `maxQueueSize` while flushing: re-queued failures are checked against the cap.
- A queued event is retried with the configured `maxRetries` / `retryBaseMs` when the queue is flushed, instead of a single attempt.
- A payload above the ~64KB `keepalive` limit is trimmed (oldest breadcrumbs first) instead of failing silently in the browser.
- A queue flush runs under a wall-clock budget; the events it could not attempt go back to the queue, so nothing is lost.
- A non-integer `entityId` is preserved as `entity_id_raw` in the context data instead of being dropped.
- A thrown non-Error value with a circular reference reports its own keys instead of `[object Object]`.
- The SDK now posts the payload shape the ingestion endpoint accepts. It sent a nested camelCase event while the endpoint validates a flat snake_case contract, so every report was rejected with HTTP 422 and nothing was ever ingested.
- `event_id` is a valid UUID even when `crypto.randomUUID` is unavailable (it is missing in non-secure contexts).

### Changed

- The length-limited fields of the ingest payload are truncated to the limits the endpoint enforces (`environment`, `app_version`, `route`, `error_name`, `error_type`, `entity_type`, `operation`).

