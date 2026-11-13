<?php
$pageTitle = 'Administrator Dashboard';
$description = 'Manage the complete DU EMBA MIS Student Portal.';

require_once __DIR__ . '/../includes/admin-header.php';

$adminName = $_SESSION['full_name'] ?? 'Administrator';

$dashboardItems = [
    [
        'title' => 'User Management',
        'description' => 'Create, edit, activate and deactivate student and staff accounts.',
        'icon' => '♙',
        'link' => 'users.php'
    ],
    [
        'title' => 'Teacher Management',
        'description' => 'Manage teacher profiles and information.',
        'icon' => '♧',
        'link' => 'teachers.php'
    ],
    [
        'title' => 'Academic Sessions',
        'description' => 'Create and manage academic sessions.',
        'icon' => '▦',
        'link' => 'academic-sessions.php'
    ],
    [
        'title' => 'Semester Management',
        'description' => 'Manage semesters and their academic status.',
        'icon' => '▣',
        'link' => 'semesters.php'
    ],
    [
        'title' => 'Course Management',
        'description' => 'Add, update and manage course information and categories.',
        'icon' => '▤',
        'link' => 'courses.php'
    ],
    [
        'title' => 'Course Offerings',
        'description' => 'Manage semester-wise course offerings, teachers and class schedules.',
        'icon' => '◷',
        'link' => 'course-offerings.php'
    ]
];
?>

<style>
/* DASHBOARD PAGE */

.dashboard-welcome {
    background: #102d50;
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 28px;
    box-shadow: 0 5px 18px rgba(16, 45, 80, 0.12);
}

.dashboard-welcome h2 {
    margin: 0 0 10px;
    font-size: 25px;
    color: white;
}

.dashboard-welcome p {
    margin: 0;
    color: #d7e1ed;
    font-size: 14px;
    line-height: 1.7;
}

.dashboard-section-title {
    color: #102d50;
    font-size: 19px;
    margin: 0 0 18px;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}

.dashboard-card {
    display: flex;
    flex-direction: column;
    background: white;
    border: 1px solid #e1e5eb;
    border-radius: 10px;
    padding: 23px;
    min-height: 205px;
    box-shadow: 0 4px 16px rgba(16, 45, 80, 0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    color: #263548;
}

.dashboard-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(16, 45, 80, 0.10);
}

.dashboard-card-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    background: #edf1f6;
    color: #102d50;
    border-radius: 9px;
    font-size: 23px;
    margin-bottom: 17px;
}

.dashboard-card h3 {
    font-size: 16px;
    color: #102d50;
    margin: 0 0 9px;
}

.dashboard-card p {
    color: #687789;
    font-size: 13px;
    line-height: 1.6;
    margin: 0 0 17px;
    flex: 1;
}

.dashboard-card-action {
    color: #102d50;
    font-size: 13px;
    font-weight: 700;
}

.dashboard-footer-note {
    margin-top: 28px;
    padding: 17px 20px;
    background: #fff;
    border: 1px solid #e1e5eb;
    border-radius: 8px;
    color: #687789;
    font-size: 13px;
    line-height: 1.6;
}

@media (max-width: 1100px) {
    .dashboard-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 600px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-welcome {
        padding: 22px;
    }

    .dashboard-welcome h2 {
        font-size: 21px;
    }
}
</style>

<div class="admin-page-header">
    <h1>Administrator Dashboard</h1>
    <p>Manage the DU EMBA MIS Student Portal from one place.</p>
</div>

<section class="dashboard-welcome">

    <h2>
        Welcome, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>!
    </h2>

    <p>
        Welcome to the DU EMBA MIS Student Portal Administration Panel.
        Use the management sections below to control users, teachers,
        academic sessions, semesters and courses.
    </p>

</section>

<section>

    <h2 class="dashboard-section-title">
        Portal Management
    </h2>

    <div class="dashboard-grid">

        <?php foreach ($dashboardItems as $item): ?>

        <a class="dashboard-card"
            href="<?= BASE_URL ?>/admin/<?= htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="dashboard-card-icon">
                <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>
            </div>

            <h3>
                <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
            </h3>

            <p>
                <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>
            </p>

            <span class="dashboard-card-action">
                Manage Section →
            </span>

        </a>

        <?php endforeach; ?>

    </div>

</section>

<div class="dashboard-footer-note">
    <strong>DU EMBA MIS Student Portal</strong><br>
    Administrator access is intended for authorized portal management.
    Please log out after completing your administrative tasks.
</div>

<?php
require_once __DIR__ . '/../includes/admin-footer.php';
?>