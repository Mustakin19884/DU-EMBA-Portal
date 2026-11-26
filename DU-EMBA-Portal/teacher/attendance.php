<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('teacher');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$teacherName = $_SESSION['full_name'] ?? 'Teacher';

if ($userId <= 0) {
    die('Invalid teacher session.');
}

function att_e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function att_prepare(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
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

$successMessage = '';
$errorMessage = '';

if (empty($_SESSION['attendance_csrf'])) {
    $_SESSION['attendance_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['attendance_csrf'];

$selectedOffering = (int)($_GET['offering_id'] ?? $_POST['offering_id'] ?? 0);
$classDate = trim($_GET['class_date'] ?? $_POST['class_date'] ?? date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $classDate)) {
    $classDate = date('Y-m-d');
}

/*
|--------------------------------------------------------------------------
| Load teacher's assigned offerings
|--------------------------------------------------------------------------
*/

$offeringStmt = att_prepare(
    $conn,
    "SELECT
        co.id,
        c.course_code,
        c.course_title,
        sem.semester_name,
        sem.id AS semester_id

     FROM course_offerings co

     INNER JOIN courses c
        ON c.id = co.course_id

     INNER JOIN semesters sem
        ON sem.id = co.semester_id

     WHERE co.teacher_id = ?

     ORDER BY sem.id DESC, c.course_code ASC",
    'i',
    [$userId]
);

$offerings = $offeringStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$validOfferingIds = array_map(
    'intval',
    array_column($offerings, 'id')
);

if ($selectedOffering > 0 && !in_array($selectedOffering, $validOfferingIds, true)) {
    $selectedOffering = 0;
}

/*
|--------------------------------------------------------------------------
| Load registered students for selected offering
|--------------------------------------------------------------------------
*/

$students = [];

if ($selectedOffering > 0) {
    $studentStmt = att_prepare(
        $conn,
        "SELECT
            cr.id AS registration_id,
            s.student_id,
            u.full_name,
            u.email

         FROM course_registrations cr

         INNER JOIN students s
            ON s.id = cr.student_id

         INNER JOIN users u
            ON u.id = s.user_id

         INNER JOIN course_offerings co
            ON co.id = cr.offering_id

         WHERE cr.offering_id = ?
           AND co.teacher_id = ?
           AND cr.status = 'registered'

         ORDER BY u.full_name ASC",
        'ii',
        [$selectedOffering, $userId]
    );

    $students = $studentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Save attendance
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {

    try {
        if (!hash_equals(
            $_SESSION['attendance_csrf'] ?? '',
            $_POST['csrf_token'] ?? ''
        )) {
            throw new RuntimeException('Invalid security token. Please refresh the page.');
        }

        if ($selectedOffering <= 0 || !in_array($selectedOffering, $validOfferingIds, true)) {
            throw new RuntimeException('Please select a valid course offering.');
        }

        $postedDate = $_POST['class_date'] ?? '';

        $dateObject = DateTime::createFromFormat('Y-m-d', $postedDate);

        if (!$dateObject || $dateObject->format('Y-m-d') !== $postedDate) {
            throw new RuntimeException('Please select a valid class date.');
        }

        if ($postedDate > date('Y-m-d')) {
            throw new RuntimeException('Attendance cannot be recorded for a future date.');
        }

        $allowedStatuses = ['present', 'absent', 'late', 'excused'];

        $submittedStatuses = $_POST['attendance'] ?? [];

        if (!is_array($submittedStatuses) || count($submittedStatuses) === 0) {
            throw new RuntimeException('No student attendance data was submitted.');
        }

        // Reload valid registrations to prevent unauthorized submissions.
        $validStmt = att_prepare(
            $conn,
            "SELECT cr.id

             FROM course_registrations cr

             INNER JOIN course_offerings co
                ON co.id = cr.offering_id

             WHERE cr.offering_id = ?
               AND co.teacher_id = ?
               AND cr.status = 'registered'",
            'ii',
            [$selectedOffering, $userId]
        );

        $validRegistrations = $validStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $validRegistrationIds = array_map(
            'intval',
            array_column($validRegistrations, 'id')
        );

        if (count($validRegistrationIds) === 0) {
            throw new RuntimeException('No registered students found for this course.');
        }

        $conn->begin_transaction();

        $savedCount = 0;

        foreach ($submittedStatuses as $registrationId => $attendanceStatus) {

            $registrationId = (int)$registrationId;

            if (!in_array($registrationId, $validRegistrationIds, true)) {
                continue;
            }

            if (!in_array($attendanceStatus, $allowedStatuses, true)) {
                throw new RuntimeException('Invalid attendance status submitted.');
            }

            $existingStmt = att_prepare(
                $conn,
                "SELECT id
                 FROM attendance
                 WHERE registration_id = ?
                   AND class_date = ?
                 LIMIT 1",
                'is',
                [$registrationId, $postedDate]
            );

            $existing = $existingStmt->get_result()->fetch_assoc();

            if ($existing) {
                $updateStmt = att_prepare(
                    $conn,
                    "UPDATE attendance
                     SET status = ?, recorded_by = ?
                     WHERE registration_id = ?
                       AND class_date = ?",
                    'siis',
                    [$attendanceStatus, $userId, $registrationId, $postedDate]
                );
            } else {
                $insertStmt = att_prepare(
                    $conn,
                    "INSERT INTO attendance
                        (registration_id, class_date, status, recorded_by)
                     VALUES (?, ?, ?, ?)",
                    'issi',
                    [$registrationId, $postedDate, $attendanceStatus, $userId]
                );
            }

            $savedCount++;
        }

        $conn->commit();

        $successMessage = $savedCount . ' student attendance record(s) saved successfully.';
        $classDate = $postedDate;

    } catch (Throwable $e) {

        if ($conn->errno === 0) {
            // No database error to report here.
        }

        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        $errorMessage = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Load existing attendance for selected date
|--------------------------------------------------------------------------
*/

$existingAttendance = [];

if ($selectedOffering > 0) {
    $attendanceStmt = att_prepare(
        $conn,
        "SELECT
            a.registration_id,
            a.status

         FROM attendance a

         INNER JOIN course_registrations cr
            ON cr.id = a.registration_id

         INNER JOIN course_offerings co
            ON co.id = cr.offering_id

         WHERE cr.offering_id = ?
           AND co.teacher_id = ?
           AND a.class_date = ?",
        'iis',
        [$selectedOffering, $userId, $classDate]
    );

    $attendanceRows = $attendanceStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($attendanceRows as $row) {
        $existingAttendance[(int)$row['registration_id']] = $row['status'];
    }
}

/*
|--------------------------------------------------------------------------
| Attendance percentage and overall student statistics
|--------------------------------------------------------------------------
*/

$studentStatistics = [];

if ($selectedOffering > 0) {
    $statisticsStmt = att_prepare(
        $conn,
        "SELECT
            cr.id AS registration_id,

            COUNT(a.id) AS total_classes,

            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,

            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count,

            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,

            SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) AS excused_count

         FROM course_registrations cr

         LEFT JOIN attendance a
            ON a.registration_id = cr.id

         INNER JOIN course_offerings co
            ON co.id = cr.offering_id

         WHERE cr.offering_id = ?
           AND co.teacher_id = ?
           AND cr.status = 'registered'

         GROUP BY cr.id",
        'ii',
        [$selectedOffering, $userId]
    );

    $statisticsRows = $statisticsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($statisticsRows as $row) {
        $studentStatistics[(int)$row['registration_id']] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Attendance history
|--------------------------------------------------------------------------
*/

$history = [];

if ($selectedOffering > 0) {
    $historyStmt = att_prepare(
        $conn,
        "SELECT
            a.class_date,
            COUNT(a.id) AS total_students,

            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,

            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,

            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count,

            SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) AS excused_count

         FROM attendance a

         INNER JOIN course_registrations cr
            ON cr.id = a.registration_id

         INNER JOIN course_offerings co
            ON co.id = cr.offering_id

         WHERE cr.offering_id = ?
           AND co.teacher_id = ?

         GROUP BY a.class_date
         ORDER BY a.class_date DESC
         LIMIT 20",
        'ii',
        [$selectedOffering, $userId]
    );

    $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Attendance Management | DU EMBA MIS</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    .attendance-container {
        max-width: 1250px;
        margin: 30px auto;
        padding: 0 22px 40px;
    }

    .attendance-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 25px;
    }

    .attendance-header h1 {
        margin: 0 0 6px;
        color: #102d50;
    }

    .attendance-header p {
        color: #687789;
        margin: 0;
    }

    .attendance-card {
        background: #ffffff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 22px;
        margin-bottom: 22px;
    }

    .attendance-card h2 {
        color: #102d50;
        font-size: 19px;
        margin: 0 0 18px;
    }

    .attendance-filters {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        align-items: end;
    }

    .attendance-filters label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #263548;
        margin-bottom: 7px;
    }

    .attendance-filters select,
    .attendance-filters input {
        width: 100%;
        height: 43px;
        border: 1px solid #d5dce5;
        border-radius: 7px;
        padding: 8px 11px;
        box-sizing: border-box;
    }

    .attendance-btn {
        display: inline-block;
        background: #102d50;
        color: #ffffff;
        border: 0;
        border-radius: 7px;
        padding: 11px 18px;
        text-decoration: none;
        cursor: pointer;
        font-weight: 600;
    }

    .attendance-btn:hover {
        background: #1c456e;
    }

    .attendance-btn.secondary {
        background: #687789;
    }

    .attendance-btn:disabled {
        opacity: .5;
        cursor: not-allowed;
    }

    .attendance-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .attendance-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 850px;
    }

    .attendance-table th,
    .attendance-table td {
        padding: 13px 11px;
        border-bottom: 1px solid #e8ecf1;
        text-align: left;
        font-size: 13px;
    }

    .attendance-table th {
        background: #f4efe5;
        color: #102d50;
    }

    .attendance-table select {
        width: 125px;
        padding: 8px;
        border: 1px solid #d5dce5;
        border-radius: 6px;
        background: #ffffff;
    }

    .attendance-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .attendance-stat {
        background: #ffffff;
        border: 1px solid #e1e5eb;
        border-radius: 10px;
        padding: 17px;
    }

    .attendance-stat span {
        display: block;
        color: #687789;
        font-size: 13px;
        margin-bottom: 8px;
    }

    .attendance-stat strong {
        color: #102d50;
        font-size: 23px;
    }

    .attendance-alert {
        padding: 13px 16px;
        border-radius: 8px;
        margin-bottom: 18px;
    }

    .attendance-alert.success {
        background: #e5f5eb;
        color: #176b37;
    }

    .attendance-alert.error {
        background: #fde8e8;
        color: #a32a2a;
    }

    .attendance-empty {
        text-align: center;
        color: #687789;
        padding: 25px;
    }

    .attendance-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 18px;
    }

    .attendance-percentage {
        font-weight: 700;
        color: #102d50;
    }

    @media(max-width: 600px) {
        .attendance-container {
            padding: 0 13px 30px;
        }

        .attendance-card {
            padding: 15px;
        }
    }
    </style>
</head>

<body>

    <main class="attendance-container">

        <div class="attendance-header">
            <div>
                <h1>Attendance Management</h1>
                <p>Manage course-wise student attendance</p>
            </div>

            <a href="<?= BASE_URL ?>/teacher/dashboard.php" class="attendance-btn secondary">
                Back to Dashboard
            </a>
        </div>

        <?php if ($successMessage !== ''): ?>
        <div class="attendance-alert success">
            <?= att_e($successMessage) ?>
        </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
        <div class="attendance-alert error">
            <?= att_e($errorMessage) ?>
        </div>
        <?php endif; ?>

        <!-- COURSE AND DATE SELECTION -->

        <section class="attendance-card">
            <h2>Select Course and Class Date</h2>

            <form method="GET">
                <div class="attendance-filters">

                    <div>
                        <label for="offering_id">Assigned Course</label>

                        <select name="offering_id" id="offering_id" required>
                            <option value="">Select a course</option>

                            <?php foreach ($offerings as $offering): ?>
                            <option value="<?= (int)$offering['id'] ?>"
                                <?= $selectedOffering === (int)$offering['id'] ? 'selected' : '' ?>>

                                <?= att_e($offering['course_code']) ?>
                                -
                                <?= att_e($offering['course_title']) ?>
                                (<?= att_e($offering['semester_name']) ?>)

                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="class_date">Class Date</label>
                        <input type="date" name="class_date" id="class_date" value="<?= att_e($classDate) ?>"
                            max="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div>
                        <button type="submit" class="attendance-btn">
                            Load Students
                        </button>
                    </div>

                </div>
            </form>
        </section>

        <?php if ($selectedOffering > 0): ?>

        <!-- ATTENDANCE SUMMARY -->

        <?php
        $totalStudents = count($students);
        $presentToday = 0;
        $absentToday = 0;
        $lateToday = 0;
        $excusedToday = 0;

        foreach ($students as $student) {
            $studentStatus = $existingAttendance[(int)$student['registration_id']] ?? '';

            if ($studentStatus === 'present') {
                $presentToday++;
            } elseif ($studentStatus === 'absent') {
                $absentToday++;
            } elseif ($studentStatus === 'late') {
                $lateToday++;
            } elseif ($studentStatus === 'excused') {
                $excusedToday++;
            }
        }
        ?>

        <section class="attendance-stats">

            <div class="attendance-stat">
                <span>Total Students</span>
                <strong><?= $totalStudents ?></strong>
            </div>

            <div class="attendance-stat">
                <span>Present</span>
                <strong><?= $presentToday ?></strong>
            </div>

            <div class="attendance-stat">
                <span>Absent</span>
                <strong><?= $absentToday ?></strong>
            </div>

            <div class="attendance-stat">
                <span>Late</span>
                <strong><?= $lateToday ?></strong>
            </div>

            <div class="attendance-stat">
                <span>Excused</span>
                <strong><?= $excusedToday ?></strong>
            </div>

        </section>

        <!-- MARK ATTENDANCE -->

        <section class="attendance-card">

            <h2>
                Student Attendance -
                <?= date('d M Y', strtotime($classDate)) ?>
            </h2>

            <?php if ($students): ?>

            <form method="POST">

                <input type="hidden" name="csrf_token" value="<?= att_e($csrfToken) ?>">

                <input type="hidden" name="offering_id" value="<?= $selectedOffering ?>">

                <input type="hidden" name="class_date" value="<?= att_e($classDate) ?>">

                <div class="attendance-table-wrap">

                    <table class="attendance-table">

                        <thead>
                            <tr>
                                <th>SL</th>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Attendance Status</th>
                                <th>Classes Recorded</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($students as $index => $student): ?>

                            <?php
                                $registrationId = (int)$student['registration_id'];

                                $currentStatus = $existingAttendance[$registrationId] ?? 'present';

                                $statistics = $studentStatistics[$registrationId] ?? [];

                                $totalClasses = (int)($statistics['total_classes'] ?? 0);
                                $presentCount = (int)($statistics['present_count'] ?? 0);
                                $lateCount = (int)($statistics['late_count'] ?? 0);

                                $percentage = $totalClasses > 0
                                    ? (($presentCount + $lateCount) / $totalClasses) * 100
                                    : 0;
                                ?>

                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td><?= att_e($student['student_id']) ?></td>

                                <td><?= att_e($student['full_name']) ?></td>

                                <td>
                                    <select name="attendance[<?= $registrationId ?>]">

                                        <option value="present" <?= $currentStatus === 'present' ? 'selected' : '' ?>>
                                            Present
                                        </option>

                                        <option value="absent" <?= $currentStatus === 'absent' ? 'selected' : '' ?>>
                                            Absent
                                        </option>

                                        <option value="late" <?= $currentStatus === 'late' ? 'selected' : '' ?>>
                                            Late
                                        </option>

                                        <option value="excused" <?= $currentStatus === 'excused' ? 'selected' : '' ?>>
                                            Excused
                                        </option>

                                    </select>
                                </td>

                                <td><?= $totalClasses ?></td>

                                <td class="attendance-percentage">
                                    <?= number_format($percentage, 1) ?>%
                                </td>
                            </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <div class="attendance-actions">

                    <span style="color:#687789;font-size:13px;">
                        Select the attendance status for every student.
                    </span>

                    <button type="submit" name="save_attendance" value="1" class="attendance-btn">

                        Save Attendance

                    </button>

                </div>

            </form>

            <?php else: ?>

            <div class="attendance-empty">
                No registered students found for this course offering.
            </div>

            <?php endif; ?>

        </section>

        <!-- ATTENDANCE HISTORY -->

        <section class="attendance-card">

            <h2>Attendance History</h2>

            <div class="attendance-table-wrap">

                <table class="attendance-table">

                    <thead>
                        <tr>
                            <th>Class Date</th>
                            <th>Total Records</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Late</th>
                            <th>Excused</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if ($history): ?>

                        <?php foreach ($history as $record): ?>

                        <tr>
                            <td>
                                <?= date('d M Y', strtotime($record['class_date'])) ?>
                            </td>

                            <td><?= (int)$record['total_students'] ?></td>

                            <td><?= (int)$record['present_count'] ?></td>

                            <td><?= (int)$record['absent_count'] ?></td>

                            <td><?= (int)$record['late_count'] ?></td>

                            <td><?= (int)$record['excused_count'] ?></td>

                            <td>
                                <a class="attendance-btn secondary"
                                    href="<?= BASE_URL ?>/teacher/attendance.php?offering_id=<?= $selectedOffering ?>&class_date=<?= att_e($record['class_date']) ?>">
                                    View / Edit
                                </a>
                            </td>
                        </tr>

                        <?php endforeach; ?>

                        <?php else: ?>

                        <tr>
                            <td colspan="7" class="attendance-empty">
                                No attendance history available.
                            </td>
                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <?php elseif (!$offerings): ?>

        <section class="attendance-card">
            <div class="attendance-empty">
                No course offerings have been assigned to your account yet.
            </div>
        </section>

        <?php endif; ?>

    </main>

</body>

</html>