<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

$pageTitle = 'Course Registration';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$studentName = $_SESSION['full_name'] ?? 'Student';

if (empty($_SESSION['course_registration_csrf'])) {
    $_SESSION['course_registration_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['course_registration_csrf'];

$successMessage = '';
$errorMessage = '';
$student = null;
$studentId = 0;
$currentSemester = null;
$offerings = [];
$myRegistrations = [];

/* =====================================================
   HELPER FUNCTIONS
===================================================== */

function registration_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function registration_query($sql, $types = '', $params = [])
{
    global $conn;

    try {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('Course Registration Prepare Error: ' . $conn->error);
            return [];
        }

        if ($types !== '' && !empty($params)) {
            $bindParams = [$types];

            foreach ($params as $key => $value) {
                $bindParams[] = &$params[$key];
            }

            call_user_func_array([$stmt, 'bind_param'], $bindParams);
        }

        if (!$stmt->execute()) {
            error_log('Course Registration Execute Error: ' . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $rows = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        $stmt->close();

        return $rows;

    } catch (Throwable $e) {
        error_log('Course Registration Error: ' . $e->getMessage());
        return [];
    }
}

function registration_one($sql, $types = '', $params = [])
{
    $rows = registration_query($sql, $types, $params);
    return $rows[0] ?? null;
}

function registration_time($time)
{
    if (empty($time)) {
        return '';
    }

    $timestamp = strtotime($time);

    return $timestamp ? date('h:i A', $timestamp) : '';
}

/* =====================================================
   FIND STUDENT PROFILE
===================================================== */

if ($userId > 0) {
    $student = registration_one(
        "SELECT
            id,
            student_id,
            user_id
         FROM students
         WHERE user_id = ?
         LIMIT 1",
        "i",
        [$userId]
    );
}

if ($student) {
    $studentId = (int)$student['id'];
}

/* =====================================================
   GET CURRENT ACTIVE SEMESTER
===================================================== */

$currentSemester = registration_one(
    "SELECT
        id,
        semester_name,
        status,
        registration_open,
        start_date,
        end_date
     FROM semesters
     WHERE status = 'active'
     ORDER BY start_date DESC
     LIMIT 1"
);

$currentSemesterId = $currentSemester
    ? (int)$currentSemester['id']
    : 0;


/* =====================================================
   HANDLE COURSE REGISTRATION
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_course'])) {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $offeringId = (int)($_POST['offering_id'] ?? 0);

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {

        $errorMessage = 'Invalid request. Please refresh the page and try again.';

    } elseif ($studentId <= 0) {

        $errorMessage = 'Your student profile is not linked yet. Please contact the Academic Office.';

    } elseif ($offeringId <= 0) {

        $errorMessage = 'Please select a valid course.';

    } else {

        try {

            $conn->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | Lock Offering and Check Availability
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    co.id,
                    co.course_id,
                    co.semester_id,
                    co.capacity,
                    co.registration_open,
                    sem.registration_open AS semester_registration_open,
                    sem.status AS semester_status
                FROM course_offerings co
                INNER JOIN semesters sem
                    ON sem.id = co.semester_id
                WHERE co.id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception('Unable to check course availability.');
            }

            $stmt->bind_param("i", $offeringId);
            $stmt->execute();

            $offering = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$offering) {
                throw new Exception('Selected course offering was not found.');
            }

            if (
                $currentSemesterId <= 0 ||
                (int)$offering['semester_id'] !== $currentSemesterId
            ) {
                throw new Exception('You can only register for the current active semester.');
            }

            if ($offering['semester_status'] !== 'active') {
                throw new Exception('This semester is not active.');
            }

            if ((int)$offering['semester_registration_open'] !== 1) {
                throw new Exception('Semester course registration is currently closed.');
            }

            if ((int)$offering['registration_open'] !== 1) {
                throw new Exception('Registration for this course is currently closed.');
            }

            /*
            |--------------------------------------------------------------------------
            | Check Existing Registration for Same Offering
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id, status
                FROM course_registrations
                WHERE student_id = ?
                  AND offering_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception('Unable to verify your registration.');
            }

            $stmt->bind_param("ii", $studentId, $offeringId);
            $stmt->execute();

            $existingRegistration = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (
                $existingRegistration &&
                $existingRegistration['status'] === 'registered'
            ) {
                throw new Exception('You have already registered for this course.');
            }

            if (
                $existingRegistration &&
                $existingRegistration['status'] === 'completed'
            ) {
                throw new Exception('You have already completed this course. Re-registration is not allowed.');
            }

            if (
                $existingRegistration &&
                $existingRegistration['status'] !== 'dropped'
            ) {
                throw new Exception('This registration cannot be modified. Please contact the Academic Office.');
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Course in Same Semester
            |--------------------------------------------------------------------------
            */

            $courseId = (int)$offering['course_id'];

            $stmt = $conn->prepare("
                SELECT cr.id, cr.status
                FROM course_registrations cr
                INNER JOIN course_offerings co
                    ON co.id = cr.offering_id
                WHERE cr.student_id = ?
                  AND co.course_id = ?
                  AND co.semester_id = ?
                  AND cr.status IN ('registered', 'completed')
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception('Unable to verify duplicate course registration.');
            }

            $stmt->bind_param(
                "iii",
                $studentId,
                $courseId,
                $currentSemesterId
            );

            $stmt->execute();

            $duplicateCourse = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($duplicateCourse) {
                throw new Exception('You are already registered for or have completed this course in this semester.');
            }

            /*
            |--------------------------------------------------------------------------
            | Check Timetable Clash
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    c.course_code,
                    c.course_title,
                    cs.start_time,
                    cs.end_time,
                    cs.room
                FROM course_registrations cr
                INNER JOIN course_offerings co
                    ON co.id = cr.offering_id
                INNER JOIN courses c
                    ON c.id = co.course_id
                INNER JOIN course_schedules cs
                    ON cs.offering_id = co.id
                INNER JOIN course_schedules new_schedule
                    ON new_schedule.offering_id = ?
                WHERE cr.student_id = ?
                  AND co.semester_id = ?
                  AND cr.status = 'registered'
                  AND co.id != ?
                  AND cs.class_day = new_schedule.class_day
                  AND cs.start_time < new_schedule.end_time
                  AND cs.end_time > new_schedule.start_time
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception('Unable to check timetable conflicts.');
            }

            $stmt->bind_param(
                "iiii",
                $offeringId,
                $studentId,
                $currentSemesterId,
                $offeringId
            );

            $stmt->execute();

            $clash = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($clash) {

                throw new Exception(
                    'Timetable conflict! Your class overlaps with ' .
                    $clash['course_code'] . ' - ' . $clash['course_title'] . '.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Check Course Capacity
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT COUNT(*) AS registered_count
                FROM course_registrations
                WHERE offering_id = ?
                  AND status = 'registered'
            ");

            if (!$stmt) {
                throw new Exception('Unable to check available seats.');
            }

            $stmt->bind_param("i", $offeringId);
            $stmt->execute();

            $countData = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $registeredCount = (int)($countData['registered_count'] ?? 0);
            $capacity = (int)$offering['capacity'];

            if ($capacity <= 0 || $registeredCount >= $capacity) {
                throw new Exception('Sorry, this course is already full.');
            }

            /*
            |--------------------------------------------------------------------------
            | Restore Dropped Registration or Insert New
            |--------------------------------------------------------------------------
            */

            if (
                $existingRegistration &&
                $existingRegistration['status'] === 'dropped'
            ) {

                $registrationId = (int)$existingRegistration['id'];

                $stmt = $conn->prepare("
                    UPDATE course_registrations
                    SET
                        status = 'registered',
                        registered_at = NOW()
                    WHERE id = ?
                      AND student_id = ?
                      AND status = 'dropped'
                ");

                if (!$stmt) {
                    throw new Exception('Unable to restore your registration.');
                }

                $stmt->bind_param("ii", $registrationId, $studentId);

                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new Exception('Unable to restore your registration.');
                }

                $stmt->close();

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO course_registrations
                    (
                        student_id,
                        offering_id,
                        status,
                        registered_at
                    )
                    VALUES (?, ?, 'registered', NOW())
                ");

                if (!$stmt) {
                    throw new Exception('Unable to save your registration.');
                }

                $stmt->bind_param("ii", $studentId, $offeringId);

                if (!$stmt->execute()) {
                    throw new Exception('Unable to save your registration: ' . $stmt->error);
                }

                $stmt->close();
            }

            $conn->commit();

            $successMessage = 'Course registration completed successfully!';

        } catch (Throwable $e) {

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('Registration Rollback Error: ' . $rollbackError->getMessage());
            }

            $errorMessage = $e->getMessage();

            error_log(
                'Course Registration Transaction Error: ' .
                $e->getMessage()
            );
        }
    }
}


if ($currentSemesterId > 0) {

    $offerings = registration_query(
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
            ) AS registered_count
         FROM course_offerings co
         INNER JOIN courses c ON c.id = co.course_id
         LEFT JOIN teachers t ON t.id = co.teacher_id
         LEFT JOIN users u ON u.id = t.user_id
         LEFT JOIN course_schedules cs ON cs.offering_id = co.id
         WHERE co.semester_id = ?
           AND c.is_active = 1
         ORDER BY c.course_code ASC",
        "i",
        [$currentSemesterId]
    );
}

/* =====================================================
   GET STUDENT REGISTRATIONS
===================================================== */

if ($studentId > 0 && $currentSemesterId > 0) {

    $myRegistrations = registration_query(
        "SELECT
            cr.id AS registration_id,
            cr.status,
            cr.registered_at,
            co.id AS offering_id,
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
         ORDER BY cr.registered_at DESC",
        "ii",
        [$studentId, $currentSemesterId]
    );
}

/* =====================================================
   CALCULATE COURSE REGISTRATION STATUS
===================================================== */

$semesterRegistrationOpen = (
    $currentSemester &&
    (int)$currentSemester['registration_open'] === 1
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= registration_escape($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    :root {
        --navy: #102d50;
        --navy-light: #1c456e;
        --beige: #f4efe5;
        --white: #ffffff;
        --text: #263548;
        --muted: #687789;
        --border: #e1e5eb;
        --green: #198754;
        --red: #dc3545;
        --orange: #d97706;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background: #f5f7fa;
        color: var(--text);
        font-family: Arial, Helvetica, sans-serif;
    }

    .registration-container {
        width: calc(100% - 40px);
        max-width: 1440px;
        margin: 30px auto;
    }

    .topbar {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 22px;
    }

    .topbar h2 {
        color: var(--navy);
        font-size: 20px;
        margin: 0;
    }

    .topbar p {
        color: var(--muted);
        font-size: 13px;
        margin: 5px 0 0;
    }

    .topbar-links {
        display: flex;
        gap: 10px;
    }

    .topbar-links a {
        display: inline-block;
        padding: 9px 14px;
        border-radius: 7px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
    }

    .dashboard-link {
        background: #edf4fb;
        color: var(--navy);
    }

    .logout-link {
        background: var(--navy);
        color: var(--white);
    }

    .page-banner {
        background: linear-gradient(115deg, var(--navy), var(--navy-light));
        color: var(--white);
        border-radius: 12px;
        padding: 25px 28px;
        margin-bottom: 22px;
    }

    .page-banner h1 {
        font-size: 24px;
        margin: 0 0 8px;
    }

    .page-banner p {
        font-size: 13px;
        color: #e0e8f1;
        margin: 0;
    }

    .semester-panel {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 18px 20px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
    }

    .semester-panel h3 {
        margin: 0 0 7px;
        color: var(--navy);
        font-size: 16px;
    }

    .semester-panel p {
        margin: 0;
        color: var(--muted);
        font-size: 12px;
    }

    .badge {
        display: inline-block;
        padding: 6px 11px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }

    .badge.open {
        background: #e8f5ed;
        color: var(--green);
    }

    .badge.closed {
        background: #fdebec;
        color: var(--red);
    }

    .badge.full {
        background: #fff4df;
        color: var(--orange);
    }

    .message {
        padding: 13px 16px;
        border-radius: 8px;
        font-size: 13px;
        margin-bottom: 18px;
    }

    .message.success {
        background: #e8f5ed;
        border: 1px solid #c6e7d1;
        color: #146c43;
    }

    .message.error {
        background: #fdebec;
        border: 1px solid #f5c2c7;
        color: #842029;
    }

    .content-card {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 22px;
        margin-bottom: 22px;
    }

    .section-heading {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 18px;
    }

    .section-heading h2 {
        color: var(--navy);
        font-size: 17px;
        margin: 0;
    }

    .table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 950px;
    }

    th {
        background: #f3f6fa;
        color: var(--navy);
        text-align: left;
        font-size: 12px;
        padding: 13px 10px;
        white-space: nowrap;
    }

    td {
        border-bottom: 1px solid #edf0f3;
        padding: 13px 10px;
        font-size: 12px;
        vertical-align: middle;
    }

    tbody tr:last-child td {
        border-bottom: none;
    }

    .course-title {
        color: var(--navy);
        font-weight: 600;
    }

    .course-code {
        color: var(--muted);
        font-size: 11px;
        margin-top: 4px;
    }

    .seat-info {
        line-height: 1.7;
    }

    .seat-info strong {
        color: var(--navy);
    }

    .register-form {
        margin: 0;
    }

    .register-button {
        border: none;
        background: var(--navy);
        color: var(--white);
        padding: 9px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
    }

    .register-button:hover {
        background: var(--navy-light);
    }

    .register-button:disabled {
        background: #aab3bd;
        cursor: not-allowed;
    }

    .empty-state {
        text-align: center;
        background: #f8fafc;
        border: 1px dashed #d8e0e8;
        padding: 28px 15px;
        border-radius: 8px;
        color: var(--muted);
        font-size: 13px;
    }

    .empty-state strong {
        display: block;
        color: var(--navy);
        margin-bottom: 7px;
        font-size: 14px;
    }

    .footer {
        text-align: center;
        padding: 18px 10px;
        color: var(--muted);
        font-size: 12px;
    }

    @media (max-width: 650px) {
        .registration-container {
            width: calc(100% - 22px);
            margin: 12px auto;
        }

        .topbar {
            flex-direction: column;
            align-items: flex-start;
            padding: 15px;
        }

        .topbar-links {
            width: 100%;
        }

        .topbar-links a {
            flex: 1;
            text-align: center;
        }

        .page-banner {
            padding: 22px 18px;
        }

        .page-banner h1 {
            font-size: 21px;
        }

        .semester-panel {
            align-items: flex-start;
            flex-direction: column;
        }

        .content-card {
            padding: 15px;
        }
    }
    </style>

</head>

<body>

    <div class="registration-container">

        <!-- TOP NAVIGATION -->

        <header class="topbar">

            <div>
                <h2>DU EMBA MIS Student Portal</h2>
                <p>Course Registration</p>
            </div>

            <div class="topbar-links">

                <a class="dashboard-link" href="<?= BASE_URL ?>/student/dashboard.php">
                    Dashboard
                </a>

                <a class="logout-link" href="<?= BASE_URL ?>/auth/logout.php">
                    Logout
                </a>

            </div>

        </header>


        <!-- PAGE BANNER -->

        <section class="page-banner">

            <h1>Course Registration</h1>

            <p>
                View available courses and register for your current semester.
            </p>

        </section>


        <!-- ALERT MESSAGES -->

        <?php if ($successMessage): ?>

        <div class="message success">
            <?= registration_escape($successMessage) ?>
        </div>

        <?php endif; ?>

        <?php if ($errorMessage): ?>

        <div class="message error">
            <?= registration_escape($errorMessage) ?>
        </div>

        <?php endif; ?>


        <!-- CURRENT SEMESTER -->

        <?php if ($currentSemester): ?>

        <section class="semester-panel">

            <div>
                <h3>
                    <?= registration_escape($currentSemester['semester_name']) ?>
                </h3>

                <p>
                    Current Active Academic Semester
                </p>
            </div>

            <?php if ($semesterRegistrationOpen): ?>

            <span class="badge open">
                Semester Registration Open
            </span>

            <?php else: ?>

            <span class="badge closed">
                Semester Registration Closed
            </span>

            <?php endif; ?>

        </section>

        <?php else: ?>

        <div class="message error">
            No active semester has been configured by the Academic Office.
        </div>

        <?php endif; ?>


        <!-- AVAILABLE COURSE OFFERINGS -->

        <section class="content-card">

            <div class="section-heading">
                <h2>Available Course Offerings</h2>
            </div>

            <?php if ($studentId <= 0): ?>

            <div class="empty-state">
                <strong>Student Profile Not Found</strong>
                Your account is not linked with a student profile yet.
                Please contact the Academic Office.
            </div>

            <?php elseif (!$currentSemester): ?>

            <div class="empty-state">
                <strong>No Active Semester</strong>
                Course offerings will appear after the Academic Office
                activates a semester.
            </div>

            <?php elseif (empty($offerings)): ?>

            <div class="empty-state">
                <strong>No Course Offerings Available</strong>
                No courses have been assigned to the current semester yet.
            </div>

            <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Credit</th>
                            <th>Assigned Teacher</th>
                            <th>Class Schedule</th>
                            <th>Available Seats</th>
                            <th>Registration</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($offerings as $offering): ?>

                        <?php
                        $offeringId = (int)$offering['offering_id'];

                        $capacity = (int)$offering['capacity'];
                        $registeredCount = (int)$offering['registered_count'];

                        $availableSeats = max(0, $capacity - $registeredCount);

                        $isFull = ($capacity > 0 && $availableSeats <= 0);

                        $isCourseOpen = (
                            $semesterRegistrationOpen &&
                            (int)$offering['registration_open'] === 1 &&
                            !$isFull
                        );

                        $isAlreadyRegistered = false;

                        foreach ($myRegistrations as $myRegistration) {
                            if (
                                (int)$myRegistration['offering_id'] === $offeringId &&
                                $myRegistration['status'] === 'registered'
                            ) {
                                $isAlreadyRegistered = true;
                                break;
                            }
                        }

                        $schedule = 'Not scheduled';

                        if (!empty($offering['class_day'])) {

                            $schedule = $offering['class_day'];

                            $startTime = registration_time($offering['start_time'] ?? '');
                            $endTime = registration_time($offering['end_time'] ?? '');

                            if ($startTime && $endTime) {
                                $schedule .= '<br>' . registration_escape($startTime . ' - ' . $endTime);
                            }

                            if (!empty($offering['room'])) {
                                $schedule .= '<br>Room: ' . registration_escape($offering['room']);
                            }
                        }
                        ?>

                        <tr>

                            <td>
                                <div class="course-title">
                                    <?= registration_escape($offering['course_title']) ?>
                                </div>

                                <div class="course-code">
                                    <?= registration_escape($offering['course_code']) ?>
                                </div>
                            </td>

                            <td>
                                <?= number_format((float)$offering['credit_hours'], 1) ?>
                            </td>

                            <td>
                                <?= registration_escape($offering['teacher_name'] ?: 'Not assigned') ?>
                            </td>

                            <td>
                                <?= $schedule ?>
                            </td>

                            <td>
                                <div class="seat-info">
                                    <strong><?= $availableSeats ?></strong>
                                    / <?= $capacity ?>
                                    <br>
                                    <small>Available</small>
                                </div>
                            </td>

                            <td>

                                <?php if ($isAlreadyRegistered): ?>

                                <span class="badge open">
                                    Registered
                                </span>

                                <?php elseif ($isFull): ?>

                                <span class="badge full">
                                    Course Full
                                </span>

                                <?php elseif (!$semesterRegistrationOpen): ?>

                                <span class="badge closed">
                                    Semester Closed
                                </span>

                                <?php elseif ((int)$offering['registration_open'] !== 1): ?>

                                <span class="badge closed">
                                    Course Closed
                                </span>

                                <?php else: ?>

                                <span class="badge open">
                                    Open
                                </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if ($isAlreadyRegistered): ?>

                                <button class="register-button" disabled>
                                    Registered
                                </button>

                                <?php elseif ($isCourseOpen): ?>

                                <form method="POST" class="register-form"
                                    onsubmit="return confirm('Are you sure you want to register for this course?');">

                                    <input type="hidden" name="csrf_token"
                                        value="<?= registration_escape($csrfToken) ?>">

                                    <input type="hidden" name="offering_id" value="<?= $offeringId ?>">

                                    <button type="submit" name="register_course" class="register-button">
                                        Register
                                    </button>

                                </form>

                                <?php else: ?>

                                <button class="register-button" disabled>
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

        </section>


        <!-- MY REGISTERED COURSES -->

        <section class="content-card">

            <div class="section-heading">
                <h2>My Course Registrations</h2>
            </div>

            <?php if (empty($myRegistrations)): ?>

            <div class="empty-state">
                <strong>No Course Registrations Yet</strong>
                Your registered courses will appear here.
            </div>

            <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th>Credit Hours</th>
                            <th>Teacher</th>
                            <th>Registration Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($myRegistrations as $registration): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= registration_escape($registration['course_code']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= registration_escape($registration['course_title']) ?>
                            </td>

                            <td>
                                <?= number_format((float)$registration['credit_hours'], 1) ?>
                            </td>

                            <td>
                                <?= registration_escape($registration['teacher_name'] ?: 'Not assigned') ?>
                            </td>

                            <td>
                                <?= registration_escape(
                                    !empty($registration['registered_at'])
                                        ? date('d M Y', strtotime($registration['registered_at']))
                                        : 'Not available'
                                ) ?>
                            </td>

                            <td>

                                <?php if ($registration['status'] === 'registered'): ?>

                                <span class="badge open">
                                    Registered
                                </span>

                                <?php elseif ($registration['status'] === 'dropped'): ?>

                                <span class="badge closed">
                                    Dropped
                                </span>

                                <?php elseif ($registration['status'] === 'completed'): ?>

                                <span class="badge open">
                                    Completed
                                </span>

                                <?php else: ?>

                                <span class="badge closed">
                                    <?= registration_escape(ucfirst($registration['status'])) ?>
                                </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <?php endif; ?>

        </section>


        <footer class="footer">

            DU EMBA MIS Student Portal
            <br>
            Department of Management Information Systems, University of Dhaka

        </footer>

    </div>

</body>

</html>