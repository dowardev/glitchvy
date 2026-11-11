# Changelog

All notable changes to `glitchvy-admin` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- A strict Content-Security-Policy is injected into production builds (`script-src 'self'`, no inline script, `frame-ancestors 'none'`). The SPA shipped without one, so any XSS could run inline script and exfiltrate the admin session. The `connect-src` origin list is configurable with `VITE_GLITCHVY_CSP_CONNECT_SRC` for a deployment that serves the SPA from another host.
- The SPA authenticates with an httpOnly session cookie. `GET /api/glitchvy/csrf-cookie` bootstraps the readable XSRF token that every mutating request echoes back in the `X-XSRF-TOKEN` header.

- Initial release of the Vue 3 + Ionic 9 admin SPA.
- Login (Sanctum), project list, project detail with API keys, error list with level/status/search filters, and error detail.
- Error status workflow: `open` → `in_progress` → `resolved`, plus `ignored`, changed from the detail view.
- Error detail shows the stack trace, the breadcrumb trail, the captured context, the tags and any uploaded screenshots.
- API key management: create a key, copy its plaintext once, and list the project's existing keys with their prefix and last-used timestamp.
- Screenshot viewer with fullscreen and download.
- Pinia stores for auth, projects and errors; a typed API client; Vue Router 5 with an auth guard.

### Changed

- A mutating request that the API refuses with `419` (stale CSRF token) now bootstraps a fresh token and is retried once, instead of failing the action outright.
- The CSRF bootstrap no longer goes through the JSON request path: the endpoint answers `204` without a body (its effect is the cookie it sets), so reading a body from it was a contract mismatch.
- The admin token is no longer kept in `localStorage`. The session lives in an httpOnly cookie the browser attaches on its own, and the SPA restores the session from `GET /api/auth/me` on boot. A token in `localStorage` was readable by any XSS and survived the browser session.
- The page size is sent as `per_page`, the parameter the API reads. The SPA sent `limit`, which the API ignored, so the error list was always 20 rows.
- The error status is edited through an `ion-select` rather than an `ion-segment`. A segment is a navigation control, not a control for changing a single value.
- The admin SPA sticks to stock Ionic: no `<style>` blocks, only Ionic utility classes and components, and an inline style only where no Ionic utility exists (the dark backdrop behind a fullscreen screenshot).
- The project configuration files match the `ionic start` output. The non-stock additions (`@vitejs/plugin-legacy`, `eslint`, `eslint-plugin-vue`, `@vue/eslint-config-typescript`, `cypress`, `terser`) and the `test:e2e` and `lint` scripts that referenced them were removed.

### Fixed

- Creating a project from the admin SPA died with *"Failed to execute 'insertBefore' on 'Node': The node before which the new node is to be inserted is not a child of this node"*, and the page was left broken. Opening a modal makes Ionic move the `<ion-modal>` out of the `<ion-content>` and into the `<ion-app>` overlay container, so the element Vue had to insert the new list before was no longer a child of the content: the project list now keeps a single stable `<ion-list>` with the state inside it, and the modals live outside the `<ion-content>`.
- The project detail view rendered an unresolved `<ion-text>` component (it was used but never imported), and the error list and the error detail asked for the `bug-outline` and `alert-circle-outline` icons by name, so those icons were not drawn.

- The SPA could not navigate or read route parameters. `useRouter()` and `useRoute()` resolve to `undefined` inside a view rendered by the Ionic router outlet, because vue-router 5 resolves its injections from the `RouterView` component and the outlet renders the view outside that scope. A successful login left the page on `/login` with *"Cannot read properties of undefined (reading 'push')"*, and the error detail view threw *"Cannot read properties of undefined (reading 'params')"*. Navigation now goes through `useIonRouter()` and the route parameters arrive as props.
- The Sanctum token is now persisted alongside the user and sent with every authenticated request. It was discarded at login, so every call after it went out unauthenticated.
- The project list is a plain array again. The endpoint returned a cursor paginator, so the list rendered empty and creating a project failed inside the modal.
- The API key listing was a stub that emptied the list without calling the API; it now loads the project's keys.
- The build is green. `vue-tsc` rejected nine errors — `inset="true"` and `maxlength="100"` passing strings to boolean and number props, and an untyped router guard.

## [0.0.0]

> Not released.
