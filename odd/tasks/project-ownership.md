# Project ownership in the admin API (GitLab issue #2)

Add a user relation to `projects` and scope every admin project endpoint by the
authenticated admin, so one admin cannot read or write another admin's projects.

## Specs

- **S1** — The `projects` table gets a nullable `user_id` column with a foreign key to
  `users` (`nullOnDelete`), plus an index on it.
- **S2** — The migration assigns every project that already exists to the first admin
  user (the lowest `users.id`), so no existing project is left ownerless after the
  upgrade.
- **S3** — A project is visible to an admin when `user_id` equals that admin's id or
  when `user_id` is null (ownerless projects stay visible to every admin).
- **S4** — `POST /api/glitchvy/projects` stores the authenticated admin as the owner of
  the new project.
- **S5** — `GET /api/glitchvy/projects` lists only the projects visible to the
  authenticated admin.
- **S6** — `GET /api/glitchvy/projects/{id}/api-keys` and
  `POST /api/glitchvy/projects/{id}/api-keys` answer `404` when the project is not
  visible to the authenticated admin.
- **S7** — The admin SPA project list and API key listing are scoped by the same
  relation, because both read the scoped admin API.
- **S8** — `php artisan glitchvy:create-project` accepts `--user=<email>` and defaults to
  the first admin user; with no admin users it creates an ownerless project and says so.
- **S9** — `php artisan glitchvy:create-api-key` only offers the projects visible to the
  operator and accepts `--user=<email>` to pick another admin.
- **S10** — The behaviour is pinned by backend tests and documented in `back/README.md`,
  `back/CHANGELOG.md` and the root `CHANGELOG.md`.

## Tasks

- **T1** (S1, S2, S3) — Migration `add_user_id_to_projects`, `Project::user()` /
  `User::projects()` relations and the visibility scope on `Project`. Commit: `feat(back): add project ownership relation`.
- **T2** (S4, S5, S6) — Scope `AdminProjectController` (index, store, apiKeys,
  createApiKey) by the authenticated admin. Commit: `feat(back): scope the admin project API by owner`.
- **T3** (S8, S9) — `glitchvy:create-project` and `glitchvy:create-api-key` honour the
  owner. Commit: `feat(back): scope the project CLI commands by owner`.
- **T4** (S7, S10) — Tests for the ownership rules plus the documentation. Commit:
  `test(back): pin the project ownership rules` and `docs(back): document project ownership`.

## Log

- **L1** — "vamos con #2" (the user picked GitLab issue #2 out of the open issue list).
- **L2** — The user decided that the projects that already exist without an owner are
  assigned to the operator: "Asignarlos al operador" — the migration assigns them to the
  first admin user.
- **L3** — The residual finding: the admin error-report endpoints
  (`/api/glitchvy/errors*`) are not scoped by ownership, so a second admin would still
  read every error report of every project. Out of the scope of issue #2, tracked as a
  follow-up issue.
- **L4** — Commits: `849f59f` (the behavior and its tests) and `018eedb` (the docs and
  this document). The behavior commit carries 451 authored lines, slightly over the
  ~400-line reviewable slice, because the tests of the ownership rules belong with the
  behavior they pin; the delivery is one feature and one MR.
- **L5** — The backend suite is green: 77/77 PHPUnit (322 assertions). The first cut of
  the tests hit two problems worth keeping in mind: the admin project endpoints live
  under `/api/projects` (the `glitchvy` prefix belongs to the ingest and error routes),
  and the SQLite rollback of the new migration cannot drop the column while the index
  still references it, so `down()` drops the index first.
- **L6** — Delivery: draft merge request !10 (`feat(back): scope the admin project API by
  owner`) on the branch `feat/project-ownership`, and the follow-up issue #7 for the
  error-report endpoints that stay unscoped by ownership.
- **L7** — The native review cycle (`review-24a936a4f19ed58a`, tier medium, 12 paths / 540 changed lines) captured the risk, readability and reliability lenses plus the provider refuter, and closed `escalated` with the causality of two findings unknown. Both were inspected against the code and refuted:
  - `R3-2` (the API key command would pass a null owner to `visibleTo()` and reach only the ownerless projects): `CreateApiKeyCommand` branches explicitly (`$owner === null ? Project::query() : Project::visibleTo($owner)`), so it already offers every project when there is no admin. Same root as `R3-1`, which the provider refuted.
  - `R3-3` (the create project command warns and still leaves the project ownerless): that is the documented fallback, and the finding itself says it is the intended behavior of S3.

  The non-blocking findings were worth taking: the owner resolution was duplicated in the two commands, the "operator" rule lived in three places, and one comment was misleading. `User::operator()` and the `ResolvesProjectOwner` trait are now the single source, and the comment says what the code does. The backend suite stays green: 78/78 PHPUnit.
