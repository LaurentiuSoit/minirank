# S5 — CSV Export Implementation Plan

## Overview

Implement a CSV export endpoint that allows users to download a keyword's position history. This is a read-only GET route—safe because no database writes occur, and output is properly escaped via `fputcsv()`.

**Per the implementation ordering:** S5 is a self-contained feature that comes before S3 (auth) and S2 (projects). The initial route will be `/keyword/{id}/export`, which will evolve to `/project/{pid}/keyword/{kid}/export` after S2/S3 are implemented.

---

## Files to Modify/Create

| File | Changes |
|------|---------|
| `public/index.php` | Add `handleExport()` handler + new route |
| `views/keyword_detail.php` | Add "Export CSV" link in `.detail-actions` area |

---

## Implementation Steps

### Step 1: Add Helper Function for Filename Sanitization

**Location:** `src/helpers.php` (new function)

```php
function sanitizeFilename(string $phrase, int $id): string
{
    // Keep only alphanumeric, spaces, hyphens; limit length
    $slug = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $phrase);
    $slug = preg_replace('/\s+/', '-', trim($slug));
    $slug = substr($slug, 0, 50);

    if ($slug === '' || $slug === '-') {
        return 'keyword-' . $id . '.csv';
    }
    return $slug . '.csv';
}
```

### Step 2: Add `handleExport()` Handler

**Location:** `public/index.php` (after `handleDetail()`)

```php
function handleExport(int $id): void
{
    $pdo = getPdo();

    // Verify keyword exists
    $stmt = $pdo->prepare(
        'SELECT id, phrase, created_at FROM keywords WHERE id = ?'
    );
    $stmt->execute([$id]);
    $keyword = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($keyword === false) {
        sendNotFound('Keyword not found.');
    }

    // Fetch position history
    $stmt = $pdo->prepare(
        'SELECT recorded_at, position
         FROM positions
         WHERE keyword_id = ?
         ORDER BY recorded_at DESC'
    );
    $stmt->execute([$id]);
    $positionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sanitize keyword phrase for filename
    $filename = sanitizeFilename($keyword['phrase'], $id);

    // Set CSV headers
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Stream CSV output
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Position', 'Trend']);

    $previousPosition = null;
    foreach ($positionRows as $row) {
        $position = (int) $row['position'];
        $date = date('M j, Y', strtotime($row['recorded_at']));
        $trend = $previousPosition !== null
            ? calculateTrend($position, $previousPosition)
            : '';
        fputcsv($output, [$date, $position, $trend]);
        $previousPosition = $position;
    }

    fclose($output);
    exit;
}
```

### Step 3: Add Route for Export

**Location:** `public/index.php` (route dispatch section)

Add before the final `else`:

```php
} elseif ($method === 'GET' && $first === 'export' && ($id = validateIntId($second)) !== null) {
    handleExport($id);
```

### Step 4: Add Export Link in View

**Location:** `views/keyword_detail.php` (in `.detail-actions` div)

```php
<a href="/export/<?= $id ?>" class="export-link">Export CSV</a>
```

Add CSS styling (optional):

```css
.export-link {
    text-decoration: none;
    color: #0366d6;
    font-size: 0.9rem;
}
.export-link:hover {
    text-decoration: underline;
}
```

---

## Security Considerations

| Concern | Mitigation |
|---------|------------|
| SQL injection | All queries use prepared statements with bound parameters |
| CSV injection | `fputcsv()` handles RFC 4180 escaping |
| Header injection | Filename sanitized to alphanumeric/hyphens only |
| Authorization | Public now; will be auth-gated after S3 |

---

## Testing Commands

```bash
php -l public/index.php
php -l src/helpers.php
php seed.php
curl -I http://localhost:8000/export/1
curl http://localhost:8000/export/1 | head -3
```

---

## Verification

1. Access a keyword detail page → "Export CSV" link visible
2. Click link → CSV downloads with proper `Content-Type: text/csv`
3. CSV contains header row + history rows with Date, Position, Trend columns