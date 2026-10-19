<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

if (!empty($_SESSION['user_id'])) {
    $rolePages = [
        'student'  => '/student/dashboard.php',
        'teacher'  => '/teacher/dashboard.php',
        'accounts' => '/accounts/dashboard.php',
        'academic' => '/academic/dashboard.php',
        'admin'    => '/admin/dashboard.php',
    ];

    $role = $_SESSION['role'] ?? '';

    if (isset($rolePages[$role])) {
        header('Location: ' . BASE_URL . $rolePages[$role]);
        exit;
    }

    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$portals = [
    'student'  => 'Student Portal',
    'teacher'  => 'Teacher Portal',
    'accounts' => 'Accounts Portal',
    'academic' => 'Academic Office',
    'admin'    => 'Administrator Portal',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DU EMBA MIS Portal</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <main class="portal-home">
        <header class="home-header">
            <div class="brand-mark">DU</div>
            <h1>DU EMBA MIS Portal</h1>
            <p>Academic and Administrative Management System</p>
        </header>

        <section class="portal-grid">
            <?php foreach ($portals as $role => $label): ?>
            <a class="portal-card" href="<?= BASE_URL ?>/auth/login.php?role=<?= urlencode($role) ?>">
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="arrow">&rarr;</span>
            </a>
            <?php endforeach; ?>
        </section>

        <footer>University of Dhaka · EMBA · MIS</footer>
    </main>
</body>

</html>