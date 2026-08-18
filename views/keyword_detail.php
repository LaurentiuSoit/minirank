<?php
/** @var array $keyword  [id, phrase, created_at] */
/** @var array $history  Entries: [date, position, trend, hasTrend] — newest first */
/** @var array $positions  [date, position] pairs, oldest-first, for the line chart */
/** @var int|null $currentPosition */
/** @var string $currentTrend  */
/** @var int $projectId */
/** @var array $project  [id, user_id, name, website, created_at] */

$id        = (int) $keyword['id'];
$phrase    = escape($keyword['phrase']);
$website   = escape($project['website']);
$createdAt = escape(date('M j, Y', strtotime($keyword['created_at'])));
?>

<a href="/project/<?= $projectId ?>" class="back-link">Back to keywords</a>

<h2 class="detail-title"><?= $phrase ?></h2>
<p class="keyword-website"><?= $website ?></p>
<p class="keyword-created">Tracking since: <?= $createdAt ?></p>

<div class="detail-summary">
    <span class="summary-label">Current position:</span>
    <span class="summary-value"><?= $currentPosition ?? '--' ?></span>
    <span class="trend <?= escape($currentTrend) ?>"><?= escape($currentTrend) ?></span>
    <span class="summary-hint">(7-day trend)</span>
</div>

<?php if (count($positions) > 0): ?>
    <div class="chart-wrapper">
        <svg class="detail-chart" viewBox="0 0 800 350" role="img"
             aria-label="Position history chart">
        </svg>
        <p class="chart-hint">Scroll to zoom &bull; Drag to pan &bull; Double-click to reset</p>
    </div>
<?php endif; ?>

<div class="detail-actions">
    <a href="/project/<?= $projectId ?>/keyword/<?= $id ?>/export" class="export-link">Export CSV</a>
    <a href="/project/<?= $projectId ?>/keyword/<?= $id ?>/edit" class="edit-link">Edit keyword</a>
    <form method="post" action="/project/<?= $projectId ?>/keyword/<?= $id ?>/delete" class="delete-form-inline"
           onsubmit="return confirm('Are you sure you want to remove this keyword?');">
          <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
          <button type="submit" class="delete-btn">Delete keyword</button>
    </form>
</div>

<?php if (count($history) === 0): ?>
    <p class="empty-state">No position history recorded yet.</p>
<?php else: ?>
    <div class="table-container">
        <table class="keyword-table">
            <thead>
                <tr>
                    <th>Date</th>
                <th>Position</th>
                <th>Trend (vs previous day)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $entry): ?>
                <?php
                    $trend      = $entry['trend'];
                    $trendClass = $trend ?? 'stable';
                ?>
                <tr>
                    <td><?= escape($entry['date']) ?></td>
                    <td><?= $entry['position'] ?></td>
                    <td>
                        <?php if ($entry['hasTrend']): ?>
                            <span class="trend <?= escape($trendClass) ?>"><?= escape($trend) ?></span>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php if (count($positions) > 0): ?>
    <script>
        window.MINIRANK_POSITIONS = <?= json_encode($positions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="/assets/js/chart.js"></script>
<?php endif; ?>
