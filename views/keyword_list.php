<?php
/** @var array $keywords  Rows with keys: id, phrase, website, position */
/** @var string|null $searchTerm */
?>

<div class="list-header">
    <a href="/add" class="add-btn">Add keyword</a>

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
    <div class="table-container">
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
                     <td class="keyword-actions">
                         <a href="/edit/<?= $id ?>" class="edit-link">Edit</a>
                          <form method="post" action="/delete/<?= $id ?>" class="delete-form"
                                onsubmit="return confirm('Are you sure you want to remove this keyword?');">
                              <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                              <button type="submit" class="delete-btn">Delete</button>
                          </form>
                     </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<script src="/assets/js/refresh.js"></script>
