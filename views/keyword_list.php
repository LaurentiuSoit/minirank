<?php
/** @var array $keywords  Rows with keys: id, phrase, website, position */
/** @var string|null $searchTerm */
?>

<div class="list-header">
    <form method="get" action="/" class="search-form">
        <input type="search" name="q"
               value="<?= escape((string)($searchTerm ?? '')) ?>"
               maxlength="100" placeholder="Search keywords...">
        <button type="submit">Search</button>
        <?php if ($searchTerm !== null): ?>
            <a href="/" class="clear-search">Clear</a>
        <?php endif; ?>
    </form>

    <button type="button" id="refresh-btn" class="refresh-btn">Refresh positions</button>
</div>

<div id="refresh-status" class="refresh-status" style="display: none;"></div>

<?php if (count($keywords) === 0): ?>
    <p class="empty-state">No keywords found.</p>
<?php else: ?>
    <table class="keyword-table">
        <thead>
            <tr>
                <th>Keyword</th>
                <th>Website</th>
                <th>Position</th>
                <th>7-day trend</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($keywords as $kw): ?>
                <?php
                    $id       = (int) $kw['id'];
                    $phrase   = escape($kw['phrase']);
                    $website  = escape($kw['website']);
                    $position = $kw['position'] !== null ? (int) $kw['position'] : null;
                    $trend    = $kw['trend'];
                    $trendClass = $trend ?? 'stable';
                ?>
                <tr data-keyword-id="<?= $id ?>">
                    <td class="keyword-phrase">
                        <a href="/keyword/<?= $id ?>"><?= $phrase ?></a>
                    </td>
                    <td class="keyword-website"><?= $website ?></td>
                    <td class="keyword-position"><?= $position ?? '--' ?></td>
                    <td class="keyword-trend">
                        <span class="trend <?= $trendClass ?>">
                            <?= $trend !== null ? $trend : 'no data' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script src="/assets/js/refresh.js"></script>
