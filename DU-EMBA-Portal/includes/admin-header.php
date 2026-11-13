<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

require_role('admin');

$pageTitle = $pageTitle ?? 'Administrator Panel';
$description = $description ?? 'DU EMBA MIS Student Portal Administration';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
        | DU EMBA MIS
    </title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin-layout.css">
</head>

<body>

    <div class="admin-layout">

        <?php require __DIR__ . '/admin-sidebar.php'; ?>

        <div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>

        <div class="admin-content">

            <div class="admin-mobile-bar">

                <div class="admin-mobile-title">
                    DU EMBA MIS
                </div>

                <button type="button" class="admin-menu-toggle" id="adminMenuToggle" aria-label="Open navigation">
                    ☰
                </button>

            </div>

            <main class="admin-page-content">