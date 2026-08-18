# Step 5 Plan: `public/router.php` + `index.php` (router logic)

## Status

| Item | Status | Detail |
|---|---|---|
| Steps 1-4 | Done | Plan, git init, seed.php, src/db.php + helpers.php committed (3 commits). |
| `public/` directory | Not created | Does not exist yet. |
| `views/` directory | Not created | Does not exist yet. |
| Seeded data | Ready | 10 keywords, 300 positions. Today = 2026-08-16. |

## What this step builds

The routing infrastructure for the application: a PHP built-in server router script,
the application entry point with route dispatch and response helpers, and a minimal
shared layout template. All route handlers are **stubs** — the actual page rendering
and database operations come in steps 6-9.

## Files to create

| File | Purpose |
|---|---|
| `public/router.php` | PHP built-in server router: serves `/assets/*` directly, forwards everything else to `index.php`. |
| `public/index.php` | Application entry point: route parsing, dispatch, response helpers, handler stubs. |
| `public/assets/.gitkeep` | Preserve empty `assets/` directory in git (CSS/JS arrive in step 10). |
| `views/layout.php` | Shared HTML layout template (minimal, references `/assets/css/style.css`). |

## File-by-file plan

### `public/router.php`

The PHP built-in server calls this script for **every** request. It decides whether
to serve a static file or delegate to the application router.

```php
<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static assets from /assets/ — reject path traversal.
if (str_starts_with($path, '/assets/') && !str_contains($path, '..')) {
    $staticFile = __DIR__ . $path;
    if (is_file($staticFile)) {
        return false; // Let the built-in server serve the file.
    }
    http_response_code(404);
    echo 'Asset not found.';
    return;
}

// Forward all application requests to the entry point.
require __DIR__ . '/index.php';
```

**How it works:**
- `parse_url(..., PHP_URL_PATH)` strips the query string (e.g. `/?q=shoes` → `/`).
- `str_starts_with($path, '/assets/')` catches asset requests.
- `!str_contains($path, '..')` prevents directory traversal (e.g. `/assets/../index.php`).
- `return false;` tells PHP's built-in server to serve the file itself with the correct
  Content-Type.
- Everything else falls through to `require index.php`.

### `public/index.php`

The application entry point. Structure (top-to-bottom):

1. **Requires** `src/db.php` and `src/helpers.php`.
2. **Response helpers** — `sendNotFound`, `sendBadRequest`, `redirect`, `sendJson`, `renderPage`.
3. **Handler stubs** — one per route; return placeholder text. Replaced in steps 6-9.
4. **Request parsing** — extract method, path, and segments.
5. **Route dispatch** — match method + path to handler.

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

// --- Response helpers ---

function sendNotFound(string $message = 'Not Found'): void
{
    http_response_code(404);
    echo escape($message);
    exit;
}

function sendBadRequest(string $message = 'Bad Request'): void
{
    http_response_code(400);
    echo escape($message);
    exit;
}

function redirect(string $path): void
{
    // 303 See Other: correct status for POST -> GET redirect (PRG pattern).
    // $path must be a server-relative path starting with '/', never user-supplied.
    http_response_code(303);
    header('Location: ' . $path);
    exit;
}

function sendJson(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function renderPage(string $title, string $viewTemplate, array $data = []): void
{
    // extract() makes $data keys available as variables in the view.
    // EXTR_SKIP prevents overwriting $title.
    extract($data, EXTR_SKIP);

    // Capture the view template's HTML output.
    ob_start();
    require __DIR__ . '/../views/' . $viewTemplate;
    $content = ob_get_clean();

    // Render within the shared layout.
    require __DIR__ . '/../views/layout.php';
}

// --- Handler stubs (implemented in steps 6-9) ---

function handleList(): void
{
    // Step 6 (M4): query keywords, render views/keyword_list.php
    echo 'Keyword list (coming in step 6)';
}

function handleAddForm(): void
{
    // Step 7 (M1): render add form
    echo 'Add form (coming in step 7)';
}

function handleCreate(): void
{
    // Step 7 (M1): validate POST, INSERT keyword, redirect to /
    echo 'Create (coming in step 7)';
}

function handleDetail(int $id): void
{
    // Step 9 (M5): query positions for keyword, render detail page
    echo "Detail for keyword $id (coming in step 9)";
}

function handleEditForm(int $id): void
{
    // Step 7 (M1): query keyword, render edit form
    echo "Edit form for keyword $id (coming in step 7)";
}

function handleUpdate(int $id): void
{
    // Step 7 (M1): validate POST, UPDATE keyword, redirect
    echo "Update keyword $id (coming in step 7)";
}

function handleDelete(int $id): void
{
    // Step 7 (M1): DELETE keyword, redirect
    echo "Delete keyword $id (coming in step 7)";
}

function handleRefresh(): void
{
    // Step 8 (M3): generate today's positions, return JSON
    sendJson(['status' => 'not implemented']);
}

// --- Request parsing ---

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Split path into segments: "/keyword/12" -> ["keyword", "12"]
$segments = explode('/', trim($path, '/'));
$first = $segments[0] ?? '';
$second = $segments[1] ?? '';

// --- Route dispatch ---

if ($method === 'GET' && $path === '/') {
    handleList();
} elseif ($method === 'GET' && $path === '/add') {
    handleAddForm();
} elseif ($method === 'POST' && $path === '/add') {
    handleCreate();
} elseif ($method === 'GET' && $first === 'keyword' && ($id = validateIntId($second)) !== null) {
    handleDetail($id);
} elseif ($method === 'GET' && $first === 'edit' && ($id = validateIntId($second)) !== null) {
    handleEditForm($id);
} elseif ($method === 'POST' && $first === 'edit' && ($id = validateIntId($second)) !== null) {
    handleUpdate($id);
} elseif ($method === 'POST' && $first === 'delete' && ($id = validateIntId($second)) !== null) {
    handleDelete($id);
} elseif ($method === 'POST' && $path === '/refresh') {
    handleRefresh();
} else {
    sendNotFound();
}
```

**Key design decisions:**

1. **Route matching** uses method + path segments. Path parameters (keyword IDs)
   are extracted from `$segments[1]` and validated with `validateIntId()` from
   `src/helpers.php`. Invalid IDs (e.g. `/keyword/abc`) fall through to the 404
   handler — there is no route that accepts non-integer IDs.

2. **`validateIntId` on path params** — if the second segment is not a valid positive
   integer, `validateIntId()` returns `null`, the `elseif` condition is false, and
   the request falls through to `sendNotFound()`.

3. **PRG redirect pattern** — `redirect()` sends a 303 See Other (confirmed 303 with user).
   POST handlers will call this to redirect back to GET routes after a mutation,
   preventing duplicate form submissions on refresh.

4. **Single file for router + handlers** — no separate handler files or controller
   classes. Follows "Functions over classes" from AGENTS.md. All 8 routes and their
   stub handlers fit cleanly in one file. Handlers stay in `index.php` for now
   (confirmed with user); can be extracted if the file grows unwieldy.

5. **Handler stubs** return plain-text placeholders. Each handler's comment states
   which step and must-have requirement will implement it, so the path from stub
   to real implementation is obvious. When step 6 replaces `handleList()`, it swaps
   the one function body.

### `views/layout.php`

Minimal shared layout (created now because `renderPage()` depends on it).

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($title ?? 'MiniRank') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <header>
        <h1>MiniRank</h1>
    </header>
    <main>
        <?= $content ?>
    </main>
</body>
</html>
```

Notes:
- `$title` is escaped with `escape()` (from `helpers.php`).
- `$content` is raw HTML output from a view template — it is **not** escaped here
  because the view template is responsible for escaping its own values.
- `<meta name="viewport">` is included now for mobile support (M8, step 10).
- The CSS file does not exist yet — the browser silently ignores the 404; no errors.

### Directory structure

```
public/
    router.php         # PHP built-in server router
    index.php          # Application entry point + dispatcher
    assets/
        .gitkeep       # Preserve empty dir (CSS/JS in step 10)
views/
    layout.php         # Shared HTML layout
```

## Security considerations (M6)

| Concern | How step 5 addresses it |
|---|---|
| SQL injection | No SQL queries in the router. Database queries happen in handlers (added in steps 6-9), which will use prepared statements. |
| Path traversal | `router.php` rejects paths containing `..` for asset requests. `renderPage()` uses hardcoded view paths — no user input in `require`. |
| Open redirect | `redirect()` accepts only server-relative paths. Handlers pass hardcoded paths like `'/'`. |
| XSS | `sendNotFound()` and `sendBadRequest()` escape messages with `escape()`. `layout.php` escapes `$title`. View templates must escape all values with `escape()`. |
| Secrets | No `.env` or credentials. Only requires `src/db.php` which uses the `DB_PATH` constant pointing to gitignored `data/` directory. |

## How this enables steps 6-9

| Step | Requirement | What the router provides |
|---|---|---|
| Step 6 | M4 keyword list | Route `GET /` dispatches to `handleList()`. `renderPage()` + `views/layout.php` ready for `views/keyword_list.php`. |
| Step 7 | M1 CRUD | Routes for `GET/POST /add`, `GET/POST /edit/{id}`, `POST /delete/{id}` wired and validated. `redirect()` supports PRG pattern. |
| Step 8 | M3 AJAX refresh | Route `POST /refresh` dispatches to `handleRefresh()`. `sendJson()` ready for JSON response. |
| Step 9 | M5 keyword detail | Route `GET /keyword/{id}` dispatches to `handleDetail($id)` with validated integer ID. `renderPage()` + layout ready for `views/keyword_detail.php`. |

## Decisions confirmed with user

1. **Two-point trend comparison**: trend = today's position vs position from 7 days ago
   (confirmed in step 4, carried forward).
2. **`escape()` wrapper**: included in `helpers.php` (step 4), used by router and views.
3. **`redirect()` status code**: 303 See Other for POST→GET redirects (PRG pattern).
4. **`views/layout.php` in step 5**: yes, created now since `renderPage()` depends on it.
5. **Handlers in `index.php`**: all handler stubs live in `index.php`. Keep for now,
   extract later if needed.
6. **Path traversal behavior**: `router.php` returning `false` for assets lets PHP's
   built-in server serve the file or return its own 404 — expected and fine for dev.

## Verification (run after implementation)

```bash
# 1. Lint
php -l public/router.php
php -l public/index.php

# 2. Ensure DB is fresh
php seed.php
# expect: Seeded 10 keywords, 300 positions into data/minirank.sqlite

# 3. Start the server
php -S localhost:8000 -t public public/router.php &
```

Then verify with curl:

```bash
# 4. Stub responses (200)
curl -s http://localhost:8000/                          # "Keyword list (coming in step 6)"
curl -s http://localhost:8000/add                       # "Add form (coming in step 7)"
curl -s http://localhost:8000/keyword/4                 # "Detail for keyword 4 (coming in step 9)"
curl -s -X POST http://localhost:8000/refresh          # {"status":"not implemented"}

# 5. Invalid keyword ID -> 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/keyword/abc      # 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/keyword/0        # 404

# 6. Unknown route -> 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/nonexistent      # 404

# 7. Path traversal blocked
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/assets/../index.php  # 404
```

Stop the server with `kill %1`.

## Suggested commit

```bash
git add public/router.php public/index.php public/assets/.gitkeep views/layout.php
git commit -m "Add router: PHP built-in server script, index.php dispatch, and layout"
```

This commit only adds the routing infrastructure. No routes are fully implemented yet —
handlers return stubs. The commit message reflects what is actually in the diff.

## Files affected

- New: `public/router.php`
- New: `public/index.php`
- New: `public/assets/.gitkeep`
- New: `views/layout.php`
