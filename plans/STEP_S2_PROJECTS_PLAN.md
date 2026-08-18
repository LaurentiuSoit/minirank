# S2 — Multiple Projects (composed) Implementation Plan

Companion to `STRETCH_GOALS_PLAN.md` §7. S2 reshapes the single-site/single-user
model into a hierarchy: **users → projects → keywords → positions**.

Prerequisite: S3 (auth + CSRF) is already implemented. S2 builds on S3's session
helpers and CSRF tokens.

## Current state (pre-S2)

- `seed.php`: schema has `users`, `keywords(website)`, `positions`. No `projects` table.
- `src/db.php`: defines `SITE_URL` constant (no longer needed after S2).
- `public/index.php`: routing is flat (`/add`, `/keyword/{id}`, `/edit/{id}`, …).
- Views use old non-project-scoped paths. `keyword_list.php` shows a "Website" column.
- `refresh.js` posts to `/refresh`.

## Data model (post-S2)

```
users      id, email UNIQUE, password_hash, created_at
projects   id, user_id (FK→users, ON DELETE CASCADE), name, website, created_at
keywords   id, project_id (FK→projects, ON DELETE CASCADE), phrase, created_at
positions  id, keyword_id (FK→keywords, ON DELETE CASCADE), position CHECK(1..100),
           recorded_at, UNIQUE(keyword_id, recorded_at)
```

- `keywords.website` is **dropped**; `projects.website` replaces it.
- `positions` is unchanged.

## Decision log

1. **Drop the per-row "Website" column** from `keyword_list.php` — all keywords in
   a list share the same project. Show "Project: {name} — {website}" in the page
   header instead.
2. **Root redirect** (`/`): redirect to the user's most-recently-created project
   (`ORDER BY id DESC LIMIT 1`). If the user has zero projects, redirect to
   `/project/add`.
3. **Refresh.js** reads `projectId` from a `data-project-id` attribute set on the
   refresh button in `keyword_list.php`.
4. **Project switcher**: a `<select>` in the header (GET form, auto-submit on
   change), visible only when logged in.
5. **Project delete**: POST + CSRF + `confirm()` dialog; cascades to keywords and
   positions via `ON DELETE CASCADE`.

## Section A — Schema + helpers

### `src/db.php`
- Remove `SITE_URL` constant.

### `seed.php`
- `createSchema()`: add `projects` table; rewrite `keywords` table (drop `website`,
  add `project_id`); keep `positions`.
- New `insertProject(PDO, int $userId, string $name, string $website): int`.
- Change `seedKeyword(PDO, int $projectId, string $phrase)` — insert with
  `project_id`, 7 params (drop `website`).
- `main()`: create demo user, then 2 demo projects ("Shop" / `example-shop.de`,
  "Blog" / `example-blog.com`), distribute 10 keywords across them (5 each),
  30 days of positions each.

### `src/helpers.php`
- `getProjectForUser(PDO, int $projectId, int $userId): ?array` — ownership check.
- `getLatestProjectForUser(PDO, int $userId): ?array` — for `/` redirect.
- `getUserProjects(PDO, int $userId): array` — for the switcher.

## Section B — Handlers + routes

### Routing (public/index.php)
Rewrite the if/elseif dispatch to be project-path-aware.

```
/                        → redirect to /project/{latestId} (or /project/add)
/project/add             → GET form | POST create
/project/{pid}           → GET keyword list
/project/{pid}/add       → GET add-keyword form | POST create keyword
/project/{pid}/edit      → GET edit-project form | POST update project
/project/{pid}/delete    → POST delete project
/project/{pid}/refresh   → POST refresh all keywords in project
/project/{pid}/keyword/{kid}           → GET detail
/project/{pid}/keyword/{kid}/export    → GET CSV
/project/{pid}/keyword/{kid}/edit      → GET form | POST update
/project/{pid}/keyword/{kid}/delete    → POST delete
```

### Handler changes
Each keyword handler gains an ownership-checked `int $projectId` parameter and
adds `WHERE ... project_id = ?` to its SQL. New project CRUD handlers added.

## Section C — Views + JS + CSS

### Views
- `layout.php`: project switcher in header.
- `views/project_form.php` (new): project add/edit form.
- `keyword_list.php`: project-scoped links; drop Website column; add page header;
  add `data-project-id` to refresh button.
- `keyword_detail.php`: project-scoped back/export/edit/delete links; `$project['website']`.
- `keyword_form.php`: project-scoped formAction + cancel link.

### JS
- `refresh.js`: `fetch('/project/' + projectId + '/refresh', …)`.

### CSS
- `.project-switcher` rules — minimal, reuse existing button styles.

## Security checklist (S2-specific)
- All new queries parameterized (bound `?`).
- All path IDs run through `validateIntId()` before use.
- Project ownership checked via `WHERE id = ? AND user_id = ?` → 404 on miss.
- All new POST forms emit CSRF token (already enforced globally for POST).
- All new output escaped with `escape()`.

## Verification (user runs these)
- `php -l` on every touched file.
- `php seed.php` — confirm demo user, 2 projects, 10 keywords, 300 positions.
- App starts; login as demo user; see 2 projects; switch projects; keyword list
  scoped; create/edit/delete project works; 404 on foreign project ID.
