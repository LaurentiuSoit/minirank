// Line chart for the keyword detail page (S1).
// Draws the N-day position history as an inline-SVG line chart.
//
// Y-axis: position 1 (best rank) at TOP, position 100 (worst) at BOTTOM.
// SVG y=0 is at the top; position numbers increase as quality worsens,
// so a direct linear map is correct (lower number = smaller y = higher visual).
//
// The line is split into per-segment polylines, colored by day-over-day
// movement: improved (green), declined (red), stable (gray).
//
// Zoom + pan:
//   - Mouse wheel over the chart: zoom in / out on the date (x) axis.
//   - Click + drag left/right: pan through the data when zoomed.
//   - Double-click: reset to full view.
//   - A scrollbar at the bottom shows the current viewport within the full range.
//
// All SVG elements are built with document.createElementNS — no innerHTML anywhere.
// Y-axis range is fixed at 1–100 so zoomed views stay comparable.

(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    // Short month names for x-axis labels: '2026-07-19' → 'Jul 19'.
    var MONTHS = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
    ];

    // --- Chart geometry (in viewBox units) ---
    // The SVG viewBox is "0 0 800 350". These paddings carve out the plot area.
    var cfg = {
        width: 800,
        height: 350,
        paddingLeft: 65,      // room for y-axis labels like "100"
        paddingRight: 25,
        paddingTop: 30,       // room for top margin
        paddingBottom: 60      // room for x-axis labels + scrollbar
    };
    cfg.plotWidth = cfg.width - cfg.paddingLeft - cfg.paddingRight;
    cfg.plotHeight = cfg.height - cfg.paddingTop - cfg.paddingBottom;

    // Minimum data points to show when fully zoomed in.
    var MIN_VISIBLE = 5;

    // --- Zoom / pan state ---
    var state = {
        points: null,
        viewStart: 0,
        viewEnd: 0,
        isPanning: false,
        panStartX: 0,           // CSS pixel — mouse x at drag start
        panStartViewStart: 0,   // viewStart at drag start
        panStartViewEnd: 0,     // viewEnd at drag start
        total: 0                // shortcut for points.length
    };

    // --- Helpers ---

    function formatDateTick(dateStr) {
        var month = parseInt(dateStr.slice(5, 7), 10);
        var day = parseInt(dateStr.slice(8, 10), 10);
        return MONTHS[month - 1] + ' ' + day;
    }

    // Day-over-day trend: posB is the NEXT day's position.
    // posB < posA (position dropped) → improved → green.
    // posB > posA (position rose)   → declined → red.
    function segmentTrend(posA, posB) {
        if (posB < posA) return 'improved';
        if (posB > posA) return 'declined';
        return 'stable';
    }

    // --- Scales ---

    // Y: position 1 (best) → top of plot; 100 (worst) → bottom.
    function y(pos) {
        return cfg.paddingTop + cfg.plotHeight * (pos - 1) / 99;
    }

    // X: maps a global data index to an SVG x-pixel within the visible range.
    // When zoomed, visible points are stretched across the full plot width.
    function x(i) {
        var visible = state.viewEnd - state.viewStart + 1;
        if (visible <= 1) {
            return cfg.paddingLeft;
        }
        return cfg.paddingLeft +
            (cfg.plotWidth * (i - state.viewStart)) / (visible - 1);
    }

    // --- Rendering ---

    function clearChart(svg) {
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }
    }

    function drawChart(svg, points) {
        clearChart(svg);

        // Plot background.
        var bg = document.createElementNS(SVG_NS, 'rect');
        bg.setAttribute('x', String(cfg.paddingLeft));
        bg.setAttribute('y', String(cfg.paddingTop));
        bg.setAttribute('width', String(cfg.plotWidth));
        bg.setAttribute('height', String(cfg.plotHeight));
        bg.setAttribute('class', 'chart-bg');
        svg.appendChild(bg);

        // Y-axis gridlines + labels (1, 25, 50, 75, 100).
        var yTicks = [1, 25, 50, 75, 100];
        for (var t = 0; t < yTicks.length; t++) {
            var val = yTicks[t];
            var yPos = y(val);

            var grid = document.createElementNS(SVG_NS, 'line');
            grid.setAttribute('x1', String(cfg.paddingLeft));
            grid.setAttribute('y1', String(yPos));
            grid.setAttribute('x2', String(cfg.width - cfg.paddingRight));
            grid.setAttribute('y2', String(yPos));
            grid.setAttribute('class', 'chart-grid');
            svg.appendChild(grid);

            var yLabel = document.createElementNS(SVG_NS, 'text');
            yLabel.setAttribute('x', String(cfg.paddingLeft - 10));
            yLabel.setAttribute('y', String(yPos + 4));
            yLabel.setAttribute('text-anchor', 'end');
            yLabel.setAttribute('class', 'chart-axis-label');
            yLabel.textContent = String(val);
            svg.appendChild(yLabel);
        }

        // X-axis labels (adaptive density for visible range).
        var renderedWidth = svg.getBoundingClientRect().width || cfg.width;
        var visibleCount = state.viewEnd - state.viewStart + 1;
        var step;
        if (visibleCount <= 7) {
            step = 1;
        } else if (renderedWidth >= 600) {
            step = 3;
        } else if (renderedWidth >= 350) {
            step = 5;
        } else {
            step = 10;
        }

        for (var i = state.viewStart; i <= state.viewEnd; i += step) {
            var xLabel = document.createElementNS(SVG_NS, 'text');
            xLabel.setAttribute('x', String(x(i)));
            xLabel.setAttribute('y', String(cfg.height - 30));
            xLabel.setAttribute('text-anchor', 'middle');
            xLabel.setAttribute('class', 'chart-axis-label');
            xLabel.textContent = formatDateTick(points[i][0]);
            svg.appendChild(xLabel);
        }

        // Line segments — one 2-point polyline per segment, colored by
        // day-over-day movement so trends are visible at a glance.
        for (var j = state.viewStart; j < state.viewEnd; j++) {
            var trend = segmentTrend(points[j][1], points[j + 1][1]);
            var segPoints =
                x(j) + ',' + y(points[j][1]) + ' ' +
                x(j + 1) + ',' + y(points[j + 1][1]);

            var seg = document.createElementNS(SVG_NS, 'polyline');
            seg.setAttribute('points', segPoints);
            seg.setAttribute('class', 'chart-line chart-line-' + trend);
            svg.appendChild(seg);
        }

        // Dots at each visible data point. Dot color reflects movement
        // INTO that point. First dot (globally index 0) has no previous day, so it's gray.
        for (var k = state.viewStart; k <= state.viewEnd; k++) {
            var dot = document.createElementNS(SVG_NS, 'circle');
            dot.setAttribute('cx', String(x(k)));
            dot.setAttribute('cy', String(y(points[k][1])));
            dot.setAttribute('r', '4');

            if (k === 0) {
                dot.setAttribute('class', 'chart-dot chart-dot-stable');
            } else {
                var dTrend = segmentTrend(points[k - 1][1], points[k][1]);
                dot.setAttribute('class', 'chart-dot chart-dot-' + dTrend);
            }
            svg.appendChild(dot);
        }

        drawZoomInfo(svg);
        drawScrollbar(svg);
    }

    // Text: "Showing A-B of N days" - placed below the scrollbar
    function drawZoomInfo(svg) {
        var info = document.createElementNS(SVG_NS, 'text');
        info.setAttribute('x', String(cfg.width - cfg.paddingRight));
        info.setAttribute('y', String(cfg.height - 2)); // below scrollbar (ends at y=height-16)
        info.setAttribute('text-anchor', 'end');
        info.setAttribute('class', 'chart-axis-label chart-zoom-text');
        info.textContent = 'Showing days ' + (state.viewStart + 1) + '–' +
            (state.viewEnd + 1) + ' of ' + state.total;
        svg.appendChild(info);
    }

    // Thin scrollbar showing the viewport position within the full range.
    function drawScrollbar(svg) {
        var barY = cfg.height - 22;  // 22 above bottom, scrollbar height is 6
        var barHeight = 6;
        var barX = cfg.paddingLeft;
        var barWidth = cfg.plotWidth;

        // Bar background track.
        var bar = document.createElementNS(SVG_NS, 'rect');
        bar.setAttribute('x', String(barX));
        bar.setAttribute('y', String(barY));
        bar.setAttribute('width', String(barWidth));
        bar.setAttribute('height', String(barHeight));
        bar.setAttribute('rx', '3');
        bar.setAttribute('class', 'chart-scrollbar-bg');
        svg.appendChild(bar);

        // Thumb — position + width proportional to the visible range.
        var totalSpan = Math.max(1, state.total - 1);
        var thumbX = barX + (barWidth * state.viewStart) / totalSpan;
        var thumbWidth = barWidth *
            (state.viewEnd - state.viewStart) / totalSpan;
        if (thumbWidth < 14) {
            thumbWidth = 14; // minimum width
        }

        var thumb = document.createElementNS(SVG_NS, 'rect');
        thumb.setAttribute('x', String(thumbX));
        thumb.setAttribute('y', String(barY));
        thumb.setAttribute('width', String(thumbWidth));
        thumb.setAttribute('height', String(barHeight));
        thumb.setAttribute('rx', '3');
        thumb.setAttribute('class', 'chart-scrollbar-thumb');
        svg.appendChild(thumb);
    }

    // --- Viewport adjustments ---

    function updateChart() {
        var svg = document.querySelector('.detail-chart');
        if (!svg || !state.points) return;
        drawChart(svg, state.points);
        updateCursor(svg);
    }

    // Mouse cursor feedback based on what's possible.
    function updateCursor(svg) {
        var visible = state.viewEnd - state.viewStart + 1;
        if (visible >= state.total) {
            svg.style.cursor = 'zoom-in';      // full view: can zoom in
        } else {
            svg.style.cursor = 'grab';         // zoomed in: can pan or zoom further
        }
    }

    // Zoom in by 20% of the visible range (from both ends).
    function zoomIn() {
        var visible = state.viewEnd - state.viewStart + 1;
        if (visible <= MIN_VISIBLE) return;

        var delta = Math.max(1, Math.floor(visible * 0.2));
        state.viewStart += delta;
        state.viewEnd -= delta;

        if (state.viewEnd - state.viewStart + 1 < MIN_VISIBLE) {
            state.viewStart = state.viewEnd - MIN_VISIBLE + 1;
        }
        updateChart();
    }

    // Zoom out by 20% (from both ends), clamped to full range.
    function zoomOut() {
        var visible = state.viewEnd - state.viewStart + 1;
        if (visible >= state.total) return;

        var delta = Math.max(1, Math.floor(visible * 0.2));
        state.viewStart = Math.max(0, state.viewStart - delta);
        state.viewEnd = Math.min(state.total - 1, state.viewEnd + delta);
        updateChart();
    }

    function resetZoom() {
        state.viewStart = 0;
        state.viewEnd = state.total - 1;
        updateChart();
    }

    // --- Event handlers ---

    function onWheel(e) {
        e.preventDefault();
        if (e.deltaY < 0) {
            zoomIn();
        } else {
            zoomOut();
        }
    }

    function onMouseDown(e) {
        // Only pan if zoomed in (not showing the full range).
        var visible = state.viewEnd - state.viewStart + 1;
        if (visible >= state.total) return;

        state.isPanning = true;
        state.panStartX = e.clientX;
        state.panStartViewStart = state.viewStart;
        state.panStartViewEnd = state.viewEnd;

        var svg = document.querySelector('.detail-chart');
        if (svg) {
            svg.style.cursor = 'grabbing';
        }
    }

    function onMouseMove(e) {
        if (!state.isPanning) return;

        // Pixel distance the mouse has moved since pan started.
        var dx = e.clientX - state.panStartX;

        // Convert pixel delta to data-index delta using current viewport scale.
        var svg = document.querySelector('.detail-chart');
        var svgRect = svg.getBoundingClientRect();
        var renderedWidth = svgRect.width || cfg.width;
        var viewBoxScale = renderedWidth / cfg.width;
        var plotWidthCSS = cfg.plotWidth * viewBoxScale;
        var visible = state.panStartViewEnd - state.panStartViewStart + 1;
        var pixelsPerPoint = plotWidthCSS / visible;

        // Natural grab: dragging right (dx > 0) pulls chart right → earlier dates (decreases index).
        // Dragging left (dx < 0) pulls chart left → later dates (increases index).
        var deltaIdx = Math.round(-dx / pixelsPerPoint);

        var newStart = state.panStartViewStart + deltaIdx;
        var newEnd = newStart + visible - 1;

        if (newStart < 0) {
            newStart = 0;
            newEnd = visible - 1;
        }
        if (newEnd > state.total - 1) {
            newEnd = state.total - 1;
            newStart = newEnd - visible + 1;
        }

        if (newStart !== state.viewStart || newEnd !== state.viewEnd) {
            state.viewStart = newStart;
            state.viewEnd = newEnd;
            updateChart();
        }
    }

    function onMouseUp() {
        if (state.isPanning) {
            state.isPanning = false;
            var svg = document.querySelector('.detail-chart');
            if (svg) {
                updateCursor(svg);
            }
        }
    }

    function onDoubleClick(e) {
        e.preventDefault();
        resetZoom();
    }

    // --- Init ---

    document.addEventListener('DOMContentLoaded', function () {
        var svg = document.querySelector('.detail-chart');
        if (!svg) return;

        var points = window.MINIRANK_POSITIONS;
        if (!points || !points.length) return;

        state.points = points;
        state.total = points.length;
        state.viewStart = 0;
        state.viewEnd = points.length - 1;

        drawChart(svg, points);
        updateCursor(svg);

        // Interactive handlers.
        svg.addEventListener('wheel', onWheel, { passive: false });
        svg.addEventListener('pointerdown', onMouseDown);
        svg.addEventListener('dblclick', onDoubleClick);

        // Drag events on document so we catch drags that leave the SVG.
        document.addEventListener('pointermove', onMouseMove);
        document.addEventListener('pointerup', onMouseUp);
        document.addEventListener('pointercancel', onMouseUp);
    });
})();
