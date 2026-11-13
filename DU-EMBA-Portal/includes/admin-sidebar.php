<?php
$currentAdminPage = basename($_SERVER['PHP_SELF'] ?? '');

$adminMenu = [
    ['label' => 'Dashboard', 'file' => 'dashboard.php', 'icon' => '⌂'],
    ['label' => 'User Management', 'file' => 'users.php', 'icon' => '♙'],
    ['label' => 'Teacher Management', 'file' => 'teachers.php', 'icon' => '♧'],
    ['label' => 'Academic Sessions', 'file' => 'academic-sessions.php', 'icon' => '▦'],
    ['label' => 'Semester Management', 'file' => 'semesters.php', 'icon' => '▣'],
    ['label' => 'Course Management', 'file' => 'courses.php', 'icon' => '▤'],
    ['label' => 'Course Offerings', 'file' => 'course-offerings.php', 'icon' => '◷'],
];
?>

<aside class="admin-sidebar" id="adminSidebar">

    <div class="admin-sidebar-brand">
        <h2>DU EMBA MIS</h2>
        <p>Administration Portal</p>
    </div>

    <div class="admin-menu-label">Main Navigation</div>

    <nav class="admin-menu">

        <?php foreach ($adminMenu as $item): ?>
        <?php
                $active = ($currentAdminPage === $item['file']);
                $url = BASE_URL . '/admin/' . $item['file'];
            ?>

        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="<?= $active ? 'active' : '' ?>">

            <span class="admin-menu-icon">
                <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>
            </span>

            <span>
                <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
            </span>

        </a>

        <?php endforeach; ?>

    </nav>

    <div class="admin-sidebar-bottom">
        <a href="<?= BASE_URL ?>/auth/logout.php">
            ↪ Logout
        </a>
    </div>

</aside>