<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function require_role(string ...$roles): void
{
    require_login();

    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('403 Forbidden: You are not authorized to access this page.');
    }
}

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function dashboard_url(string $role): string
{
    $pages = [
        'student'  => '/student/dashboard.php',
        'teacher'  => '/teacher/dashboard.php',
        'accounts' => '/accounts/dashboard.php',
        'academic' => '/academic/dashboard.php',
        'admin'    => '/admin/dashboard.php',
    ];

    if (!isset($pages[$role])) {
        return BASE_URL . '/index.php';
    }

    return BASE_URL . $pages[$role];
}