# Step 6 Plan: M4 Keyword List View (+ Search + Refresh Button)

## Status

| Item | Status | Detail |
|---|---|---|
| Steps 1-5 | Done | Plan, git init, seed.php, db.php + helpers.php, router + stubs + layout committed. |
| `handleList()` | Stub | Returns "Keyword list (coming in step 6)" in `public/index.php` lines 56-60. |
| `views/keyword_list.php` | Does not exist | To be created. |
| `public/assets/css/style.css` | Does not exist | Layout links to it. Will add minimal baseline in this step. |
| `public/assets/js/refresh.js` | Does not exist | To be created for the Refresh button. |
| Seeded data | Ready | 10 keywords, 300 positions. All have today's position + 7-day-ago position. Trends verified. |

## What This Step Builds

**Requirement M4:** Every keyword with its current position, a 7-day trend indicator (improved / declined / stable), and a text search.

**Requirement M3 (structural placement):** A "Refresh positions" button is placed on the
page and wired to `POST /refresh` via AJAX `fetch()`. The server handler (`handleRefresh`)
remains a stub returning `{"status":"not implemented"}` — the real position-generation
logic arrives in step 8. The JavaScript is written forward-compatible so that when step
8 swaps the handler for the real JSON response, no JavaScript changes are needed.

## Files to Create / Modify

| File | Action | Purpose |
|---|---|---|
| `public/index.php` | Modify | Replace `handleList()` stub (lines 56-60) with real implementation. |
| `views/keyword_list.php` | Create | View template: search form, refresh button, status element, keyword table. |
| `public/assets/css/style.css` | Create | Minimal baseline CSS (table, form, trend colors). Expanded in step 10 (M8). |
| `public/assets/js/refresh.js` | Create | JS: POST `/refresh` via fetch, update table rows in place, show inline status. |

This is 1 modification + 3 new files — within the 3-4 file limit per AGENTS.md.

## Data Contract

### Keyword List SQL (prepared, bound parameters)

Two query variants — with and without search:

```sql
-- No search term (q is empty or null):
SELECT k.id, k.phrase, k.website, p.position
FROM keywords k
LEFT JOIN positions p
  ON p.id = (
    SELECT id FROM positions
    WHERE keyword_id = k.id
    ORDER BY recorded_at DESC, id DESC
    LIMIT 1
  )
ORDER BY k.id ASC
```

```sql
-- With search (filters on both phrase and website):
SELECT k.id, k.phrase, k.website, p.position
FROM keywords k
LEFT JOIN positions p
  ON p.id = (SELECT id FROM positions WHERE keyword_id = k.id ORDER BY recorded_at DESC, id DESC LIMIT 1)
WHERE k.phrase LIKE ? OR k.website LIKE ?
ORDER BY k.id ASC
```

The `?` placeholders receive the same `%search%` value — never string-interpolated.

### `/refresh` JSON Contract (defines what step 8 will return)

```json
{
  "status": "ok",
  "updated": 10,
  "keywords": [
    {"id": 1, "phrase": "best running shoes", "position": 77, "trend": "improved"},
    ...
  ]
}
```

Step 6's `refresh.js` is written against this shape. When the payload has `status:"ok"`,
it updates each row's position and trend cell. When the stub returns `{"status":"not implemented"}`,
it shows an inline status message instead.

## File-by-File Plan

### `public/index.php` — replace `handleList()` stub

```php
function handleList(): void
{
    $pdo = getPdo();

    // Read + validate the search term (M6: treat $_GET as hostile).
    $searchTerm = validateString($_GET['q'] ?? null, 100);

    $sql = 'SELECT k.id, k.phrase, k.website, p.position
            FROM keywords k
            LEFT JOIN positions p
              ON p.id = (SELECT id FROM positions
                         WHERE keyword_id = k.id
                         ORDER BY recorded_at DESC, id DESC
                         LIMIT 1)';

    $params = [];
    if ($searchTerm !== null) {
        $sql .= ' WHERE k.phrase LIKE ? OR k.website LIKE ?';
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
    }

    $sql .= ' ORDER BY k.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $keywords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    renderPage('Keyword List', 'keyword_list.php', [
        'keywords'   => $keywords,
        'searchTerm' => $searchTerm,
    ]);
}
```

**Key decisions:**
- `validateString($_GET['q'] ?? null, 100)` — caps search at 100 chars (per STEP_4 spec),
  trims whitespace, returns `null` for empty/invalid. Null means "no search filter."
- The LIKE pattern uses **two bound parameters** (one for phrase, one for website) with
  the same `%search%` value. No SQL interpolation.
- `LEFT JOIN` via correlated subquery fetches the latest position per keyword.
- For each keyword, `getKeywordTrend($pdo, $id)` is called in the view to get the trend.
  N+1 is acceptable (11 queries for 10 keywords — negligible).

No other changes to `index.php`. The route dispatch (`GET /` → `handleList()`) already
exists from step 5.

### `views/keyword_list.php` — new view template

```php
<?php
/** @var array $keywords  Rows with keys: id, phrase, website, position */
/** @var string|null $searchTerm */
?>

<div class="list-header">
    <form method="get" action="/" class="search-form">
        <input type="search" name="q"
               value="<?= escape((string)($searchTerm ?? '')) ?>"
               maxlength="100" placeholder="Search keywords...">
        <button type="submit">Search</button>
        <?php if ($searchTerm !== null): ?>
            <a href="/" class="clear-search">Clear</a>
        <?php endif; ?>
    </form>

    <button type="button" id="refresh-btn" class="refresh-btn">Refresh positions</button>
</div>

<div id="refresh-status" class="refresh-status" style="display: none;"></div>

<?php if (count($keywords) === 0): ?>
    <p class="empty-state">No keywords found.</p>
<?php else: ?>
    <table class="keyword-table">
        <thead>
            <tr>
                <th>Keyword</th>
                <th>Website</th>
                <th>Position</th>
                <th>7-day trend</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($keywords as $kw): ?>
                <?php
                    $id       = (int) $kw['id'];
                    $phrase   = escape($kw['phrase']);
                    $website  = escape($kw['website']);
                    $position = (int) $kw['position'];
                    $trend    = getKeywordTrend($pdo, $id);
                    $trendClass = $trend ?? 'stable';
                ?>
                <tr data-keyword-id="<?= $id ?>">
                    <td class="keyword-phrase">
                        <a href="/keyword/<?= $id ?>"><?= $phrase ?></a>
                    </td>
                    <td class="keyword-website"><?= $website ?></td>
                    <td class="keyword-position"><?= $position ?></td>
                    <td class="keyword-trend">
                        <span class="trend <?= $trendClass ?>">
                            <?= $trend !== null ? $trend : 'no data' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script src="/assets/js/refresh.js"></script>
```

**Escaping discipline (M6):**
- `escape($kw['phrase'])` — user data from DB, must escape.
- `escape($kw['website'])` — user data from DB, must escape.
- `escape((string)($searchTerm ?? ''))` — search term echoed back into input value.
- `$id` and `$position` are `(int)` cast from DB — safe in attributes and URL.
- `$content` in `layout.php` is raw HTML (trusted markup); individual values escaped above.

**Trend CSS classes:** `trend improved`, `trend declined`, `trend stable`.
The JS uses these class names when updating rows after a refresh.

### `public/assets/css/style.css` — minimal baseline

Just enough for readability. Full responsive design comes in step 10 (M8).

```css
/* Minimal baseline — expanded in step 10 (M8 responsive CSS) */

.keyword-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
}
.keyword-table th,
.keyword-table td {
    border: 1px solid #ddd;
    padding: 0.5rem;
    text-align: left;
}
.keyword-table thead th {
    background: #f4f4f4;
}
.list-header {
    display: flex;
    gap: 1rem;
    align-items: center;
    margin-bottom: 1rem;
}
.search-form {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.search-form input {
    padding: 0.4rem;
    width: 200px;
    border: 1px solid #ccc;
    border-radius: 3px;
}
.search-form button {
    padding: 0.4rem 0.8rem;
    cursor: pointer;
}
.clear-search {
    font-size: 0.85rem;
}
.refresh-btn {
    padding: 0.4rem 1rem;
    cursor: pointer;
}
.refresh-btn:disabled {
    opacity: 0.6;
    cursor: default;
}
.refresh-status {
    padding: 0.5rem;
    margin-bottom: 1rem;
    border-radius: 3px;
}
.status-ok { background: #d4edda; color: #155724; }
.status-error { background: #f8d7da; color: #721c24; }
.trend.improved { color: #1a7f37; }  /* green */
.trend.declined { color: #cf222e; }  /* red */
.trend.stable { color: #6a737d; }    /* gray */
.empty-state { color: #6a737d; }
```

### `public/assets/js/refresh.js` — Refresh button AJAX with inline status

```js
/* Refresh positions button — POST /refresh, update table rows in place.
   Forward-compatible with step 8's response format.
   Uses inline status text instead of alert dialogs. */

document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('refresh-btn');
    var statusEl = document.getElementById('refresh-status');
    if (!btn || !statusEl) return;

    function showStatus(msg, isError) {
        statusEl.textContent = msg;
        statusEl.style.display = 'block';
        statusEl.className = 'refresh-status ' + (isError ? 'status-error' : 'status-ok');
    }

    function hideStatus() {
        statusEl.style.display = 'none';
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        showStatus('Refreshing...', false);

        fetch('/refresh', { method: 'POST' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'ok' && data.keywords) {
                    data.keywords.forEach(function (kw) {
                        var row = document.querySelector('tr[data-keyword-id="' + kw.id + '"]');
                        if (!row) return;
                        var posCell = row.querySelector('.keyword-position');
                        var trendCell = row.querySelector('.keyword-trend');
                        if (posCell) posCell.textContent = kw.position;
                        if (trendCell) {
                            var label = kw.trend || 'stable';
                            trendCell.innerHTML = '<span class="trend ' + label + '">' + label + '</span>';
                        }
                    });
                    showStatus('Updated ' + data.updated + ' keywords.', false);
                } else {
                    showStatus('Refresh not available yet.', false);
                }
            })
            .catch(function () {
                showStatus('Error contacting server.', true);
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'Refresh positions';
            });
    });
});
```

**Forward compatibility notes:**
- When step 8 returns `{"status":"ok","updated":N,"keywords":[...]}`, the JS
  updates positions and trends in place — no page reload. This satisfies M3.
- The stub `{"status":"not implemented"}` triggers the `else` branch showing
  inline status text "Refresh not available yet." No errors in console.
- Uses `querySelector` with `data-keyword-id` — safe since these are server-rendered
  integer IDs (not user-supplied raw input).

## Security (M6 Compliance)

| Concern | How step 6 addresses it |
|---|---|
| SQL injection | `LIKE ?` with bound params. No string interpolation anywhere in SQL. All queries use `prepare()` + `execute()`. |
| XSS | All values from `$kw['phrase']`, `$kw['website']`, `$searchTerm` wrapped in `escape()`. IDs/positions are `(int)` cast. |
| Input validation | `$_GET['q']` validated via `validateString(..., 100)` — trims, enforces max length, returns `null` for invalid. |
| Path traversal | No file paths derived from user input. `renderPage()` uses hardcoded template names. |
| Secrets | No new secrets. Uses existing `DB_PATH` and `SITE_URL` constants. |

## How This Enables Steps 7-9

| Step | Need | Satisfied by step 6 |
|---|---|---|
| Step 7 (M1 CRUD) | `renderPage()` + `redirect()` + view pattern | `keyword_list.php` demonstrates the view pattern; `redirect()` exists. |
| Step 8 (M3 AJAX) | `/refresh` route + `sendJson()` + table structure | `refresh.js` is forward-compatible; table rows have `data-keyword-id` + class hooks. |
| Step 9 (M5 detail) | `/keyword/{id}` route + layout | Layout and routing already wired. |

## Verification (run after implementation)

```bash
# 1. Lint all files
php -l public/index.php
php -l views/keyword_list.php
php -l public/assets/js/refresh.js 2>/dev/null || true  # JS lint if tool available

# 2. Ensure DB is fresh
php seed.php
# expect: Seeded 10 keywords, 300 positions into data/minirank.sqlite

# 3. Start the server
php -S localhost:8000 -t public public/router.php &

# 4. List page shows all 10 keywords
curl -s http://localhost:8000/ | grep -c 'data-keyword-id'
# expect: 10

# 5. Search filters results (keyword "keyboard" matches 1 phrase)
curl -s "http://localhost:8000/?q=keyboard" | grep -c 'data-keyword-id'
# expect: 1

# 6. Search with no match shows empty state
curl -s "http://localhost:8000/?q=zzz_nonexistent" | grep -c 'No keywords found'
# expect: >= 1

# 7. Refresh button POST hits stub (returns stub JSON, not 500)
curl -s -X POST http://localhost:8000/refresh
# expect: {"status":"not implemented"}

# 8. CSS is served (no 404)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/assets/css/style.css
# expect: 200

# 9. Invalid keyword detail still 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/keyword/abc
# expect: 404

# 10. Oversize search term rejected (returns no filter, not error)
curl -s "http://localhost:8000/?q=$(php -r 'echo str_repeat("x", 101);')" | grep -c 'data-keyword-id'
# expect: 10 (search ignored, all keywords shown)

# Stop the server
kill %1
```

## Example Commit Message

```
M4: implement keyword list with search and Refresh button
```

## Files Affected

- Modified: `public/index.php` (replace `handleList()` stub, ~4 lines → ~30 lines)
- New: `views/keyword_list.php`
- New: `public/assets/css/style.css` (minimal baseline; step 10 expands for M8)
- New: `public/assets/js/refresh.js` (Refresh button AJAX; forward-compatible with step 8)

## Decisions Applied From User Feedback

1. **CSS baseline ships in step 6** (per user preference) — minimal `style.css` so the page is readable; step 10 expands for full responsiveness (M8).
2. **Inline status text** replaces alert dialogs — a `<div id="refresh-status">` element shows "Refreshing...", "Updated N keywords", "Refresh not available yet", or "Error contacting server" with appropriate styling.
3. **Search filters on both `phrase` and `website`** — `WHERE k.phrase LIKE ? OR k.website LIKE ?` with the same `%search%` value bound to both placeholders.
