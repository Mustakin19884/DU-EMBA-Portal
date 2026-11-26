<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    die('Invalid student session.');
}

function student_att_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function student_att_prepare(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): mysqli_stmt {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Database error: ' . $conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Query execution failed: ' . $stmt->error);
    }

    return $stmt;
}

/*
|--------------------------------------------------------------------------
| Get student profile
|--------------------------------------------------------------------------
*/

$studentStmt = student_att_prepare(
    $conn,
    "SELECT id, student_id
     FROM students
     WHERE user_id = ?
     LIMIT 1",
    'i',
    [$userId]
);

$student = $studentStmt->get_result()->fetch_assoc();

if (!$student) {
    die('Student profile not found.');
}

$studentDbId = (int)$student['id'];

/*
|--------------------------------------------------------------------------
| Get course-wise attendance summary
|--------------------------------------------------------------------------
*/

$summaryStmt = student_att_prepare(
    $conn,
    "SELECT
        cr.id AS registration_id,

        co.id AS offering_id,

        c.course_code,
        c.course_title,

        sem.semester_name,

        COUNT(a.id) AS total_classes,

        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,

        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,

        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count,

        SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) AS excused_count

     FROM course_registrations cr

     INNER JOIN course_offerings co
        ON co.id = cr.offering_id

     INNER JOIN courses c
        ON c.id = co.course_id

     LEFT JOIN semesters sem
        ON sem.id = co.semester_id

     LEFT JOIN attendance a
        ON a.registration_id = cr.id

     WHERE cr.student_id = ?
       AND cr.status IN ('registered', 'completed')

     GROUP BY
        cr.id,
        co.id,
        c.course_code,
        c.course_title,
        sem.semester_name

     ORDER BY co.semester_id DESC, c.course_code ASC",
    'i',
    [$studentDbId]
);

$courseSummaries = $summaryStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Overall attendance statistics
|--------------------------------------------------------------------------
*/

$overall = [
    'total_classes' => 0,
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'excused' => 0
];

foreach ($courseSummaries as $course) {
    $overall['total_classes'] += (int)$course['total_classes'];
    $overall['present'] += (int)$course['present_count'];
    $overall['absent'] += (int)$course['absent_count'];
    $overall['late'] += (int)$course['late_count'];
    $overall['excused'] += (int)$course['excused_count'];
}

$overallPercentage = $overall['total_classes'] > 0
    ? (($overall['present'] + $overall['late']) / $overall['total_classes']) * 100
    : 0;

/*
|--------------------------------------------------------------------------
| Selected course for attendance history
|--------------------------------------------------------------------------
*/

$selectedRegistration = (int)($_GET['registration_id'] ?? 0);

$validRegistrationIds = array_map(
    'intval',
    array_column($courseSummaries, 'registration_id')
);

if (
    $selectedRegistration > 0 &&
    !in_array($selectedRegistration, $validRegistrationIds, true)
) {
    $selectedRegistration = 0;
}

/*
|--------------------------------------------------------------------------
| Load attendance history
|--------------------------------------------------------------------------
*/

$history = [];

if ($selectedRegistration > 0) {

    $historyStmt = student_att_prepare(
        $conn,
        "SELECT
            a.class_date,
            a.status,
            c.course_code,
            c.course_title,
            sem.semester_name

         FROM attendance a

         INNER JOIN course_registrations cr
            ON cr.id = a.registration_id

         INNER JOIN course_offerings co
            ON co.id = cr.offering_id

         INNER JOIN courses c
            ON c.id = co.course_id

         LEFT JOIN semesters sem
            ON sem.id = co.semester_id

         WHERE cr.id = ?
           AND cr.student_id = ?

         ORDER BY a.class_date DESC",
        'ii',
        [$selectedRegistration, $studentDbId]
    );

    $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Status display helper
|--------------------------------------------------------------------------
*/

function attendance_status_class(string $status): string
{
    return match ($status) {
        'present' => 'present',
        'absent' => 'absent',
        'late' => 'late',
        'excused' => 'excused',
        default => ''
    };
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Attendance | DU EMBA MIS</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    .student-attendance-container {
        max-width: 1200px;
        margin: 30px auto;
        padding: 0 22px 40px;
    }

    .attendance-page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 25px;
    }

    .attendance-page-header h1 {
        color: #102d50;
        margin: 0 0 7px;
    }

    .attendance-page-header p {
        color: #687789;
        margin: 0;
    }

    .attendance-back-btn {
        display: inline-block;
        background: #102d50;
        color: #fff;
        padding: 11px 17px;
        border-radius: 7px;
        text-decoration: none;
        font-weight: 600;
    }

    .attendance-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
        gap: 15px;
        margin-bottom: 24px;
    }

    .attendance-summary-card {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 11px;
        padding: 19px;
    }

    .attendance-summary-card span {
        display: block;
        color: #687789;
        font-size: 13px;
        margin-bottom: 9px;
    }

    .attendance-summary-card strong {
        display: block;
        color: #102d50;
        font-size: 24px;
    }

    .student-attendance-card {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 22px;
        margin-bottom: 22px;
    }

    .student-attendance-card h2 {
        color: #102d50;
        font-size: 19px;
        margin: 0 0 18px;
    }

    .attendance-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .student-attendance-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 750px;
    }

    .student-attendance-table th,
    .student-attendance-table td {
        padding: 13px 11px;
        text-align: left;
        border-bottom: 1px solid #e7ebf0;
        font-size: 13px;
    }

    .student-attendance-table th {
        background: #f4efe5;
        color: #102d50;
    }

    .attendance-status {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: capitalize;
    }

    .attendance-status.present {
        background: #e2f5e9;
        color: #176b37;
    }

    .attendance-status.absent {
        background: #fde8e8;
        color: #a32a2a;
    }

    .attendance-status.late {
        background: #fff1d6;
        color: #8b5b00;
    }

    .attendance-status.excused {
        background: #e8edf7;
        color: #34527c;
    }

    .attendance-percentage {
        font-weight: 700;
        color: #102d50;
    }

    .attendance-progress {
        width: 110px;
        height: 8px;
        background: #e8ecf1;
        border-radius: 10px;
        overflow: hidden;
        margin-top: 7px;
    }

    .attendance-progress-bar {
        height: 100%;
        background: #1c456e;
        border-radius: 10px;
    }

    .attendance-view-btn {
        display: inline-block;
        padding: 8px 12px;
        background: #102d50;
        color: #fff;
        border-radius: 6px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
    }

    .attendance-empty {
        text-align: center;
        color: #687789;
        padding: 28px;
    }

    .attendance-info {
        color: #687789;
        font-size: 13px;
        margin-top: 12px;
    }

    @media(max-width: 600px) {
        .student-attendance-container {
            padding: 0 13px 30px;
        }

        .student-attendance-card {
            padding: 15px;
        }
    }
    </style>
</head>

<body>

    <main class="student-attendance-container">

        <div class="attendance-page-header">
            <div>
                <h1>My Attendance</h1>
                <p>
                    Student ID:
                    <strong><?= student_att_e($student['student_id']) ?></strong>
                </p>
            </div>

            <a href="<?= BASE_URL ?>/student/dashboard.php" class="attendance-back-btn">
                Back to Dashboard
            </a>
        </div>

        <!-- OVERALL SUMMARY -->

        <section class="attendance-summary-grid">

            <div class="attendance-summary-card">
                <span>Total Classes Recorded</span>
                <strong><?= $overall['total_classes'] ?></strong>
            </div>

            <div class="attendance-summary-card">
                <span>Present</span>
                <strong><?= $overall['present'] ?></strong>
            </div>

            <div class="attendance-summary-card">
                <span>Absent</span>
                <strong><?= $overall['absent'] ?></strong>
            </div>

            <div class="attendance-summary-card">
                <span>Late</span>
                <strong><?= $overall['late'] ?></strong>
            </div>

            <div class="attendance-summary-card">
                <span>Excused</span>
                <strong><?= $overall['excused'] ?></strong>
            </div>

            <div class="attendance-summary-card">
                <span>Overall Attendance</span>
                <strong><?= number_format($overallPercentage, 1) ?>%</strong>
            </div>

        </section>

        <!-- COURSE-WISE SUMMARY -->

        <section class="student-attendance-card">

            <h2>Course-wise Attendance Summary</h2>

            <div class="attendance-table-wrap">

                <table class="student-attendance-table">

                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Semester</th>
                            <th>Total Classes</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Late</th>
                            <th>Excused</th>
                            <th>Attendance %</th>
                            <th>Details</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if ($courseSummaries): ?>

                        <?php foreach ($courseSummaries as $course): ?>

                        <?php
                        $total = (int)$course['total_classes'];
                        $present = (int)$course['present_count'];
                        $late = (int)$course['late_count'];

                        $percentage = $total > 0
                            ? (($present + $late) / $total) * 100
                            : 0;

                        $percentage = min(100, $percentage);
                        ?>

                        <tr>

                            <td>
                                <strong><?= student_att_e($course['course_code']) ?></strong>
                                <br>
                                <?= student_att_e($course['course_title']) ?>
                            </td>

                            <td>
                                <?= student_att_e($course['semester_name'] ?? 'N/A') ?>
                            </td>

                            <td><?= $total ?></td>

                            <td><?= $present ?></td>

                            <td><?= (int)$course['absent_count'] ?></td>

                            <td><?= $late ?></td>

                            <td><?= (int)$course['excused_count'] ?></td>

                            <td>
                                <span class="attendance-percentage">
                                    <?= number_format($percentage, 1) ?>%
                                </span>

                                <div class="attendance-progress">
                                    <div class="attendance-progress-bar" style="width:<?= $percentage ?>%">
                                    </div>
                                </div>
                            </td>

                            <td>
                                <a class="attendance-view-btn"
                                    href="<?= BASE_URL ?>/student/attendance.php?registration_id=<?= (int)$course['registration_id'] ?>">
                                    View History
                                </a>
                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <?php else: ?>

                        <tr>
                            <td colspan="9" class="attendance-empty">
                                No registered courses found.
                            </td>
                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <p class="attendance-info">
                Attendance percentage = (Present + Late) / Total recorded classes × 100.
                Excused classes are included in the total, following the current teacher attendance calculation.
            </p>

        </section>

        <!-- DATE-WISE HISTORY -->

        <?php if ($selectedRegistration > 0): ?>

        <section class="student-attendance-card">

            <h2>My Attendance History</h2>

            <div class="attendance-table-wrap">

                <table class="student-attendance-table">

                    <thead>
                        <tr>
                            <th>Class Date</th>
                            <th>Course</th>
                            <th>Semester</th>
                            <th>Attendance Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if ($history): ?>

                        <?php foreach ($history as $record): ?>

                        <tr>

                            <td>
                                <?= date('d M Y', strtotime($record['class_date'])) ?>
                            </td>

                            <td>
                                <?= student_att_e($record['course_code']) ?>
                                -
                                <?= student_att_e($record['course_title']) ?>
                            </td>

                            <td>
                                <?= student_att_e($record['semester_name'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <span class="attendance-status <?= attendance_status_class($record['status']) ?>">
                                    <?= student_att_e($record['status']) ?>
                                </span>
                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <?php else: ?>

                        <tr>
                            <td colspan="4" class="attendance-empty">
                                No attendance records found for this course.
                            </td>
                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div style="margin-top:18px;">
                <a class="attendance-view-btn" href="<?= BASE_URL ?>/student/attendance.php">
                    Back to All Courses
                </a>
            </div>

        </section>

        <?php endif; ?>

    </main>

</body>

</html>