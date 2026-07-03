<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (is_logged_in()) {
    header('Location: /');
    exit;
}

$error = '';
$next = $_GET['next'] ?? '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $next = $_POST['next'] ?? '/';
    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (attempt_login($username, $password)) {
        header('Location: ' . (str_starts_with($next, '/') ? $next : '/'));
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - <?= h(app_name()) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-page">
<main class="login-card">
    <h1><?= h(app_name()) ?></h1>
    <?php if ($error): ?>
        <div class="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <label>
            Username
            <input type="text" name="username" autocomplete="username" required>
        </label>
        <label>
            Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
