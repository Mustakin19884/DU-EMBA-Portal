<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

// Load the database connection if it is not already loaded.
require_once __DIR__ . '/../config/database.php';

$currentSemester = null;
$semesterError = false;

$sql = "SELECT semester_name
        FROM semesters
        WHERE status = 'active'
        ORDER BY id DESC
        LIMIT 1";

$result = $conn->query($sql);

if ($result) {
    $currentSemester = $result->fetch_assoc();
} else {
    $semesterError = true;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <main class="dashboard">
        <header class="dashboard-header">
            <div>
                <h1>Student Dashboard</h1>
                <p>Welcome, <?= e($_SESSION['full_name']) ?></p>
            </div>
            <a class="btn-logout" href="<?= BASE_URL ?>/auth/logout.php">
                Logout
            </a>
        </header>


        <section class="dashboard-content">
            <div class="dashboard-actions">
                <h2>My Academic Portal</h2>

                <a class="btn-logout" href="<?= BASE_URL ?>/student/profile.php"
                    style="display: inline-block; text-decoration: none; margin-bottom: 15px;">
                    My Profile
                </a>
            </div>


            <div class="semester-card">
                <h3>Current Semester</h3>

                <?php if ($semesterError): ?>
                <p>Unable to load the current semester.</p>
                <?php elseif ($currentSemester): ?>
                <p>
                    <strong>
                        <?= e($currentSemester['semester_name']) ?>
                    </strong>
                </p>
                <?php else: ?>
                <p>No active semester has been configured.</p>
                <?php endif; ?>
            </div>

            <h3>Academic Services</h3>
            <p>
                Course registration, results, class routine, attendance,
                payments and notices will appear here.
            </p>

            <p>Your role: <?= e($_SESSION['role']) ?></p>
        </section>
    </main>
</body>

</html>