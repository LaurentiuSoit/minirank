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
    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
    <label for="phrase">Keyword</label>
    <input type="text" name="phrase" id="phrase"
           value="<?= escape($phrase) ?>"
           maxlength="200" required>
    <div class="form-actions">
        <button type="submit"><?= escape($submitLabel) ?></button>
        <a href="/">Cancel</a>
    </div>
</form>
