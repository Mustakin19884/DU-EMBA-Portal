<?php
$currentTeacherPage = basename($_SERVER['PHP_SELF'] ?? '');

$teacherMenu = [
    ['label' => 'Dashboard', 'file' => 'dashboard.php', 'icon' => '⌂'],
    ['label' => 'My Students', 'file' => 'students.php', 'icon' => '♙'],
    ['label' => 'Attendance', 'file' => 'attendance.php', 'icon' => '▣'],
    ['label' => 'Assessments', 'file' => 'assessments.php', 'icon' => '▤'],
    ['label' => 'Marks Entry', 'file' => 'marks.php', 'icon' => '✎'],
    ['label' => 'Result Calculation', 'file' => 'results.php', 'icon' => '▤'],
];
?>
<aside class="admin-sidebar" id="teacherSidebar">
    <div class="admin-sidebar-brand">
        <h2>DU EMBA MIS</h2>
        <p>Teacher Workspace</p>
    </div>

    <div class="admin-menu-label">Teaching</div>

    <nav class="admin-menu">
        <?php foreach ($teacherMenu as $item): ?>
        <a href="<?= BASE_URL ?>/teacher/<?= e($item['file']) ?>"
            class="<?= $currentTeacherPage === $item['file'] ? 'active' : '' ?>">
            <span class="admin-menu-icon"><?= e($item['icon']) ?></span>
            <span><?= e($item['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="admin-sidebar-bottom">
        <a href="<?= BASE_URL ?>/auth/logout.php">↪ Logout</a>
    </div>
</aside>