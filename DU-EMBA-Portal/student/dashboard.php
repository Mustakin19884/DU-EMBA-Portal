<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

$pageTitle = 'Student Dashboard';

$studentName = $_SESSION['full_name'] ?? 'Student';
$username   = $_SESSION['username'] ?? '';
$userId     = (int)($_SESSION['user_id'] ?? 0);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function dashboard_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dashboard_query($sql, $types = '', $params = [])
{
    global $conn;

    try {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('Dashboard SQL Prepare Error: ' . $conn->error);
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
            error_log('Dashboard SQL Execute Error: ' . $stmt->error);
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
        error_log('Dashboard Error: ' . $e->getMessage());
        return [];
    }
}

function dashboard_one($sql, $types = '', $params = [])
{
    $rows = dashboard_query($sql, $types, $params);
    return $rows[0] ?? null;
}

function dashboard_money($amount)
{
    return '৳ ' . number_format((float)$amount, 2);
}

function dashboard_date($date)
{
    if (empty($date) || $date === '0000-00-00') {
        return 'Not available';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date('d M Y', $timestamp) : 'Not available';
}

function dashboard_datetime($datetime)
{
    if (empty($datetime)) {
        return 'Not available';
    }

    $timestamp = strtotime($datetime);

    return $timestamp ? date('d M Y, h:i A', $timestamp) : 'Not available';
}

function dashboard_time($time)
{
    if (empty($time)) {
        return '';
    }

    $timestamp = strtotime($time);

    return $timestamp ? date('h:i A', $timestamp) : '';
}

/* =========================================================
   FIND LOGGED-IN USER
========================================================= */

if ($userId <= 0 && !empty($username)) {
    $userRow = dashboard_one(
        "SELECT id, full_name FROM users WHERE username = ? LIMIT 1",
        "s",
        [$username]
    );

    if ($userRow) {
        $userId = (int)$userRow['id'];
        $studentName = $userRow['full_name'] ?? $studentName;
    }
}

/* =========================================================
   STUDENT PROFILE
========================================================= */

$student = null;
$studentId = 0;

if ($userId > 0) {
    $student = dashboard_one(
        "SELECT
            s.id AS student_db_id,
            s.student_id,
            s.phone,
            s.address,
            s.admission_date,
            u.full_name,
            u.username,
            u.email,
            b.batch_name,
            sem.semester_name AS admission_semester
         FROM students s
         INNER JOIN users u ON u.id = s.user_id
         LEFT JOIN batches b ON b.id = s.batch_id
         LEFT JOIN semesters sem ON sem.id = s.admission_semester_id
         WHERE s.user_id = ?
         LIMIT 1",
        "i",
        [$userId]
    );
}

if ($student) {
    $studentId = (int)$student['student_db_id'];
    $studentName = $student['full_name'] ?: $studentName;
}

/* =========================================================
   CURRENT ACTIVE SEMESTER
========================================================= */

$currentSemester = dashboard_one(
    "SELECT id, semester_name, start_date, end_date
     FROM semesters
     WHERE status = 'active'
     ORDER BY start_date DESC
     LIMIT 1"
);

$currentSemesterId = $currentSemester ? (int)$currentSemester['id'] : 0;

/* =========================================================
   REGISTERED COURSES
========================================================= */

$registeredCourses = [];

if ($studentId > 0 && $currentSemesterId > 0) {
    $registeredCourses = dashboard_query(
        "SELECT
            cr.id AS registration_id,
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
           AND cr.status = 'registered'
           AND co.semester_id = ?
         ORDER BY c.course_code ASC",
        "ii",
        [$studentId, $currentSemesterId]
    );
}

$courseCount = count($registeredCourses);
$totalCreditHours = 0;

foreach ($registeredCourses as $course) {
    $totalCreditHours += (float)($course['credit_hours'] ?? 0);
}

/* =========================================================
   CGPA - ONLY PUBLISHED RESULTS
========================================================= */

$cgpaData = null;

if ($studentId > 0) {
    $cgpaData = dashboard_one(
        "SELECT
            SUM(r.grade_point * c.credit_hours) AS total_grade_points,
            SUM(c.credit_hours) AS total_credits
         FROM results r
         INNER JOIN course_registrations cr ON cr.id = r.registration_id
         INNER JOIN course_offerings co ON co.id = cr.offering_id
         INNER JOIN courses c ON c.id = co.course_id
         WHERE cr.student_id = ?
           AND r.status = 'published'
           AND r.grade_point IS NOT NULL",
        "i",
        [$studentId]
    );
}

$cgpa = null;

if (
    $cgpaData &&
    (float)$cgpaData['total_credits'] > 0
) {
    $cgpa = (float)$cgpaData['total_grade_points']
          / (float)$cgpaData['total_credits'];
}

/* =========================================================
   CURRENT SEMESTER SGPA - ONLY PUBLISHED RESULTS
========================================================= */

$sgpaData = null;

if ($studentId > 0 && $currentSemesterId > 0) {
    $sgpaData = dashboard_one(
        "SELECT
            SUM(r.grade_point * c.credit_hours) AS total_grade_points,
            SUM(c.credit_hours) AS total_credits
         FROM results r
         INNER JOIN course_registrations cr ON cr.id = r.registration_id
         INNER JOIN course_offerings co ON co.id = cr.offering_id
         INNER JOIN courses c ON c.id = co.course_id
         WHERE cr.student_id = ?
           AND co.semester_id = ?
           AND r.status = 'published'
           AND r.grade_point IS NOT NULL",
        "ii",
        [$studentId, $currentSemesterId]
    );
}

$sgpa = null;

if (
    $sgpaData &&
    (float)$sgpaData['total_credits'] > 0
) {
    $sgpa = (float)$sgpaData['total_grade_points']
          / (float)$sgpaData['total_credits'];
}

/* =========================================================
   RECENT PUBLISHED RESULTS
========================================================= */

$recentResults = [];

if ($studentId > 0) {
    $recentResults = dashboard_query(
        "SELECT
            c.course_code,
            c.course_title,
            c.credit_hours,
            sem.semester_name,
            r.total_marks,
            r.letter_grade,
            r.grade_point,
            r.published_at
         FROM results r
         INNER JOIN course_registrations cr ON cr.id = r.registration_id
         INNER JOIN course_offerings co ON co.id = cr.offering_id
         INNER JOIN courses c ON c.id = co.course_id
         LEFT JOIN semesters sem ON sem.id = co.semester_id
         WHERE cr.student_id = ?
           AND r.status = 'published'
         ORDER BY r.published_at DESC, r.id DESC
         LIMIT 5",
        "i",
        [$studentId]
    );
}

/* =========================================================
   INVOICE AND PAYMENT SUMMARY
========================================================= */

$invoiceSummary = [
    'total_invoices' => 0,
    'total_amount' => 0,
    'paid_amount' => 0,
    'due_amount' => 0
];

$recentInvoices = [];
$recentPayments = [];

if ($studentId > 0) {

    $invoiceSummaryData = dashboard_one(
        "SELECT
            COUNT(*) AS total_invoices,
            COALESCE(SUM(si.total_amount), 0) AS total_amount,
            COALESCE(SUM(
                (
                    SELECT COALESCE(SUM(p.amount), 0)
                    FROM payments p
                    WHERE p.invoice_id = si.id
                      AND p.status = 'verified'
                )
            ), 0) AS paid_amount
         FROM student_invoices si
         WHERE si.student_id = ?",
        "i",
        [$studentId]
    );

    if ($invoiceSummaryData) {
        $invoiceSummary['total_invoices'] = (int)$invoiceSummaryData['total_invoices'];
        $invoiceSummary['total_amount'] = (float)$invoiceSummaryData['total_amount'];
        $invoiceSummary['paid_amount'] = (float)$invoiceSummaryData['paid_amount'];
        $invoiceSummary['due_amount'] = max(
            0,
            $invoiceSummary['total_amount'] - $invoiceSummary['paid_amount']
        );
    }

    $recentInvoices = dashboard_query(
        "SELECT
            si.id,
            si.invoice_number,
            si.total_amount,
            si.due_date,
            si.status,
            sem.semester_name
         FROM student_invoices si
         LEFT JOIN semesters sem ON sem.id = si.semester_id
         WHERE si.student_id = ?
         ORDER BY si.id DESC
         LIMIT 5",
        "i",
        [$studentId]
    );

    $recentPayments = dashboard_query(
        "SELECT
            p.amount,
            p.status,
            p.payment_method,
            p.transaction_reference,
            p.paid_at,
            si.invoice_number
         FROM payments p
         INNER JOIN student_invoices si ON si.id = p.invoice_id
         WHERE si.student_id = ?
         ORDER BY p.id DESC
         LIMIT 5",
        "i",
        [$studentId]
    );
}

/* =========================================================
   NOTIFICATIONS
========================================================= */

$notifications = [];
$unreadNotifications = 0;

if ($userId > 0) {
    $notifications = dashboard_query(
        "SELECT id, title, message, read_at, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 5",
        "i",
        [$userId]
    );

    $unreadData = dashboard_one(
        "SELECT COUNT(*) AS unread_count
         FROM notifications
         WHERE user_id = ?
           AND read_at IS NULL",
        "i",
        [$userId]
    );

    $unreadNotifications = (int)($unreadData['unread_count'] ?? 0);
}

/* =========================================================
   NOTICES
========================================================= */

$notices = dashboard_query(
    "SELECT id, title, body, published_at, created_at
     FROM notices
     WHERE is_published = 1
       AND (audience = 'all' OR audience = 'student')
     ORDER BY published_at DESC, created_at DESC
     LIMIT 5"
);

/* =========================================================
   DISPLAY VALUES
========================================================= */

$displayStudentId = $student['student_id'] ?? 'Not available';
$displayBatch = $student['batch_name'] ?? 'Not available';
$displayAdmissionSemester = $student['admission_semester'] ?? 'Not available';
$displayEmail = $student['email'] ?? ($_SESSION['email'] ?? 'Not available');

$displaySemester = $currentSemester['semester_name'] ?? 'No active semester';

$displayCgpa = $cgpa !== null ? number_format($cgpa, 2) : '—';
$displaySgpa = $sgpa !== null ? number_format($sgpa, 2) : '—';

$paidPercentage = 0;

if ($invoiceSummary['total_amount'] > 0) {
    $paidPercentage = min(
        100,
        ($invoiceSummary['paid_amount'] / $invoiceSummary['total_amount']) * 100
    );
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= dashboard_escape($pageTitle) ?></title>

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
        --light-blue: #edf4fb;
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

    a {
        text-decoration: none;
    }

    .student-dashboard {
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

    .brand-area h2 {
        margin: 0;
        color: var(--navy);
        font-size: 20px;
    }

    .brand-area p {
        margin: 5px 0 0;
        color: var(--muted);
        font-size: 13px;
    }

    .topbar-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .top-link {
        padding: 9px 14px;
        border-radius: 7px;
        color: var(--navy);
        background: var(--light-blue);
        font-size: 13px;
        font-weight: 600;
    }

    .logout-link {
        padding: 9px 15px;
        border-radius: 7px;
        color: var(--white);
        background: var(--navy);
        font-size: 13px;
        font-weight: 600;
    }

    .welcome-panel {
        background: linear-gradient(115deg, var(--navy), var(--navy-light));
        color: var(--white);
        padding: 28px 30px;
        border-radius: 13px;
        margin-bottom: 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
    }

    .welcome-panel h1 {
        font-size: 25px;
        margin: 0 0 8px;
    }

    .welcome-panel p {
        margin: 5px 0;
        color: #e2eaf3;
        font-size: 14px;
    }

    .welcome-id {
        text-align: right;
        min-width: 160px;
    }

    .welcome-id span {
        display: block;
        color: #d4e0ed;
        font-size: 12px;
        margin-bottom: 5px;
    }

    .welcome-id strong {
        font-size: 17px;
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 17px;
        margin-bottom: 22px;
    }

    .stat-card {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 20px;
        min-height: 115px;
    }

    .stat-card .stat-label {
        font-size: 13px;
        color: var(--muted);
        margin-bottom: 12px;
    }

    .stat-card .stat-value {
        font-size: 25px;
        font-weight: 700;
        color: var(--navy);
    }

    .stat-card .stat-note {
        font-size: 12px;
        color: var(--muted);
        margin-top: 7px;
    }

    .dashboard-columns {
        display: grid;
        grid-template-columns: minmax(0, 1.65fr) minmax(300px, 1fr);
        gap: 20px;
        align-items: start;
    }

    .dashboard-section {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 21px;
        margin-bottom: 20px;
        min-width: 0;
    }

    .section-heading {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 17px;
    }

    .section-heading h2 {
        color: var(--navy);
        font-size: 17px;
        margin: 0;
    }

    .section-heading a {
        font-size: 12px;
        font-weight: 600;
        color: var(--navy-light);
    }

    .profile-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 17px;
    }

    .profile-item {
        border-bottom: 1px solid #edf0f3;
        padding-bottom: 10px;
        min-width: 0;
    }

    .profile-item span {
        display: block;
        color: var(--muted);
        font-size: 12px;
        margin-bottom: 6px;
    }

    .profile-item strong {
        color: var(--text);
        font-size: 13px;
        overflow-wrap: anywhere;
    }

    .table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px;
    }

    th {
        background: #f4f7fa;
        color: var(--navy);
        text-align: left;
        font-size: 12px;
        padding: 12px 10px;
        white-space: nowrap;
    }

    td {
        border-bottom: 1px solid #edf0f3;
        padding: 12px 10px;
        font-size: 12px;
        color: var(--text);
        vertical-align: middle;
    }

    tbody tr:last-child td {
        border-bottom: none;
    }

    .status {
        display: inline-block;
        border-radius: 20px;
        padding: 5px 9px;
        font-size: 11px;
        font-weight: 600;
        background: #edf4fb;
        color: var(--navy);
        white-space: nowrap;
    }

    .status.paid,
    .status.published,
    .status.verified {
        background: #e8f5ed;
        color: var(--green);
    }

    .status.pending,
    .status.draft {
        background: #fff4df;
        color: var(--orange);
    }

    .status.failed,
    .status.unpaid {
        background: #fdebec;
        color: var(--red);
    }

    .empty-state {
        padding: 24px 15px;
        text-align: center;
        background: #f8fafc;
        border: 1px dashed #d8e0e8;
        border-radius: 8px;
        color: var(--muted);
        font-size: 13px;
    }

    .empty-state strong {
        display: block;
        color: var(--navy);
        margin-bottom: 6px;
        font-size: 14px;
    }

    .summary-list {
        display: flex;
        flex-direction: column;
        gap: 13px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        font-size: 13px;
    }

    .summary-row span {
        color: var(--muted);
    }

    .summary-row strong {
        color: var(--navy);
    }

    .progress-track {
        height: 9px;
        background: #edf0f3;
        border-radius: 20px;
        overflow: hidden;
        margin-top: 14px;
    }

    .progress-fill {
        height: 100%;
        background: var(--green);
        border-radius: 20px;
    }

    .quick-links {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .quick-link {
        display: block;
        padding: 13px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        color: var(--navy);
        background: #fbfcfd;
        font-size: 12px;
        font-weight: 600;
        transition: 0.2s;
    }

    .quick-link:hover {
        border-color: var(--navy-light);
        background: var(--light-blue);
    }

    .notice-item,
    .notification-item {
        padding: 12px 0;
        border-bottom: 1px solid #edf0f3;
    }

    .notice-item:last-child,
    .notification-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .notice-item h3,
    .notification-item h3 {
        font-size: 13px;
        color: var(--navy);
        margin: 0 0 6px;
    }

    .notice-item p,
    .notification-item p {
        color: var(--muted);
        font-size: 12px;
        line-height: 1.6;
        margin: 0 0 6px;
        overflow-wrap: anywhere;
    }

    .notice-item small,
    .notification-item small {
        color: #8995a3;
        font-size: 11px;
    }

    .notification-item.unread {
        border-left: 3px solid var(--navy-light);
        padding-left: 10px;
    }

    .footer {
        text-align: center;
        padding: 18px 10px;
        color: var(--muted);
        font-size: 12px;
    }

    @media (max-width: 1000px) {
        .dashboard-columns {
            grid-template-columns: 1fr;
        }

        .stat-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 600px) {
        .student-dashboard {
            width: calc(100% - 22px);
            margin: 12px auto;
        }

        .topbar {
            padding: 14px;
            align-items: flex-start;
            flex-direction: column;
        }

        .topbar-actions {
            width: 100%;
        }

        .top-link,
        .logout-link {
            text-align: center;
            flex: 1;
        }

        .welcome-panel {
            padding: 22px 18px;
            flex-direction: column;
            align-items: flex-start;
        }

        .welcome-panel h1 {
            font-size: 21px;
        }

        .welcome-id {
            text-align: left;
        }

        .stat-grid {
            gap: 10px;
        }

        .stat-card {
            padding: 15px;
            min-height: 100px;
        }

        .stat-card .stat-value {
            font-size: 21px;
        }

        .dashboard-section {
            padding: 16px;
        }

        .profile-grid {
            grid-template-columns: 1fr;
        }

        .quick-links {
            grid-template-columns: 1fr 1fr;
        }
    }
    </style>
</head>

<body>

    <div class="student-dashboard">

        <!-- TOP NAVIGATION -->
        <header class="topbar">

            <div class="brand-area">
                <h2>DU EMBA MIS Student Portal</h2>
                <p>Department of Management Information Systems</p>
            </div>

            <div class="topbar-actions">
                <a class="top-link" href="<?= BASE_URL ?>/student/profile.php">
                    My Profile
                </a>

                <a class="logout-link" href="<?= BASE_URL ?>/auth/logout.php">
                    Logout
                </a>
            </div>

        </header>


        <!-- WELCOME PANEL -->
        <section class="welcome-panel">

            <div>
                <h1>Welcome, <?= dashboard_escape($studentName) ?>!</h1>

                <p>
                    Welcome to your DU EMBA MIS Student Dashboard.
                </p>

                <p>
                    Current Semester:
                    <strong><?= dashboard_escape($displaySemester) ?></strong>
                </p>
            </div>

            <div class="welcome-id">
                <span>Student ID</span>
                <strong><?= dashboard_escape($displayStudentId) ?></strong>
            </div>

        </section>


        <!-- SUMMARY CARDS -->
        <section class="stat-grid">

            <div class="stat-card">
                <div class="stat-label">Registered Courses</div>
                <div class="stat-value"><?= $courseCount ?></div>
                <div class="stat-note">Current semester</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Credit Hours</div>
                <div class="stat-value">
                    <?= number_format($totalCreditHours, 1) ?>
                </div>
                <div class="stat-note">Current semester</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Current Semester SGPA</div>
                <div class="stat-value"><?= dashboard_escape($displaySgpa) ?></div>
                <div class="stat-note">Published results only</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Overall CGPA</div>
                <div class="stat-value"><?= dashboard_escape($displayCgpa) ?></div>
                <div class="stat-note">Published results only</div>
            </div>

        </section>


        <!-- MAIN DASHBOARD COLUMNS -->
        <div class="dashboard-columns">

            <!-- LEFT COLUMN -->
            <div>

                <!-- PROFILE -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h2>Student Information</h2>

                        <a href="<?= BASE_URL ?>/student/profile.php">
                            View Profile
                        </a>
                    </div>

                    <?php if ($student): ?>

                    <div class="profile-grid">

                        <div class="profile-item">
                            <span>Full Name</span>
                            <strong><?= dashboard_escape($student['full_name']) ?></strong>
                        </div>

                        <div class="profile-item">
                            <span>Student ID</span>
                            <strong><?= dashboard_escape($displayStudentId) ?></strong>
                        </div>

                        <div class="profile-item">
                            <span>Username</span>
                            <strong><?= dashboard_escape($student['username']) ?></strong>
                        </div>

                        <div class="profile-item">
                            <span>Email Address</span>
                            <strong><?= dashboard_escape($displayEmail) ?></strong>
                        </div>

                        <div class="profile-item">
                            <span>Batch</span>
                            <strong><?= dashboard_escape($displayBatch) ?></strong>
                        </div>

                        <div class="profile-item">
                            <span>Admission Semester</span>
                            <strong><?= dashboard_escape($displayAdmissionSemester) ?></strong>
                        </div>

                        <div class="profile-item">
                            <span>Phone</span>
                            <strong><?= dashboard_escape($student['phone'] ?: 'Not available') ?></strong>
                        </div>

                        <div class="profile-item">
                            <span>Admission Date</span>
                            <strong><?= dashboard_escape(dashboard_date($student['admission_date'] ?? null)) ?></strong>
                        </div>

                    </div>

                    <?php else: ?>

                    <div class="empty-state">
                        <strong>Student profile is not linked yet.</strong>
                        Your student information will appear here after the
                        Academic Office creates and links your student profile.
                    </div>

                    <?php endif; ?>

                </section>


                <!-- REGISTERED COURSES -->
                <section class="dashboard-section" id="my-courses">

                    <div class="section-heading">
                        <h2>My Registered Courses</h2>

                        <a href="<?= BASE_URL ?>/student/course-registration.php">
                            Course Registration
                        </a>
                    </div>

                    <?php if (!empty($registeredCourses)): ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Title</th>
                                    <th>Credit</th>
                                    <th>Teacher</th>
                                    <th>Class Schedule</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($registeredCourses as $course): ?>

                                <?php
                                $schedule = 'Not scheduled';

                                if (!empty($course['class_day'])) {
                                    $schedule = $course['class_day'];

                                    $startTime = dashboard_time($course['start_time'] ?? '');
                                    $endTime = dashboard_time($course['end_time'] ?? '');

                                    if ($startTime !== '' && $endTime !== '') {
                                        $schedule .= ' (' . $startTime . ' - ' . $endTime . ')';
                                    }

                                    if (!empty($course['room'])) {
                                        $schedule .= ' | ' . $course['room'];
                                    }
                                }
                                ?>

                                <tr>
                                    <td>
                                        <strong><?= dashboard_escape($course['course_code']) ?></strong>
                                    </td>

                                    <td><?= dashboard_escape($course['course_title']) ?></td>

                                    <td><?= number_format((float)$course['credit_hours'], 1) ?></td>

                                    <td>
                                        <?= dashboard_escape($course['teacher_name'] ?: 'Not assigned') ?>
                                    </td>

                                    <td><?= dashboard_escape($schedule) ?></td>
                                </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                    <?php else: ?>

                    <div class="empty-state">
                        <strong>No registered courses found.</strong>
                        Your registered courses and class schedules will
                        appear here after course registration.
                    </div>

                    <?php endif; ?>

                </section>


                <!-- ACADEMIC RESULTS -->
                <section class="dashboard-section" id="academic-results">

                    <div class="section-heading">
                        <h2>Recent Academic Results</h2>
                    </div>

                    <?php if (!empty($recentResults)): ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Semester</th>
                                    <th>Total Marks</th>
                                    <th>Grade</th>
                                    <th>Grade Point</th>
                                    <th>Published</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($recentResults as $result): ?>

                                <tr>
                                    <td>
                                        <strong><?= dashboard_escape($result['course_code']) ?></strong>
                                        <br>
                                        <?= dashboard_escape($result['course_title']) ?>
                                    </td>

                                    <td><?= dashboard_escape($result['semester_name'] ?: 'Not available') ?></td>

                                    <td><?= number_format((float)$result['total_marks'], 2) ?></td>

                                    <td>
                                        <span class="status published">
                                            <?= dashboard_escape($result['letter_grade']) ?>
                                        </span>
                                    </td>

                                    <td><?= number_format((float)$result['grade_point'], 2) ?></td>

                                    <td><?= dashboard_escape(dashboard_date($result['published_at'])) ?></td>
                                </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                    <?php else: ?>

                    <div class="empty-state">
                        <strong>No published results available.</strong>
                        Results will appear here after the Academic Office
                        publishes them.
                    </div>

                    <?php endif; ?>

                </section>


                <!-- INVOICES -->
                <section class="dashboard-section" id="payments">

                    <div class="section-heading">
                        <h2>Recent Invoices</h2>
                    </div>

                    <?php if (!empty($recentInvoices)): ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice No.</th>
                                    <th>Semester</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($recentInvoices as $invoice): ?>

                                <tr>
                                    <td><?= dashboard_escape($invoice['invoice_number']) ?></td>

                                    <td><?= dashboard_escape($invoice['semester_name'] ?: 'Not available') ?></td>

                                    <td><?= dashboard_money($invoice['total_amount']) ?></td>

                                    <td><?= dashboard_escape(dashboard_date($invoice['due_date'])) ?></td>

                                    <td>
                                        <span
                                            class="status <?= dashboard_escape(strtolower($invoice['status'] ?? 'pending')) ?>">
                                            <?= dashboard_escape(ucfirst($invoice['status'] ?? 'Pending')) ?>
                                        </span>
                                    </td>
                                </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                    <?php else: ?>

                    <div class="empty-state">
                        <strong>No invoices available.</strong>
                        Your tuition and other payment invoices will appear here.
                    </div>

                    <?php endif; ?>

                </section>


                <!-- PAYMENT HISTORY -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h2>Recent Payment History</h2>
                    </div>

                    <?php if (!empty($recentPayments)): ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Transaction Reference</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($recentPayments as $payment): ?>

                                <tr>
                                    <td><?= dashboard_escape($payment['invoice_number']) ?></td>

                                    <td><?= dashboard_money($payment['amount']) ?></td>

                                    <td><?= dashboard_escape($payment['payment_method'] ?: 'Not available') ?></td>

                                    <td><?= dashboard_escape($payment['transaction_reference'] ?: 'Not available') ?>
                                    </td>

                                    <td><?= dashboard_escape(dashboard_date($payment['paid_at'])) ?></td>

                                    <td>
                                        <span
                                            class="status <?= dashboard_escape(strtolower($payment['status'] ?? 'pending')) ?>">
                                            <?= dashboard_escape(ucfirst($payment['status'] ?? 'Pending')) ?>
                                        </span>
                                    </td>
                                </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                    <?php else: ?>

                    <div class="empty-state">
                        <strong>No payment records available.</strong>
                        Your payment history will appear here after a payment
                        is recorded.
                    </div>

                    <?php endif; ?>

                </section>

            </div>


            <!-- RIGHT COLUMN -->
            <div>

                <!-- ACADEMIC OVERVIEW -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h2>Academic Overview</h2>
                    </div>

                    <div class="summary-list">

                        <div class="summary-row">
                            <span>Current Semester</span>
                            <strong><?= dashboard_escape($displaySemester) ?></strong>
                        </div>

                        <div class="summary-row">
                            <span>Registered Courses</span>
                            <strong><?= $courseCount ?></strong>
                        </div>

                        <div class="summary-row">
                            <span>Total Credit Hours</span>
                            <strong><?= number_format($totalCreditHours, 1) ?></strong>
                        </div>

                        <div class="summary-row">
                            <span>Current SGPA</span>
                            <strong><?= dashboard_escape($displaySgpa) ?></strong>
                        </div>

                        <div class="summary-row">
                            <span>Overall CGPA</span>
                            <strong><?= dashboard_escape($displayCgpa) ?></strong>
                        </div>

                    </div>

                </section>


                <!-- PAYMENT SUMMARY -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h2>Payment Summary</h2>
                    </div>

                    <div class="summary-list">

                        <div class="summary-row">
                            <span>Total Invoices</span>
                            <strong><?= (int)$invoiceSummary['total_invoices'] ?></strong>
                        </div>

                        <div class="summary-row">
                            <span>Total Amount</span>
                            <strong><?= dashboard_money($invoiceSummary['total_amount']) ?></strong>
                        </div>

                        <div class="summary-row">
                            <span>Verified Payments</span>
                            <strong><?= dashboard_money($invoiceSummary['paid_amount']) ?></strong>
                        </div>

                        <div class="summary-row">
                            <span>Outstanding Due</span>
                            <strong style="color:var(--red);">
                                <?= dashboard_money($invoiceSummary['due_amount']) ?>
                            </strong>
                        </div>

                    </div>

                    <div class="progress-track">
                        <div class="progress-fill" style="width: <?= number_format($paidPercentage, 2, '.', '') ?>%;">
                        </div>
                    </div>

                    <p style="font-size:11px;color:var(--muted);margin:8px 0 0;">
                        Payment progress based on verified payments.
                    </p>

                </section>


                <!-- QUICK LINKS -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h2>Quick Access</h2>
                    </div>

                    <div class="quick-links">

                        <a class="quick-link" href="<?= BASE_URL ?>/student/profile.php">
                            My Profile
                        </a>

                        <a class="quick-link" href="<?= BASE_URL ?>/student/course-registration.php">
                            Course Registration
                        </a>
                        <a class="quick-link" href="<?= BASE_URL ?>/student/registered-courses.php">
                            My Registered Courses
                        </a>
                        <a class="quick-link" href="#my-courses">
                            My Courses
                        </a>

                        <a class="quick-link" href="#academic-results">
                            Academic Results
                        </a>
                        <a class="quick-link" href="<?= BASE_URL ?>/student/results.php">
                            My Results & Transcript
                        </a>
                        <a class="quick-link" href="#payments">
                            Payment History
                        </a>

                        <a class="quick-link" href="<?= BASE_URL ?>/student/payments.php">
                            My Payments & Invoices
                        </a>

                        <a class="quick-link" href="<?= BASE_URL ?>/auth/logout.php">
                            Logout
                        </a>

                    </div>

                </section>


                <!-- NOTIFICATIONS -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h2>Notifications</h2>

                        <span class="status">
                            <?= $unreadNotifications ?> Unread
                        </span>
                    </div>

                    <?php if (!empty($notifications)): ?>

                    <?php foreach ($notifications as $notification): ?>

                    <div class="notification-item <?= empty($notification['read_at']) ? 'unread' : '' ?>">

                        <h3><?= dashboard_escape($notification['title']) ?></h3>

                        <p><?= nl2br(dashboard_escape($notification['message'])) ?></p>

                        <small>
                            <?= dashboard_escape(dashboard_datetime($notification['created_at'])) ?>
                        </small>

                    </div>

                    <?php endforeach; ?>

                    <?php else: ?>

                    <div class="empty-state">
                        <strong>No notifications.</strong>
                        You will see important portal notifications here.
                    </div>

                    <?php endif; ?>

                </section>


                <!-- NOTICES -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h2>Latest Notices</h2>
                    </div>

                    <?php if (!empty($notices)): ?>

                    <?php foreach ($notices as $notice): ?>

                    <div class="notice-item">

                        <h3><?= dashboard_escape($notice['title']) ?></h3>

                        <p>
                            <?= nl2br(dashboard_escape($notice['body'])) ?>
                        </p>

                        <small>
                            <?= dashboard_escape(
                                    dashboard_datetime(
                                        $notice['published_at'] ?: $notice['created_at']
                                    )
                                ) ?>
                        </small>

                    </div>

                    <?php endforeach; ?>

                    <?php else: ?>

                    <div class="empty-state">
                        <strong>No notices available.</strong>
                        Published department notices will appear here.
                    </div>

                    <?php endif; ?>

                </section>

            </div>

        </div>


        <!-- FOOTER -->
        <footer class="footer">
            DU EMBA MIS Student Portal
            <br>
            Department of Management Information Systems, University of Dhaka
        </footer>

    </div>

</body>

</html>