# Step 9 Plan: M5 Keyword Detail Page (History Table)

## Status

| Item | Status | Detail |
|---|---|---|
| Steps 1–8 | Done | Full app implemented and committed. |
| `/keyword/{id}` route | Wired | `public/index.php:298-299` — `GET /keyword/{id}` → `handleDetail($id)` with `validateIntId()` guard. |
| `handleDetail()` | Stub | `public/index.php:138-142` — echoes placeholder text. |
| `views/keyword_detail.php` | Does not exist | To be created. |
| List page links | Present | `views/keyword_list.php:49` — keyword phrase links to `/keyword/{id}`. |
| `getKeywordTrend()` | Available | `src/helpers.php:38` — 7-day trend, returns `'improved'|'declined'|null`. |
| `calculateTrend()` | Available | `src/helpers.php:27` — compares two positions → `'improved'|'declined'|'stable'`. |
| History data | Ready | 30 days × 10 keywords = 300 rows, `recorded_at` is `Y-m-d`. |

## What This Step Builds

**Requirement M5:** A keyword detail page showing the full position history for one keyword.

The page displays:
1. **Keyword header** — phrase, website, tracking-since date, "Back to keywords" link.
2. **Current summary** — latest position + 7-day trend (via `getKeywordTrend()`), consistent with the list page's trend column.
3. **History table** — all 30 position records (newest first), each with a day-over-day trend.
4. **Actions** — Edit link + Delete form (POST with JS `confirm()`), reusing patterns from `keyword_list.php`.

The route, link from list page, DB schema, and helper functions are all already in place. Step 9 replaces the stub and creates the view.

## Files Affected

| File | Action | Purpose |
|---|---|---|
| `public/index.php` | Modify | Replace `handleDetail()` stub (lines 138–142). |
| `views/keyword_detail.php` | **Create** | Detail view: header, summary, history table, actions. |
| `public/assets/css/style.css` | Modify | Add minimal detail-specific styles (header, summary, actions). |

3 files. The CSS change is additive and small; no existing styles are removed or changed. Within the AGENTS.md 3-4 file guideline.

## Handler Implementation

### `handleDetail(int $id)` — replaces `index.php:138-142`

```php
function handleDetail(int $id): void
{
    $pdo = getPdo();

    // 1. Fetch keyword info + verify existence (404 if not found).
    $stmt = $pdo->prepare(
        'SELECT id, phrase, website, created_at FROM keywords WHERE id = ?'
    );
    $stmt->execute([$id]);
    $keyword = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($keyword === false) {
        sendNotFound('Keyword not found.');
    }

    // 2. Fetch full position history, newest first.
    $stmt = $pdo->prepare(
        'SELECT position, recorded_at
         FROM positions
         WHERE keyword_id = ?
         ORDER BY recorded_at DESC, id DESC'
    );
    $stmt->execute([$id]);
    $positionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Compute day-over-day trend per row.
    //    Newest-first ordering: each row's trend compares against the
    //    next row in the array (the previous day chronologically).
    $history = [];
    for ($i = 0; $i < count($positionRows); $i++) {
        $currentPosition = (int) $positionRows[$i]['position'];
        $previousPosition = isset($positionRows[$i + 1])
            ? (int) $positionRows[$i + 1]['position']
            : null;

        $history[] = [
            'date'     => date('M j, Y', strtotime($positionRows[$i]['recorded_at'])),
            'position' => $currentPosition,
            'trend'    => $previousPosition !== null
                ? calculateTrend($currentPosition, $previousPosition)
                : null,
            'hasTrend' => $previousPosition !== null,
        ];
    }

    // 4. Current 7-day trend (today vs 7 days ago) — consistent with list page.
    $currentTrend = getKeywordTrend($pdo, (int) $keyword['id']) ?? 'stable';

    renderPage('Keyword Detail', 'keyword_detail.php', [
        'keyword'         => $keyword,
        'history'         => $history,
        'currentPosition' => count($history) > 0 ? $history[0]['position'] : null,
        'currentTrend'    => $currentTrend,
    ]);
}
```

### Design decisions

1. **Two queries, both parameterized.** One fetch for the keyword row (verifies existence + gets metadata), one for the position history. Same prepared-statement pattern as `handleList()`.

2. **Day-over-day trend computed in the handler, not the view.** The AGENTS rule says "Do not put SQL or business logic in views/." The view receives a fully-prepared `$history` array where each entry already has `trend` and `hasTrend` fields. This mirrors how `handleList()` now computes trends server-side (see `index.php:83-93` comment).

3. **Newest-first ordering.** `ORDER BY recorded_at DESC, id DESC` puts the latest position at the top of the table — the user sees the current situation immediately. The secondary `id DESC` tiebreaker handles the (theoretical) case where multiple rows share the same date, though the `UNIQUE(keyword_id, recorded_at)` constraint prevents that.

4. **Trend direction semantics.** `calculateTrend($currentPosition, $previousPosition)` returns `'improved'` when the current (newer) position number is lower than the previous (older) one. This matches the ranking rule: lower position = better. So if yesterday was 50 and today is 30, today's row shows "improved".

5. **Day-over-day vs 7-day trend.** The summary line at the top uses `getKeywordTrend()` (today vs 7 days ago) to stay consistent with the list page. The history table rows use day-over-day `calculateTrend()` for finer-grained visibility into the walk. Both are documented in the view with a label on the summary and a column header on the table.

6. **Oldest entry has no trend.** The last row in the array (the earliest date) has no "next" entry to compare against, so `hasTrend` is `false` and the trend cell renders `—`.

7. **No input validation on `$id`.** The route already runs `validateIntId($second)` — non-integer strings → `null` → 404. Same pattern as `handleEditForm()` and `handleDelete()`.

8. **No pagination.** 30 rows per keyword is small enough to render on a single page.

## View Template

### `views/keyword_detail.php` (new)

Receives:
- `keyword` — `[id, phrase, website, created_at]` from the DB.
- `history` — array of `[date, position, trend, hasTrend]` entries (newest first).
- `currentPosition` — `int|null` (latest position, or null if no history).
- `currentTrend` — `'improved'|'declined'|'stable'` (7-day trend).

```php
<?php
/** @var array $keyword  [id, phrase, website, created_at] */
/** @var array $history  Entries: [date, position, trend, hasTrend] — newest first */
/** @var int|null $currentPosition */
/** @var string $currentTrend */

$id        = (int) $keyword['id'];
$phrase    = escape($keyword['phrase']);
$website   = escape($keyword['website']);
$createdAt = escape(date('M j, Y', strtotime($keyword['created_at'])));
?>

<a href="/" class="back-link">Back to keywords</a>

<h2 class="detail-title"><?= $phrase ?></h2>
<p class="keyword-website"><?= $website ?></p>
<p class="keyword-created">Tracking since: <?= $createdAt ?></p>

<div class="detail-summary">
    <span class="summary-label">Current position:</span>
    <span class="summary-value"><?= $currentPosition ?? '--' ?></span>
    <span class="trend <?= escape($currentTrend) ?>"><?= escape($currentTrend) ?></span>
    <span class="summary-hint">(7-day trend)</span>
</div>

<div class="detail-actions">
    <a href="/edit/<?= $id ?>" class="edit-link">Edit keyword</a>
    <form method="post" action="/delete/<?= $id ?>" class="delete-form-inline"
          onsubmit="return confirm('Are you sure you want to remove this keyword?');">
        <button type="submit" class="delete-btn">Delete keyword</button>
    </form>
</div>

<?php if (count($history) === 0): ?>
    <p class="empty-state">No position history recorded yet.</p>
<?php else: ?>
    <table class="keyword-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Position</th>
                <th>Trend (vs previous day)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $entry): ?>
                <?php
                    $trend      = $entry['trend'];
                    $trendClass = $trend ?? 'stable';
                ?>
                <tr>
                    <td><?= escape($entry['date']) ?></td>
                    <td><?= $entry['position'] ?></td>
                    <td>
                        <?php if ($entry['hasTrend']): ?>
                            <span class="trend <?= escape($trendClass) ?>"><?= escape($trend) ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
```

**Escaping audit (M6):**
- `$phrase`, `$website`, `$createdAt` — DB data, all `escape()`'d.
- `$entry['date']`, `$trend`, `$trendClass` — handler-computed, all `escape()`'d.
- `$id` — `(int)` cast, safe in URLs and attributes.
- `$entry['position']` — `(int)` from handler, safe.
- `$currentTrend` — enum value from `getKeywordTrend()`, escaped when used as CSS class and text.
- `confirm()` string — **static literal**, no dynamic content, no XSS risk.
- Reuses `.keyword-table` and `.trend` CSS classes from existing code.

**View conventions match existing views:**
- Same `@var` docblock style as `keyword_form.php` and `keyword_list.php`.
- Same `escape()` usage pattern.
- Same trend CSS classes: `trend improved`, `trend declined`, `trend stable`.
- Same `.keyword-table` class reused for the history table.
- Same edit/delete pattern as `keyword_list.php` Actions column.

## CSS Additions

Add to end of `public/assets/css/style.css`:

```css
/* Keyword detail page (M5) — minimal styles, expanded in step 10 (M8) */

.back-link {
    display: inline-block;
    text-decoration: none;
    color: #0366d6;
    font-size: 0.9rem;
    margin-bottom: 0.75rem;
}
.detail-title {
    margin: 0 0 0.25rem 0;
    font-size: 1.5rem;
}
.keyword-website {
    margin: 0 0 0.25rem 0;
    color: #6a737d;
}
.keyword-created {
    margin: 0 0 1rem 0;
    font-size: 0.85rem;
    color: #6a737d;
}
.detail-summary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    font-size: 1.1rem;
}
.summary-label {
    font-weight: 600;
    color: #24292f;
}
.summary-value {
    font-size: 1.5rem;
    font-weight: 700;
}
.summary-hint {
    font-size: 0.8rem;
    color: #6a737d;
}
.detail-actions {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.delete-form-inline {
    display: inline;
}
```

**Note:** `.delete-form-inline` is identical to the existing `.delete-form` (`display: inline`). Using a separate class for clarity — could be collapsed into `.delete-form` reuse. Left as-is to keep the change self-contained; step 10 (M8) can normalize.

## Data Flow

```
GET /keyword/12
  → router: validateIntId("12") = 12
  → handleDetail(12)
    ├→ SELECT keyword row WHERE id = ?          → 404 if not found
    ├→ SELECT all positions WHERE keyword_id = ? ORDER BY recorded_at DESC
    ├→ loop: compute day-over-day trend per row via calculateTrend()
    ├→ getKeywordTrend($pdo, 12)                 → 7-day trend for summary
    └→ renderPage('Keyword Detail', 'keyword_detail.php', [...])
```

## Security (M6 Compliance)

| Concern | How this step is safe |
|---|---|
| SQL injection | Both queries use `prepare()` + `execute([$id])`. No string interpolation. |
| XSS | All echoed values pass through `escape()`. IDs are `(int)` cast. Trend strings are hardcoded enum values from `calculateTrend()` / `getKeywordTrend()`, but escaped anyway when used in HTML text or CSS class names. |
| Input validation | `$id` arrives already validated by `validateIntId()` in the route dispatch (`index.php:298`). Non-integer or `< 1` → `null` → 404. No raw `$_GET` reaches the handler. |
| Path traversal | No file paths derived from user input. `renderPage()` uses hardcoded template name `'keyword_detail.php'`. |
| Secrets | No new config. Uses existing `SITE_URL` and `DB_PATH` constants. |
| GET writes | Detail page is GET-only (read). No mutations on this route. |

## Edge Cases

| Case | Behavior |
|---|---|
| Non-existent keyword (`/keyword/99999`) | `SELECT` returns `false` → `sendNotFound('Keyword not found.')` → HTTP 404. |
| Invalid ID (`/keyword/abc`) | `validateIntId("abc")` returns `null` → route falls through to `sendNotFound()` → HTTP 404. |
| Keyword with no positions | `$positionRows` empty → `$history = []`, `$currentPosition = null`. View shows "No position history recorded yet." message. (Theoretically shouldn't happen with seed, but CRUD could create a keyword without running seed.) |
| Keyword with exactly 1 position | `$history` has 1 entry with `hasTrend = false` → trend cell renders `—`. Summary shows that position with `'stable'` trend (from `getKeywordTrend()` returning `null`). |
| Position date format in seed | `recorded_at` is `Y-m-d` (e.g. `2026-08-16`). `strtotime()` parses it correctly. `date('M j, Y', ...)` formats as `Aug 16, 2026` for display. |

## What This Does Not Touch

- `src/helpers.php` — `getKeywordTrend()` and `calculateTrend()` reused as-is.
- `seed.php` — schema already supports the queries. No changes needed.
- `public/router.php` — route already forwards `/keyword/{id}` to `index.php`.
- `views/keyword_list.php` — already links to `/keyword/{id}`.
- `views/layout.php` — shared layout works as-is.

## Verification Steps

```bash
# 1. Lint all touched files
php -l public/index.php
php -l views/keyword_detail.php

# 2. Ensure DB is fresh (with full 30-day history)
php seed.php
# expect: Seeded 10 keywords, 300 positions into data/minirank.sqlite

# 3. Start server
php -S localhost:8000 -t public public/router.php &

# 4. Detail page loads for existing keyword (200, contains "Current position")
curl -s http://localhost:8000/keyword/1 | grep -o 'Current position'
# expect: Current position

# 5. History table has 30 rows × 3 columns = 90 <td> elements
curl -s http://localhost:8000/keyword/1 | grep -c '<td>'
# expect: 90

# 6. Back link present
curl -s http://localhost:8000/keyword/1 | grep -o 'Back to keywords'
# expect: Back to keywords

# 7. Edit link present with correct keyword ID
curl -s http://localhost:8000/keyword/1 | grep -o 'href="/edit/1"'
# expect: href="/edit/1"

# 8. Delete form present
curl -s http://localhost:8000/keyword/1 | grep -o 'Delete keyword'
# expect: Delete keyword

# 9. Non-existent keyword -> 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/keyword/99999
# expect: 404

# 10. Invalid ID -> 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/keyword/abc
# expect: 404

# 11. Trend values are valid
curl -s http://localhost:8000/keyword/1 | grep -oE 'trend (improved|declined|stable)' | sort | uniq -c

# 12. CSS is served (no 404)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/assets/css/style.css
# expect: 200

# Stop server
kill %1
```

## Suggested Commit

```
M5: implement keyword detail page with position history table
```

## Files Affected (Summary)

- **Modified:** `public/index.php` — replace `handleDetail()` stub (~5 lines → ~45 lines)
- **New:** `views/keyword_detail.php` — keyword detail view with history table
- **Modified:** `public/assets/css/style.css` — add ~40 lines of detail-specific styles
