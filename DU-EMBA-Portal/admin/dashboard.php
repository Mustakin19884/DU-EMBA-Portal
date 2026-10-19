<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$pageTitle = 'Administrator Dashboard';
$description = 'Manage user accounts, roles, system access and portal settings.';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | DU EMBA MIS Portal</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <main class="dashboard-container">
        <header class="dashboard-header">
            <div>
                <h1><?= e($pageTitle) ?></h1>
                <p>Welcome, <?= e($_SESSION['full_name'] ?? 'Administrator') ?></p>
            </div>
            <a href="<?= BASE_URL ?>/auth/logout.php">Logout</a>
        </header>

        <section class="dashboard-content">
            <h2>Administrator Workspace</h2>
            <p><?= e($description) ?></p>

            <div class="dashboard-grid">
                <a class="portal-card" href="<?= BASE_URL ?>/admin/users.php">
                    <span>User Management</span>
                    <span class="arrow">&rarr;</span>
                </a>

                <a class="portal-card" href="<?= BASE_URL ?>/admin/users.php">
                    <span>Manage Roles & Access</span>
                    <span class="arrow">&rarr;</span>
                </a>

                <a class="portal-card" href="<?= BASE_URL ?>/admin/users.php">
                    <span>Account Status</span>
                    <span class="arrow">&rarr;</span>
                </a>
            </div>
        </section>
    </main>
</body>

</html>