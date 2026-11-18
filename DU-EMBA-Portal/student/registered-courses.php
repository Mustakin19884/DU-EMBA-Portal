<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

$studentName = $_SESSION['full_name'] ?? 'Student';
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

$registrations = [];
$registeredCount = 0;
$completedCount = 0;
$totalCredits = 0.0;
$errorMessage = '';

function course_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function course_time($time): string
{
    if (!$time) {
        return 'Not Scheduled';
    }

    return date('h:i A', strtotime((string)$time));
}

function course_date($date): string
{
    if (!$date) {
        return 'N/A';
    }

    return date('d M Y', strtotime((string)$date));
}

try {
    // Find the student's actual profile ID.
    $studentStmt = $conn->prepare(
        "SELECT id, student_id
         FROM students
         WHERE user_id = ?
         LIMIT 1"
    );

    $studentStmt->bind_param('i', $userId);
    $studentStmt->execute();

    $student = $studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();

    if (!$student) {
        throw new RuntimeException(
            'Student profile was not found. Please contact the Academic Office.'
        );
    }

    $studentProfileId = (int)$student['id'];

    // Load all registered courses and class schedules.
    $sql = "
        SELECT
            cr.id AS registration_id,
            cr.status AS registration_status,
            cr.registered_at,

            c.course_code,
            c.course_title,
            c.credit_hours,

            sem.semester_name,
            sem.start_date,
            sem.end_date,

            ac.session_name,

            COALESCE(u.full_name, 'Not Assigned') AS teacher_name,

            cs.class_day,
            cs.start_time,
            cs.end_time,
            cs.room

        FROM course_registrations cr

        INNER JOIN course_offerings co
            ON co.id = cr.offering_id

        INNER JOIN courses c
            ON c.id = co.course_id

        INNER JOIN semesters sem
            ON sem.id = co.semester_id

        LEFT JOIN academic_sessions ac
            ON ac.id = sem.session_id

        LEFT JOIN teachers t
            ON t.id = co.teacher_id

        LEFT JOIN users u
            ON u.id = t.user_id

        LEFT JOIN course_schedules cs
            ON cs.offering_id = co.id

        WHERE cr.student_id = ?

        ORDER BY
            sem.start_date DESC,
            c.course_code ASC
    ";

    $courseStmt = $conn->prepare($sql);
    $courseStmt->bind_param('i', $studentProfileId);
    $courseStmt->execute();

    $result = $courseStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $registrations[] = $row;

        if ($row['registration_status'] === 'registered') {
            $registeredCount++;
            $totalCredits += (float)$row['credit_hours'];
        } elseif ($row['registration_status'] === 'completed') {
            $completedCount++;
        }
    }

    $courseStmt->close();

} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Registered Courses | DU EMBA MIS</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    .registered-page {
        background: #f4f6f9;
        min-height: 100vh;
        padding: 30px 20px 50px;
    }

    .registered-container {
        width: 100%;
        max-width: 1250px;
        margin: 0 auto;
    }

    .registered-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 25px;
    }

    .registered-topbar h1 {
        color: #102d50;
        font-size: 27px;
        margin: 0 0 5px;
    }

    .registered-topbar p {
        color: #687789;
        margin: 0;
    }

    .registered-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .registered-btn {
        display: inline-block;
        padding: 11px 17px;
        border-radius: 7px;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        background: #102d50;
        color: #fff;
    }

    .registered-btn.secondary {
        background: #fff;
        color: #102d50;
        border: 1px solid #dce2e9;
    }

    .registered-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 25px;
    }

    .registered-stat {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 21px;
        box-shadow: 0 3px 12px rgba(16, 45, 80, .04);
    }

    .registered-stat span {
        display: block;
        color: #687789;
        font-size: 13px;
        margin-bottom: 9px;
    }

    .registered-stat strong {
        display: block;
        font-size: 28px;
        color: #102d50;
    }

    .registered-card {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 23px;
        margin-bottom: 20px;
        box-shadow: 0 3px 12px rgba(16, 45, 80, .04);
    }

    .registered-card h2 {
        color: #102d50;
        font-size: 19px;
        margin: 0 0 5px;
    }

    .registered-card .subtitle {
        color: #687789;
        font-size: 13px;
        margin: 0 0 20px;
    }

    .registered-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .registered-table {
        width: 100%;
        min-width: 900px;
        border-collapse: collapse;
    }

    .registered-table th {
        text-align: left;
        background: #f4f6f9;
        color: #42546a;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .3px;
        padding: 13px 12px;
        white-space: nowrap;
    }

    .registered-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #edf0f3;
        color: #263548;
        font-size: 13px;
        vertical-align: middle;
    }

    .registered-table tr:last-child td {
        border-bottom: none;
    }

    .course-code {
        display: block;
        color: #102d50;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .course-title {
        display: block;
        color: #687789;
        max-width: 240px;
        line-height: 1.5;
    }

    .status-pill {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: capitalize;
    }

    .status-registered {
        color: #176b43;
        background: #e6f5ed;
    }

    .status-completed {
        color: #245b9a;
        background: #e8f0fc;
    }

    .status-dropped {
        color: #a33b3b;
        background: #fceaea;
    }

    .schedule-info {
        line-height: 1.7;
        white-space: nowrap;
    }

    .empty-state {
        text-align: center;
        padding: 45px 20px;
    }

    .empty-state .empty-icon {
        font-size: 35px;
        color: #9aa8b7;
        margin-bottom: 12px;
    }

    .empty-state h3 {
        color: #102d50;
        margin: 0 0 8px;
    }

    .empty-state p {
        color: #687789;
        font-size: 14px;
        margin: 0;
    }

    .error-message {
        background: #fff0f0;
        color: #a33b3b;
        border: 1px solid #f2cccc;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .page-footer {
        text-align: center;
        color: #8793a1;
        font-size: 12px;
        padding: 25px 0 0;
    }

    @media (max-width: 700px) {
        .registered-page {
            padding: 20px 12px 35px;
        }

        .registered-stats {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .registered-stat {
            padding: 17px;
        }

        .registered-stat strong {
            font-size: 24px;
        }

        .registered-card {
            padding: 16px;
        }

        .registered-topbar h1 {
            font-size: 23px;
        }
    }
    </style>
</head>

<body>

    <div class="registered-page">
        <div class="registered-container">

            <header class="registered-topbar">
                <div>
                    <h1>My Registered Courses</h1>
                    <p>
                        Welcome, <?= course_e($studentName) ?>.
                        View your course registration and class schedule.
                    </p>
                </div>

                <div class="registered-actions">
                    <a class="registered-btn secondary" href="<?= BASE_URL ?>/student/dashboard.php">
                        ← Dashboard
                    </a>

                    <a class="registered-btn" href="<?= BASE_URL ?>/auth/logout.php">
                        Logout
                    </a>
                </div>
            </header>

            <?php if ($errorMessage !== ''): ?>

            <div class="error-message">
                <?= course_e($errorMessage) ?>
            </div>

            <?php else: ?>

            <section class="registered-stats">

                <article class="registered-stat">
                    <span>Currently Registered Courses</span>
                    <strong><?= $registeredCount ?></strong>
                </article>

                <article class="registered-stat">
                    <span>Registered Credit Hours</span>
                    <strong><?= number_format($totalCredits, 1) ?></strong>
                </article>

                <article class="registered-stat">
                    <span>Completed Courses</span>
                    <strong><?= $completedCount ?></strong>
                </article>

            </section>

            <section class="registered-card">

                <h2>Course Registration Details</h2>

                <p class="subtitle">
                    Your semester-wise course registration and class schedule.
                </p>

                <?php if (empty($registrations)): ?>

                <div class="empty-state">
                    <div class="empty-icon">▤</div>
                    <h3>No Registered Courses Found</h3>
                    <p>
                        Your registered courses will appear here
                        after course registration is completed.
                    </p>
                </div>

                <?php else: ?>

                <div class="registered-table-wrap">

                    <table class="registered-table">

                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Semester</th>
                                <th>Credit</th>
                                <th>Teacher</th>
                                <th>Class Schedule</th>
                                <th>Registration Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($registrations as $course): ?>

                            <?php
                                    $status = $course['registration_status'];
                                    $statusClass = 'status-' . $status;
                                    ?>

                            <tr>

                                <td>
                                    <span class="course-code">
                                        <?= course_e($course['course_code']) ?>
                                    </span>

                                    <span class="course-title">
                                        <?= course_e($course['course_title']) ?>
                                    </span>
                                </td>

                                <td>
                                    <strong>
                                        <?= course_e($course['semester_name']) ?>
                                    </strong>

                                    <br>

                                    <small>
                                        <?= course_e($course['session_name'] ?? '') ?>
                                    </small>
                                </td>

                                <td>
                                    <?= number_format((float)$course['credit_hours'], 1) ?>
                                </td>

                                <td>
                                    <?= course_e($course['teacher_name']) ?>
                                </td>

                                <td class="schedule-info">

                                    <?php if (!empty($course['class_day'])): ?>

                                    <strong>
                                        <?= course_e($course['class_day']) ?>
                                    </strong>

                                    <br>

                                    <?= course_time($course['start_time']) ?>
                                    -
                                    <?= course_time($course['end_time']) ?>

                                    <br>

                                    Room:
                                    <?= course_e($course['room']) ?>

                                    <?php else: ?>

                                    <span style="color:#8793a1">
                                        Schedule not published
                                    </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= course_date($course['registered_at']) ?>
                                </td>

                                <td>
                                    <span class="status-pill <?= course_e($statusClass) ?>">
                                        <?= course_e($status) ?>
                                    </span>
                                </td>

                            </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <?php endif; ?>

            </section>

            <?php endif; ?>

            <footer class="page-footer">
                DU EMBA MIS Student Portal
            </footer>

        </div>
    </div>

</body>

</html>