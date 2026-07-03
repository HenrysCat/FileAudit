<?php

declare(strict_types=1);

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in']);
}

function require_login(): void
{
    if (is_logged_in()) {
        return;
    }

    $next = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: /login.php?next=' . rawurlencode($next));
    exit;
}

function attempt_login(string $username, string $password): bool
{
    $config = config();
    $expectedUser = (string)($config['ADMIN_USERNAME'] ?? '');
    $hash = (string)($config['ADMIN_PASSWORD_HASH'] ?? '');

    if (!hash_equals($expectedUser, $username)) {
        return false;
    }

    if (!password_verify($password, $hash)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = $username;

    return true;
}

function logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }

    session_destroy();
}
