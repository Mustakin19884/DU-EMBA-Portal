<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$username = (string)($_SESSION['username'] ?? '');

function registration_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function registration_query(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Course registration SQL prepare error: ' . $conn->error);
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log('Course registration SQL execute error: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $stmt->close();

    return $rows;
}

function registration_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = registration_query($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function registration_time($time): string
{
    if (!$time) {
        return 'Not scheduled';
    }

    $timestamp = strtotime((string)$time);
    return $timestamp ? date('h:i A', $timestamp) : 'Not scheduled';
}

/*
|--------------------------------------------------------------------------
| Resolve student
|--------------------------------------------------------------------------
*/

if ($userId <= 0 && $username !== '') {
    $user = registration_one(
        $conn,
        "SELECT id FROM users WHERE username = ? LIMIT 1",
        "s",
        [$username]
    );

    $userId = (int)($user['id'] ?? 0);
}

$student = null;

if ($userId > 0) {
    $student = registration_one(
        $conn,
        "SELECT id, student_id, user_id
         FROM students
         WHERE user_id = ?
         LIMIT 1",
        "i",
        [$userId]
    );
}

$studentTableId = (int)($student['id'] ?? 0);

if ($studentTableId <= 0) {
    http_response_code(403);
    exit('Student profile not found. Please contact the department office.');
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['course_registration_token'])) {
    $_SESSION['course_registration_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['course_registration_token'];

/*
|--------------------------------------------------------------------------
| Current semester
|--------------------------------------------------------------------------
*/

$currentSemester = registration_one(
    $conn,
    "SELECT id, semester_name, start_date, end_date, registration_open, status
     FROM semesters
     WHERE status = 'active'
     ORDER BY id DESC
     LIMIT 1"
);

$currentSemesterId = (int)($currentSemester['id'] ?? 0);

$semesterRegistrationOpen =
    $currentSemester && (int)$currentSemester['registration_open'] === 1;

/*
|--------------------------------------------------------------------------
| Handle course registration
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $offeringId = (int)($_POST['offering_id'] ?? 0);

    if (!hash_equals($csrfToken, $submittedToken)) {

        $message = 'Invalid request. Please refresh the page and try again.';
        $messageType = 'error';

    } elseif ($offeringId <= 0) {

        $message = 'Please select a valid course.';
        $messageType = 'error';

    } elseif (!$currentSemester || !$semesterRegistrationOpen) {

        $message = 'Course registration is currently closed for this semester.';
        $messageType = 'error';

    } else {

        try {

            $conn->begin_transaction();

            /*
             * Lock the selected course offering.
             * This helps prevent two students from taking the last seat simultaneously.
             */

            $stmt = $conn->prepare(
                "SELECT
                    co.id,
                    co.course_id,
                    co.semester_id,
                    co.capacity,
                    co.registration_open,
                    c.course_code,
                    c.course_title,
                    c.credit_hours,
                    c.is_active
                 FROM course_offerings co
                 INNER JOIN courses c ON c.id = co.course_id
                 WHERE co.id = ?
                   AND co.semester_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$stmt) {
                throw new RuntimeException('Unable to prepare course query.');
            }

            $stmt->bind_param("ii", $offeringId, $currentSemesterId);
            $stmt->execute();

            $offeringResult = $stmt->get_result();
            $offering = $offeringResult ? $offeringResult->fetch_assoc() : null;

            $stmt->close();

            if (!$offering) {

                throw new RuntimeException('This course offering is not available.');

            }

            if ((int)$offering['is_active'] !== 1) {

                throw new RuntimeException('This course is currently inactive.');

            }

            if ((int)$offering['registration_open'] !== 1) {

                throw new RuntimeException('Registration is closed for this course.');

            }

            /*
             * Check duplicate active registration.
             */

            $stmt = $conn->prepare(
                "SELECT id
                 FROM course_registrations
                 WHERE student_id = ?
                   AND offering_id = ?
                   AND status = 'registered'
                 LIMIT 1"
            );

            if (!$stmt) {
                throw new RuntimeException('Unable to check existing registration.');
            }

            $stmt->bind_param("ii", $studentTableId, $offeringId);
            $stmt->execute();

            $duplicateResult = $stmt->get_result();
            $duplicate = $duplicateResult ? $duplicateResult->fetch_assoc() : null;

            $stmt->close();

            if ($duplicate) {
                throw new RuntimeException('You have already registered for this course.');
            }

            /*
             * Check course capacity.
             */

            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM course_registrations
                 WHERE offering_id = ?
                   AND status = 'registered'"
            );

            if (!$stmt) {
                throw new RuntimeException('Unable to check course capacity.');
            }

            $stmt->bind_param("i", $offeringId);
            $stmt->execute();

            $capacityResult = $stmt->get_result();
            $capacityData = $capacityResult ? $capacityResult->fetch_assoc() : [];

            $stmt->close();

            $registeredCount = (int)($capacityData['total'] ?? 0);
            $capacity = (int)$offering['capacity'];

            if ($capacity > 0 && $registeredCount >= $capacity) {
                throw new RuntimeException('Sorry, this course has reached its registration capacity.');
            }

            /*
             * Check weekly schedule conflict.
             */

            $newSchedule = registration_one(
                $conn,
                "SELECT class_day, start_time, end_time
                 FROM course_schedules
                 WHERE offering_id = ?
                 LIMIT 1",
                "i",
                [$offeringId]
            );

            if ($newSchedule && !empty($newSchedule['class_day'])) {

                $conflictingCourse = registration_one(
                    $conn,
                    "SELECT
                        c.course_code,
                        c.course_title
                     FROM course_registrations cr
                     INNER JOIN course_offerings co ON co.id = cr.offering_id
                     INNER JOIN courses c ON c.id = co.course_id
                     INNER JOIN course_schedules cs ON cs.offering_id = co.id
                     WHERE cr.student_id = ?
                       AND cr.status = 'registered'
                       AND co.semester_id = ?
                       AND co.id <> ?
                       AND cs.class_day = ?
                       AND TIME(?) < cs.end_time
                       AND TIME(?) > cs.start_time
                     LIMIT 1",
                    "iiisss",
                    [
                        $studentTableId,
                        $currentSemesterId,
                        $offeringId,
                        $newSchedule['class_day'],
                        $newSchedule['start_time'],
                        $newSchedule['end_time']
                    ]
                );

                if ($conflictingCourse) {
                    throw new RuntimeException(
                        'Schedule conflict with ' .
                        $conflictingCourse['course_code'] . ' - ' .
                        $conflictingCourse['course_title'] . '.'
                    );
                }
            }

            /*
             * Create registration.
             */

            $stmt = $conn->prepare(
                "INSERT INTO course_registrations
                    (student_id, offering_id, status, registered_at)
                 VALUES (?, ?, 'registered', NOW())"
            );

            if (!$stmt) {
                throw new RuntimeException('Unable to prepare registration.');
            }

            $stmt->bind_param("ii", $studentTableId, $offeringId);

            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to complete course registration.');
            }

            $stmt->close();

            $conn->commit();

            $message = 'Course successfully registered!';
            $messageType = 'success';

        } catch (Throwable $e) {

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log($rollbackError->getMessage());
            }

            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'An unexpected error occurred. Please try again.';

            error_log('Course registration error: ' . $e->getMessage());

            $messageType = 'error';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Available courses
|--------------------------------------------------------------------------
*/

$availableCourses = [];

if ($currentSemesterId > 0) {

    $availableCourses = registration_query(
        $conn,
        "SELECT
            co.id AS offering_id,
            co.capacity,
            co.registration_open,
            c.course_code,
            c.course_title,
            c.credit_hours,
            u.full_name AS teacher_name,
            cs.class_day,
            cs.start_time,
            cs.end_time,
            cs.room,

            (
                SELECT COUNT(*)
                FROM course_registrations cr
                WHERE cr.offering_id = co.id
                  AND cr.status = 'registered'
            ) AS registered_count,

            (
                SELECT COUNT(*)
                FROM course_registrations cr2
                WHERE cr2.offering_id = co.id
                  AND cr2.student_id = ?
                  AND cr2.status = 'registered'
            ) AS already_registered

         FROM course_offerings co
         INNER JOIN courses c ON c.id = co.course_id
         LEFT JOIN teachers t ON t.id = co.teacher_id
         LEFT JOIN users u ON u.id = t.user_id
         LEFT JOIN course_schedules cs ON cs.offering_id = co.id

         WHERE co.semester_id = ?
           AND c.is_active = 1

         ORDER BY c.course_code ASC",
        "ii",
        [$studentTableId, $currentSemesterId]
    );
}

/*
|--------------------------------------------------------------------------
| My registered courses
|--------------------------------------------------------------------------
*/

$myCourses = [];

if ($currentSemesterId > 0) {

    $myCourses = registration_query(
        $conn,
        "SELECT
            cr.id AS registration_id,
            cr.status,
            cr.registered_at,
            c.course_code,
            c.course_title,
            c.credit_hours,
            u.full_name AS teacher_name,
            cs.class_day,
            cs.start_time,
            cs.end_time,
            cs.room

         FROM course_registrations cr
         INNER JOIN course_offerings co ON co.id = cr.offering_id
         INNER JOIN courses c ON c.id = co.course_id
         LEFT JOIN teachers t ON t.id = co.teacher_id
         LEFT JOIN users u ON u.id = t.user_id
         LEFT JOIN course_schedules cs ON cs.offering_id = co.id

         WHERE cr.student_id = ?
           AND co.semester_id = ?
           AND cr.status = 'registered'

         ORDER BY c.course_code ASC",
        "ii",
        [$studentTableId, $currentSemesterId]
    );
}

$myCourseCount = count($myCourses);
$totalCredits = 0;

foreach ($myCourses as $course) {
    $totalCredits += (float)$course['credit_hours'];
}

$dashboardLink = BASE_URL . '/student/dashboard.php';
$logoutLink = BASE_URL . '/auth/logout.php';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Course Registration | DU EMBA MIS Portal</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    :root {
        --navy: #102d50;
        --navy-light: #1c456e;
        --beige: #f4efe5;
        --bg: #f5f7fb;
        --text: #263548;
        --muted: #687789;
        --border: #e5e9f0;
        --white: #ffffff;
        --green: #16845b;
        --orange: #c77a19;
        --red: #c34d4d;
        --shadow: 0 8px 24px rgba(16, 45, 80, .06);
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family: Arial, Helvetica, sans-serif;
    }

    a {
        color: inherit;
        text-decoration: none;
    }

    .cr-shell {
        width: min(1440px, calc(100% - 48px));
        margin: 0 auto;
        padding: 26px 0 45px;
    }

    .cr-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        background: white;
        padding: 17px 22px;
        border: 1px solid var(--border);
        border-radius: 15px;
        box-shadow: var(--shadow);
        margin-bottom: 22px;
    }

    .cr-brand {
        display: flex;
        align-items: center;
        gap: 13px;
    }

    .cr-logo {
        width: 46px;
        height: 46px;
        display: grid;
        place-items: center;
        background: var(--navy);
        color: white;
        border-radius: 12px;
        font-weight: 800;
    }

    .cr-brand h2 {
        color: var(--navy);
        font-size: 17px;
        margin: 0;
    }

    .cr-brand p {
        color: var(--muted);
        font-size: 12px;
        margin: 4px 0 0;
    }

    .cr-actions {
        display: flex;
        gap: 9px;
    }

    .cr-btn {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        padding: 10px 15px;
        border: 1px solid var(--border);
        border-radius: 9px;
        background: white;
        color: var(--navy);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }

    .cr-btn.primary {
        background: var(--navy);
        color: white;
        border-color: var(--navy);
    }

    .cr-btn.disabled {
        background: #edf0f4;
        color: #8793a1;
        border-color: #edf0f4;
        cursor: not-allowed;
    }

    .cr-hero {
        background: linear-gradient(120deg, #102d50, #1d4b76);
        color: white;
        padding: 27px 30px;
        border-radius: 16px;
        margin-bottom: 20px;
        box-shadow: 0 12px 28px rgba(16, 45, 80, .14);
    }

    .cr-hero small {
        color: #d7e2ef;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 1.2px;
    }

    .cr-hero h1 {
        margin: 9px 0 7px;
        font-size: 27px;
    }

    .cr-hero p {
        margin: 0;
        color: #d7e2ef;
        font-size: 13px;
    }

    .cr-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .cr-stat {
        background: white;
        border: 1px solid var(--border);
        border-radius: 13px;
        padding: 18px;
        box-shadow: var(--shadow);
    }

    .cr-stat span {
        display: block;
        color: var(--muted);
        font-size: 11px;
        margin-bottom: 9px;
    }

    .cr-stat strong {
        display: block;
        color: var(--navy);
        font-size: 23px;
    }

    .cr-panel {
        background: white;
        border: 1px solid var(--border);
        border-radius: 15px;
        padding: 21px;
        margin-bottom: 20px;
        box-shadow: var(--shadow);
    }

    .cr-panel-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 17px;
    }

    .cr-panel-head h3 {
        color: var(--navy);
        font-size: 16px;
        margin: 0;
    }

    .cr-panel-head span {
        color: var(--muted);
        font-size: 11px;
    }

    .cr-message {
        padding: 14px 16px;
        border-radius: 10px;
        font-size: 13px;
        margin-bottom: 20px;
        line-height: 1.5;
    }

    .cr-message.success {
        background: #e9f5ef;
        color: var(--green);
        border: 1px solid #cce9d9;
    }

    .cr-message.error {
        background: #fdecec;
        color: var(--red);
        border: 1px solid #f5cccc;
    }

    .cr-semester {
        background: var(--beige);
        border-radius: 11px;
        padding: 15px 17px;
        margin-bottom: 17px;
    }

    .cr-semester strong {
        display: block;
        color: var(--navy);
        font-size: 15px;
        margin-bottom: 5px;
    }

    .cr-semester span {
        color: var(--muted);
        font-size: 11px;
    }

    .cr-table-wrap {
        overflow-x: auto;
    }

    .cr-table {
        width: 100%;
        min-width: 850px;
        border-collapse: collapse;
    }

    .cr-table th {
        text-align: left;
        padding: 12px 10px;
        background: #f7f9fc;
        color: var(--muted);
        font-size: 11px;
        white-space: nowrap;
    }

    .cr-table td {
        padding: 14px 10px;
        border-bottom: 1px solid var(--border);
        font-size: 12px;
        vertical-align: middle;
    }

    .cr-table td strong {
        display: block;
        color: var(--navy);
        margin-bottom: 4px;
    }

    .cr-muted {
        color: var(--muted);
        font-size: 10px;
    }

    .cr-status {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 700;
        white-space: nowrap;
    }

    .cr-status.open {
        color: var(--green);
        background: #e9f5ef;
    }

    .cr-status.closed {
        color: var(--red);
        background: #fdecec;
    }

    .cr-status.registered {
        color: var(--navy);
        background: #eaf0f8;
    }

    .cr-status.full {
        color: var(--orange);
        background: #fff3df;
    }

    .cr-register-form {
        margin: 0;
    }

    .cr-register-btn {
        border: 0;
        border-radius: 8px;
        padding: 9px 12px;
        background: var(--navy);
        color: white;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
    }

    .cr-register-btn:hover {
        background: var(--navy-light);
    }

    .cr-register-btn:disabled {
        background: #e8ebef;
        color: #8793a1;
        cursor: not-allowed;
    }

    .cr-empty {
        padding: 22px;
        border-radius: 10px;
        background: #f8f9fb;
        text-align: center;
        color: var(--muted);
        font-size: 12px;
        line-height: 1.6;
    }

    .cr-note {
        color: var(--muted);
        font-size: 11px;
        line-height: 1.7;
        margin-top: 13px;
    }

    .cr-footer {
        text-align: center;
        color: #8b97a6;
        font-size: 11px;
        padding: 10px 0;
    }

    @media(max-width: 750px) {
        .cr-summary {
            grid-template-columns: 1fr 1fr;
        }

        .cr-shell {
            width: calc(100% - 24px);
            padding-top: 12px;
        }

        .cr-header {
            padding: 13px;
        }

        .cr-brand h2 {
            font-size: 13px;
        }

        .cr-brand p {
            font-size: 10px;
        }

        .cr-actions {
            gap: 5px;
        }

        .cr-btn {
            padding: 9px 10px;
            font-size: 10px;
        }

        .cr-hero {
            padding: 22px;
        }

        .cr-hero h1 {
            font-size: 23px;
        }

        .cr-panel {
            padding: 15px;
        }
    }

    @media(max-width: 420px) {
        .cr-summary {
            grid-template-columns: 1fr;
        }

        .cr-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .cr-actions {
            width: 100%;
        }

        .cr-actions .cr-btn {
            flex: 1;
        }
    }
    </style>

</head>

<body>

    <div class="cr-shell">

        <!-- Header -->

        <header class="cr-header">

            <div class="cr-brand">
                <div class="cr-logo">DU</div>

                <div>
                    <h2>DU EMBA MIS Portal</h2>
                    <p>Department of Management Information Systems</p>
                </div>
            </div>

            <div class="cr-actions">

                <a class="cr-btn" href="<?= registration_escape($dashboardLink) ?>">
                    Dashboard
                </a>

                <a class="cr-btn primary" href="<?= registration_escape($logoutLink) ?>">
                    Logout
                </a>

            </div>

        </header>

        <!-- Hero -->

        <section class="cr-hero">

            <small>Student Services</small>

            <h1>Course Registration</h1>

            <p>
                Explore available courses and register for your current academic semester.
            </p>

        </section>

        <!-- Messages -->

        <?php if ($message !== ''): ?>

        <div class="cr-message <?= registration_escape($messageType) ?>">
            <?= registration_escape($message) ?>
        </div>

        <?php endif; ?>

        <!-- Semester status -->

        <?php if ($currentSemester): ?>

        <div class="cr-semester">

            <strong>
                <?= registration_escape($currentSemester['semester_name']) ?>
            </strong>

            <span>
                Registration period:

                <?= $semesterRegistrationOpen ? 'Open' : 'Closed' ?>

                &nbsp; | &nbsp;

                <?= registration_escape($currentSemester['start_date']) ?>
                –
                <?= registration_escape($currentSemester['end_date']) ?>
            </span>

        </div>

        <?php else: ?>

        <div class="cr-message error">
            No active semester is configured. Please contact the Academic Office.
        </div>

        <?php endif; ?>

        <!-- Summary -->

        <section class="cr-summary">

            <div class="cr-stat">
                <span>Current Semester</span>

                <strong style="font-size:16px">
                    <?= registration_escape($currentSemester['semester_name'] ?? 'Not configured') ?>
                </strong>
            </div>

            <div class="cr-stat">
                <span>My Registered Courses</span>
                <strong><?= (int)$myCourseCount ?></strong>
            </div>

            <div class="cr-stat">
                <span>Total Registered Credits</span>
                <strong><?= number_format($totalCredits, 1) ?></strong>
            </div>

        </section>

        <!-- Available Courses -->

        <section class="cr-panel">

            <div class="cr-panel-head">

                <h3>Available Courses</h3>

                <span>
                    <?= count($availableCourses) ?> course offering(s)
                </span>

            </div>

            <?php if (!$currentSemester): ?>

            <div class="cr-empty">
                Course registration is unavailable because no active semester exists.
            </div>

            <?php elseif (!$availableCourses): ?>

            <div class="cr-empty">
                No course offerings have been published for this semester yet.
            </div>

            <?php else: ?>

            <div class="cr-table-wrap">

                <table class="cr-table">

                    <thead>

                        <tr>
                            <th>Course</th>
                            <th>Credit</th>
                            <th>Teacher</th>
                            <th>Class Schedule</th>
                            <th>Room</th>
                            <th>Seats</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($availableCourses as $course): ?>

                        <?php

                        $capacity = (int)$course['capacity'];
                        $registered = (int)$course['registered_count'];

                        $isFull = $capacity > 0 && $registered >= $capacity;
                        $isRegistered = (int)$course['already_registered'] > 0;

                        $isCourseOpen =
                            $semesterRegistrationOpen &&
                            (int)$course['registration_open'] === 1 &&
                            !$isFull;

                        ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= registration_escape($course['course_code']) ?>
                                </strong>

                                <?= registration_escape($course['course_title']) ?>

                            </td>

                            <td>
                                <?= registration_escape($course['credit_hours']) ?>
                            </td>

                            <td>
                                <?= registration_escape($course['teacher_name'] ?? 'Not assigned') ?>
                            </td>

                            <td>

                                <?php if (!empty($course['class_day'])): ?>

                                <?= registration_escape($course['class_day']) ?>

                                <br>

                                <span class="cr-muted">

                                    <?= registration_escape(
                                            registration_time($course['start_time'])
                                        ) ?>

                                    –

                                    <?= registration_escape(
                                            registration_time($course['end_time'])
                                        ) ?>

                                </span>

                                <?php else: ?>

                                Not scheduled

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= registration_escape($course['room'] ?? '—') ?>
                            </td>

                            <td>

                                <?= $registered ?>

                                /

                                <?= $capacity > 0 ? $capacity : 'Unlimited' ?>

                            </td>

                            <td>

                                <?php if ($isRegistered): ?>

                                <span class="cr-status registered">
                                    Registered
                                </span>

                                <?php elseif ($isFull): ?>

                                <span class="cr-status full">
                                    Full
                                </span>

                                <?php elseif (!$semesterRegistrationOpen || (int)$course['registration_open'] !== 1): ?>

                                <span class="cr-status closed">
                                    Closed
                                </span>

                                <?php else: ?>

                                <span class="cr-status open">
                                    Open
                                </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if ($isRegistered): ?>

                                <button class="cr-register-btn" disabled>
                                    Registered
                                </button>

                                <?php elseif ($isCourseOpen): ?>

                                <form method="POST" class="cr-register-form"
                                    onsubmit="return confirm('Are you sure you want to register for this course?');">

                                    <input type="hidden" name="csrf_token"
                                        value="<?= registration_escape($csrfToken) ?>">

                                    <input type="hidden" name="offering_id" value="<?= (int)$course['offering_id'] ?>">

                                    <button type="submit" class="cr-register-btn">
                                        Register
                                    </button>

                                </form>

                                <?php else: ?>

                                <button class="cr-register-btn" disabled>
                                    Unavailable
                                </button>

                                <?php endif; ?>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <?php endif; ?>

            <p class="cr-note">
                Please review the course details, teacher, class schedule and room before registration.
                You cannot register for the same course offering twice. Registration is subject to
                available seats and the semester registration period.
            </p>

        </section>

        <!-- My Registered Courses -->

        <section class="cr-panel">

            <div class="cr-panel-head">

                <h3>My Registered Courses</h3>

                <span>
                    <?= (int)$myCourseCount ?> registered course(s)
                </span>

            </div>

            <?php if ($myCourses): ?>

            <div class="cr-table-wrap">

                <table class="cr-table">

                    <thead>

                        <tr>
                            <th>Course</th>
                            <th>Credit</th>
                            <th>Teacher</th>
                            <th>Schedule</th>
                            <th>Room</th>
                            <th>Registration Date</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($myCourses as $course): ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= registration_escape($course['course_code']) ?>
                                </strong>

                                <?= registration_escape($course['course_title']) ?>

                            </td>

                            <td>
                                <?= registration_escape($course['credit_hours']) ?>
                            </td>

                            <td>
                                <?= registration_escape($course['teacher_name'] ?? 'Not assigned') ?>
                            </td>

                            <td>

                                <?php if (!empty($course['class_day'])): ?>

                                <?= registration_escape($course['class_day']) ?>

                                <br>

                                <span class="cr-muted">

                                    <?= registration_escape(
                                            registration_time($course['start_time'])
                                        ) ?>

                                    –

                                    <?= registration_escape(
                                            registration_time($course['end_time'])
                                        ) ?>

                                </span>

                                <?php else: ?>

                                Not scheduled

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= registration_escape($course['room'] ?? '—') ?>
                            </td>

                            <td>
                                <?= registration_escape(
                                    $course['registered_at']
                                        ? date('d M Y', strtotime($course['registered_at']))
                                        : '—'
                                ) ?>
                            </td>

                            <td>
                                <span class="cr-status registered">
                                    <?= registration_escape($course['status']) ?>
                                </span>
                            </td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <?php else: ?>

            <div class="cr-empty">
                You have not registered for any courses in the current semester yet.
            </div>

            <?php endif; ?>

        </section>

        <footer class="cr-footer">
            DU EMBA MIS Student Portal · Department of Management Information Systems
        </footer>

    </div>

</body>

</html>