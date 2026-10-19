<?php
$page_title = $page_title ?? 'Dashboard';
$app_name = defined('APP_NAME')
    ? APP_NAME
    : 'DU EMBA MIS Student Portal';

$base_url = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> | <?= e($app_name) ?></title>

    <link rel="stylesheet" href="<?= e($base_url) ?>/assets/css/style.css">
</head>

<body>

    <header class="portal-topbar">
        <div class="portal-brand">
            <div class="portal-brand-mark">DU</div>
            <div>
                <strong>DU EMBA MIS</strong>
                <span>Student Portal</span>
            </div>
        </div>

        <?php if (!empty($_SESSION['full_name'])): ?>
        <div class="portal-user">
            <?= e($_SESSION['full_name']) ?>
        </div>
        <?php endif; ?>
    </header>

    <main class="site-main">