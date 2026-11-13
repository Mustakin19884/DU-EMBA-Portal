<?php
// Include this file inside .admin-layout, before .admin-content.
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
        <div class="admin-sidebar-logo">DU</div>
        <div><strong>DU EMBA MIS</strong><span>Admin Workspace</span></div>
    </div>
    <div class="admin-sidebar-label">MAIN MENU</div>
    <nav class="admin-sidebar-nav" aria-label="Admin navigation">
        <?php foreach ($adminMenu as $item): ?>
        <a class="admin-nav-link <?= $currentAdminPage === $item['file'] ? 'active' : '' ?>"
            href="<?= BASE_URL ?>/admin/<?= e($item['file']) ?>">
            <span class="admin-nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span>
            <span><?= e($item['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar-bottom">
        <div class="admin-sidebar-user"><span class="admin-user-avatar">A</span>
            <div><strong><?= e($_SESSION['full_name'] ?? 'Administrator') ?></strong><small>Administrator</small></div>
        </div>
        <a class="admin-sidebar-logout" href="<?= BASE_URL ?>/auth/logout.php">↪ <span>Logout</span></a>
    </div>
</aside>