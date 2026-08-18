# Step 4 Plan: src/db.php + helpers.php

## Status

| Item | Status | Detail |
|---|---|---|
| `src/db.php` | Done (committed in 6246da7) | Constants (DATA_DIR, DB_PATH, SITE_URL) + ensureDataDir() + getPdo() with strict_types, type hints, UTC, foreign keys on. |
| `seed.php` | Done | 10 keywords x 30 days = 300 rows. php -l passes. DB verified (10 keywords, 300 positions). |
| `src/helpers.php` | Not created | To be written in this step. |

The actual work in step 4 is solely creating `src/helpers.php`. `src/db.php` already
matches the spec from `plans/DB_SEED_PLAN.md`.

## Current data context

- Today (UTC): 2026-08-16
- 7 days ago: 2026-08-09
- Seed produces 30 days of data: today-29 ... today
- All keywords have positions for both today and 7-days-ago in the seeded data.

## Functions to implement in src/helpers.php

### 1. validateIntId(mixed $value): ?int

Safely extract and validate a keyword ID from `$_GET` or `$_POST`.

- Input: raw value (string, int, or null from superglobals).
- Cast using `filter_var($value, FILTER_VALIDATE_INT)` — this is safer than a bare
  `(int)` cast because it rejects non-numeric strings.
- Reject non-positive values (IDs must be >= 1).
- Return `int` on success, `null` on failure.
- Caller (router) checks for `null` and responds with 404 or redirect.

Use case: keyword IDs in `/keyword/12`, `/edit/12`, `/delete/12`, and POST forms.

### 2. validateString(mixed $value, int $maxLength): ?string

Safely extract and validate a string from `$_GET` or `$_POST`.

- Input: raw value from superglobals.
- Cast to `(string)`, then `trim()`.
- Reject if empty after trimming or if `strlen()` exceeds `$maxLength`.
- Return trimmed string on success, `null` on failure.

Length caps:
- Search term (M4): 100 characters.
- Keyword phrase (M1 CRUD): 200 characters.

### 3. calculateTrend(int $currentPosition, int $previousPosition): string

Pure function: compute trend from two position values.
This is the core business rule from INITIAL_PLAN.md:

- position went from 37 to 12: improved (lower = better)
- position went from 12 to 37: declined
- equal: stable

- If `$currentPosition < $previousPosition` -> return `'improved'`
- If `$currentPosition > $previousPosition` -> return `'declined'`
- If equal -> return `'stable'`

No DB access. Pure and unit-testable (S6).

### 4. getKeywordTrend(PDO $pdo, int $keywordId): ?string

DB convenience function: fetch the two positions needed by `calculateTrend`.

- Query today's position and the position from 7 days ago for the given keyword.
- Uses a single prepared statement with bound parameters (M6 compliance).
- Calls `calculateTrend()` internally.
- Returns the trend string, or `null` if either position is missing.
  (Edge case: a freshly added keyword with < 7 days of history.)

Query:
```sql
SELECT position, recorded_at
FROM positions
WHERE keyword_id = ? AND recorded_at IN (?, ?)
ORDER BY recorded_at
```

The two date parameters (PHP, UTC):
- 7 days ago: `date('Y-m-d', strtotime('-7 days'))`
- today: `date('Y-m-d')`

After fetching: the row set is ordered by date ascending, so index 0 is
7-days-ago and index 1 is today. If both rows exist, pass them to calculateTrend.

### 5. escape(string $value): string

Wrapper around PHP's built-in HTML escaper.

```php
htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
```

- `ENT_QUOTES` escapes both single and double quotes (defense against attribute
  injection).
- `'UTF-8'` is explicit to avoid charset-related pitfalls.
- Used by all views when printing values into HTML (M6 compliance).
- Keeping it as a helper means views stay thin and the escaping is consistent.

## Decisions confirmed by user

1. **Two-point comparison**: trend = today's position vs position from 7 days ago.
   (Not average-of-7 vs average-of-previous-7.)
2. **escape() wrapper**: yes, include it in helpers.php.
3. **getKeywordTrend return type**: `?string` — `null` means insufficient data,
   M4 list view will treat `null` as stable / show dash.

## Verification (run after implementation)

```bash
# Lint
php -l src/helpers.php

# Unit-check the pure functions
php -r '
require "src/helpers.php";
echo calculateTrend(88, 83) . "\n";        // expect: declined
echo calculateTrend(77, 81) . "\n";        // expect: improved
echo calculateTrend(52, 52) . "\n";        // expect: stable

echo validateIntId("12") . "\n";           // expect: 12
echo validateIntId("0") . "\n";            // expect: (empty / null)
echo validateIntId("abc") . "\n";          // expect: (empty / null)

echo validateString("hello", 100) . "\n";   // expect: hello
echo validateString(str_repeat("x",101), 100) . "\n";  // expect: (empty / null)

echo escape("<b>") . "\n";                 // expect: &lt;b&gt;
'

# Integration: trend from real seeded data
php -r '
require "src/db.php";
require "src/helpers.php";
$pdo = getPdo();
echo getKeywordTrend($pdo, 4) . "\n";      // expect: improved (77 < 81)
echo getKeywordTrend($pdo, 1) . "\n";      // expect: declined (88 > 83)
echo getKeywordTrend($pdo, 5) . "\n";      // expect: declined (52 > 51)
'
```

## Suggested commit

```
git add src/helpers.php && git commit -m "Add helpers: input validation, escape, and 7-day trend calculation"
```

## Files affected

- New: `src/helpers.php`
