<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

$studentName = $_SESSION['full_name'] ?? 'Student';
$username = $_SESSION['username'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function dashboard_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dashboard_query(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Dashboard SQL prepare error: ' . $conn->error);
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log('Dashboard SQL execute error: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $stmt->close();

    return $rows;
}

function dashboard_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = dashboard_query($conn, $sql, $types, $params);

    return $rows[0] ?? null;
}

function dashboard_money($amount): string
{
    return '৳' . number_format((float)$amount, 2);
}

function dashboard_date($date): string
{
    if (!$date) {
        return 'Not available';
    }

    $timestamp = strtotime((string)$date);

    return $timestamp ? date('d M Y', $timestamp) : 'Not available';
}

function dashboard_datetime($date): string
{
    if (!$date) {
        return 'Not available';
    }

    $timestamp = strtotime((string)$date);

    return $timestamp ? date('d M Y, h:i A', $timestamp) : 'Not available';
}

/*
|--------------------------------------------------------------------------
| Resolve logged-in user
|--------------------------------------------------------------------------
*/

if ($userId <= 0 && $username !== '') {
    $user = dashboard_one(
        $conn,
        "SELECT id FROM users WHERE username = ? LIMIT 1",
        "s",
        [$username]
    );

    $userId = (int)($user['id'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Student profile
|--------------------------------------------------------------------------
*/

$profile = null;

if ($userId > 0) {
    $profile = dashboard_one(
        $conn,
        "SELECT
            st.id AS student_table_id,
            st.student_id,
            st.phone,
            st.address,
            st.admission_date,
            b.batch_name,
            s.semester_name AS admission_semester,
            u.email,
            u.username,
            u.full_name
         FROM students st
         INNER JOIN users u ON u.id = st.user_id
         LEFT JOIN batches b ON b.id = st.batch_id
         LEFT JOIN semesters s ON s.id = st.admission_semester_id
         WHERE st.user_id = ?
         LIMIT 1",
        "i",
        [$userId]
    );
}

$studentTableId = (int)($profile['student_table_id'] ?? 0);

if (!empty($profile['full_name'])) {
    $studentName = $profile['full_name'];
}

/*
|--------------------------------------------------------------------------
| Current semester
|--------------------------------------------------------------------------
*/

$currentSemester = dashboard_one(
    $conn,
    "SELECT
        id,
        semester_name,
        start_date,
        end_date,
        registration_open
     FROM semesters
     WHERE status = 'active'
     ORDER BY id DESC
     LIMIT 1"
);

$currentSemesterId = (int)($currentSemester['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Registered courses and schedules
|--------------------------------------------------------------------------
*/

$registeredCourses = [];
$totalCredits = 0;
$courseCount = 0;

if ($studentTableId > 0 && $currentSemesterId > 0) {
    $registeredCourses = dashboard_query(
        $conn,
        "SELECT
            cr.id AS registration_id,
            cr.status AS registration_status,
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
         ORDER BY
            FIELD(cs.class_day, 'Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday'),
            cs.start_time",
        "ii",
        [$studentTableId, $currentSemesterId]
    );

    $courseCount = count($registeredCourses);

    foreach ($registeredCourses as $course) {
        $totalCredits += (float)$course['credit_hours'];
    }
}

/*
|--------------------------------------------------------------------------
| Academic results: SGPA and CGPA
|--------------------------------------------------------------------------
*/

$currentSgpa = null;
$cgpa = null;
$publishedResultCount = 0;

if ($studentTableId > 0) {
    $resultSummary = dashboard_one(
        $conn,
        "SELECT
            SUM(
                CASE
                    WHEN r.status IN ('approved', 'published')
                    THEN r.grade_point * c.credit_hours
                    ELSE 0
                END
            ) AS total_points,

            SUM(
                CASE
                    WHEN r.status IN ('approved', 'published')
                    THEN c.credit_hours
                    ELSE 0
                END
            ) AS total_credits,

            COUNT(
                CASE
                    WHEN r.status = 'published' THEN 1
                END
            ) AS published_count

         FROM course_registrations cr
         INNER JOIN course_offerings co ON co.id = cr.offering_id
         INNER JOIN courses c ON c.id = co.course_id
         INNER JOIN results r ON r.registration_id = cr.id
         WHERE cr.student_id = ?",
        "i",
        [$studentTableId]
    );

    if ($resultSummary && (float)$resultSummary['total_credits'] > 0) {
        $cgpa = (float)$resultSummary['total_points']
            / (float)$resultSummary['total_credits'];
    }

    $publishedResultCount = (int)($resultSummary['published_count'] ?? 0);

    if ($currentSemesterId > 0) {
        $semesterResult = dashboard_one(
            $conn,
            "SELECT
                SUM(r.grade_point * c.credit_hours) AS total_points,
                SUM(c.credit_hours) AS total_credits
             FROM course_registrations cr
             INNER JOIN course_offerings co ON co.id = cr.offering_id
             INNER JOIN courses c ON c.id = co.course_id
             INNER JOIN results r ON r.registration_id = cr.id
             WHERE cr.student_id = ?
               AND co.semester_id = ?
               AND r.status IN ('approved', 'published')",
            "ii",
            [$studentTableId, $currentSemesterId]
        );

        if ($semesterResult && (float)$semesterResult['total_credits'] > 0) {
            $currentSgpa = (float)$semesterResult['total_points']
                / (float)$semesterResult['total_credits'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Recent academic results
|--------------------------------------------------------------------------
*/

$recentResults = [];

if ($studentTableId > 0) {
    $recentResults = dashboard_query(
        $conn,
        "SELECT
            c.course_code,
            c.course_title,
            c.credit_hours,
            s.semester_name,
            r.total_marks,
            r.letter_grade,
            r.grade_point,
            r.status,
            r.published_at
         FROM results r
         INNER JOIN course_registrations cr ON cr.id = r.registration_id
         INNER JOIN course_offerings co ON co.id = cr.offering_id
         INNER JOIN courses c ON c.id = co.course_id
         LEFT JOIN semesters s ON s.id = co.semester_id
         WHERE cr.student_id = ?
           AND r.status IN ('approved', 'published')
         ORDER BY
            COALESCE(r.published_at, cr.registered_at) DESC,
            r.id DESC
         LIMIT 10",
        "i",
        [$studentTableId]
    );
}

/*
|--------------------------------------------------------------------------
| Payment and invoice summary
|--------------------------------------------------------------------------
*/

$paymentSummary = [
    'total_amount' => 0,
    'paid_amount' => 0,
    'due_amount' => 0
];

$recentInvoices = [];
$recentPayments = [];

if ($studentTableId > 0) {
    $paymentSummary = dashboard_one(
        $conn,
        "SELECT
            COALESCE(SUM(si.total_amount), 0) AS total_amount,

            COALESCE(SUM(
                (
                    SELECT COALESCE(SUM(p.amount), 0)
                    FROM payments p
                    WHERE p.invoice_id = si.id
                      AND p.status = 'verified'
                )
            ), 0) AS paid_amount,

            COALESCE(SUM(
                GREATEST(
                    0,
                    si.total_amount - (
                        SELECT COALESCE(SUM(p.amount), 0)
                        FROM payments p
                        WHERE p.invoice_id = si.id
                          AND p.status = 'verified'
                    )
                )
            ), 0) AS due_amount

         FROM student_invoices si
         WHERE si.student_id = ?",
        "i",
        [$studentTableId]
    ) ?? $paymentSummary;

    /*
    | Recent invoices
    */

    $recentInvoices = dashboard_query(
        $conn,
        "SELECT
            si.invoice_number,
            si.total_amount,
            si.due_date,
            si.status,
            s.semester_name
         FROM student_invoices si
         LEFT JOIN semesters s ON s.id = si.semester_id
         WHERE si.student_id = ?
         ORDER BY si.id DESC
         LIMIT 5",
        "i",
        [$studentTableId]
    );

    /*
    | Payment history
    */

    $recentPayments = dashboard_query(
        $conn,
        "SELECT
            p.amount,
            p.payment_method,
            p.transaction_reference,
            p.status,
            p.paid_at,
            si.invoice_number
         FROM payments p
         INNER JOIN student_invoices si ON si.id = p.invoice_id
         WHERE si.student_id = ?
         ORDER BY p.id DESC
         LIMIT 10",
        "i",
        [$studentTableId]
    );
}

/*
|--------------------------------------------------------------------------
| Notices
|--------------------------------------------------------------------------
*/

$notices = dashboard_query(
    $conn,
    "SELECT
        title,
        body,
        published_at,
        created_at
     FROM notices
     WHERE is_published = 1
       AND audience IN ('all', 'student')
     ORDER BY COALESCE(published_at, created_at) DESC
     LIMIT 5"
);

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

$notifications = [];

if ($userId > 0) {
    $notifications = dashboard_query(
        $conn,
        "SELECT
            title,
            message,
            read_at,
            created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 5",
        "i",
        [$userId]
    );
}

/*
|--------------------------------------------------------------------------
| Total unread notification count
|--------------------------------------------------------------------------
*/

$unreadCount = 0;

if ($userId > 0) {
    $unreadRow = dashboard_one(
        $conn,
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE user_id = ?
           AND read_at IS NULL",
        "i",
        [$userId]
    );

    $unreadCount = (int)($unreadRow['total'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Links
|--------------------------------------------------------------------------
*/

$profileLink = BASE_URL . '/student/profile.php';
$logoutLink = BASE_URL . '/auth/logout.php';

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Student Dashboard | DU EMBA MIS Portal</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    :root {
        --sd-navy: #102d50;
        --sd-navy-light: #1c456e;
        --sd-beige: #f4efe5;
        --sd-bg: #f5f7fb;
        --sd-text: #263548;
        --sd-muted: #687789;
        --sd-border: #e5e9f0;
        --sd-white: #ffffff;
        --sd-green: #16845b;
        --sd-orange: #c77a19;
        --sd-red: #c34d4d;
        --sd-shadow: 0 8px 24px rgba(16, 45, 80, .06);
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background: var(--sd-bg);
        color: var(--sd-text);
        font-family: Arial, Helvetica, sans-serif;
    }

    a {
        text-decoration: none;
        color: inherit;
    }

    .sd-shell {
        width: min(1440px, calc(100% - 48px));
        margin: 0 auto;
        padding: 26px 0 45px;
    }

    .sd-topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        background: var(--sd-white);
        padding: 17px 22px;
        border: 1px solid var(--sd-border);
        border-radius: 15px;
        box-shadow: var(--sd-shadow);
        margin-bottom: 24px;
    }

    .sd-brand {
        display: flex;
        align-items: center;
        gap: 13px;
    }

    .sd-logo {
        width: 46px;
        height: 46px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        color: white;
        background: var(--sd-navy);
        font-size: 16px;
        font-weight: 800;
    }

    .sd-brand h2 {
        margin: 0;
        color: var(--sd-navy);
        font-size: 17px;
    }

    .sd-brand p {
        margin: 4px 0 0;
        color: var(--sd-muted);
        font-size: 12px;
    }

    .sd-top-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .sd-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 10px 15px;
        border-radius: 9px;
        font-size: 13px;
        font-weight: 700;
        border: 1px solid var(--sd-border);
        background: white;
        color: var(--sd-navy);
    }

    .sd-btn.primary {
        background: var(--sd-navy);
        color: white;
        border-color: var(--sd-navy);
    }

    .sd-btn:hover {
        opacity: .88;
    }

    .sd-welcome {
        background: linear-gradient(120deg, #102d50, #1d4b76);
        color: white;
        padding: 30px 32px;
        border-radius: 17px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 25px;
        margin-bottom: 22px;
        box-shadow: 0 12px 28px rgba(16, 45, 80, .14);
    }

    .sd-welcome small {
        color: #d7e2ef;
        font-size: 12px;
        letter-spacing: 1.3px;
        text-transform: uppercase;
    }

    .sd-welcome h1 {
        margin: 9px 0 7px;
        font-size: clamp(23px, 3vw, 31px);
    }

    .sd-welcome p {
        margin: 0;
        color: #d7e2ef;
        font-size: 14px;
    }

    .sd-student-id {
        background: rgba(255, 255, 255, .12);
        border: 1px solid rgba(255, 255, 255, .2);
        padding: 15px 19px;
        border-radius: 12px;
        min-width: 170px;
    }

    .sd-student-id span {
        display: block;
        color: #d7e2ef;
        font-size: 11px;
        margin-bottom: 6px;
    }

    .sd-student-id strong {
        font-size: 18px;
    }

    .sd-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 17px;
        margin-bottom: 22px;
    }

    .sd-stat {
        background: white;
        border: 1px solid var(--sd-border);
        border-radius: 14px;
        padding: 19px;
        box-shadow: var(--sd-shadow);
        min-width: 0;
    }

    .sd-stat-label {
        color: var(--sd-muted);
        font-size: 12px;
        margin-bottom: 10px;
    }

    .sd-stat-value {
        color: var(--sd-navy);
        font-size: 23px;
        font-weight: 800;
        overflow-wrap: anywhere;
    }

    .sd-stat-note {
        color: var(--sd-muted);
        font-size: 11px;
        margin-top: 7px;
    }

    .sd-main-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.65fr) minmax(280px, .85fr);
        gap: 20px;
        align-items: start;
    }

    .sd-panel {
        background: white;
        border: 1px solid var(--sd-border);
        border-radius: 15px;
        padding: 21px;
        margin-bottom: 20px;
        box-shadow: var(--sd-shadow);
        min-width: 0;
        scroll-margin-top: 20px;
    }

    .sd-panel-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 17px;
    }

    .sd-panel-head h3 {
        color: var(--sd-navy);
        font-size: 16px;
        margin: 0;
    }

    .sd-panel-head span {
        color: var(--sd-muted);
        font-size: 11px;
    }

    .sd-profile-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .sd-info {
        border-bottom: 1px solid var(--sd-border);
        padding-bottom: 12px;
        min-width: 0;
    }

    .sd-info label {
        display: block;
        color: var(--sd-muted);
        font-size: 11px;
        margin-bottom: 6px;
    }

    .sd-info strong {
        display: block;
        color: var(--sd-text);
        font-size: 13px;
        overflow-wrap: anywhere;
    }

    .sd-table-wrap {
        overflow-x: auto;
    }

    .sd-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 650px;
    }

    .sd-table th {
        text-align: left;
        color: var(--sd-muted);
        background: #f7f9fc;
        padding: 12px 10px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .sd-table td {
        padding: 13px 10px;
        border-bottom: 1px solid var(--sd-border);
        font-size: 12px;
        vertical-align: top;
    }

    .sd-table td strong {
        display: block;
        color: var(--sd-navy);
        margin-bottom: 4px;
    }

    .sd-muted {
        color: var(--sd-muted);
        font-size: 11px;
    }

    .sd-pill {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 20px;
        background: #e9f5ef;
        color: var(--sd-green);
        font-size: 10px;
        font-weight: 700;
        text-transform: capitalize;
        white-space: nowrap;
    }

    .sd-pill.pending {
        background: #fff3df;
        color: var(--sd-orange);
    }

    .sd-pill.overdue {
        background: #fdecec;
        color: var(--sd-red);
    }

    .sd-quick-links {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .sd-quick-link {
        display: block;
        border: 1px solid var(--sd-border);
        border-radius: 11px;
        padding: 14px;
        transition: .2s;
    }

    .sd-quick-link:hover {
        border-color: var(--sd-navy);
        background: #f7f9fc;
    }

    .sd-quick-link strong {
        display: block;
        color: var(--sd-navy);
        font-size: 12px;
        margin-bottom: 5px;
    }

    .sd-quick-link span {
        color: var(--sd-muted);
        font-size: 10px;
    }

    .sd-list-item {
        padding: 13px 0;
        border-bottom: 1px solid var(--sd-border);
    }

    .sd-list-item:last-child {
        border-bottom: 0;
    }

    .sd-list-item strong {
        color: var(--sd-navy);
        display: block;
        font-size: 13px;
        margin-bottom: 5px;
    }

    .sd-list-item p {
        margin: 0 0 6px;
        color: var(--sd-muted);
        font-size: 12px;
        line-height: 1.6;
        overflow-wrap: anywhere;
    }

    .sd-list-item small {
        color: #8b97a6;
        font-size: 10px;
    }

    .sd-empty {
        padding: 18px;
        background: #f8f9fb;
        color: var(--sd-muted);
        border-radius: 10px;
        text-align: center;
        font-size: 12px;
    }

    .sd-semester-card {
        background: var(--sd-beige);
        border-radius: 12px;
        padding: 17px;
    }

    .sd-semester-card small {
        color: var(--sd-muted);
        font-size: 11px;
    }

    .sd-semester-card h4 {
        color: var(--sd-navy);
        font-size: 18px;
        margin: 8px 0;
    }

    .sd-semester-card p {
        margin: 0;
        color: var(--sd-muted);
        font-size: 11px;
    }

    .sd-payment-line {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 11px 0;
        border-bottom: 1px solid var(--sd-border);
        font-size: 12px;
    }

    .sd-payment-line span {
        color: var(--sd-muted);
    }

    .sd-payment-line strong {
        color: var(--sd-navy);
    }

    .sd-footer {
        text-align: center;
        color: #8b97a6;
        font-size: 11px;
        padding: 10px 0 0;
    }

    @media (max-width: 1000px) {
        .sd-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .sd-main-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 600px) {
        .sd-shell {
            width: min(100% - 24px, 1440px);
            padding-top: 12px;
        }

        .sd-topbar {
            padding: 13px;
        }

        .sd-brand h2 {
            font-size: 13px;
        }

        .sd-brand p {
            font-size: 10px;
        }

        .sd-top-actions {
            gap: 6px;
        }

        .sd-btn {
            padding: 9px 10px;
            font-size: 11px;
        }

        .sd-welcome {
            padding: 23px 20px;
            align-items: flex-start;
            flex-direction: column;
        }

        .sd-student-id {
            width: 100%;
        }

        .sd-grid {
            gap: 10px;
        }

        .sd-stat {
            padding: 14px;
        }

        .sd-stat-value {
            font-size: 19px;
        }

        .sd-panel {
            padding: 16px;
        }

        .sd-profile-grid {
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .sd-quick-links {
            grid-template-columns: 1fr 1fr;
        }
    }
    </style>
</head>

<body>

    <div class="sd-shell">

        <!-- Header -->

        <header class="sd-topbar">

            <div class="sd-brand">

                <div class="sd-logo">DU</div>

                <div>
                    <h2>DU EMBA MIS Portal</h2>
                    <p>Department of Management Information Systems</p>
                </div>

            </div>

            <div class="sd-top-actions">

                <a class="sd-btn" href="<?= dashboard_escape($profileLink) ?>">
                    My Profile
                </a>

                <a class="sd-btn primary" href="<?= dashboard_escape($logoutLink) ?>">
                    Logout
                </a>

            </div>

        </header>

        <!-- Welcome -->

        <section class="sd-welcome">

            <div>
                <small>Student Dashboard</small>

                <h1>
                    Welcome, <?= dashboard_escape($studentName) ?>!
                </h1>

                <p>
                    Here is your academic overview and student portal information.
                </p>
            </div>

            <div class="sd-student-id">
                <span>Student ID</span>

                <strong>
                    <?= dashboard_escape($profile['student_id'] ?? 'Not available') ?>
                </strong>
            </div>

        </section>

        <!-- Summary cards -->

        <section class="sd-grid">

            <div class="sd-stat">

                <div class="sd-stat-label">Current Semester</div>

                <div class="sd-stat-value" style="font-size:17px">
                    <?= dashboard_escape($currentSemester['semester_name'] ?? 'Not configured') ?>
                </div>

                <div class="sd-stat-note">
                    Active academic semester
                </div>

            </div>

            <div class="sd-stat">

                <div class="sd-stat-label">Registered Courses</div>

                <div class="sd-stat-value">
                    <?= (int)$courseCount ?>
                </div>

                <div class="sd-stat-note">
                    Courses in current semester
                </div>

            </div>

            <div class="sd-stat">

                <div class="sd-stat-label">Current Semester SGPA</div>

                <div class="sd-stat-value">
                    <?= $currentSgpa !== null ? number_format($currentSgpa, 2) : '—' ?>
                </div>

                <div class="sd-stat-note">
                    Based on published/approved results
                </div>

            </div>

            <div class="sd-stat">

                <div class="sd-stat-label">Overall CGPA</div>

                <div class="sd-stat-value">
                    <?= $cgpa !== null ? number_format($cgpa, 2) : '—' ?>
                </div>

                <div class="sd-stat-note">
                    Based on published/approved results
                </div>

            </div>

        </section>

        <div class="sd-main-grid">

            <!-- Left column -->

            <main>

                <!-- Student profile -->

                <section class="sd-panel">

                    <div class="sd-panel-head">

                        <h3>Student Information</h3>

                        <a class="sd-muted" href="<?= dashboard_escape($profileLink) ?>">
                            View profile →
                        </a>

                    </div>

                    <div class="sd-profile-grid">

                        <div class="sd-info">
                            <label>Full Name</label>
                            <strong><?= dashboard_escape($studentName) ?></strong>
                        </div>

                        <div class="sd-info">
                            <label>Username</label>
                            <strong>
                                <?= dashboard_escape(
                                !empty($profile['username'])
                                    ? $profile['username']
                                    : (!empty($username) ? $username : 'Not available')
                            ) ?>
                            </strong>
                        </div>

                        <div class="sd-info">
                            <label>Email Address</label>
                            <strong><?= dashboard_escape($profile['email'] ?? 'Not available') ?></strong>
                        </div>

                        <div class="sd-info">
                            <label>Student ID</label>
                            <strong><?= dashboard_escape($profile['student_id'] ?? 'Not available') ?></strong>
                        </div>

                        <div class="sd-info">
                            <label>Batch</label>
                            <strong><?= dashboard_escape($profile['batch_name'] ?? 'Not available') ?></strong>
                        </div>

                        <div class="sd-info">
                            <label>Admission Semester</label>
                            <strong><?= dashboard_escape($profile['admission_semester'] ?? 'Not available') ?></strong>
                        </div>

                        <div class="sd-info">
                            <label>Phone</label>
                            <strong><?= dashboard_escape($profile['phone'] ?? 'Not available') ?></strong>
                        </div>

                        <div class="sd-info">
                            <label>Admission Date</label>
                            <strong><?= dashboard_escape(dashboard_date($profile['admission_date'] ?? null)) ?></strong>
                        </div>

                    </div>

                </section>

                <!-- Academic Overview -->

                <section class="sd-panel">

                    <div class="sd-panel-head">

                        <h3>Academic Overview</h3>

                        <span>
                            <?= number_format($totalCredits, 1) ?> current credits
                        </span>

                    </div>

                    <?php if ($currentSemester): ?>

                    <div class="sd-semester-card">

                        <small>ACTIVE SEMESTER</small>

                        <h4>
                            <?= dashboard_escape($currentSemester['semester_name']) ?>
                        </h4>

                        <p>
                            <?= dashboard_escape(dashboard_date($currentSemester['start_date'])) ?>
                            &nbsp;–&nbsp;
                            <?= dashboard_escape(dashboard_date($currentSemester['end_date'])) ?>
                        </p>

                    </div>

                    <?php else: ?>

                    <div class="sd-empty">
                        No active semester is configured at the moment.
                    </div>

                    <?php endif; ?>

                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:15px">

                        <div class="sd-info">
                            <label>Registered Courses</label>
                            <strong><?= (int)$courseCount ?></strong>
                        </div>

                        <div class="sd-info">
                            <label>Credit Hours</label>
                            <strong><?= number_format($totalCredits, 1) ?></strong>
                        </div>

                        <div class="sd-info">
                            <label>Published Results</label>
                            <strong><?= (int)$publishedResultCount ?></strong>
                        </div>

                    </div>

                </section>

                <!-- Registered Courses -->

                <section class="sd-panel" id="courses">

                    <div class="sd-panel-head">

                        <h3>My Registered Courses</h3>

                        <span>Current semester</span>

                    </div>

                    <?php if ($registeredCourses): ?>

                    <div class="sd-table-wrap">

                        <table class="sd-table">

                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Teacher</th>
                                    <th>Credit</th>
                                    <th>Class Schedule</th>
                                    <th>Room</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($registeredCourses as $course): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= dashboard_escape($course['course_code']) ?>
                                        </strong>

                                        <?= dashboard_escape($course['course_title']) ?>
                                    </td>

                                    <td>
                                        <?= dashboard_escape($course['teacher_name'] ?? 'Not assigned') ?>
                                    </td>

                                    <td>
                                        <?= dashboard_escape($course['credit_hours']) ?>
                                    </td>

                                    <td>

                                        <?php if (!empty($course['class_day'])): ?>

                                        <?= dashboard_escape($course['class_day']) ?>

                                        <br>

                                        <span class="sd-muted">

                                            <?= dashboard_escape(
                                                        date('h:i A', strtotime($course['start_time']))
                                                    ) ?>

                                            –

                                            <?= dashboard_escape(
                                                        date('h:i A', strtotime($course['end_time']))
                                                    ) ?>

                                        </span>

                                        <?php else: ?>

                                        Not scheduled

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= dashboard_escape($course['room'] ?? '—') ?>
                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php else: ?>

                    <div class="sd-empty">
                        No registered courses found for the current semester.
                    </div>

                    <?php endif; ?>

                </section>

                <!-- Academic Results -->

                <section class="sd-panel" id="results">

                    <div class="sd-panel-head">

                        <h3>Academic Results</h3>

                        <span>Latest approved/published results</span>

                    </div>

                    <?php if ($recentResults): ?>

                    <div class="sd-table-wrap">

                        <table class="sd-table">

                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Semester</th>
                                    <th>Marks</th>
                                    <th>Grade</th>
                                    <th>Grade Point</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($recentResults as $result): ?>

                                <tr>

                                    <td>

                                        <strong>
                                            <?= dashboard_escape($result['course_code']) ?>
                                        </strong>

                                        <?= dashboard_escape($result['course_title']) ?>

                                    </td>

                                    <td>
                                        <?= dashboard_escape($result['semester_name'] ?? '—') ?>
                                    </td>

                                    <td>
                                        <?= $result['total_marks'] !== null
                                                ? dashboard_escape($result['total_marks'])
                                                : '—' ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= dashboard_escape($result['letter_grade'] ?? '—') ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= $result['grade_point'] !== null
                                                ? number_format((float)$result['grade_point'], 2)
                                                : '—' ?>
                                    </td>

                                    <td>

                                        <span class="sd-pill">
                                            <?= dashboard_escape($result['status']) ?>
                                        </span>

                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php else: ?>

                    <div class="sd-empty">
                        No approved or published academic results available.
                    </div>

                    <?php endif; ?>

                </section>

                <!-- Recent Invoices -->

                <section class="sd-panel" id="payments">

                    <div class="sd-panel-head">

                        <h3>Recent Invoices</h3>

                        <span>Payment records</span>

                    </div>

                    <?php if ($recentInvoices): ?>

                    <div class="sd-table-wrap">

                        <table class="sd-table">

                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Semester</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($recentInvoices as $invoice): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= dashboard_escape($invoice['invoice_number']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= dashboard_escape($invoice['semester_name'] ?? '—') ?>
                                    </td>

                                    <td>
                                        <?= dashboard_escape(dashboard_money($invoice['total_amount'])) ?>
                                    </td>

                                    <td>
                                        <?= dashboard_escape(dashboard_date($invoice['due_date'])) ?>
                                    </td>

                                    <td>

                                        <?php
                                            $invoiceStatus = strtolower((string)$invoice['status']);

                                            $invoiceClass = '';

                                            if ($invoiceStatus === 'overdue') {
                                                $invoiceClass = 'overdue';
                                            } elseif (in_array($invoiceStatus, ['unpaid', 'partial'], true)) {
                                                $invoiceClass = 'pending';
                                            }
                                            ?>

                                        <span class="sd-pill <?= $invoiceClass ?>">
                                            <?= dashboard_escape($invoice['status']) ?>
                                        </span>

                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php else: ?>

                    <div class="sd-empty">
                        No invoice records found.
                    </div>

                    <?php endif; ?>

                </section>

                <!-- Payment History -->

                <section class="sd-panel">

                    <div class="sd-panel-head">

                        <h3>Payment History</h3>

                        <span>Recent transactions</span>

                    </div>

                    <?php if ($recentPayments): ?>

                    <div class="sd-table-wrap">

                        <table class="sd-table">

                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($recentPayments as $payment): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= dashboard_escape($payment['invoice_number']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= dashboard_money($payment['amount']) ?>
                                    </td>

                                    <td>
                                        <?= dashboard_escape($payment['payment_method'] ?? '—') ?>
                                    </td>

                                    <td>
                                        <?= dashboard_escape($payment['transaction_reference'] ?? '—') ?>
                                    </td>

                                    <td>
                                        <?= dashboard_escape(dashboard_date($payment['paid_at'])) ?>
                                    </td>

                                    <td>

                                        <?php
                                            $paymentStatus = strtolower((string)$payment['status']);

                                            $paymentClass = '';

                                            if ($paymentStatus === 'rejected') {
                                                $paymentClass = 'overdue';
                                            } elseif ($paymentStatus === 'pending') {
                                                $paymentClass = 'pending';
                                            }
                                            ?>

                                        <span class="sd-pill <?= $paymentClass ?>">
                                            <?= dashboard_escape($payment['status']) ?>
                                        </span>

                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php else: ?>

                    <div class="sd-empty">
                        No payment transactions found.
                    </div>

                    <?php endif; ?>

                </section>

            </main>

            <!-- Right column -->

            <aside>

                <!-- Payment Summary -->

                <section class="sd-panel">

                    <div class="sd-panel-head">
                        <h3>Payment Summary</h3>
                    </div>

                    <div class="sd-payment-line">

                        <span>Total Invoice Amount</span>

                        <strong>
                            <?= dashboard_money($paymentSummary['total_amount'] ?? 0) ?>
                        </strong>

                    </div>

                    <div class="sd-payment-line">

                        <span>Verified Payments</span>

                        <strong style="color:var(--sd-green)">
                            <?= dashboard_money($paymentSummary['paid_amount'] ?? 0) ?>
                        </strong>

                    </div>

                    <div class="sd-payment-line">

                        <span>Outstanding Due</span>

                        <strong style="color:var(--sd-red)">
                            <?= dashboard_money($paymentSummary['due_amount'] ?? 0) ?>
                        </strong>

                    </div>

                </section>

                <!-- Quick Access -->

                <section class="sd-panel">

                    <div class="sd-panel-head">
                        <h3>Quick Access</h3>
                    </div>

                    <div class="sd-quick-links">

                        <a class="sd-quick-link" href="<?= dashboard_escape($profileLink) ?>">

                            <strong>My Profile</strong>

                            <span>Personal information</span>

                        </a>

                        <a class="sd-quick-link"
                            href="<?= dashboard_escape(BASE_URL . '/student/course-registration.php') ?>">

                            <strong>Course Registration</strong>
                            <span>Browse and register courses</span>

                        </a>

                        <a class="sd-quick-link" href="#courses">

                            <strong>My Courses</strong>

                            <span>Course information</span>

                        </a>

                        <a class="sd-quick-link" href="#results">

                            <strong>Academic Results</strong>

                            <span>SGPA and CGPA</span>

                        </a>

                        <a class="sd-quick-link" href="#payments">

                            <strong>Payments</strong>

                            <span>Invoices and dues</span>

                        </a>

                    </div>

                </section>

                <!-- Notifications -->

                <section class="sd-panel">

                    <div class="sd-panel-head">

                        <h3>Notifications</h3>

                        <span>
                            <?= (int)$unreadCount ?> unread
                        </span>

                    </div>

                    <?php if ($notifications): ?>

                    <?php foreach ($notifications as $notification): ?>

                    <div class="sd-list-item">

                        <strong>
                            <?= dashboard_escape($notification['title']) ?>
                        </strong>

                        <p>
                            <?= dashboard_escape($notification['message']) ?>
                        </p>

                        <small>

                            <?= dashboard_escape(
                                    dashboard_datetime($notification['created_at'])
                                ) ?>

                            <?= empty($notification['read_at']) ? ' · Unread' : '' ?>

                        </small>

                    </div>

                    <?php endforeach; ?>

                    <?php else: ?>

                    <div class="sd-empty">
                        You have no notifications.
                    </div>

                    <?php endif; ?>

                </section>

                <!-- Latest Notices -->

                <section class="sd-panel">

                    <div class="sd-panel-head">

                        <h3>Latest Notices</h3>

                        <span>Department updates</span>

                    </div>

                    <?php if ($notices): ?>

                    <?php foreach ($notices as $notice): ?>

                    <div class="sd-list-item">

                        <strong>
                            <?= dashboard_escape($notice['title']) ?>
                        </strong>

                        <p>
                            <?= dashboard_escape(
                                    function_exists('mb_strimwidth')
                                        ? mb_strimwidth((string)$notice['body'], 0, 145, '...')
                                        : substr((string)$notice['body'], 0, 145)
                                ) ?>
                        </p>

                        <small>
                            <?= dashboard_escape(
                                    dashboard_datetime($notice['published_at'] ?? $notice['created_at'])
                                ) ?>
                        </small>

                    </div>

                    <?php endforeach; ?>

                    <?php else: ?>

                    <div class="sd-empty">
                        No published notices available.
                    </div>

                    <?php endif; ?>

                </section>

            </aside>

        </div>

        <!-- Footer -->

        <footer class="sd-footer">
            DU EMBA MIS Student Portal · Department of Management Information Systems
        </footer>

    </div>

</body>

</html>