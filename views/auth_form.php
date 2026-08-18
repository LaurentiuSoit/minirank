<?php
/** @var string $mode    'register' or 'login' */
/** @var string|null $error  Error message to display */
/** @var string $action  POST target URL */

// Pre-fill the email field from the most recent submission so the user
// doesn't retype it after a validation error. Escaped on output below.
$emailValue = $_POST['email'] ?? '';
$submitLabel = $mode === 'register' ? 'Create account' : 'Log in';
$switchLabel = $mode === 'register'
    ? 'Already have an account? Log in'
    : 'Need an account? Register';
$switchPath = $mode === 'register' ? '/login' : '/register';
?>

<?php if ($error !== null): ?>
    <p class="form-error"><?= escape($error) ?></p>
<?php endif; ?>

<form method="post" action="<?= escape($action) ?>" class="keyword-form">
    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
    <div class="form-group">
        <label for="email">Email</label>
        <input type="email" name="email" id="email"
               value="<?= escape((string) $emailValue) ?>"
               maxlength="254" required autofocus>
    </div>
    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" name="password" id="password"
               minlength="8" required>
    </div>
    <div class="form-actions">
        <button type="submit"><?= escape($submitLabel) ?></button>
        <a href="<?= escape($switchPath) ?>"><?= escape($switchLabel) ?></a>
    </div>
</form>
