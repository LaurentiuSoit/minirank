/* Refresh positions button — POST /refresh, update table rows in place.
   Forward-compatible with step 8's response format.
   Uses inline status text instead of alert dialogs. */

document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('refresh-btn');
    var statusEl = document.getElementById('refresh-status');
    if (!btn || !statusEl) return;

    function showStatus(msg, isError) {
        statusEl.textContent = msg;
        statusEl.style.display = 'block';
        statusEl.className = 'refresh-status ' + (isError ? 'status-error' : 'status-ok');
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = 'Refreshing…';
        showStatus('Refreshing...', false);

        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';

        fetch('/refresh', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(token)
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'ok' && data.keywords) {
                    data.keywords.forEach(function (kw) {
                        var row = document.querySelector('tr[data-keyword-id="' + kw.id + '"]');
                        if (!row) return;
                        var posCell = row.querySelector('.keyword-position');
                        var trendCell = row.querySelector('.keyword-trend');
                        if (posCell) posCell.textContent = kw.position;
                        if (trendCell) {
                            var label = kw.trend || 'stable';
                            trendCell.innerHTML = '<span class="trend ' + label + '">' + label + '</span>';
                        }
                    });
                    showStatus('Updated ' + data.updated + ' keywords.', false);
                } else {
                    showStatus('Refresh not available yet.', false);
                }
            })
            .catch(function () {
                showStatus('Error contacting server.', true);
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'Refresh positions';
            });
    });
});
