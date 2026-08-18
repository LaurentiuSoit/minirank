# S4 — Position / Movement Filters (Implementation Plan)

## Overview

Add two filter types to the keyword list page (`/project/{pid}`), composed with the existing text search (`?q=`):

1. **Position range** — "current position between *min* and *max*" → applied **in SQL** (bound params).
2. **Movement** — "improved / declined / stable" (7-day trend) → applied **in PHP** via a batched trend query.

**Key value-add:** this step replaces the current per-row `getKeywordTrend()` call in `handleList` (an N+1 — one DB query per keyword) with a single `getKeywordTrends()` batch query. After S4, the list page loads with **2 queries total** regardless of keyword count.

Filters are GET parameters (read-only, no DB writes on GET — per AGENTS rules). All controls live in **one combined form** so filters compose without hidden-input juggling.

## Files to Modify (4)

| File | Changes |
|------|---------|
| `src/helpers.php` | Add `getKeywordTrends(PDO, array $keywordIds): array` |
| `public/index.php` | Refactor `handleList`: read 3 new params, position range in SQL, batched trends, movement filter in PHP |
| `views/keyword_list.php` | Extend `.search-form` with position inputs + movement `<select>`; pre-fill values; generalize "Clear" link |
| `public/assets/css/style.css` | Style `.filter-label`, number inputs, select; mobile tweak in existing `@media` block |

## Current State (relevant code)

- **`handleList`** (index.php:52-97) — already reads `$_GET['q']`, JOINs latest position, calls `getKeywordTrend()` per row. SQL already has `WHERE k.project_id = ?` + optional `LIKE ?` for search.
- **`getKeywordTrend`** (helpers.php:38-57) — single-keyword; query is `recorded_at IN (?, ?)` for today + 7-days-ago, then `calculateTrend`.
- **`keyword_list.php`** (view:28-36) — a single `.search-form` GET form with one `q` input + "Clear" link.

## Step-by-step Plan

### Step 1 — `getKeywordTrends()` helper (`src/helpers.php`)

Add alongside existing `getKeywordTrend` (which stays — detail page + refresh still use it).

```php
function getKeywordTrends(PDO $pdo, array $keywordIds): array
{
    if (empty($keywordIds)) {
        return [];
    }

    // Build IN(?, ?, ...) — placeholders only; values are bound, never interpolated.
    $inPlaceholders = str_repeat('?,', count($keywordIds) - 1) . '?';

    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));

    $stmt = $pdo->prepare(
        'SELECT keyword_id, position, recorded_at
         FROM positions
         WHERE keyword_id IN (' . $inPlaceholders . ')
           AND recorded_at IN (?, ?)
         ORDER BY keyword_id, recorded_at ASC'
    );

    $params = array_values($keywordIds);
    $params[] = $weekAgo;  // position [0] per keyword = older (previous)
    $params[] = $today;    // position [1] per keyword = newer (current)
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $positionsByKeyword = [];
    foreach ($rows as $row) {
        $positionsByKeyword[(int) $row['keyword_id']][] = (int) $row['position'];
    }

    $trends = [];
    foreach ($positionsByKeyword as $kwId => $positions) {
        if (count($positions) >= 2) {
            $trends[$kwId] = calculateTrend($positions[1], $positions[0]);
        } else {
            $trends[$kwId] = null;  // not enough data for this keyword
        }
    }

    // Fill in nulls for keywords with zero position rows.
    foreach ($keywordIds as $id) {
        $key = (int) $id;
        if (!isset($trends[$key])) {
            $trends[$key] = null;
        }
    }

    return $trends;
}
```

**Safety note:** `str_repeat('?,', ...)` produces placeholder *syntax* only. Each ID is bound as a parameter. The keyword IDs come from a DB fetch (not raw user input) and are cast to `(int)` when collected in the handler.

### Step 2 — Refactor `handleList` (`public/index.php`)

Three additions to the handler:

**(a) Read + validate filter params** (after the existing `$searchTerm` line):

```php
// Position range: validated integers 1–100, null = unbounded.
$minPos = null;
$val = filter_var($_GET['position_min'] ?? null, FILTER_VALIDATE_INT);
if ($val !== false && $val >= 1 && $val <= 100) {
    $minPos = (int) $val;
}

$maxPos = null;
$val = filter_var($_GET['position_max'] ?? null, FILTER_VALIDATE_INT);
if ($val !== false && $val >= 1 && $val <= 100) {
    $maxPos = (int) $val;
}

// Movement: whitelist only — never placed in SQL.
$movement = null;
if (isset($_GET['movement'])) {
    $whitelist = ['improved', 'declined', 'stable'];
    if (in_array($_GET['movement'], $whitelist, true)) {
        $movement = $_GET['movement'];
    }
}
```

**(b) Apply position range to the SQL** (after the search `LIKE` clause):

```php
if ($minPos !== null) {
    $sql .= ' AND p.position >= ?';
    $params[] = $minPos;
}
if ($maxPos !== null) {
    $sql .= ' AND p.position <= ?';
    $params[] = $maxPos;
}
```

> **Behavior note:** when a position filter is active, keywords with no position (`p.position IS NULL`) are naturally excluded — `NULL >= ?` is NULL/false. This is correct: a keyword with no position can't match a position-range filter. When no filter is active, the LEFT JOIN preserves show-everything behavior.

**(c) Batch trends + movement filter** (replace the per-row loop):

```php
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// One batch query for all keyword IDs (de-N+1).
$keywordIds = [];
foreach ($rows as $row) {
    $keywordIds[] = (int) $row['id'];
}
$trends = getKeywordTrends($pdo, $keywordIds);

$keywords = [];
foreach ($rows as $row) {
    $kwId = (int) $row['id'];
    $trend = $trends[$kwId] ?? null;

    // Movement filter applied in PHP after trend computation.
    if ($movement !== null && $trend !== $movement) {
        continue;
    }

    $keywords[] = [
        'id'       => $kwId,
        'phrase'   => $row['phrase'],
        'position' => $row['position'] !== null ? (int) $row['position'] : null,
        'trend'    => $trend,
    ];
}
```

Pass new values to the view:

```php
renderPage('Keyword List', 'keyword_list.php', [
    'keywords'    => $keywords,
    'searchTerm'  => $searchTerm,
    'positionMin' => $minPos,
    'positionMax' => $maxPos,
    'movement'    => $movement,
    'projectId'   => $projectId,
    'project'     => $project,
]);
```

### Step 3 — Filter controls in view (`views/keyword_list.php`)

Extend the existing `.search-form` (lines 28-36) into a single combined form with all four controls. Replace the current form block:

```php
<form method="get" action="/project/<?= $projectId ?>" class="search-form">
    <input type="search" name="q"
           value="<?= escape((string)($searchTerm ?? '')) ?>"
           maxlength="100" placeholder="Search keywords...">

    <label class="filter-label" for="position_min">Position</label>
    <input type="number" name="position_min" id="position_min" min="1" max="100"
           value="<?= escape((string)($positionMin ?? '')) ?>" placeholder="min">
    <input type="number" name="position_max" id="position_max" min="1" max="100"
           value="<?= escape((string)($positionMax ?? '')) ?>" placeholder="max">

    <label class="filter-label" for="movement">Movement (7-day)</label>
    <select name="movement" id="movement">
        <option value="">All</option>
        <option value="improved" <?= ($movement === 'improved') ? 'selected' : '' ?>>Improved</option>
        <option value="declined" <?= ($movement === 'declined') ? 'selected' : '' ?>>Declined</option>
        <option value="stable"   <?= ($movement === 'stable') ? 'selected' : '' ?>>Stable</option>
    </select>

    <button type="submit">Filter</button>

    <?php if ($searchTerm !== null || $positionMin !== null || $positionMax !== null || $movement !== null): ?>
        <a href="/project/<?= $projectId ?>" class="clear-search">Clear</a>
    <?php endif; ?>
</form>
```

Key points:
- All echoed values wrapped in `escape()`.
- "Clear" link now appears when **any** filter is active (not just search); links to clean `/project/{pid}` URL.
- Native `min`/`max`/`type="number"` for browser-side defense-in-depth; server still validates.
- The `movement` `<select>` uses PHP string comparison (`$movement === 'improved'`) — `$movement` is already whitelist-validated to be one of those exact strings or `null`.

### Step 4 — CSS (`public/assets/css/style.css`)

```css
/* === S4: Position / movement filters === */
.filter-label {
    font-size: 0.85rem;
    color: #586069;
    white-space: nowrap;
}

.search-form input[type="number"] {
    width: 70px;
    padding: 0.4rem;
    border: 1px solid #ccc;
    border-radius: 3px;
}

.search-form select {
    padding: 0.35rem;
    border: 1px solid #ccc;
    border-radius: 3px;
    background: #fff;
}
```

In the existing `@media (max-width: 640px)` block, add:

```css
/* Filter form on mobile: keep inputs compact */
.search-form input[type="number"] {
    flex: 0 0 auto;
}
.filter-label {
    font-size: 0.8rem;
}
```

## Security Checklist

| Concern | Mitigation |
|---------|------------|
| SQL injection | `position_min`/`position_max` bound as `?` params. `movement` never enters SQL (whitelist-checked PHP string). `IN(...)` placeholders generated by `str_repeat` — syntax only, values bound. |
| Reflected XSS | All filter values `escape()`'d into `value=` + `selected=` attributes. |
| Invalid input | Ints validated via `filter_var(FILTER_VALIDATE_INT)` + 1–100 range; invalid → null (no filter). Movement whitelist-checked; non-match → null. |
| No GET writes | Filters are read-only SELECT query params — no DB mutation. |
| No secrets | No new constants or credentials. |

## Verification

```bash
php -l src/helpers.php
php -l public/index.php
# expect: No syntax errors detected

php seed.php
# expect: "Seeded 2 projects, 10 keywords, 300 positions into data/minirank.sqlite"

php -S localhost:8000 -t public public/router.php
```

Then log in (demo@example-shop.de / minirank), open `/project/1`, and verify:

| Check | URL/query | Expected |
|-------|-----------|----------|
| Position max | `?position_max=30` | Only rows with position ≤ 30 |
| Position min | `?position_min=80` | Only positions ≥ 80 |
| Movement = improved | `?movement=improved` | Only `improved` trend rows |
| Movement = declined | `?movement=declined` | Only `declined` rows |
| Combined filters | `?q=keyboard&position_max=50&movement=improved` | Text + range + movement all applied |
| Invalid input | `?position_min=abc&position_max=999&movement=hacked` | All ignored → full list (no crash) |
| Clear | Click "Clear" after filtering | Full unfiltered list |
| Query count | Reload list page | 2 queries (list + batch trends) regardless of keyword count |

Security grep sanity check:

```bash
grep -RIn 'query(\|exec(' public/index.php src/helpers.php   # expect: NO interpolated SQL
```

## Notes / Edge cases

- **`movement=stable` excludes null-trend rows**: a keyword with no 7-day data (`null`) is not "stable" — it has no trend. This is the correct interpretation.
- **min > max**: if a user enters `position_min=80&position_max=10`, the SQL produces `position >= 80 AND position <= 10` → zero rows. Technically correct; swapping silently could confuse. Acceptable for v1.
- **Refresh.js compatibility**: the AJAX refresh updates only visible `.keyword-position` / `.keyword-trend` cells. After filters hide rows, a refresh won't re-apply the filter (page not reloaded). Same limitation as text search — accepted for v1.

## Commit Message

```
S4: filter keywords by position range or 7-day movement

Add position-range and movement filters to the keyword list page.
Position range is applied in SQL (bound params); movement is applied
in PHP via a new getKeywordTrends() batch helper that replaces the
per-row getKeywordTrend() call (de-N+1). Combined into one GET form
alongside the existing text search so filters compose.
```
