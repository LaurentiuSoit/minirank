<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= escape(csrfToken()) ?>">
    <title><?= escape($title ?? 'MiniRank') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <header>
        <h1>MiniRank</h1>
        <?php if (isLoggedIn()): ?>
            <nav class="auth-nav">
                <form method="post" action="/logout" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                    <button type="submit" class="logout-btn">Log out</button>
                </form>
            </nav>
        <?php else: ?>
            <nav class="auth-nav">
                <a href="/login">Log in</a>
                <span class="nav-divider">|</span>
                <a href="/register">Register</a>
            </nav>
        <?php endif; ?>
    </header>
    <main>
        <?= $content ?>
    </main>
</body>
</html>
