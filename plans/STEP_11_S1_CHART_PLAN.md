# Step 11 — S1: Line Chart on the Keyword Detail Page

## Goal

Add a hand-rolled inline-SVG line chart to the keyword detail page showing the
30-day position history. Y-axis is oriented with **position 1 (best) at the top**
and **position 100 at the bottom**. The line is split into per-segment polylines colored
by day-over-day movement: **green** (improved), **red** (declined), **gray**
(stable). Includes zoom in/out, pan, reset, and scrollbar indicator.

## Files Modified / Created (4)

### 1. `public/index.php` — `handleDetail()`

**No new SQL.** The existing query (already fetches `position, recorded_at`
newest-first) is reused. Add a `$positions` array (oldest-first, chronological)
after `$history` is built, and pass it to the view.

```php
$positions = [];
foreach (array_reverse($positionRows) as $row) {
    $positions[] = [
        $row['recorded_at'],   // 'Y-m-d' string — from DB, no user input
        (int) $row['position'], // integer — CHECK(1..100) at DB level
    ];
}
```

### 2. `views/keyword_detail.php`

Two insertions:

**(A)** After `.detail-summary` div, before `.detail-actions`:
```php
<?php if (count($positions) > 0): ?>
    <div class="chart-wrapper">
        <svg class="detail-chart" viewBox="0 0 800 350" role="img"
             aria-label="Position history chart">
        </svg>
        <p class="chart-hint">Scroll to zoom &bull; Drag to pan &bull; Double-click to reset</p>
    </div>
<?php endif; ?>
```

**(B)** At end of file (after history table):
```php
<?php if (count($positions) > 0): ?>
    <script>
        window.MINIRANK_POSITIONS = <?= json_encode($positions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="/assets/js/chart.js"></script>
<?php endif; ?>
```

**Safety:** `JSON_HEX_TAG` escapes `<` → `\u003C` and `>` → `\u003E`, preventing
`</script>` breakout. Data is only date strings + integers. No `innerHTML` in JS.

### 3. `public/assets/js/chart.js` (new file)

- `drawChart(svg, points)` creates all SVG elements via `document.createElementNS`.
- Y-axis: `y(pos) = paddingTop + plotHeight * (pos - 1) / 99` (pos 1 = top, pos 100 = bottom).
- X-axis: maps data index to x within visible range.
- Line split into per-segment `<polyline>` elements, colored by `segmentTrend()`.
- `<circle>` dots colored by movement into that point (first dot = gray).
- Y-axis gridlines and labels at 1, 25, 50, 75, 100.
- X-axis labels with adaptive step based on rendered width and visible point count.
- **Zoom & Pan**:
  - Mouse wheel zooms in/out (20% step, min 5 points visible).
  - Click & drag pans horizontally across data.
  - Double-click resets to full 30-day view.
  - Bottom scrollbar track and thumb show current viewport within full range.
  - Dynamic cursor feedback (`zoom-in`, `zoom-out`, `grab`, `grabbing`).

### 4. `public/assets/css/style.css`

- Desktop max-width: 800px; mobile max-width: 100%.
- Line stroke-width: 2.5px; dot radius: 4px.
- Styling for `.chart-bg`, `.chart-grid`, `.chart-axis-label`, `.chart-scrollbar-bg`, `.chart-scrollbar-thumb`, `.chart-hint`.

## Security Compliance

| Rule | Compliance |
|------|-----------|
| Parameterized SQL | No new queries — reuses existing prepared statement. |
| Escaped output | `json_encode` with `JSON_HEX_TAG` escapes `<`/`>`/`&`. |
| No secrets | No new constants or credentials. |
| Validate superglobals | No new superglobal access. |
| No GET writes | Read-only chart rendering. |

## Verification

```bash
php -l public/index.php
php -l views/keyword_detail.php
node --check public/assets/js/chart.js
grep -RIn 'prepare(' views/              # expect: no output
php -S localhost:8000 -t public public/router.php
# Visit /keyword/1 → verify chart rendering, zoom, pan, reset
```

## Commit Message

```
S1: line chart on keyword detail page with zoom/pan

Add an inline-SVG line chart (800x350) to the detail page showing the 30-day
position history with position 1 (best) at top and 100 at bottom.
Segments colored by day-over-day movement (green/red/gray).
Includes mouse wheel zoom, click-and-drag pan, double-click reset,
and a viewport scrollbar indicator.
```
