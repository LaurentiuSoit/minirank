<?php
/** @var array $keywords  Rows with keys: id, phrase, position */
/** @var string|null $searchTerm */
/** @var int $projectId */
/** @var array $project  [id, user_id, name, website, created_at] */
?>

<div class="project-header">
    <h2 class="project-name"><?= escape($project['name']) ?></h2>
    <p class="project-website">
        <a href="<?= escape($project['website']) ?>" target="_blank" rel="noopener noreferrer">
            <?= escape($project['website']) ?>
        </a>
    </p>
    <div class="project-actions">
        <a href="/project/<?= $projectId ?>/edit" class="edit-link">Edit project</a>
        <form method="post" action="/project/<?= $projectId ?>/delete" class="delete-form-inline"
              onsubmit="return confirm('Are you sure you want to delete this project? All its keywords and positions will be removed.');">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <button type="submit" class="delete-btn">Delete project</button>
        </form>
    </div>
</div>

<div class="list-header">
    <a href="/project/<?= $projectId ?>/add" class="add-btn">Add keyword</a>

    <form method="get" action="/project/<?= $projectId ?>" class="search-form">
        <input type="search" name="q"
               value="<?= escape((string)($searchTerm ?? '')) ?>"
               maxlength="100" placeholder="Search keywords...">
        <button type="submit">Search</button>
        <?php if ($searchTerm !== null): ?>
            <a href="/project/<?= $projectId ?>" class="clear-search">Clear</a>
        <?php endif; ?>
    </form>

    <button type="button" id="refresh-btn" class="refresh-btn"
            data-project-id="<?= $projectId ?>">Refresh positions</button>
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
                        $position = $kw['position'] !== null ? (int) $kw['position'] : null;
                        $trend    = $kw['trend'];
                        $trendClass = $trend ?? 'stable';
                    ?>
                    <tr data-keyword-id="<?= $id ?>">
                        <td class="keyword-phrase">
                            <a href="/project/<?= $projectId ?>/keyword/<?= $id ?>"><?= $phrase ?></a>
                        </td>
                        <td class="keyword-position"><?= $position ?? '--' ?></td>
                        <td class="keyword-trend">
                            <span class="trend <?= $trendClass ?>">
                                <?= $trend !== null ? $trend : 'no data' ?>
                            </span>
                        </td>
                        <td class="keyword-actions">
                            <a href="/project/<?= $projectId ?>/keyword/<?= $id ?>/edit" class="edit-link">Edit</a>
                            <form method="post" action="/project/<?= $projectId ?>/keyword/<?= $id ?>/delete" class="delete-form"
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
