# Stretch Goals S1–S7 — Overarching Plan

> Companion to `INITIAL_PLAN.md`. Covers the optional stretch goals only (S1–S7). S8 (a hand-written `AGENTS.md` of project conventions) is out of scope — the repo's `AGENTS.md` is the assessment-provided file.
>
> The current codebase already implements all must-haves (M1–M8): keyword CRUD, seeded 30-day history, AJAX refresh, list + detail views, text search, parameterized queries, escaped output, responsive CSS, and a README. This plan assumes that working baseline.

## 0. Composed data model (S2 + S3 together)

S2 and S3 are planned **as one cohesive change**, not bolted on independently. The single-site / single-user model is replaced by a hierarchy:

```
users      id, email UNIQUE, password_hash, created_at
projects   id, user_id (FK→users, ON DELETE CASCADE), name, website, created_at
keywords   id, project_id (FK→projects, ON DELETE CASCADE), phrase, created_at
positions  id, keyword_id (FK→keywords, ON DELETE CASCADE), position CHECK(1..100), recorded_at
           UNIQUE(keyword_id, recorded_at)
```

Key changes from the current schema:
- New `users` table (S3).
- New `projects` table; the `website` column **moves off `keywords` onto `projects`** — it is no longer the single `SITE_URL` constant, it is per-project.
- `keywords.website` is **dropped**; `keywords.project_id` is added.
- `positions` is unchanged (still keyed by `keyword_id` + date).

Why together: S3 adds auth as the foundation layer (users own data); S2 reshapes data ownership (users → projects → keywords). Doing them separately would migrate the `keywords` table twice.

## 1. Routing scheme (path-based, confirmed)

Project context lives in the **path** (not a query param), so every keyword route is project-scoped. Auth routes sit at the root.

```
GET  /                         → redirect to the active project's keyword list
GET  /register                 → registration form
POST /register                 → create account
GET  /login                    → login form
POST /login                    → authenticate
POST /logout                   → end session (POST + CSRF)

GET  /project/add              → add-project form
POST /project/add              → create project (user-scoped)
GET  /project/{id}/edit        → edit-project form
POST /project/{id}/edit         → update project
POST /project/{id}/delete       → delete project
GET  /project/all              → project switcher / index (optional)

GET  /project/{id}             → keyword list for that project
GET  /project/{id}/add          → add-keyword form
POST /project/{id}/add          → create keyword (project-scoped)
GET  /project/{id}/keyword/{id}  → keyword detail
GET  /project/{id}/keyword/{kid}/export  → CSV download
POST /project/{id}/keyword/{kid}/refresh → AJAX refresh (per-project) — M3
GET  /project/{id}/keyword/{kid}/edit   → edit-keyword form
POST /project/{id}/keyword/{kid}/edit   → update keyword
POST /project/{id}/keyword/{kid}/delete → delete keyword
```

Router notes (per AGENTS "functions over classes," path-segment dispatch):
- Every `{id}` / `{kid}` segment is run through `validateIntId()` before the handler; non-integers fall through to 404.
- The project `{id}` is **ownership-checked**: a `SELECT ... WHERE id = ? AND user_id = ?` — if it doesn't belong to the logged-in user, 404 (not 403, to avoid leaking existence).
- `/` (bare root) reads the active project from the session-stored preference or redirects to the user's most recent project's list.
- The existing `keyword_detail.php` "Back" links and list links must be updated to emit the new project-prefixed paths.

## 2. Implementation ordering

| Step | Goal | Rationale |
|------|------|-----------|
| 1 | **S7** Docker | Pure infra (2 files), unblocks running everything else in Docker. No app-logic dependency. |
| 2 | **S1** Detail line chart | Self-contained view enhancement; zero schema change. |
| 3 | **S5** CSV export | Self-contained GET route on the detail page; pairs with S1. |
| 4 | **S3** Auth + CSRF | Foundation layer. Adds `users`, login/logout/register, sessions, CSRF tokens on every POST form. Applied to existing routes. |
| 5 | **S2** Projects (composed) | Reshapes schema (`projects`, `keywords.project_id`, drop `website`). Adds project CRUD + project-scoped routing. Must be after S3 so project forms already carry CSRF tokens. |
| 6 | **S4** Position/movement filters | Builds on the project-scoped `handleList`; adds a batched-trend helper (de-N+1). Must be after S2. |
| 7 | **S6** PHPUnit + seed main() guard | Schema is final; pure functions stable. Last, so the test bootstrap reflects the final model. |

Each step stays one logical feature. S2 is the one exception to the "3–4 files" soft guideline — it is cross-cutting — and should be split in-session into: (a) schema + helpers, (b) handlers + routes, (c) views + JS, committed as separate small diffs even though they form one feature.

## 3. S1 — Line chart on the keyword detail page (general approach)

**Decision:** hand-rolled inline SVG, no JS chart library. Respects the "vanilla JS, no build step" stack.

**General instructions:**
1. `handleDetail`: already builds `$history` (newest-first). Pass a compact `$positions = [[date, position], ...]` to the view; serialize it via `json_encode($positions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES)` into a `<script>` tag — safe embedding (defense-in-depth, per AGENTS "not even for values you believe are safe").
2. `views/keyword_detail.php`: insert an `<svg class="detail-chart" viewBox="0 0 600 250">` + the JSON script. Keep drawing logic out of the view (AGENTS: no logic in views/).
3. `public/assets/js/chart.js` (new): `drawChart(svg, points, width, height)`.
   - **Y-axis inverted** (the one non-obvious bit — document it): position 1 (best) at top, 100 at bottom.
   - Map dates to x (evenly spaced), positions to y (linear scale, inverted).
   - Render a `<polyline points="...">` + light axis ticks/labels. Re-run on `DOMContentLoaded`.
4. CSS: `.detail-chart { width: 100%; height: auto; max-width: 600px; }` — responsive.
5. No user input reaches `innerHTML`; positions are ints, dates are `Y-m-d`.

**Files affected:** ~3 (`public/index.php`, `views/keyword_detail.php`, `public/assets/js/chart.js`, `public/assets/css/style.css`).

## 4. S5 — CSV export of a keyword's position history (general approach)

**Decision:** a single GET route returning a streamed CSV download. GET is acceptable because it performs **no DB write** (the AGENTS rule forbids writes on GET, not reads). Auth-gated and project-scoped.

**General instructions:**
1. `handleExport(int $keywordId)`: reuse `handleDetail`'s history query (`ORDER BY recorded_at DESC, id DESC`). Set headers:
   - `Content-Type: text/csv`
   - `Content-Disposition: attachment; filename="..."` — **sanitize** the phrase into a slug (alphanumeric + spaces + hyphens only; strip everything else; fall back to `keyword-{id}` if empty). Prevents `Content-Disposition` header injection.
2. Stream rows via `fputcsv($output, [$date, $position, $trend])` to `php://output` — RFC 4180 escaping is automatic.
3. Route: `GET /project/{pid}/keyword/{kid}/export` → `handleExport($kid)`; guard with `validateIntId` on both path segments + ownership check on the project + `requireAuth()`.
4. `views/keyword_detail.php`: add an "Export CSV" link in the detail-actions area.

**Security note:** document *why* GET-with-download is safe here (read-only, no state change, auth-gated, project-owned). The reviewer may flag "GET returns a file" — preempt with the read-only rationale + auth guard in a comment.

**Files affected:** ~2-3 (`public/index.php` handler + route, `views/keyword_detail.php` link).

## 5. S7 — Docker setup (`docker compose up`) (general approach)

**Decision:** single-service PHP container with the built-in server + SQLite file. No MySQL service — that would require env-based DB config + credentials, and AGENTS forbids committed secrets. SQLite is the existing, recommended stack (no credentials needed).

**General instructions:**
1. `Dockerfile` (new, repo root): `FROM php:8-cli`; enable `pdo_sqlite` (`docker-php-ext-install pdo_sqlite` — verify it isn't already in the official CLI image; install if missing); `WORKDIR /app`; `EXPOSE 8000`. No `composer install` in the image (dev deps aren't needed to run).
2. `docker-compose.yml` (new): one service `web` — `build: .`, `ports: ["8000:8000"]`, host bind-mount `./data:/app/data` (keeps the SQLite file on the host, same as the non-Docker path), `command: php -S 0.0.0.0:8000 -t public public/router.php`.
3. `README.md`: add a "Docker" section under Setup: `docker compose up` → open `http://localhost:8000`.

**Files affected:** 3 (`Dockerfile`, `docker-compose.yml`, `README.md`).

## 6. S3 — User accounts + CSRF (general approach)

**Decision:** no Composer dependency. PHP's `password_hash`/`password_verify` + `session_*` cover auth; CSRF is a ~15-line session-bound token verified centrally.

**General instructions:**
1. **Schema:** `users(id, email UNIQUE, password_hash, created_at)` (DDL in `seed.php`).
2. **Seed (demo user):** `php seed.php` creates one demo account so the app is usable immediately: email `demo@example-shop.de`, password `minirank` — **hashed** with `password_hash(..., PASSWORD_DEFAULT)`. This is demo data, not a secret (AGENTS rule 3 is about real secrets/keys, not a seeded demo credential documented in the README). Document the demo credentials in the README "Setup" section.
3. **Helpers (`src/helpers.php`):**
   - `csrfToken(): string` — returns (or generates into `$_SESSION`) a 32-byte hex token.
   - `verifyCsrf(): void` — compares `$_POST['csrf_token']` to the session token with `hash_equals()`; 400 on mismatch.
   - `requireAuth(): void` — if `$_SESSION['user_id']` is unset, redirect to `/login`. Called for all data routes.
4. **`public/index.php`:**
   - `session_start()` at the very top (before any output).
   - CSRF check: for all POST requests except `/register`, `/login`, `/logout`... actually **include** logout (it's POST and state-changing). So: for all POST requests, call `verifyCsrf()` (the auth routes' forms also need the token). Simplest: verify CSRF for **every** POST, and ensure register/login/logout forms emit a token.
   - Auth gate: wrap data routes (everything except `/register`, `/login`, `/logout`, `/`) with `requireAuth()`. `/` redirects to the active project.
   - New handlers + routes: `handleRegisterForm/handleRegister`, `handleLoginForm/handleLogin`, `handleLogout` (POST + CSRF).
   - Input validation: email via `filter_var(FILTER_VALIDATE_EMAIL)`; password min-length 8; re-render form with error on invalid.
5. **`views/auth_form.php` (new):** shared register/login form (email + password + CSRF). `escape()` all output.
6. **Existing forms** (`keyword_form.php`, `keyword_list.php` refresh/delete, later `project_form.php`): add `<input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">`.
7. **`views/layout.php`:** add auth nav — show "Log out" when logged in, "Log in / Register" when not.

**Security (M6 + S3):**
- Passwords hashed (never stored/compared plaintext).
- CSRF on every state-changing POST.
- Session holds only `user_id` (no sensitive data).
- Login error messages generic ("Invalid email or password") — no user-enumeration.
- Logout is POST + CSRF (prevents `<img src="/logout">` logout-CSRF).

**Files affected:** ~6 (`seed.php`, `src/helpers.php`, `public/index.php`, `views/auth_form.php` new, `views/keyword_form.php`, `views/keyword_list.php`, `views/layout.php`).

## 7. S2 — Multiple projects, project CRUD (composed with S3) (general approach)

**Decision:** project-scoped path routing (`/project/{id}/keyword/{kid}`), project CRUD (add/edit/delete + website URL), demo user owns the seeded projects.

**General instructions:**
1. **Schema migration (in `seed.php`, re-run from empty):**
   - Add `projects(id, user_id, name, website, created_at)` with `ON DELETE CASCADE`.
   - Drop `keywords.website`; add `keywords.project_id FK→projects ON DELETE CASCADE`.
   - Keep `positions` unchanged.
   - The seed's `SITE_URL` constant is replaced by the `projects.website` column.
2. **Seed (demo data):** create the demo user (per S3), then 2–3 demo projects under that user (e.g. "Shop" / `example-shop.de`, "Blog" / `example-blog.com`), distribute the 10 demo keywords across them, 30 days of positions each. So after `php seed.php` the app is fully usable: log in as the demo user, see 2–3 projects, browse keywords.
3. **Helpers (`src/helpers.php`):** add `getActiveProject(PDO, int $userId): ?array` — reads `{id}` from the path, validates via `validateIntId`, checks ownership (`WHERE id = ? AND user_id = ?`), returns the project row or falls back to the user's most recent project. Sessions are NOT used to persist the active project (keeping routing stateless); the project id travels in the path.
4. **Handlers (`public/index.php`):**
   - All keyword handlers gain `int $projectId` from the path, validated + ownership-checked, and filter every query with `WHERE project_id = ?`.
   - New handlers + routes for project CRUD: `handleProjectAdd/Edit/Delete`, `handleProjectForm`. Project create/edit/delete are POST + CSRF + auth.
   - `/` redirects to the demo user's most recent project (`/project/{id}`).
5. **Views:**
   - `layout.php`: add a **project switcher** (a `<select>` or link list of the user's projects, GET-form to switch). Visible only when logged in.
   - New `views/project_form.php`: add/edit project (name + website + CSRF). Reuses `keyword_form.php` patterns.
   - `keyword_list.php` / `keyword_detail.php`: update all internal links to emit `/project/{pid}/...` paths; the "website" column reads from the project, not the keyword row.
6. **`refresh.js`:** the refresh fetches `/project/{pid}/keyword/{kid}/refresh`? No — refresh is per-project (refreshes all of the project's keywords). Update `refresh.js` to POST to `/project/{projectId}/refresh` (derive `projectId` from the current path). The server handler refreshes only that project's keywords.

**Ownership + 404 discipline:** every project-scoped route must `SELECT ... WHERE id = ? AND user_id = ?` and 404 (not 403) on no row, so a user cannot probe another user's project IDs.

**Files affected:** ~8 (cross-cutting): `seed.php`, `src/helpers.php`, `public/index.php`, `views/layout.php`, `views/keyword_list.php`, `views/keyword_detail.php`, `views/project_form.php` (new), `public/assets/js/refresh.js`, `public/assets/css/style.css`. Split in-session into (a) schema+helpers, (b) handlers+routes, (c) views+JS.

## 8. S4 — Filter by position range or movement (general approach)

**Decision:** position-range filter in SQL; movement filter in PHP via a **batched trend query** (this de-N+1's the list and is the real value-add of the step).

**General instructions:**
1. **Helpers (`src/helpers.php`):** add `getKeywordTrends(PDO, array $keywordIds): array` returning `[keywordId => 'improved'|'declined'|'stable'|null]`. One query: `SELECT position, recorded_at FROM positions WHERE keyword_id IN (...) AND recorded_at IN (?, ?) ORDER BY keyword_id, recorded_at ASC`, then `calculateTrend` per keyword in PHP. Leave `getKeywordTrend` (single) in place — used by detail page + refresh.
2. **Handler (`handleList`):** read `position_min` / `position_max` (validated ints 1–100, nullable) + `movement` (`improved|declined|stable`, nullable). Position range → `WHERE p.position >= ? AND p.position <= ?` (position already JOINed in the existing list query). Movement → filter the in-memory result set by the batched trend after computing it. Fetch trends in one batch, not per-row.
3. **View (`keyword_list.php`):** extend the filter area with a small "Filters" GET-form: two `<input type="number" min="1" max="100">` (min/max position) + a `<select>` for movement (`All` / improved / declined / stable). Preserve current values in the controls. Keep the existing text search `?q=` (project-scoped routes already carry `?project=` won't coexist now — project is in the path; search/filter are GET query params on the project-scoped list).
4. CSS: reuse `.search-form` styling; add minimal `.filter-form`.

**Security (M6):** all filter params validated/cast; SQL stays parameterized (range via two bound params, movement applied in PHP); filter values `escape()`'d into the form controls.

**Files affected:** ~4 (`src/helpers.php`, `public/index.php`, `views/keyword_list.php`, `public/assets/css/style.css`).

## 9. S6 — PHPUnit tests for core logic + seed main() guard (general approach)

**Decision:** PHPUnit via `composer.json` (require-dev). The brief names PHPUnit explicitly, and `.gitignore` already ignores `vendor/` + `.phpunit.cache/`.

**Two prerequisite refactors:**
1. **`seed.php` main() guard.** Today `main()` runs on `require`. Tests must `require seed.php` to reach `generateWalk`/`clamp` without side effects. Replace the trailing unconditional `main();` with a guard that only runs `main()` when the file is executed directly (CLI), not when required by a test:
   ```php
   if (php_sapi_name() === 'cli'
       && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
       main();
   }
   ```
   This is a standard, obvious PHP pattern (not cleverness). Document it as a deliberate testability change.
2. **PDO injection (already satisfied).** `getKeywordTrend(PDO, int)` already takes PDO — tests pass an in-memory SQLite. The test bootstrap builds a **minimal** schema (`keywords(id)`, `positions(keyword_id, position, recorded_at UNIQUE)`) — enough for trend tests, decoupled from the `projects`/`users` tables so the bootstrap stays stable regardless of S2/S3 schema churn.

**General instructions:**
1. `composer.json` (new): `{"require-dev": {"phpunit/phpunit": "^10.5"}}`.
2. `phpunit.xml` (new): bootstrap `tests/bootstrap.php`, suite dir `tests/`.
3. `tests/bootstrap.php` (new): `require src/db.php` + `src/helpers.php`; build `:memory:` PDO with the minimal schema above; expose it.
4. `tests/HelpersTest.php`: `calculateTrend` (improved/declined/stable incl. boundary 1→1, 100→100); `validateIntId` (valid/zero/negative/non-numeric); `validateString` (normal/empty/over-length); `escape` (quotes, `<script>`).
5. `tests/SeedTest.php`: `require_once seed.php`; test `clamp` (min/max/boundary) and `generateWalk` (30 entries, keys are `Y-m-d` ending today, all positions 1–100, stays in range).
6. `tests/TrendTest.php`: use the bootstrap's in-memory PDO; insert controlled positions (today + 7-days-ago) per keyword; assert `getKeywordTrend` returns `improved`/`declined`/`stable`/`null`.

**Note:** tests run on the host via `vendor/bin/phpunit`, not inside the Docker container (the S7 image doesn't install composer). Flag in README: `composer install && vendor/bin/phpunit`.

**Files affected:** ~6 (`composer.json`, `phpunit.xml`, `tests/bootstrap.php`, `tests/HelpersTest.php`, `tests/SeedTest.php`, `tests/TrendTest.php`, plus the `seed.php` guard).

## 10. Cross-cutting concerns

**CSRF (from S3) touches everything that follows:**
- S2 project forms, S4 has no new POST forms (filter is GET), S5 is GET (no token — read-only), S1 is view-only.
- The existing refresh/add/edit/delete forms gain their CSRF hidden field during the S3 step and keep it through all subsequent work.

**Auth gate (from S3):**
- S2, S4, S5, S1 (detail page) are all auth-gated and project-scoped. S7 is infra (no auth). S6 tests run in-process (no auth needed).

**Seeding:**
- S3 + S2 both modify `seed.php`'s schema and demo data. The final seed creates: demo user → 2–3 projects → 10 keywords → 300 positions. Run `php seed.php` after any schema step.

**Refresh scope:**
- Post-S2, `handleRefresh` refreshes only the active project's keywords (the JS posts to the project-scoped refresh route). Verify the JS path derivation in `refresh.js`.

## 11. Security checklist (M6 + S3) per goal

| Goal | SQL safety | Output escaping | Input validation | Secrets/auth |
|------|-----------|-----------------|------------------|--------------|
| S1 | None (view only) | N/A (SVG) | N/A | None |
| S5 | Reuses detail query (parameterized) | `fputcsv` (CSV auto-escapes) | `validateIntId` + ownership | Project-owned + auth |
| S7 | None (infra) | N/A | None | No `.env`; config in compose |
| S3 | New queries parameterized | `escape()` + auth views | Email validate, pw ≥8 | Passwords hashed; CSRF central |
| S2 | All keyword queries: `WHERE project_id = ?` (bound) | Views `escape()` | `validateIntId` + ownership check | Auth-gated (from S3) |
| S4 | Position range: `WHERE p.position >= ? AND <= ?` | Filter values `escape()`'d | `validateIntId` (1–100) + movement whitelist | Auth-gated (from S3) |
| S6 | In-memory test DB | N/A (CLI) | N/A | No secrets in test config |

**CSRF verification command** (run after S3): `curl -s -X POST http://localhost:8000/project/1/keyword/1/delete` with no `csrf_token` → expect HTTP 400.

## 12. Verification strategy (per step)

- `php -l` on every touched file.
- `php seed.php` after any schema change — confirm demo user + projects + keywords + positions are seeded and counts match.
- `vendor/bin/phpunit` green (after S6).
- `docker compose up` serves the app and `/assets/*` loads (after S7).
- Manual / `curl` checks per goal:
  - S1: detail page contains an `<svg class="detail-chart">` with a `<polyline>`.
  - S5: `Content-Type: text/csv`, `Content-Disposition: attachment`, row count = position count.
  - S3: unauthenticated access to `/project/1` redirects to `/login`; POST without CSRF → 400; register then log in.
  - S2: switching projects changes the keyword list; creating/deleting a project works; a project you don't own → 404.
  - S4: `?movement=improved` narrows the list; `?position_max=30` filters correctly.
- Security grep: `grep -RIn 'query(\|exec(.*SELECT\|WHERE.*\$.*\$' public/ src/` → no interpolated SQL; `grep -RIn 'prepare(' public/ src/` → all queries prepared.

## 13. Files touched (cumulative estimate)

| Goal | Files |
|------|-------|
| S7 | `Dockerfile`, `docker-compose.yml`, `README.md` |
| S1 | `public/index.php`, `views/keyword_detail.php`, `public/assets/js/chart.js` (new), `public/assets/css/style.css` |
| S5 | `public/index.php`, `views/keyword_detail.php` |
| S3 | `seed.php`, `src/helpers.php`, `public/index.php`, `views/auth_form.php` (new), `views/keyword_form.php`, `views/keyword_list.php`, `views/layout.php` |
| S2 | `seed.php`, `src/helpers.php`, `public/index.php`, `views/layout.php`, `views/keyword_list.php`, `views/keyword_detail.php`, `views/project_form.php` (new), `public/assets/js/refresh.js`, `public/assets/css/style.css` |
| S4 | `src/helpers.php`, `public/index.php`, `views/keyword_list.php`, `public/assets/css/style.css` |
| S6 | `composer.json`, `phpunit.xml`, `tests/bootstrap.php`, `tests/HelpersTest.php`, `tests/SeedTest.php`, `tests/TrendTest.php`, `seed.php` (main guard) |

## 14. Open items (resolve when detailing each step)

- S3: logout as POST + CSRF (confirmed by this plan) vs. a signed GET link. **Decision: POST + CSRF.**
- S2: `GET /project/all` project switcher UX — a `<select>` that auto-submits, or a list of links. **Decision deferred to S2 detail; prefer a small link list in the header for simplicity.**
- S2: editing a project's `website` after keywords exist — does it reassign keywords? **No**; `website` is metadata on the project only. Changing it is cosmetic. Document.
- S6: whether to also install composer in the S7 Docker image for in-container test runs. **Decision deferred; default is host-side `composer install && vendor/bin/phpunit`.**
