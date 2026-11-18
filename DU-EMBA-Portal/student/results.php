<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

$pageTitle = 'My Results & Academic Transcript';

$userId = (int)($_SESSION['user_id'] ?? 0);
$student = null;
$results = [];
$semesterResults = [];
$overallCGPA = 0;
$totalCredits = 0;
$overallCompletedCourses = 0;
$errorMessage = '';

/* =====================================================
   HELPER FUNCTIONS
===================================================== */

function result_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function result_query($sql, $types = '', $params = [])
{
    global $conn;

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Student Results SQL Error: ' . $conn->error);
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
        error_log('Student Results Execute Error: ' . $stmt->error);
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
}

function result_one($sql, $types = '', $params = [])
{
    $rows = result_query($sql, $types, $params);
    return $rows[0] ?? null;
}

function result_grade_class($grade)
{
    if ($grade === 'F') {
        return 'grade-f';
    }

    return 'grade-pass';
}

/* =====================================================
   FIND STUDENT PROFILE
===================================================== */

if ($userId > 0) {
    $student = result_one(
        "SELECT id, student_id
         FROM students
         WHERE user_id = ?
         LIMIT 1",
        "i",
        [$userId]
    );
}

$studentId = $student ? (int)$student['id'] : 0;

/* =====================================================
   LOAD ONLY PUBLISHED RESULTS
===================================================== */

if ($studentId > 0) {

    $results = result_query(
        "SELECT
            r.id AS result_id,
            r.registration_id,
            r.total_marks,
            r.letter_grade,
            r.grade_point,
            r.published_at,

            cr.status AS registration_status,

            co.id AS offering_id,

            c.course_code,
            c.course_title,
            c.credit_hours,

            sem.id AS semester_id,
            sem.semester_name,
            sem.start_date,
            sem.end_date

         FROM results r

         INNER JOIN course_registrations cr
            ON cr.id = r.registration_id

         INNER JOIN course_offerings co
            ON co.id = cr.offering_id

         INNER JOIN courses c
            ON c.id = co.course_id

         INNER JOIN semesters sem
            ON sem.id = co.semester_id

         WHERE cr.student_id = ?
           AND r.status = 'published'

         ORDER BY
            sem.start_date DESC,
            c.course_code ASC,
            r.id DESC",
        "i",
        [$studentId]
    );

} else {

    $errorMessage = 'Your student profile is not linked with your account. Please contact the Academic Office.';
}

/* =====================================================
   GET ASSESSMENT MARKS FOR EACH PUBLISHED RESULT
===================================================== */

$componentOrder = [
    'Mid-1',
    'Mid-2',
    'Final',
    'Attendance',
    'Term Paper',
    'Presentation'
];

foreach ($results as $index => $result) {

    $registrationId = (int)$result['registration_id'];

    $marks = result_query(
        "SELECT
            a.assessment_name,
            a.max_marks,
            m.marks_obtained

         FROM assessments a

         LEFT JOIN marks m
            ON m.assessment_id = a.id
           AND m.registration_id = ?

         WHERE a.offering_id = ?

         ORDER BY
            CASE a.assessment_name
                WHEN 'Mid-1' THEN 1
                WHEN 'Mid-2' THEN 2
                WHEN 'Final' THEN 3
                WHEN 'Attendance' THEN 4
                WHEN 'Term Paper' THEN 5
                WHEN 'Presentation' THEN 6
                ELSE 7
            END",
        "ii",
        [
            $registrationId,
            (int)$result['offering_id']
        ]
    );

    $results[$index]['assessment_marks'] = $marks;
}

/* =====================================================
   GROUP RESULTS BY SEMESTER
===================================================== */

foreach ($results as $result) {

    $semesterId = (int)$result['semester_id'];

    if (!isset($semesterResults[$semesterId])) {

        $semesterResults[$semesterId] = [
            'semester_name' => $result['semester_name'],
            'start_date' => $result['start_date'],
            'end_date' => $result['end_date'],
            'courses' => [],
            'total_credits' => 0,
            'weighted_points' => 0,
            'sgpa' => 0
        ];
    }

    $credit = (float)$result['credit_hours'];
    $gradePoint = (float)$result['grade_point'];

    $semesterResults[$semesterId]['courses'][] = $result;

    $semesterResults[$semesterId]['total_credits'] += $credit;

    $semesterResults[$semesterId]['weighted_points'] += (
        $gradePoint * $credit
    );

    $totalCredits += $credit;
    $overallCGPA += ($gradePoint * $credit);
    $overallCompletedCourses++;
}

/* =====================================================
   CALCULATE SGPA AND CGPA
   Credit-weighted GPA calculation
===================================================== */

foreach ($semesterResults as $semesterId => $semester) {

    if ($semester['total_credits'] > 0) {

        $semesterResults[$semesterId]['sgpa'] =
            $semester['weighted_points'] / $semester['total_credits'];
    }
}

if ($totalCredits > 0) {
    $overallCGPA = $overallCGPA / $totalCredits;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= result_escape($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background: #f5f7fa;
        color: #263548;
        font-family: Arial, Helvetica, sans-serif;
    }

    .results-container {
        width: calc(100% - 40px);
        max-width: 1440px;
        margin: 30px auto;
    }

    .topbar {
        background: #ffffff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 16px 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 22px;
    }

    .topbar h2 {
        color: #102d50;
        font-size: 20px;
        margin: 0;
    }

    .topbar p {
        color: #687789;
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
        color: #102d50;
    }

    .logout-link {
        background: #102d50;
        color: #ffffff;
    }

    .page-banner {
        background: linear-gradient(115deg, #102d50, #1c456e);
        color: #ffffff;
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

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 17px;
        margin-bottom: 22px;
    }

    .summary-card {
        background: #ffffff;
        border: 1px solid #e1e5eb;
        border-radius: 10px;
        padding: 20px;
    }

    .summary-card p {
        color: #687789;
        font-size: 12px;
        margin: 0 0 10px;
    }

    .summary-card h2 {
        color: #102d50;
        font-size: 27px;
        margin: 0;
    }

    .summary-card small {
        color: #687789;
        font-size: 11px;
    }

    .content-card {
        background: #ffffff;
        border: 1px solid #e1e5eb;
        border-radius: 11px;
        padding: 22px;
        margin-bottom: 22px;
    }

    .semester-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        padding-bottom: 16px;
        border-bottom: 1px solid #e9edf2;
        margin-bottom: 17px;
    }

    .semester-header h2 {
        color: #102d50;
        font-size: 18px;
        margin: 0 0 6px;
    }

    .semester-header p {
        color: #687789;
        font-size: 12px;
        margin: 0;
    }

    .sgpa-box {
        background: #edf4fb;
        border-radius: 8px;
        padding: 10px 16px;
        text-align: center;
        min-width: 105px;
    }

    .sgpa-box small {
        display: block;
        color: #687789;
        font-size: 11px;
        margin-bottom: 4px;
    }

    .sgpa-box strong {
        color: #102d50;
        font-size: 21px;
    }

    .table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 780px;
    }

    th {
        background: #f3f6fa;
        color: #102d50;
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
        color: #102d50;
        font-weight: 600;
    }

    .course-code {
        color: #687789;
        font-size: 11px;
        margin-top: 4px;
    }

    .grade {
        display: inline-block;
        min-width: 40px;
        text-align: center;
        padding: 6px 9px;
        border-radius: 6px;
        font-weight: 700;
        font-size: 12px;
    }

    .grade-pass {
        background: #e8f5ed;
        color: #198754;
    }

    .grade-f {
        background: #fdebec;
        color: #dc3545;
    }

    .marks-button {
        border: 1px solid #dce5ef;
        background: #f3f7fb;
        color: #102d50;
        padding: 8px 11px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
    }

    .marks-button:hover {
        background: #e6eef7;
    }

    .marks-detail {
        display: none;
        background: #f8fafc;
    }

    .marks-detail.show {
        display: table-row;
    }

    .marks-detail td {
        padding: 17px;
    }

    .marks-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }

    .mark-item {
        background: #ffffff;
        border: 1px solid #e1e5eb;
        border-radius: 7px;
        padding: 11px;
    }

    .mark-item span {
        display: block;
        color: #687789;
        font-size: 11px;
        margin-bottom: 6px;
    }

    .mark-item strong {
        color: #102d50;
        font-size: 15px;
    }

    .empty-state {
        text-align: center;
        background: #f8fafc;
        border: 1px dashed #d8e0e8;
        padding: 35px 15px;
        border-radius: 8px;
        color: #687789;
        font-size: 13px;
    }

    .empty-state strong {
        display: block;
        color: #102d50;
        margin-bottom: 7px;
        font-size: 15px;
    }

    .message {
        padding: 13px 16px;
        border-radius: 8px;
        font-size: 13px;
        margin-bottom: 18px;
        background: #fdebec;
        border: 1px solid #f5c2c7;
        color: #842029;
    }

    .transcript-note {
        color: #687789;
        font-size: 11px;
        line-height: 1.6;
        margin-top: 12px;
    }

    .footer {
        text-align: center;
        padding: 18px 10px;
        color: #687789;
        font-size: 12px;
    }

    @media (max-width: 750px) {
        .summary-grid {
            grid-template-columns: 1fr;
        }

        .marks-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 600px) {
        .results-container {
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

        .content-card {
            padding: 15px;
        }

        .marks-grid {
            grid-template-columns: 1fr 1fr;
        }
    }
    </style>

</head>

<body>

    <div class="results-container">

        <!-- TOP NAVIGATION -->

        <header class="topbar">

            <div>
                <h2>DU EMBA MIS Student Portal</h2>
                <p>Academic Results & Transcript</p>
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

            <h1>My Results & Academic Transcript</h1>

            <p>
                View your published academic results, course-wise marks,
                semester GPA and cumulative GPA.
            </p>

        </section>


        <?php if ($errorMessage): ?>

        <div class="message">
            <?= result_escape($errorMessage) ?>
        </div>

        <?php endif; ?>


        <?php if ($studentId > 0): ?>

        <!-- SUMMARY CARDS -->

        <section class="summary-grid">

            <div class="summary-card">

                <p>Overall CGPA</p>

                <h2>
                    <?= $totalCredits > 0
                        ? number_format($overallCGPA, 2)
                        : '0.00' ?>
                </h2>

                <small>Credit-weighted cumulative GPA</small>

            </div>


            <div class="summary-card">

                <p>Published Courses</p>

                <h2>
                    <?= number_format($overallCompletedCourses) ?>
                </h2>

                <small>Courses with published results</small>

            </div>


            <div class="summary-card">

                <p>Total Published Credits</p>

                <h2>
                    <?= number_format($totalCredits, 1) ?>
                </h2>

                <small>Total credit hours completed in published results</small>

            </div>

        </section>


        <!-- SEMESTER-WISE RESULTS -->

        <?php if (empty($semesterResults)): ?>

        <section class="content-card">

            <div class="empty-state">

                <strong>No Published Results Available</strong>

                Your academic results will appear here after
                the Academic Office publishes them.

            </div>

        </section>

        <?php else: ?>

        <?php foreach ($semesterResults as $semesterId => $semester): ?>

        <section class="content-card">

            <div class="semester-header">

                <div>

                    <h2>
                        <?= result_escape($semester['semester_name']) ?>
                    </h2>

                    <p>
                        Semester Academic Transcript
                    </p>

                </div>

                <div class="sgpa-box">

                    <small>Semester SGPA</small>

                    <strong>
                        <?= number_format($semester['sgpa'], 2) ?>
                    </strong>

                </div>

            </div>


            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>Course</th>
                            <th>Credit</th>
                            <th>Total Marks</th>
                            <th>Letter Grade</th>
                            <th>Grade Point</th>
                            <th>Marks Breakdown</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($semester['courses'] as $result): ?>

                        <?php
                                $resultId = (int)$result['result_id'];
                                ?>

                        <tr>

                            <td>

                                <div class="course-title">
                                    <?= result_escape($result['course_title']) ?>
                                </div>

                                <div class="course-code">
                                    <?= result_escape($result['course_code']) ?>
                                </div>

                            </td>

                            <td>
                                <?= number_format((float)$result['credit_hours'], 1) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= number_format((float)$result['total_marks'], 2) ?>
                                </strong>
                            </td>

                            <td>

                                <span class="grade <?= result_grade_class($result['letter_grade']) ?>">

                                    <?= result_escape($result['letter_grade']) ?>

                                </span>

                            </td>

                            <td>
                                <strong>
                                    <?= number_format((float)$result['grade_point'], 2) ?>
                                </strong>
                            </td>

                            <td>

                                <button type="button" class="marks-button"
                                    onclick="toggleMarks('marks-<?= $resultId ?>', this)">

                                    View Marks

                                </button>

                            </td>

                        </tr>


                        <!-- MARKS BREAKDOWN -->

                        <tr class="marks-detail" id="marks-<?= $resultId ?>">

                            <td colspan="6">

                                <div class="marks-grid">

                                    <?php

                                            $markMap = [];

                                            foreach ($result['assessment_marks'] as $mark) {
                                                $markMap[$mark['assessment_name']] = $mark;
                                            }

                                            foreach ($componentOrder as $component):

                                                $componentData = $markMap[$component] ?? null;

                                            ?>

                                    <div class="mark-item">

                                        <span>
                                            <?= result_escape($component) ?>
                                        </span>

                                        <strong>

                                            <?php if ($componentData && $componentData['marks_obtained'] !== null): ?>

                                            <?= number_format((float)$componentData['marks_obtained'], 2) ?>

                                            /

                                            <?= number_format((float)$componentData['max_marks'], 2) ?>

                                            <?php else: ?>

                                            Not available

                                            <?php endif; ?>

                                        </strong>

                                    </div>

                                    <?php endforeach; ?>

                                </div>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <p class="transcript-note">
                SGPA is calculated using credit-weighted grade points
                of the published courses in this semester.
            </p>

        </section>

        <?php endforeach; ?>

        <?php endif; ?>

        <?php endif; ?>


        <footer class="footer">

            DU EMBA MIS Student Portal
            <br>
            Department of Management Information Systems, University of Dhaka

        </footer>

    </div>


    <script>
    function toggleMarks(rowId, button) {

        const row = document.getElementById(rowId);

        if (!row) {
            return;
        }

        const isVisible = row.classList.contains('show');

        row.classList.toggle('show');

        button.textContent = isVisible ? 'View Marks' : 'Hide Marks';
    }
    </script>

</body>

</html>