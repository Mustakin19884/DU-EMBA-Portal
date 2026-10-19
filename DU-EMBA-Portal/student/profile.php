<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('student');

require_once __DIR__ . '/../config/database.php';

$userId = (int) $_SESSION['user_id'];

$sql = "SELECT
            u.full_name,
            u.username,
            u.email,
            s.student_id,
            s.phone,
            s.address,
            b.batch_name,
            sem.semester_name AS admission_semester
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN batches b ON b.id = s.batch_id
        LEFT JOIN semesters sem ON sem.id = s.admission_semester_id
        WHERE u.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Unable to load profile. Please check the database configuration.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$student = $result->fetch_assoc();

$stmt->close();

if (!$student) {
    die('Student profile not found.');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <main class="dashboard">

        <header class="dashboard-header">
            <div>
                <h1>My Profile</h1>
                <p>View your student information</p>
            </div>

            <a class="btn-logout" href="<?= BASE_URL ?>/student/dashboard.php">
                Dashboard
            </a>
        </header>

        <section class="dashboard-content">
            <h2>Personal Information</h2>

            <p>
                <strong>Full Name:</strong>
                <?= e($student['full_name'] ?? '') ?>
            </p>

            <p>
                <strong>Username:</strong>
                <?= e($student['username'] ?? '') ?>
            </p>

            <p>
                <strong>Email:</strong>
                <?= e($student['email'] ?? 'Not provided') ?>
            </p>

            <p>
                <strong>Phone:</strong>
                <?= e($student['phone'] ?? 'Not provided') ?>
            </p>

            <p>
                <strong>Address:</strong>
                <?= e($student['address'] ?? 'Not provided') ?>
            </p>

            <h2>Academic Information</h2>

            <p>
                <strong>Student ID:</strong>
                <?= e($student['student_id'] ?? 'Not assigned') ?>
            </p>

            <p>
                <strong>Batch:</strong>
                <?= e($student['batch_name'] ?? 'Not assigned') ?>
            </p>

            <p>
                <strong>Admission Semester:</strong>
                <?= e($student['admission_semester'] ?? 'Not assigned') ?>
            </p>
        </section>

    </main>
</body>

</html>