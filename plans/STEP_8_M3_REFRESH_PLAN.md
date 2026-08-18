# Step 8 Plan: M3 AJAX Refresh (POST → JSON → UI update)

## Status

| Item | Status | Detail |
|---|---|---|
| Steps 1–7 | Done (committed) | seed.php, src/db.php, src/helpers.php, router.php + index.php, keyword list view, CRUD handlers, keyword_form view, refresh.js |
| `handleRefresh()` | Stub | `public/index.php:213-217` — returns `{"status":"not implemented"}` |
| Route dispatch | Wired | `POST /refresh → handleRefresh()` at `public/index.php:245-246` |
| `sendJson()` | Exists | `public/index.php:32-37` — sets header, echoes JSON, exits |
| `public/assets/js/refresh.js` | Written (step 6) | POSTs to `/refresh`, parses JSON, updates table rows in place via `data-keyword-id` selectors |
| `keyword_list.php` | Has table hooks | Rows have `data-keyword-id`, cells have `.keyword-position` and `.keyword-trend` classes — exactly what the JS targets |
| DB schema | Supports upsert | `UNIQUE (keyword_id, recorded_at)` in `positions` (seed.php:30) |

## What This Step Builds

**Requirement M3:** "A 'Refresh positions' button generates today's positions server-side and updates the page via AJAX, without a full page reload."

The button, the AJAX wiring, the route, and the DOM update logic are already done (steps 5–6). What's missing is the server-side position generation in `handleRefresh()`. This step replaces the stub with logic that:

1. Generates a new simulated ranking position (1–100) for each keyword for today.
2. Persists it via upsert (replace today's row if the seed already wrote it).
3. Recomputes the 7-day trend for each keyword.
4. Returns JSON in the exact shape that `refresh.js` already expects.

Result: zero changes to JavaScript or the view. The existing `refresh.js` calls `fetch('/refresh', {method: 'POST'})`, receives the JSON, and updates the table in place. The AJAX-without-reload behavior is already proven.

## Files Affected

| File | Action | Lines | Purpose |
|---|---|---|---|
| `public/index.php` | Modify | 213–217 | Replace `handleRefresh()` stub with real implementation |

That's one file, one function body. The JS, route, helpers, and DB schema are all already in place.

## Data Contract (already defined in STEP_6_PLAN)

The `refresh.js` from step 6 was written forward-compatible against this JSON shape. Step 8 just produces it:

```json
{
  "status": "ok",
  "updated": 10,
  "keywords": [
    {"id": 1, "phrase": "best running shoes", "position": 42, "trend": "improved"},
    {"id": 2, "phrase": "wireless headphones", "position": 88, "trend": "declined"}
  ]
}
```

The JS uses `kw.id` (to find the `<tr>`), `kw.position` (to update `.keyword-position` text), and `kw.trend` (to rebuild the `.keyword-trend` span). `kw.phrase` is included for completeness but the JS does not currently use it.

## `handleRefresh()` Implementation

```php
function handleRefresh(): void
{
    $pdo = getPdo();
    $today = date('Y-m-d');

    $pdo->beginTransaction();
    try {
        // Fetch all keywords with their most recent position (correlated subquery,
        // same pattern as handleList() at line 62-68).
        $select = $pdo->prepare(
            'SELECT k.id, k.phrase,
                    (SELECT p.position FROM positions p
                     WHERE p.keyword_id = k.id
                     ORDER BY p.recorded_at DESC, p.id DESC
                     LIMIT 1) AS latest_position
             FROM keywords k
             ORDER BY k.id ASC'
        );
        $select->execute();
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the upsert once; execute per-keyword with different params.
        $upsert = $pdo->prepare(
            'INSERT INTO positions (keyword_id, position, recorded_at)
             VALUES (?, ?, ?)
             ON CONFLICT(keyword_id, recorded_at) DO UPDATE SET position = excluded.position'
        );

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $base = $row['latest_position'] !== null
                ? (int) $row['latest_position']
                : random_int(20, 80);

            // Random walk step: ±3, clamped to 1..100 — same range as seed.php.
            $newPosition = max(1, min(100, $base + random_int(-3, 3)));

            $upsert->execute([$id, $newPosition, $today]);

            $result[] = [
                'id'       => $id,
                'phrase'   => $row['phrase'],
                'position' => $newPosition,
                'trend'    => getKeywordTrend($pdo, $id) ?? 'stable',
            ];
        }

        $pdo->commit();

        sendJson([
            'status'   => 'ok',
            'updated'  => count($result),
            'keywords' => $result,
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        sendJson([
            'status'  => 'error',
            'message' => 'Refresh failed.',
        ]);
    }
}
```

### Design decisions

1. Random walk from the most recent position (step ±3, clamped 1–100) — consistent with `seed.php`'s `generateWalk()` pattern. Each refresh simulates a small drift in ranking, which keeps trends meaningful. If a keyword has no positions at all (just added, never refreshed), we fall back to `random_int(20, 80)` as the base, matching the seed's base range.

2. Upsert via `ON CONFLICT(...)` — the seed already writes today's position, so a plain `INSERT` would fail on the `UNIQUE (keyword_id, recorded_at)` constraint. The upsert replaces today's value. `excluded.position` refers to the would-be-inserted row's `position` column — standard SQLite UPSERT syntax (confirmed supported: SQLite 3.53.4).

3. Transaction — all upserts happen inside one transaction. If any fails, we roll back the whole refresh so the DB stays consistent. `$pdo->inTransaction()` guards the `rollBack()` call.

4. Trend via existing `getKeywordTrend($pdo, $id)` — after the upsert writes today's new position, `getKeywordTrend()` reads it back (same transaction sees uncommitted write) and compares against 7-days-ago. Returns `null` if 7-days-ago data is missing; we map that to `'stable'`.

5. No changes to `refresh.js` — it already handles `data.status === 'ok'` by iterating `data.keywords` and updating `.keyword-position` / `.keyword-trend` cells per row.

## Security Audit (M6)

| Concern | How this step is safe |
|---|---|
| SQL injection | All three queries use `prepare()` + bound params. No string interpolation. |
| XSS | The response is `json_encode`'d, not echoed into HTML. The JS uses `textContent` for the position and only sets `innerHTML` for the trend label, which is constrained to `'improved' \| 'declined' \| 'stable'` from `calculateTrend()` — no user input reaches `innerHTML`. |
| Input validation | `handleRefresh()` takes no `$_GET`/`$_POST` input — it is a POST endpoint that generates data server-side. |
| Secrets | No new config or secrets. Uses existing `DB_PATH` / `SITE_URL` constants. |
| GET writes | Refresh is POST-only (route at line 245). |

## Edge Cases

| Case | Behavior |
|---|---|
| No keywords in DB | `$rows` is empty → `updated: 0`, `keywords: []`. JS shows "Updated 0 keywords." |
| Keyword with no positions at all | `$base` falls back to `random_int(20, 80)`. Trend is `null` → `'stable'`. |
| Keyword with < 7 days of history | `getKeywordTrend()` returns `null` → `'stable'`. |
| DB error mid-refresh | Transaction rolls back; JSON `{"status":"error","message":"Refresh failed."}` with HTTP 500 returned. |

## What this does not touch

- `public/assets/js/refresh.js` — already forward-compatible.
- `views/keyword_list.php` — already has the table structure + `<script>` include.
- `src/helpers.php` — `getKeywordTrend()` and `escape()` are reused as-is.
- `seed.php` — schema already supports upsert.

Optional follow-up (not part of this step): `refresh.js` line 37's `else` branch shows "Refresh not available yet." for any non-ok response. Now that the server returns `{"status":"error"}` on failure, we could update the `else` branch to show "Refresh failed." with the error style. This would be a 3-line change to `refresh.js`. Left out of scope per AGENTS "don't touch what you didn't ask" guidance.

## Verification Steps

```bash
# 1. Lint
php -l public/index.php

# 2. Fresh DB
php seed.php
# expect: Seeded 10 keywords, 300 positions into data/minirank.sqlite

# 3. Start server
php -S localhost:8000 -t public public/router.php &

# 4. POST /refresh returns JSON with correct shape
curl -s -X POST http://localhost:8000/refresh | python3 -m json.tool
# expect: {"status":"ok","updated":10,"keywords":[{"id":1,...,"position":<1-100>,"trend":"..."}, ...]}

# 5. Verify "updated" count matches keyword count
curl -s -X POST http://localhost:8000/refresh | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d["updated"], len(d["keywords"]))'
# expect: 10 10

# 6. Verify all positions are 1-100 and all trends are valid
curl -s -X POST http://localhost:8000/refresh | python3 -c '
import sys,json; d=json.load(sys.stdin);
assert all(1 <= k["position"] <= 100 for k in d["keywords"]), "position out of range"
assert all(k["trend"] in ("improved","declined","stable") for k in d["keywords"]), "bad trend"
print("all positions 1-100, all trends valid")
'

# 7. Verify today's positions were upserted in the DB
php -r '
require "src/db.php"; require "src/helpers.php";
$pdo = getPdo();
$today = date("Y-m-d");
$stmt = $pdo->prepare("SELECT COUNT(*) FROM positions WHERE recorded_at = ?");
$stmt->execute([$today]);
echo "positions for today: ", $stmt->fetchColumn(), "\n";
'
# expect: positions for today: 10 (one per keyword, upserted, not duplicated from seed)

# 8. Verify trend is recalculated correctly after refresh
curl -s -X POST http://localhost:8000/refresh | python3 -c '
import sys,json; d=json.load(sys.stdin);
for k in d["keywords"]: print(k["id"], k["position"], k["trend"])
'

# 9. Full page still loads with updated positions
curl -s http://localhost:8000/ | grep -c 'data-keyword-id'
# expect: 10

# 10. GET /refresh is not a route (only POST) -> 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/refresh
# expect: 404

# Stop server
kill %1
```

## Suggested Commit

```
M3: implement server-side position generation and JSON refresh endpoint
```

## Enables Step 9

Step 9 (M5 keyword detail) needs the `/keyword/{id}` route (already in index.php:237-238 as a stub) and `views/keyword_detail.php`. Step 8 validates that the `positions` table's `recorded_at` date column and the `UNIQUE (keyword_id, recorded_at)` constraint work as expected — foundational for the detail page's date-ordered history query.
