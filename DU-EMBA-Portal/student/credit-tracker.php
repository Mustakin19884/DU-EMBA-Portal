<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

$pageTitle = 'Credit Tracker & Degree Progress';

$userId = (int)($_SESSION['user_id'] ?? 0);
$studentName = $_SESSION['full_name'] ?? 'Student';

define('DEGREE_TOTAL_CREDITS', 48);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function ct_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ct_query(string $sql, string $types = '', array $params = []): array
{
    global $conn;

    try {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('Credit Tracker SQL Error: ' . $conn->error);
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
            error_log('Credit Tracker Execute Error: ' . $stmt->error);
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
        error_log('Credit Tracker Error: ' . $e->getMessage());
        return [];
    }
}

function ct_percentage(float $completed, float $total): float
{
    if ($total <= 0) {
        return 0;
    }

    return min(100, max(0, ($completed / $total) * 100));
}

function ct_category_key(string $name): string
{
    $name = strtolower(trim($name));

    if (strpos($name, 'prerequisite') !== false) {
        return 'prerequisite';
    }

    if ($name === 'core') {
        return 'core';
    }

    if ($name === 'elective') {
        return 'elective';
    }

    if (
        strpos($name, 'internship') !== false ||
        strpos($name, 'project') !== false ||
        strpos($name, 'thesis') !== false
    ) {
        return 'internship';
    }

    return 'other';
}

/* =========================================================
   STUDENT PROFILE
========================================================= */

$student = null;
$studentId = 0;

$profileRows = ct_query(
    "SELECT id, student_id, user_id
     FROM students
     WHERE user_id = ?
     LIMIT 1",
    "i",
    [$userId]
);

if (!empty($profileRows)) {
    $student = $profileRows[0];
    $studentId = (int)$student['id'];
}

/* =========================================================
   DEGREE CATEGORY REQUIREMENTS
========================================================= */

$categoryConfig = [
    'prerequisite' => [
        'name' => 'Prerequisite',
        'required_courses' => 6,
        'required_credits' => 18
    ],
    'core' => [
        'name' => 'Core',
        'required_courses' => 5,
        'required_credits' => 15
    ],
    'elective' => [
        'name' => 'Elective',
        'required_courses' => 4,
        'required_credits' => 12
    ],
    'internship' => [
        'name' => 'Internship / Project / Thesis',
        'required_courses' => 1,
        'required_credits' => 3
    ]
];

/* =========================================================
   FETCH COURSE PROGRESS
========================================================= */

$courses = [];

if ($studentId > 0) {

    $courses = ct_query(
        "SELECT
            c.id,
            c.course_code,
            c.course_title,
            c.credit_hours,
            cc.category_name,

            MAX(
                CASE
                    WHEN r.status = 'published'
                     AND r.grade_point > 0
                    THEN 1 ELSE 0
                END
            ) AS is_completed,

            MAX(
                CASE
                    WHEN cr.status = 'registered'
                     AND (
                        r.id IS NULL
                        OR COALESCE(r.grade_point, 0) <= 0
                     )
                    THEN 1 ELSE 0
                END
            ) AS is_running,

            MAX(
                CASE
                    WHEN pw.id IS NOT NULL
                    THEN 1 ELSE 0
                END
            ) AS is_waived

         FROM courses c

         INNER JOIN course_categories cc
            ON cc.id = c.category_id

         LEFT JOIN course_offerings co
            ON co.course_id = c.id

         LEFT JOIN course_registrations cr
            ON cr.offering_id = co.id
           AND cr.student_id = ?

         LEFT JOIN results r
            ON r.registration_id = cr.id
           AND r.status = 'published'

         LEFT JOIN prerequisite_waivers pw
            ON pw.course_id = c.id
           AND pw.student_id = ?
           AND pw.status = 'approved'

         WHERE c.is_active = 1

         GROUP BY
            c.id,
            c.course_code,
            c.course_title,
            c.credit_hours,
            cc.category_name

         ORDER BY
            CASE
                WHEN cc.category_name = 'Prerequisite' THEN 1
                WHEN cc.category_name = 'Core' THEN 2
                WHEN cc.category_name = 'Elective' THEN 3
                ELSE 4
            END,
            c.course_code ASC",
        "ii",
        [$studentId, $studentId]
    );
}

/* =========================================================
   INITIALIZE CATEGORY SUMMARY
========================================================= */

$categoryProgress = [];

foreach ($categoryConfig as $key => $config) {

    $categoryProgress[$key] = [
        'name' => $config['name'],
        'required_courses' => $config['required_courses'],
        'required_credits' => $config['required_credits'],
        'completed_courses' => 0,
        'completed_credits' => 0,
        'running_courses' => 0,
        'running_credits' => 0,
        'remaining_courses' => $config['required_courses'],
        'remaining_credits' => $config['required_credits'],
        'courses' => []
    ];
}

/* =========================================================
   PROCESS COURSE STATUS
========================================================= */

foreach ($courses as $course) {

    $key = ct_category_key($course['category_name']);

    if (!isset($categoryProgress[$key])) {
        continue;
    }

    $credit = (float)$course['credit_hours'];

    $isWaived = (int)$course['is_waived'] === 1;
    $isCompleted = (int)$course['is_completed'] === 1;
    $isRunning = (int)$course['is_running'] === 1;

    if ($key === 'prerequisite' && $isWaived) {
        $status = 'Waived';
    } elseif ($isCompleted) {
        $status = 'Completed';
    } elseif ($isRunning) {
        $status = 'Running';
    } else {
        $status = 'Remaining';
    }

    $course['display_status'] = $status;

    $categoryProgress[$key]['courses'][] = $course;
}

/* =========================================================
   CALCULATE CATEGORY-WISE PROGRESS

   Elective requirement is limited to 4 courses / 12 credits.
========================================================= */

$totalCompletedCredits = 0;
$totalRunningCredits = 0;
$totalCompletedCourses = 0;
$totalRunningCourses = 0;

foreach ($categoryProgress as $key => &$category) {

    $completedCourses = [];
    $runningCourses = [];

    foreach ($category['courses'] as $course) {

        if (
            $course['display_status'] === 'Completed' ||
            $course['display_status'] === 'Waived'
        ) {
            $completedCourses[] = $course;

        } elseif ($course['display_status'] === 'Running') {
            $runningCourses[] = $course;
        }
    }

    $requiredCourses = (int)$category['required_courses'];
    $requiredCredits = (float)$category['required_credits'];

    $completedCount = 0;
    $completedCredits = 0;

    foreach ($completedCourses as $course) {

        if ($completedCount >= $requiredCourses) {
            break;
        }

        $credit = (float)$course['credit_hours'];

        if (($completedCredits + $credit) > $requiredCredits) {
            $credit = max(0, $requiredCredits - $completedCredits);
        }

        $completedCredits += $credit;
        $completedCount++;
    }

    $runningCount = 0;
    $runningCredits = 0;

    $availableCourseSlots = max(0, $requiredCourses - $completedCount);
    $availableCreditSlots = max(0, $requiredCredits - $completedCredits);

    foreach ($runningCourses as $course) {

        if (
            $runningCount >= $availableCourseSlots ||
            $availableCreditSlots <= 0
        ) {
            break;
        }

        $credit = min(
            (float)$course['credit_hours'],
            $availableCreditSlots
        );

        $runningCredits += $credit;
        $runningCount++;
        $availableCreditSlots -= $credit;
    }

    $category['completed_courses'] = $completedCount;
    $category['completed_credits'] = $completedCredits;

    $category['running_courses'] = $runningCount;
    $category['running_credits'] = $runningCredits;

    $category['remaining_courses'] = max(
        0,
        $requiredCourses - $completedCount - $runningCount
    );

    $category['remaining_credits'] = max(
        0,
        $requiredCredits - $completedCredits - $runningCredits
    );

    $category['percentage'] = ct_percentage(
        $completedCredits,
        $requiredCredits
    );

    $totalCompletedCredits += $completedCredits;
    $totalRunningCredits += $runningCredits;

    $totalCompletedCourses += $completedCount;
    $totalRunningCourses += $runningCount;
}

unset($category);

/* =========================================================
   OVERALL DEGREE PROGRESS
========================================================= */

$totalRemainingCredits = max(
    0,
    DEGREE_TOTAL_CREDITS - $totalCompletedCredits - $totalRunningCredits
);

$totalDegreePercentage = ct_percentage(
    $totalCompletedCredits,
    DEGREE_TOTAL_CREDITS
);

$approvedWaiverCount = 0;

if ($studentId > 0) {

    $waiverRows = ct_query(
        "SELECT COUNT(*) AS total
         FROM prerequisite_waivers
         WHERE student_id = ?
           AND status = 'approved'",
        "i",
        [$studentId]
    );

    $approvedWaiverCount = (int)($waiverRows[0]['total'] ?? 0);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= ct_escape($pageTitle) ?></title>

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
        --orange: #d97706;
        --red: #dc3545;
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

    .credit-container {
        width: calc(100% - 40px);
        max-width: 1250px;
        margin: 30px auto;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 17px 21px;
        margin-bottom: 20px;
    }

    .page-header h1 {
        color: var(--navy);
        font-size: 21px;
        margin: 0 0 5px;
    }

    .page-header p {
        color: var(--muted);
        font-size: 12px;
        margin: 0;
    }

    .back-link {
        background: var(--light-blue);
        color: var(--navy);
        padding: 10px 14px;
        border-radius: 7px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }

    .progress-panel {
        background: linear-gradient(115deg, var(--navy), var(--navy-light));
        color: white;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 20px;
    }

    .progress-panel h2 {
        font-size: 18px;
        margin: 0 0 7px;
    }

    .progress-panel p {
        color: #e2eaf3;
        font-size: 13px;
        margin: 0;
    }

    .progress-numbers {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 15px;
        margin-top: 20px;
    }

    .progress-numbers strong {
        font-size: 31px;
    }

    .progress-numbers span {
        display: block;
        font-size: 12px;
        color: #d4e0ed;
        margin-top: 4px;
    }

    .progress-track {
        width: 100%;
        height: 11px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        overflow: hidden;
        margin-top: 18px;
    }

    .progress-fill {
        height: 100%;
        background: #ffffff;
        border-radius: 20px;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 15px;
        margin-bottom: 22px;
    }

    .stat-card {
        background: white;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 18px;
    }

    .stat-card span {
        display: block;
        color: var(--muted);
        font-size: 12px;
        margin-bottom: 10px;
    }

    .stat-card strong {
        display: block;
        font-size: 25px;
        color: var(--navy);
    }

    .stat-card small {
        color: var(--muted);
        font-size: 11px;
    }

    .section {
        background: white;
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .section-heading {
        color: var(--navy);
        font-size: 17px;
        margin: 0 0 17px;
    }

    .category-card {
        border: 1px solid var(--border);
        border-radius: 9px;
        padding: 17px;
        margin-bottom: 13px;
    }

    .category-card:last-child {
        margin-bottom: 0;
    }

    .category-top {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
    }

    .category-top h3 {
        font-size: 14px;
        color: var(--navy);
        margin: 0;
    }

    .category-top strong {
        font-size: 13px;
        color: var(--navy);
        white-space: nowrap;
    }

    .category-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-top: 12px;
        font-size: 11px;
        color: var(--muted);
    }

    .category-track {
        height: 8px;
        background: #edf0f3;
        border-radius: 20px;
        overflow: hidden;
        margin-top: 13px;
    }

    .category-fill {
        height: 100%;
        background: var(--green);
        border-radius: 20px;
    }

    .table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 650px;
    }

    th {
        background: #f4f7fa;
        color: var(--navy);
        text-align: left;
        padding: 12px 10px;
        font-size: 12px;
        white-space: nowrap;
    }

    td {
        padding: 12px 10px;
        border-bottom: 1px solid #edf0f3;
        font-size: 12px;
    }

    tbody tr:last-child td {
        border-bottom: none;
    }

    .status {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }

    .status.completed,
    .status.waived {
        color: var(--green);
        background: #e8f5ed;
    }

    .status.running {
        color: var(--orange);
        background: #fff4df;
    }

    .status.remaining {
        color: var(--muted);
        background: #edf0f3;
    }

    .note {
        background: #f8fafc;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 14px;
        color: var(--muted);
        font-size: 12px;
        line-height: 1.7;
    }

    .empty-state {
        text-align: center;
        padding: 25px;
        color: var(--muted);
        background: #f8fafc;
        border: 1px dashed var(--border);
        border-radius: 8px;
        font-size: 13px;
    }

    .page-footer {
        text-align: center;
        color: var(--muted);
        font-size: 12px;
        padding: 15px;
    }

    @media (max-width: 850px) {
        .stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 550px) {
        .credit-container {
            width: calc(100% - 22px);
            margin: 12px auto;
        }

        .page-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .progress-panel {
            padding: 19px;
        }

        .progress-numbers strong {
            font-size: 25px;
        }

        .stats-grid {
            gap: 10px;
        }

        .stat-card {
            padding: 14px;
        }

        .stat-card strong {
            font-size: 21px;
        }

        .section {
            padding: 15px;
        }

        .category-meta {
            gap: 9px;
        }
    }
    </style>
</head>

<body>

    <div class="credit-container">

        <!-- HEADER -->

        <header class="page-header">

            <div>
                <h1>Credit Tracker & Degree Progress</h1>
                <p>
                    DU EMBA MIS Programme |
                    <?= ct_escape($studentName) ?>
                </p>
            </div>

            <a class="back-link" href="<?= BASE_URL ?>/student/dashboard.php">
                ← Dashboard
            </a>

        </header>

        <?php if (!$student): ?>

        <section class="section">
            <div class="empty-state">
                <strong>Student profile not found.</strong>
                Please contact the Academic Office to link your student profile.
            </div>
        </section>

        <?php else: ?>

        <!-- OVERALL PROGRESS -->

        <section class="progress-panel">

            <h2>Overall Degree Completion</h2>

            <p>
                Your MBA programme requires 48 credits to complete.
            </p>

            <div class="progress-numbers">

                <div>
                    <strong>
                        <?= number_format($totalCompletedCredits, 1) ?>
                        / 48
                    </strong>
                    <span>Completed Credits</span>
                </div>

                <div style="text-align:right;">
                    <strong>
                        <?= number_format($totalDegreePercentage, 1) ?>%
                    </strong>
                    <span>Degree Progress</span>
                </div>

            </div>

            <div class="progress-track">
                <div class="progress-fill" style="width:<?= number_format($totalDegreePercentage, 2, '.', '') ?>%;">
                </div>
            </div>

        </section>

        <!-- SUMMARY CARDS -->

        <section class="stats-grid">

            <div class="stat-card">
                <span>Completed Credits</span>
                <strong><?= number_format($totalCompletedCredits, 1) ?></strong>
                <small>Including approved waivers</small>
            </div>

            <div class="stat-card">
                <span>Running Credits</span>
                <strong><?= number_format($totalRunningCredits, 1) ?></strong>
                <small>Currently registered</small>
            </div>

            <div class="stat-card">
                <span>Remaining Credits</span>
                <strong><?= number_format($totalRemainingCredits, 1) ?></strong>
                <small>Required to finish the degree</small>
            </div>

            <div class="stat-card">
                <span>Approved Waivers</span>
                <strong><?= $approvedWaiverCount ?> / 4</strong>
                <small>Prerequisite courses</small>
            </div>

        </section>

        <!-- CATEGORY PROGRESS -->

        <section class="section">

            <h2 class="section-heading">
                Category-wise Progress
            </h2>

            <?php foreach ($categoryProgress as $category): ?>

            <div class="category-card">

                <div class="category-top">

                    <h3><?= ct_escape($category['name']) ?></h3>

                    <strong>
                        <?= number_format($category['completed_credits'], 1) ?>
                        /
                        <?= number_format($category['required_credits'], 1) ?>
                        Credits
                    </strong>

                </div>

                <div class="category-meta">

                    <span>
                        Completed:
                        <?= (int)$category['completed_courses'] ?>
                        / <?= (int)$category['required_courses'] ?>
                    </span>

                    <span>
                        Running:
                        <?= (int)$category['running_courses'] ?>
                    </span>

                    <span>
                        Remaining:
                        <?= (int)$category['remaining_courses'] ?>
                    </span>

                </div>

                <div class="category-track">

                    <div class="category-fill"
                        style="width:<?= number_format($category['percentage'], 2, '.', '') ?>%;">
                    </div>

                </div>

            </div>

            <?php endforeach; ?>

        </section>

        <!-- COURSE STATUS -->

        <section class="section">

            <h2 class="section-heading">
                Course Completion Details
            </h2>

            <?php if (!empty($courses)): ?>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th>Category</th>
                            <th>Credits</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($courses as $course): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= ct_escape($course['course_code']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= ct_escape($course['course_title']) ?>
                            </td>

                            <td>
                                <?= ct_escape($course['category_name']) ?>
                            </td>

                            <td>
                                <?= number_format((float)$course['credit_hours'], 1) ?>
                            </td>

                            <td>
                                <?php
        $displayStatus = $course['display_status'] ?? 'Remaining';
        $statusClass = strtolower(str_replace('/', '-', $displayStatus));
    ?>
                                <span class="status <?= ct_escape($statusClass) ?>">
                                    <?= ct_escape($displayStatus) ?>
                                </span>
                            </td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <?php else: ?>

            <div class="empty-state">
                No active courses found in the curriculum.
            </div>

            <?php endif; ?>

        </section>

        <!-- IMPORTANT NOTES -->

        <section class="section">

            <h2 class="section-heading">
                Credit Calculation Guidelines
            </h2>

            <div class="note">

                <strong>Completed:</strong>
                Courses with published passing results count as completed.

                <br>

                <strong>Waived:</strong>
                Approved prerequisite waivers count as earned credits,
                but do not affect CGPA.

                <br>

                <strong>Running:</strong>
                Courses currently registered without a published passing result.

                <br>

                <strong>Remaining:</strong>
                Courses and credits still required to complete the programme.

                <br>

                <strong>Electives:</strong>
                Only 4 elective courses and 12 credits are required,
                even though more elective courses may be available.

            </div>

            <p style="margin-top:15px;">
                <a class="back-link" href="<?= BASE_URL ?>/student/waiver-application.php">
                    Apply for Prerequisite Waiver →
                </a>
            </p>

        </section>

        <?php endif; ?>

        <footer class="page-footer">
            DU EMBA MIS Student Portal
            <br>
            Department of Management Information Systems, University of Dhaka
        </footer>

    </div>

</body>

</html>