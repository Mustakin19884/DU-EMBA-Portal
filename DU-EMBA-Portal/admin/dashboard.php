<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$pageTitle = 'Administrator Dashboard';
$description = 'Manage the complete DU EMBA MIS Student Portal.';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= e($pageTitle) ?> | DU EMBA MIS Portal</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    .admin-dashboard {
        max-width: 1450px;
        margin: 35px auto;
        padding: 0 25px;
    }

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        margin-bottom: 30px;
        padding: 25px 30px;
        background: var(--navy);
        color: white;
        border-radius: 14px;
    }

    .admin-header h1 {
        margin: 0 0 8px;
        font-size: 28px;
    }

    .admin-header p {
        margin: 0;
        opacity: .85;
    }

    .admin-header a {
        background: white;
        color: var(--navy);
        padding: 10px 18px;
        border-radius: 7px;
        text-decoration: none;
        font-weight: 600;
        white-space: nowrap;
    }

    .admin-section {
        margin-bottom: 35px;
    }

    .admin-section h2 {
        color: var(--navy);
        margin-bottom: 8px;
    }

    .admin-section-description {
        color: var(--muted);
        margin-bottom: 20px;
    }

    .admin-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 20px;
    }

    .admin-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        padding: 24px;
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        text-decoration: none;
        color: var(--text);
        transition: .2s ease;
    }

    .admin-card:hover {
        border-color: var(--navy);
        transform: translateY(-3px);
        box-shadow: 0 5px 18px rgba(16, 45, 80, .08);
    }

    .admin-card-content strong {
        display: block;
        color: var(--navy);
        font-size: 17px;
        margin-bottom: 8px;
    }

    .admin-card-content span {
        display: block;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.5;
    }

    .admin-card-arrow {
        font-size: 23px;
        color: var(--navy);
    }

    .admin-footer {
        margin-top: 35px;
        padding: 20px 0;
        border-top: 1px solid var(--border);
        color: var(--muted);
        text-align: center;
        font-size: 13px;
    }

    @media (max-width: 950px) {
        .admin-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 600px) {
        .admin-dashboard {
            padding: 0 12px;
        }

        .admin-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .admin-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
</head>

<body>

    <main class="admin-dashboard">

        <!-- HEADER -->

        <header class="admin-header">
            <div>
                <h1>DU EMBA MIS Admin Portal</h1>
                <p>
                    Welcome, <?= e($_SESSION['full_name'] ?? 'Administrator') ?>
                </p>
            </div>

            <a href="<?= BASE_URL ?>/auth/logout.php">
                Logout
            </a>
        </header>

        <!-- USER MANAGEMENT -->

        <section class="admin-section">

            <h2>User & Account Management</h2>

            <p class="admin-section-description">
                Manage users, accounts, roles and system access.
            </p>

            <div class="admin-grid">

                <a class="admin-card" href="<?= BASE_URL ?>/admin/users.php">
                    <div class="admin-card-content">
                        <strong>User Management</strong>
                        <span>Create, view, edit and manage user accounts.</span>
                    </div>
                    <span class="admin-card-arrow">&rarr;</span>
                </a>

                <a class="admin-card" href="<?= BASE_URL ?>/admin/teachers.php">
                    <div class="admin-card-content">
                        <strong>Teacher Management</strong>
                        <span>Manage teacher accounts and information.</span>
                    </div>
                    <span class="admin-card-arrow">&rarr;</span>
                </a>

            </div>

        </section>

        <!-- ACADEMIC MANAGEMENT -->

        <section class="admin-section">

            <h2>Academic Management</h2>

            <p class="admin-section-description">
                Manage academic sessions, semesters and curriculum.
            </p>

            <div class="admin-grid">

                <a class="admin-card" href="<?= BASE_URL ?>/admin/academic-sessions.php">
                    <div class="admin-card-content">
                        <strong>Academic Sessions</strong>
                        <span>Create and manage academic sessions.</span>
                    </div>
                    <span class="admin-card-arrow">&rarr;</span>
                </a>

                <a class="admin-card" href="<?= BASE_URL ?>/admin/semesters.php">
                    <div class="admin-card-content">
                        <strong>Semester Management</strong>
                        <span>Create semesters and manage registration periods.</span>
                    </div>
                    <span class="admin-card-arrow">&rarr;</span>
                </a>

                <a class="admin-card" href="<?= BASE_URL ?>/admin/courses.php">
                    <div class="admin-card-content">
                        <strong>Course Management</strong>
                        <span>Manage course codes, titles, categories and credits.</span>
                    </div>
                    <span class="admin-card-arrow">&rarr;</span>
                </a>

                <a class="admin-card" href="<?= BASE_URL ?>/admin/course-offerings.php">
                    <div class="admin-card-content">
                        <strong>Course Offerings & Schedule</strong>
                        <span>Assign courses, teachers, class days, times and rooms.</span>
                    </div>
                    <span class="admin-card-arrow">&rarr;</span>
                </a>

            </div>

        </section>

        <!-- FINANCIAL MANAGEMENT -->

        <section class="admin-section">

            <h2>Financial Management</h2>

            <p class="admin-section-description">
                Manage student fees, payments and financial records.
            </p>

            <div class="admin-grid">

                <div class="admin-card">
                    <div class="admin-card-content">
                        <strong>Fee Management</strong>
                        <span>Fee structures and student payment management.</span>
                    </div>
                    <span class="admin-card-arrow">&rarr;</span>
                </div>

            </div>

        </section>

        <!-- PORTAL MANAGEMENT -->

        <section class="admin-section">

            <h2>Portal Management</h2>

            <p class="admin-section-description">
                Manage notices, announcements and portal settings.
            </p>

            <div class="admin-grid">

                <div class="admin-card">
                    <div class="admin-card-content">
                        <strong>Notice Management</strong>
                        <span>Create and publish student announcements.</span>
                    </div>
                    <span class="admin-card-arrow">&rarr;</span>
                </div>

            </div>

        </section>

        <footer class="admin-footer">
            DU EMBA MIS Student Portal &copy; <?= date('Y') ?>
            <br>
            Administrator Workspace
        </footer>

    </main>

</body>

</html>