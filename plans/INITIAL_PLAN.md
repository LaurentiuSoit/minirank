# MiniRank — Implementation Plan

## Tech Choices
- **PHP 8** plain, no framework (per M4/Tech rules)
- **SQLite via PDO**, single `.sqlite` DB file in `data/` (gitignored)
- **Server:** `php -S localhost:8000 -t public public/router.php`

## Database Schema
```sql
keywords:   id, phrase, website, created_at
positions:  id, keyword_id, position, recorded_at
```
- `position` = 1–100 (lower = better)
- `recorded_at` = unique keyword_id+date index (prevents duplicate daily entries)
- Seed: ~10 keywords × 30 days = 300 rows

## Routing (Option A — clean URLs)
```
GET  /               — keyword list (M4)
GET  /add            — add form (M1)
POST /add            — create keyword
GET  /keyword/12     — detail page (M5)
GET  /edit/12        — edit form (M1)
POST /edit/12        — update keyword
POST /delete/12      — delete keyword
POST /refresh         — generate today's positions, returns JSON (M3)
GET  /assets/*       — static CSS/JS
```

## File Structure
```
minirank/
  seed.php                    # CLI: (re)create schema + 30 days demo data
  data/                       # gitignored
  src/
    db.php                    # PDO config: localhost, SITE_URL constant (hardcoded, no secret)
    helpers.php               # validateIntId, validateString(len cap), trend calc
  public/
    router.php                # dispatches routes + serves assets
    index.php                 # single entry point / router
  views/
    layout.php
    keyword_list.php
    keyword_detail.php
    edit_keyword_form.php
  README.md                   # setup (M7)
```

## Security (M6 — non-negotiable)
- Every query: `$pdo->prepare('... WHERE id = ?')->execute([$id])`
- Every output: `htmlspecialchars($value, ENT_QUOTES)`
- Validate `$_GET`/`$_POST`: IDs → `(int)`, search term string with length cap
- No secrets committed — DB path + localhost config only, no `.env`

## Implementation Order
1. Write `PLAN.md` (this file)
2. `git init`, initial commit
3. Build `seed.php`, verify schema
4. `src/db.php` + `helpers.php`
5. `public/router.php` + `index.php` (router logic)
6. M4 keyword list view (+ search + Refresh button)
7. M1 CRUD (add/edit/delete forms + handlers)
8. M3 AJAX refresh (POST -> JSON, `fetch()` updates UI)
9. M5 keyword detail page (history table)
10. M8 responsive CSS
11. README with setup steps (M7)
