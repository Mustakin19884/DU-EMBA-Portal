<?php
require_once __DIR__ . '/../includes/auth.php';

$requiredRole = 'teacher';
$pageTitle = 'Teacher Dashboard';

require_role($requiredRole);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <div>
                <h1><?= e($pageTitle) ?></h1>
                <p>Welcome, <?= e($_SESSION['full_name']) ?></p>
            </div>
            <a class="btn-logout" href="<?= BASE_URL ?>/auth/logout.php">Logout</a>
        </header>
        <section class="dashboard-content">
            <h2>Teaching Workspace</h2>
            <p>Assigned courses, student lists, attendance and marks.</p>
        </section>
    </main>
</body>

</html>