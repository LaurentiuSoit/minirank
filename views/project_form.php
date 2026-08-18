<?php
/** @var int|null $projectId  null for add, int for edit */
/** @var string $projectName  Current or previously-submitted name */
/** @var string $projectWebsite  Current or previously-submitted website */
/** @var string|null $error  Error message to display */
/** @var string $formAction  POST target URL */
/** @var string $submitLabel  Button text */
?>

<?php if ($error !== null): ?>
    <p class="form-error"><?= escape($error) ?></p>
<?php endif; ?>

<form method="post" action="<?= escape($formAction) ?>" class="keyword-form">
    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
    <label for="name">Project name</label>
    <input type="text" name="name" id="name"
           value="<?= escape($projectName) ?>"
           maxlength="100" required>
    <label for="website">Website</label>
    <input type="url" name="website" id="website"
           value="<?= escape($projectWebsite) ?>"
           maxlength="2048" required>
    <div class="form-actions">
        <button type="submit"><?= escape($submitLabel) ?></button>
        <a href="<?= $projectId !== null ? '/project/' . $projectId : '/' ?>">Cancel</a>
    </div>
</form>
