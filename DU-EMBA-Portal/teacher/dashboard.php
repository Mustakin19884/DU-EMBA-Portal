<?php
require_once __DIR__ . '/../includes/auth.php';

$requiredRole = 'teacher';
$pageTitle = 'Teacher Dashboard';

require_role($requiredRole);

// Fetch courses assigned to the logged-in teacher.
$assignedCourses = [];
$courseQueryError = false;

if (isset($conn, $_SESSION['user_id'])) {
    $sql = "
        SELECT
            c.id,
            c.course_code,
            c.course_title,
            c.credit_hours,
            c.description
        FROM courses AS c
        INNER JOIN teachers AS t ON t.id = c.teacher_id
        WHERE t.user_id = ?
          AND c.is_active = 1
        ORDER BY c.course_code ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param('i', $userId);

        if ($stmt->execute()) {
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $assignedCourses[] = $row;
            }
        } else {
            $courseQueryError = true;
        }

        $stmt->close();
    } else {
        $courseQueryError = true;
    }
} else {
    $courseQueryError = true;
}
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
                <p>Welcome, <?= e($_SESSION['full_name'] ?? 'Teacher') ?></p>
            </div>

            <a class="btn-logout" href="<?= BASE_URL ?>/auth/logout.php">
                Logout
            </a>
        </header>

        <section class="dashboard-content">
            <h2>Teaching Workspace</h2>

            <a href="<?= BASE_URL ?>/teacher/students.php" class="btn btn-primary">
                View My Students
            </a>
            <p>
                View your assigned courses and access teaching-related
                features from this workspace.
            </p>
        </section>

        <section class="dashboard-content">
            <h2>My Assigned Courses</h2>

            <?php if ($courseQueryError): ?>

            <p>
                We couldn't load your assigned courses. Please check the
                database connection and teacher login session configuration.
            </p>

            <?php elseif (empty($assignedCourses)): ?>

            <p>
                No active courses have been assigned to your account yet.
                Please contact the Academic Officer or Admin.
            </p>

            <?php else: ?>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th>Credit Hours</th>
                            <th>Description</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($assignedCourses as $course): ?>
                        <tr>
                            <td><?= e($course['course_code']) ?></td>
                            <td><?= e($course['course_title']) ?></td>
                            <td><?= e($course['credit_hours']) ?></td>
                            <td>
                                <?= e(
                                            $course['description'] ?: 'No description'
                                        ) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>
        </section>

    </main>
</body>

</html>