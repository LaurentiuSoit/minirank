# MiniRank — DB schema + seed.php implementation plan

Scope: SQLite schema and the CLI `seed.php` (covers M2 seeding; lays the M6 security foundation). Nothing else is implemented here.

## Status of prior decisions (from candidate-brief.md + plans/INITIAL_PLAN.md + AGENTS.md)
- Stack: PHP 8, no framework, SQLite via PDO. (AGENTS.md §Tech Choices)
- `.gitignore` already excludes `data/*`, `*.sqlite`, `*.sqlite3` → DB file stays out of git.
- Repo currently has 1 commit (`initial setup and structure plan`) containing the planning docs and `.gitignore`; no PHP code yet.
- Security rules that govern this work: parameterized queries for all VALUES (incl. seed script); `CHECK` constraints; `date_default_timezone_set('UTC')`.

## Config
- **DB path:** `data/minirank.sqlite` (dir auto-created).
- **Configured website (SITE_URL):** `https://www.example-shop.de` — single configured site; the `website` column is seeded uniformly with this value (kept per `INITIAL_PLAN.md` schema; supports S2 multi-site later). (Q3: keep)
- **Timezone:** UTC.

## Schema (DDL)
```sql
CREATE TABLE keywords (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    phrase     TEXT    NOT NULL,
    website    TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE positions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    keyword_id  INTEGER NOT NULL,
    position    INTEGER NOT NULL CHECK (position >= 1 AND position <= 100),
    recorded_at TEXT    NOT NULL,
    UNIQUE (keyword_id, recorded_at),
    FOREIGN KEY (keyword_id) REFERENCES keywords (id)
);

CREATE INDEX idx_positions_recorded_at ON positions (recorded_at);
```
Decisions:
- `recorded_at` is a **date** (`YYYY-MM-DD`), so `UNIQUE(keyword_id, recorded_at)` directly enforces one position per keyword per day (the "keyword_id+date index" from INITIAL_PLAN.md). (Q2: date-only)
- `CHECK(position >= 1 AND position <= 100)` enforces the 1–100 ranking range at the DB level (M2/M4). Lower = better.
- DDL (CREATE TABLE) is static → run via `PDO::exec()`. Row inserts use prepared statements with bound parameters.
- Seed **includes today** in the 30-day window, so M4 shows a current position immediately. Consequence for M3 (future): refresh must upsert today (`INSERT ... ON CONFLICT(keyword_id, recorded_at) DO UPDATE`), not plain insert.

## File plan

### src/db.php  (minimal shared config — option B)
- `const DATA_DIR` = `__DIR__.'/../data'`
- `const DB_PATH` = `DATA_DIR.'/minirank.sqlite'`
- `const SITE_URL` = `'https://www.example-shop.de'`
- `function ensureDataDir(): void` — `mkdir(DATA_DIR, 0777, true)` if missing.
- `function getPdo(): PDO` — `new PDO('sqlite:'.DB_PATH)`, `ERRMODE_EXCEPTION`, `exec('PRAGMA foreign_keys = ON')`.
- `declare(strict_types=1);`, type hints + return types, no classes, no logic.

### seed.php  (CLI entry point; requires src/db.php)
```
declare(strict_types=1);
require __DIR__ . '/src/db.php';

const KEYWORDS = [ ...10 phrases... ];  // ~10 keywords (M2)
const NUM_DAYS  = 30;

function clamp(int $v, int $min, int $max): int
function insertKeyword(PDO $pdo, string $phrase, string $website, string $createdAt): int
function insertPosition(PDO $pdo, int $keywordId, int $position, string $date): void
function generateWalk(int $base, int $days): array  // [date => position], last $days incl. today
function seedKeyword(PDO $pdo, string $phrase, string $website, string $createdAt): int
function main(): void

main();
```
`main()` flow:
1. `ensureDataDir()`.
2. If `file_exists(DB_PATH)` → `unlink` it (regenerated per AGENTS rule 3; clean slate, no DROP TABLE needed).
3. `$pdo = getPdo(); createSchema($pdo);` (DDL via `exec()`).
4. For each phrase in `KEYWORDS`:
   - `created_at` = `today-29 days` (start of the 30-day window).
   - Build random walk (`base = random_int(20,80)`; step `random_int(-3,3)`; clamp to 1..100). (Q4: spread confirmed)
   - For dayOffset 0..29 (0 = today … 29 = today-29) → `insertPosition(keyword_id, pos, date('Y-m-d', strtotime('-'.dayOffset.' days')))`.
5. Print: `Seeded 10 keywords, 300 positions into data/minirank.sqlite`.

Security: every INSERT is `prepare(...)->execute([$a,$b,$c])` with bound params — even though values are self-generated (AGENTS rule 1 explicitly covers the seed script). No `$_GET`/`$_POST` involved (CLI only).

Sample keyword phrases (10):
`best running shoes`, `wireless headphones`, `ergonomic office chair`, `mechanical keyboard`, `4k monitor`, `premium yoga mat`, `automatic coffee maker`, `robot vacuum cleaner`, `bluetooth speaker`, `standing desk converter`.

Counts: 10 × 30 = **300 position rows**, dates `today-29 … today`. Each keyword has exactly 30 rows.

## Verification (run after implementation — not part of this step)
```
php seed.php
php -l seed.php && php -l src/db.php
php -r 'require "src/db.php"; $pdo=getPdo();
  echo "keywords=", $pdo->query("SELECT COUNT(*) FROM keywords")->fetchColumn(), "\n";
  echo "positions=", $pdo->query("SELECT COUNT(*) FROM positions")->fetchColumn(), "\n";'
# expect: keywords=10, positions=300
sqlite3 data/minirank.sqlite "SELECT keyword_id, COUNT(*) FROM positions GROUP BY keyword_id;"  # expect 30 each
grep -RIn 'query("' . seed.php src/db.php     # expect no string-interpolated SQL
grep -RInE '\$[a-z_]+ .* (SELECT|INSERT|WHERE)' seed.php src/db.php  # expect no interpolated SQL
```

## Suggested commit (after implementation)
`git add src/db.php seed.php data/.gitkeep && git commit -m "Add SQLite schema and seed script (10 keywords, 30 days of positions)"`
