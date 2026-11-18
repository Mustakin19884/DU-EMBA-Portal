<?php
require_once __DIR__ . '/auth.php';

require_role('academic');

if (!isset($pageTitle)) {
    $pageTitle = 'Academic Office';
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    .academic-layout {
        max-width: 1250px;
        margin: 30px auto;
        padding: 0 20px;
    }

    .academic-header {
        background: #102d50;
        color: #fff;
        padding: 24px 28px;
        border-radius: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
    }

    .academic-header h1 {
        margin: 0 0 7px;
    }

    .academic-header p {
        margin: 0;
        opacity: .85;
    }

    .academic-nav {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin: 18px 0;
        padding: 12px;
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 10px;
    }

    .academic-nav a {
        text-decoration: none;
        color: #263548;
        padding: 10px 16px;
        border-radius: 7px;
        font-weight: 600;
    }

    .academic-nav a.active,
    .academic-nav a:hover {
        background: #102d50;
        color: #fff;
    }

    .academic-content {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 25px;
        min-height: 350px;
    }

    .academic-logout {
        background: #fff;
        color: #102d50;
        text-decoration: none;
        padding: 10px 17px;
        border-radius: 7px;
        font-weight: 600;
    }

    @media (max-width: 600px) {
        .academic-layout {
            padding: 0 12px;
            margin: 15px auto;
        }

        .academic-content {
            padding: 16px;
        }
    }
    </style>
</head>

<body>

    <div class="academic-layout">

        <header class="academic-header">
            <div>
                <h1><?= e($pageTitle) ?></h1>
                <p>Welcome, <?= e($_SESSION['full_name'] ?? 'Academic Officer') ?></p>
            </div>

            <a class="academic-logout" href="<?= BASE_URL ?>/auth/logout.php">
                Logout
            </a>
        </header>

        <nav class="academic-nav">
            <a href="<?= BASE_URL ?>/academic/dashboard.php"
                class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                Dashboard
            </a>

            <a href="<?= BASE_URL ?>/academic/results.php"
                class="<?= $currentPage === 'results.php' || $currentPage === 'result-details.php' ? 'active' : '' ?>">
                Result Approval
            </a>
        </nav>

        <main class="academic-content">