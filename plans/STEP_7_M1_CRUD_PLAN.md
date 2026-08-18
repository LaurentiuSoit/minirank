# Step 7 Plan: M1 CRUD (Add/Edit/Delete Keywords)

## Status

| Item | Status | Detail |
|---|---|---|
| Steps 1-6 | Done | Plan, git init, seed.php, db.php + helpers.php, router + stubs + layout, keyword list with search + refresh button. |
| `handleAddForm()` | Stub | `public/index.php:102-106` |
| `handleCreate()` | Stub | `public/index.php:108-112` |
| `handleEditForm()` | Stub | `public/index.php:120-125` |
| `handleUpdate()` | Stub | `public/index.php:126-130` |
| `handleDelete()` | Stub | `public/index.php:132-136` |
| Route dispatch | Wired | `GET/POST /add`, `GET/POST /edit/{id}`, `POST /delete/{id}` already in `index.php:156-169` |
| Keyword list view | No CRUD links | `views/keyword_list.php` has no add/edit/delete actions yet |
| `keyword_form.php` | Does not exist | To be created |

## What This Step Builds

**Requirement M1:** Add/edit/delete keywords (search phrases) for the one configured website. All mutations via POST (PRG pattern). All queries parameterized. All output escaped.

The website is always `SITE_URL` — the form has a single `phrase` input. No website field. This matches the candidate brief: "add/edit/delete the keywords (search phrases) tracked for one configured website."

## Decisions Confirmed with User

1. **Delete strategy**: Modify `seed.php` schema to add `ON DELETE CASCADE` to the `positions` foreign key. `handleDelete` becomes a single `DELETE FROM keywords WHERE id = ?` — position rows cascade-delete automatically.
2. **Form view name**: `views/keyword_form.php` (neutral name serving both add and edit).
3. **Delete confirmation**: JS `confirm()` dialog on the POST form's `onsubmit` event — no separate confirmation page/route needed. Message is generic (no dynamic content in the JS string, avoiding XSS).
4. **CSRF**: No CSRF tokens (single-user, no login, per AGENTS rules).

## Files Affected

| File | Action | Purpose |
|---|---|---|
| `seed.php` | Modify | Add `ON DELETE CASCADE` to `positions` table foreign key (line 38). |
| `public/index.php` | Modify | Replace 5 handler stubs with real implementations. |
| `views/keyword_form.php` | **Create** | Shared add/edit form view (single `phrase` field + error display). |
| `views/keyword_list.php` | Modify | Add "Add keyword" button + per-row Edit/Delete actions column. |
| `public/assets/css/style.css` | Modify | Add form + action-button styles. |

5 files. The `seed.php` change is one word (`ON DELETE CASCADE`). The remaining changes are new view code + incremental additions. Within AGENTS.md 3-4 file guideline for substantive changes (seed.php is trivial; CSS is additive).

## Schema Change

**File:** `seed.php`, line 38

Before:
```sql
    FOREIGN KEY (keyword_id) REFERENCES keywords (id)
```

After:
```sql
    FOREIGN KEY (keyword_id) REFERENCES keywords (id) ON DELETE CASCADE
```

Consequence: `handleDelete` no longer needs to explicitly delete positions. SQLite handles it via foreign key cascade (`getPdo()` already sets `PRAGMA foreign_keys = ON`).

---

## Handler Implementations

All five handlers live in `public/index.php`, replacing the existing stubs.

### `handleAddForm()` — replaces `index.php:102-106`

```php
function handleAddForm(): void
{
    renderPage('Add Keyword', 'keyword_form.php', [
        'keywordId'    => null,
        'phrase'       => '',
        'error'        => null,
        'formAction'   => '/add',
        'submitLabel'  => 'Add keyword',
    ]);
}
```

### `handleCreate()` — replaces `index.php:108-112`

```php
function handleCreate(): void
{
    $pdo = getPdo();

    $phrase = validateString($_POST['phrase'] ?? null, 200);

    if ($phrase === null) {
        renderPage('Add Keyword', 'keyword_form.php', [
            'keywordId'    => null,
            'phrase'       => is_string($_POST['phrase'] ?? null) ? $_POST['phrase'] : '',
            'error'        => 'Please enter a keyword (1–200 characters).',
            'formAction'   => '/add',
            'submitLabel'  => 'Add keyword',
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO keywords (phrase, website, created_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$phrase, SITE_URL, date('Y-m-d H:i:s')]);

    redirect('/');
}
```

### `handleEditForm(int $id)` — replaces `index.php:120-125`

```php
function handleEditForm(int $id): void
{
    $pdo = getPdo();

    $stmt = $pdo->prepare('SELECT id, phrase FROM keywords WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        sendNotFound('Keyword not found.');
    }

    renderPage('Edit Keyword', 'keyword_form.php', [
        'keywordId'    => (int) $row['id'],
        'phrase'       => $row['phrase'],
        'error'        => null,
        'formAction'   => '/edit/' . (int) $row['id'],
        'submitLabel'  => 'Update keyword',
    ]);
}
```

### `handleUpdate(int $id)` — replaces `index.php:126-130`

```php
function handleUpdate(int $id): void
{
    $pdo = getPdo();

    // Verify keyword exists (don't silently no-op on stale form POST).
    $stmt = $pdo->prepare('SELECT id FROM keywords WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() === false) {
        sendNotFound('Keyword not found.');
    }

    $phrase = validateString($_POST['phrase'] ?? null, 200);

    if ($phrase === null) {
        renderPage('Edit Keyword', 'keyword_form.php', [
            'keywordId'    => $id,
            'phrase'       => is_string($_POST['phrase'] ?? null) ? $_POST['phrase'] : '',
            'error'        => 'Please enter a keyword (1–200 characters).',
            'formAction'   => '/edit/' . $id,
            'submitLabel'  => 'Update keyword',
        ]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE keywords SET phrase = ? WHERE id = ?');
    $stmt->execute([$phrase, $id]);

    redirect('/');
}
```

### `handleDelete(int $id)` — replaces `index.php:132-136`

With `ON DELETE CASCADE`, this is a single DELETE. Positions cascade-delete via the foreign key.

```php
function handleDelete(int $id): void
{
    $pdo = getPdo();

    // Verify keyword exists (avoid silent no-op on stale POST).
    $stmt = $pdo->prepare('SELECT id FROM keywords WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() === false) {
        sendNotFound('Keyword not found.');
    }

    $stmt = $pdo->prepare('DELETE FROM keywords WHERE id = ?');
    $stmt->execute([$id]);

    redirect('/');
}
```

### Validation error handling

When `validateString()` returns `null` (empty or >200 chars), the form is re-rendered with:
- The error message displayed above the form.
- The user's submitted input preserved in the text field (so they can edit and retry without retyping everything).

The submitted value is preserved via `is_string($_POST['phrase'] ?? null) ? $_POST['phrase'] : ''` — this safely extracts a string from `$_POST`, returning an empty string for non-string inputs.

---

## View Templates

### `views/keyword_form.php` (new)

Shared form for both add and edit. Receives:
- `keywordId` — `null` for add, `int` for edit (used for the hidden field).
- `phrase` — current value from DB or previously-submitted value.
- `error` — error message or `null`.
- `formAction` — POST target (`/add` or `/edit/{id}`).
- `submitLabel` — button text ("Add keyword" / "Update keyword").

```php
<?php
/** @var int|null $keywordId  null for add, int for edit */
/** @var string $phrase  Current or previously-submitted phrase */
/** @var string|null $error  Error message to display */
/** @var string $formAction  POST target URL */
/** @var string $submitLabel  Button text */
?>

<?php if ($error !== null): ?>
    <p class="form-error"><?= escape($error) ?></p>
<?php endif; ?>

<form method="post" action="<?= escape($formAction) ?>" class="keyword-form">
    <label for="phrase">Search phrase</label>
    <input type="text" name="phrase" id="phrase"
           value="<?= escape($phrase) ?>"
           maxlength="200" required>
    <div class="form-actions">
        <button type="submit"><?= escape($submitLabel) ?></button>
        <a href="/">Cancel</a>
    </div>
</form>
```

**Escaping audit:**
- `$formAction` — server-constructed path (`/add` or `/edit/{int}`). Escaped per safety habit.
- `$phrase` — from DB or `$_POST`. Escaped. The `value="..."` attribute is safe because `escape()` converts `"` to `&quot;`.
- `$submitLabel`, `$error` — hardcoded strings. Escaped for consistency.
- No dynamic content in any JavaScript context.

### `views/keyword_list.php` (modified)

Two changes to the existing list view:

**1. Add "Add keyword" button in `.list-header`:**
```php
<div class="list-header">
    <a href="/add" class="add-btn">Add keyword</a>

    <form method="get" action="/" class="search-form">
        ...existing search form...
    </form>

    <button type="button" id="refresh-btn" class="refresh-btn">Refresh positions</button>
</div>
```

**2. Add Actions column with Edit link and Delete form:**
```php
<table class="keyword-table">
    <thead>
        <tr>
            <th>Keyword</th>
            <th>Website</th>
            <th>Position</th>
            <th>7-day trend</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($keywords as $kw): ?>
            ...existing row data...
            <td class="keyword-actions">
                <a href="/edit/<?= $id ?>" class="edit-link">Edit</a>
                <form method="post" action="/delete/<?= $id ?>" class="delete-form"
                      onsubmit="return confirm('Are you sure you want to remove this keyword?');">
                    <button type="submit" class="delete-btn">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
```

The `onsubmit="return confirm(...)"` uses a **static** confirmation message (no dynamic content) to avoid any JS injection risk. The `return false` on cancel prevents form submission; `return true` allows it.

---

## CSS Additions (`public/assets/css/style.css`)

Add styles for: form layout, error message, input styling, form action buttons, add keyword button, actions column, delete form (inline), delete button with hover state.

---

## Data Flow

```
GET  /add              → handleAddForm()          → renderPage(title='Add Keyword', 'keyword_form.php', empty form data)
POST /add              → handleCreate()           → validateString() → INSERT → redirect('/')
GET  /edit/12          → handleEditForm(12)       → SELECT keyword → renderPage(title='Edit Keyword', 'keyword_form.php', pre-filled)
POST /edit/12          → handleUpdate(12)         → validateString() → UPDATE → redirect('/')
POST /delete/12         → handleDelete(12)         → DELETE (positions cascade) → redirect('/')
```

---

## Security (M6 Compliance)

| Concern | How step 7 addresses it |
|---|---|
| **SQL injection** | Every query uses `prepare()` + `execute([$param])`. INSERT, UPDATE, DELETE, SELECT — all parameterized. No string interpolation in SQL. Even the seed.php change adds only a DDL keyword, not dynamic SQL. |
| **XSS** | All echoed values pass through `escape()`. Form field value, error message, action URL, button labels — all escaped. `keyword_list.php` uses `(int)` cast for IDs in attributes. |
| **Input validation** | `$_POST['phrase']` → `validateString(..., 200)` returns `null` on empty/oversized. `validateIntId` already guards the route path segments (e.g. `/keyword/abc` → 404). |
| **Open redirect** | `redirect()` only receives hardcoded `'/'`. No user input in redirect target. |
| **Delete via GET** | Delete is POST-only with JS `confirm()` guard. AGENTS rule: "Do not write to the database from a GET request." |
| **CSRF** | Not addressed (single-user, no login). Noted for potential future follow-up. |
| **Secrets** | No new config. Uses existing `SITE_URL` and `DB_PATH` constants. |

## How This Enables Steps 8-9

| Step | Need | Satisfied by step 7 |
|---|---|---|
| Step 8 (M3 AJAX refresh) | `renderPage()` + view pattern + list view structure | `keyword_form.php` demonstrates the view pattern; list view now has structured table with `data-keyword-id` hooks. |
| Step 9 (M5 detail) | `/keyword/{id}` route + layout + view convention | Form/list views establish conventions. `handleDetail` stub remains for step 9. |

## Verification Steps

```bash
# 1. Re-seed to pick up ON DELETE CASCADE schema change
php seed.php
# expect: Seeded 10 keywords, 300 positions into data/minirank.sqlite

# 2. Lint all touched PHP files
php -l public/index.php
php -l views/keyword_form.php
php -l views/keyword_list.php

# 3. Start server
php -S localhost:8000 -t public public/router.php &

# 4. Add form renders (200, contains form + submit)
curl -s http://localhost:8000/add | grep -o 'Add keyword'

# 5. Create keyword (POST -> 303 redirect)
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8000/add \
  --data-urlencode "phrase=test+keyword"
# expect: 303

# 6. Verify keyword was inserted
curl -s http://localhost:8000/ | grep -c "test keyword"
# expect: >= 1

# 7. Create with empty phrase (re-renders form with error, 200)
curl -s -X POST http://localhost:8000/add --data-urlencode "phrase=" | grep -o "Please enter a keyword"

# 8. Create with empty phrase (no body) (re-renders form with error, 200)
curl -s -X POST http://localhost:8000/add | grep -o "Please enter a keyword"

# 9. Create with too-long phrase (>200 chars) (re-renders form with error, 200)
curl -s -X POST http://localhost:8000/add --data-urlencode "phrase=$(php -r 'echo str_repeat("x", 201);')" | grep -o "Please enter a keyword"

# 10. Edit form renders for existing keyword
curl -s http://localhost:8000/edit/1 | grep -o "Update keyword"

# 11. Edit form 404s for non-existent keyword
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/edit/99999
# expect: 404

# 12. Update keyword (POST -> 303)
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8000/edit/1 \
  --data-urlencode "phrase=updated+phrase"
# expect: 303

# 13. Verify update
curl -s http://localhost:8000/ | grep -c "updated phrase"
# expect: >= 1

# 14. Update with empty phrase re-renders form with error
curl -s -X POST http://localhost:8000/edit/1 --data-urlencode "phrase=" | grep -o "Please enter a keyword"

# 15. Update on non-existent keyword -> 404
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8000/edit/99999 \
  --data-urlencode "phrase=test"
# expect: 404

# 16. Delete keyword (POST -> 303)
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8000/delete/2
# expect: 303

# 17. Deleted keyword no longer appears in list
curl -s http://localhost:8000/ | grep -c "best running shoes"
# expect: 0

# 18. Positions for deleted keyword cascade-deleted
php -r 'require "src/db.php"; $pdo=getPdo();
  $stmt=$pdo->prepare("SELECT COUNT(*) FROM positions WHERE keyword_id=?");
  $stmt->execute([2]); echo "positions for deleted keyword: ", $stmt->fetchColumn(), "\n";'
# expect: positions for deleted keyword: 0

# 19. Delete on non-existent keyword -> 404
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8000/delete/99999
# expect: 404

# 20. Invalid keyword ID in URL -> 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/edit/abc
# expect: 404
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/delete/abc
# expect: 404

# 21. Add button visible on list page
curl -s http://localhost:8000/ | grep -o 'Add keyword'

# 22. Edit link visible per row
curl -s http://localhost:8000/ | grep -c 'href="/edit/'
# expect: >= 1

# Stop server
kill %1
```

## Suggested Commit

```
M1: implement add/edit/delete keyword CRUD with forms and POST handlers
```

## Files Affected (Summary)

- **Modified**: `seed.php` (add `ON DELETE CASCADE` to positions FK — one word)
- **Modified**: `public/index.php` (implement 5 handler stubs)
- **New**: `views/keyword_form.php` (shared add/edit form)
- **Modified**: `views/keyword_list.php` (add "Add keyword" button + Actions column)
- **Modified**: `public/assets/css/style.css` (form + button styles)
