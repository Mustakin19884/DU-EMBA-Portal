<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_role('teacher');

$pageTitle = $pageTitle ?? 'Teacher Dashboard';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | DU EMBA MIS</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin-layout.css">

    <style>
    .teacher-layout .admin-sidebar {
        background: #102d50;
    }

    .teacher-layout .admin-page-content {
        max-width: 1550px;
    }

    .teacher-layout .admin-menu a.active {
        background: #ffffff;
        color: #102d50;
    }

    .teacher-layout .admin-menu a:hover {
        background: #1c456e;
        color: #ffffff;
    }

    .teacher-layout .admin-menu a.active:hover {
        background: #ffffff;
        color: #102d50;
    }
    </style>
</head>

<body>

    <div class="admin-layout teacher-layout">

        <?php require __DIR__ . '/teacher-sidebar.php'; ?>

        <div class="admin-sidebar-backdrop" id="teacherSidebarBackdrop"></div>

        <div class="admin-content">

            <div class="admin-mobile-bar">

                <div class="admin-mobile-title">
                    DU EMBA MIS - Teacher
                </div>

                <button type="button" class="admin-menu-toggle" id="teacherMenuToggle" aria-label="Open navigation">
                    ☰
                </button>

            </div>

            <main class="admin-page-content">