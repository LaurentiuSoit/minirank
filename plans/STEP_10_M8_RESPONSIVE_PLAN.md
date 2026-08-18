# Step 10 Plan: M8 Responsive CSS

## Status

| Item | Status | Detail |
|---|---|---|
| Steps 1–9 | Done | Full app implemented and committed. All must-haves (M1–M7) working. |
| `public/assets/css/style.css` | Baseline only | 204 lines. Comments at lines 1 and 156 say "expanded in step 10 (M8 responsive CSS)." No `@media` queries. No body reset. No max-width container. |
| `views/layout.php` | Viewport meta present | `<meta name="viewport" content="width=device-width, initial-scale=1.0">` already in place. `<main>` has no class. |
| `views/keyword_list.php` | Desktop-only layout | `.list-header` is horizontal flex (Add + Search + Refresh). `.search-form input` has fixed `width: 200px`. Table is `width: 100%` with no overflow wrapper. |
| `views/keyword_detail.php` | Desktop-only layout | `.detail-summary` and `.detail-actions` are horizontal flex. History table has no overflow wrapper. |
| `views/keyword_form.php` | Mostly OK | `.keyword-form` `max-width: 400px` is fine. `.form-actions` is horizontal flex — needs to stack on mobile. |
| Touch targets | Too small | Delete button padding `0.3rem 0.6rem` (~7px×12px). Below 44px minimum. |

## What This Step Builds

**Requirement M8:** "Responsive: usable at phone width. Functional beats beautiful."

The app works at any width but is not usable on a phone because:

1. The list header overflows — Add + 200px search input + Refresh in one row.
2. The keyword table (5 columns) has no horizontal scroll on narrow screens.
3. Form action buttons (Submit + Cancel) are cramped side-by-side.
4. Touch targets too small for fingers.
5. No max-width on desktop — tables stretch to full viewport width.

This step adds responsive CSS so the app is usable at 320px width. Changes are purely presentational — two wrapper divs and a CSS media query.

## Files Affected

| File | Action | Changes |
|---|---|---|
| `public/assets/css/style.css` | Modify | Add body reset, header styles, container, `.table-container`, base responsive rules, `@media (max-width: 640px)` block. |
| `views/keyword_list.php` | Modify | Wrap `<table class="keyword-table">` in `<div class="table-container">`. |
| `views/keyword_detail.php` | Modify | Wrap `<table class="keyword-table">` in `<div class="table-container">`. |

3 files. View changes are one-line wrapper additions. No changes to `layout.php`, `index.php`, `helpers.php`, or JavaScript.

## Design Decisions

1. **Breakpoint: 640px.** Catches portrait phones (320–480px) and small tablets. Below this, layout stacks. Above, existing desktop layout unchanged.
2. **Desktop-first with overrides.** Existing CSS is desktop styles. We keep them as base and add a `@media (max-width: 640px)` block for mobile overrides. Least invasive approach.
3. **Horizontal scroll for tables.** 5 columns can't be both readable and fitting on 320px. `.table-container { overflow-x: auto; }` is the standard functional solution.
4. **`min-width` on tables in media query.** `.keyword-table { width: auto; min-width: 550px; }` forces scrolling rather than squishing cell text.
5. **System font stack** for crisp, fast rendering (no web fonts).
6. **Max-width container on `main`** via CSS — no `layout.php` change needed: `max-width: 960px; margin: 0 auto; padding: 0 1rem;`.
7. **Full-width buttons on mobile** for easy thumb-tapping.
8. **Larger touch targets** — padding increases to `0.6rem 1rem` inside media query.
9. **`flex-wrap: wrap`** on `.detail-summary` so it wraps naturally.

## CSS Changes

### New CSS: body reset + container + header + table wrapper

**Append near the top of the file (after the existing comment, before `.keyword-table`):**

```css
/* === Step 10: M8 Responsive CSS (base rules) === */

body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    font-size: 0.9rem;
    line-height: 1.5;
    color: #24292f;
    background: #ffffff;
}

header {
    background: #f6f8fa;
    border-bottom: 1px solid #ddd;
    padding: 0.75rem 1rem;
}

header h1 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

main {
    max-width: 960px;
    margin: 0 auto;
    padding: 0 1rem;
}

/* Horizontal scroll wrapper for tables on narrow screens */
.table-container {
    overflow-x: auto;
}
```

### New CSS: mobile media query

**Append at the end of the file:**

```css
/* === Step 10: M8 Responsive CSS (mobile) === */

@media (max-width: 640px) {
    /* Stack list header: Add button, search, refresh — vertically */
    .list-header {
        flex-direction: column;
        align-items: stretch;
        gap: 0.5rem;
    }

    /* Search form: input full-width, button wraps below */
    .search-form {
        width: 100%;
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    .search-form input {
        flex: 1 1 100%;
        width: 100%;
    }

    .search-form button,
    .search-form .clear-search {
        flex: 0 0 auto;
    }

    /* Full-width action buttons */
    .add-btn,
    .refresh-btn,
    .form-actions button {
        width: 100%;
        box-sizing: border-box;
    }

    /* Form actions: stack submit + cancel */
    .form-actions {
        flex-direction: column;
    }

    .form-actions a {
        text-align: center;
        padding: 0.4rem 0;
        display: block;
    }

    /* Detail summary: allow wrapping */
    .detail-summary {
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    /* Detail actions: stack */
    .detail-actions {
        flex-direction: column;
        gap: 0.5rem;
    }

    /* Larger touch targets */
    .refresh-btn,
    .delete-btn,
    .add-btn,
    .form-actions button,
    .keyword-table .delete-btn {
        padding: 0.6rem 1rem;
    }

    /* Tables: natural width, scrollable in .table-container on narrow screens */
    .keyword-table {
        width: auto;
        min-width: 550px;
    }
}
```

### Media queries explained

- `.list-header { flex-direction: column }` — Add, Search, Refresh stack vertically; each fills full width.
- `.search-form input { flex: 1 1 100% }` — input takes the full row; Search button wraps to the next line.
- `.add-btn, .refresh-btn { width: 100% }` — full-width buttons for easy tapping.
- `.form-actions { flex-direction: column }` — Submit and Cancel stack vertically.
- `.detail-summary { flex-wrap: wrap }` — summary line wraps if too wide.
- `.detail-actions { flex-direction: column }` — Edit and Delete buttons stack.
- `.keyword-table { width: auto; min-width: 550px }` — table sizes to content, forces horizontal scroll inside `.table-container` on screens narrower than 550px. On desktop, the media query doesn't apply, so `width: 100%` from the base rule stays.

## View Changes

### `views/keyword_list.php` — wrap table

```php
<?php else: ?>
    <div class="table-container">
        <table class="keyword-table">
            ...existing table...
        </table>
    </div>
<?php endif; ?>
```

### `views/keyword_detail.php` — wrap table

```php
<?php else: ?>
    <div class="table-container">
        <table class="keyword-table">
            ...existing table...
        </table>
    </div>
<?php endif; ?>
```

## Security (M6 Compliance)

| Concern | Status |
|---|---|
| SQL injection | No SQL changes in this step. |
| XSS | No HTML output changes. All values already escaped in existing views. The new wrapper divs contain no user data. |
| Input validation | No `$_GET`/`$_POST` touched. |
| Secrets | No new config or secrets. |
| GET writes | No mutations. CSS only. |

No security audit needed — this step adds CSS and wrapper divs only. No data flows change.

## Verification Steps

```bash
# 1. Fresh DB
php seed.php
# expect: Seeded 10 keywords, 300 positions

# 2. Lint touched PHP view files
php -l views/keyword_list.php
php -l views/keyword_detail.php

# 3. Start server
php -S localhost:8000 -t public public/router.php &

# 4. List page has table-container wrapper
curl -s http://localhost:8000/ | grep -c 'table-container'
# expect: 1

# 5. Detail page has table-container wrapper
curl -s http://localhost:8000/keyword/1 | grep -c 'table-container'
# expect: 1

# 6. CSS is served (200)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/assets/css/style.css
# expect: 200

# 7. CSS contains mobile media query
curl -s http://localhost:8000/assets/css/style.css | grep -c '@media (max-width: 640px)'
# expect: 1

# 8. CSS contains container rule
curl -s http://localhost:8000/assets/css/style.css | grep -c 'max-width: 960px'
# expect: 1

# 9. Full page still renders (list loads, 10 rows)
curl -s http://localhost:8000/ | grep -c 'data-keyword-id'
# expect: 10

# 10. Detail page still renders (30 history rows)
curl -s http://localhost:8000/keyword/1 | grep -c '<td>'
# expect: 90

# Stop server
kill %1
```

**Manual (visual) verification — open in browser dev tools and toggle device toolbar:**

```
# Desktop (~1200px): content centered within 960px max-width.
# Phone (375px): list header stacked, table horizontally scrollable (scroll
#   right to see Actions column), buttons full-width, form actions stacked.
```

## Suggested Commit

```
M8: add responsive CSS for phone-width usability
```

## Enables Step 11

Step 11 (M7 README) documents the setup steps. Step 10 completes all must-have features (M1–M8). The CSS is now complete — no further style changes needed.
