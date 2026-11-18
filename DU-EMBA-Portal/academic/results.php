<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('academic');

$pageTitle = 'Result Approval & Publishing';

$successMessage = '';
$errorMessage = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['academic_result_csrf'])) {
    $_SESSION['academic_result_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['academic_result_csrf'];

/* =====================================================
   HELPER FUNCTIONS
===================================================== */

function academic_result_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function academic_result_query($sql, $types = '', $params = [])
{
    global $conn;

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Academic Result SQL Error: ' . $conn->error);
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
        error_log('Academic Result Execute Error: ' . $stmt->error);
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

/* =====================================================
   APPROVE / PUBLISH RESULT
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $resultId = (int)($_POST['result_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {

        $errorMessage = 'Invalid request. Please refresh the page and try again.';

    } elseif ($resultId <= 0) {

        $errorMessage = 'Invalid result selected.';

    } elseif (!in_array($action, ['approve', 'publish'], true)) {

        $errorMessage = 'Invalid action requested.';

    } else {

        try {

            $conn->begin_transaction();

            if ($action === 'approve') {

                /*
                 * Only Submitted results can be approved.
                 */

                $newStatus = 'approved';
                $oldStatus = 'submitted';

                $message = 'Result approved successfully.';

            } else {

                /*
                 * Only Approved results can be published.
                 */

                $newStatus = 'published';
                $oldStatus = 'approved';

                $message = 'Result published successfully.';
            }

            if ($action === 'publish') {

                $stmt = $conn->prepare(
                    "UPDATE results
                     SET status = 'published',
                         published_at = NOW()
                     WHERE id = ?
                       AND status = 'approved'"
                );

            } else {

                $stmt = $conn->prepare(
                    "UPDATE results
                     SET status = 'approved'
                     WHERE id = ?
                       AND status = 'submitted'"
                );
            }

            if (!$stmt) {
                throw new Exception('Unable to prepare result update.');
            }

            $stmt->bind_param("i", $resultId);

            if (!$stmt->execute()) {
                throw new Exception('Unable to update result status.');
            }

            if ($stmt->affected_rows !== 1) {
                $stmt->close();

                throw new Exception(
                    'This result cannot be updated. It may have already been processed.'
                );
            }

            $stmt->close();

            $conn->commit();

            $successMessage = $message;

        } catch (Throwable $e) {

            $conn->rollback();

            $errorMessage = $e->getMessage();

            error_log('Academic Result Workflow Error: ' . $e->getMessage());
        }
    }
}

/* =====================================================
   LOAD SUBMITTED AND APPROVED RESULTS
===================================================== */

$results = academic_result_query(
    "SELECT
        r.id AS result_id,
        r.registration_id,
        r.total_marks,
        r.letter_grade,
        r.grade_point,
        r.status,

        cr.student_id,

        s.student_id AS student_number,

        u.full_name AS student_name,

        c.course_code,
        c.course_title,
        c.credit_hours,

        sem.semester_name

     FROM results r

     INNER JOIN course_registrations cr
        ON cr.id = r.registration_id

     INNER JOIN students s
        ON s.id = cr.student_id

     INNER JOIN users u
        ON u.id = s.user_id

     INNER JOIN course_offerings co
        ON co.id = cr.offering_id

     INNER JOIN courses c
        ON c.id = co.course_id

     INNER JOIN semesters sem
        ON sem.id = co.semester_id

     WHERE r.status IN ('submitted', 'approved')

     ORDER BY
        CASE r.status
            WHEN 'submitted' THEN 1
            WHEN 'approved' THEN 2
            ELSE 3
        END,
        r.id DESC"
);

/* =====================================================
   LOAD ASSESSMENT MARKS FOR EACH RESULT
===================================================== */

$assessmentOrder = [
    'Mid-1',
    'Mid-2',
    'Final',
    'Attendance',
    'Term Paper',
    'Presentation'
];

foreach ($results as $index => $result) {

    $results[$index]['assessment_marks'] = academic_result_query(
        "SELECT
            a.assessment_name,
            a.max_marks,
            m.marks_obtained

         FROM assessments a

         LEFT JOIN marks m
            ON m.assessment_id = a.id
           AND m.registration_id = ?

         INNER JOIN course_registrations cr
            ON cr.offering_id = a.offering_id

         WHERE cr.id = ?

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
            (int)$result['registration_id'],
            (int)$result['registration_id']
        ]
    );
}

/* =====================================================
   SUMMARY COUNTS
===================================================== */

$submittedCount = 0;
$approvedCount = 0;

foreach ($results as $result) {

    if ($result['status'] === 'submitted') {
        $submittedCount++;
    }

    if ($result['status'] === 'approved') {
        $approvedCount++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= academic_result_escape($pageTitle) ?></title>

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

    .page-container {
        width: calc(100% - 40px);
        max-width: 1450px;
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

    .nav-links {
        display: flex;
        gap: 10px;
    }

    .nav-links a {
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

    .content-card {
        background: #ffffff;
        border: 1px solid #e1e5eb;
        border-radius: 11px;
        padding: 22px;
        margin-bottom: 22px;
    }

    .section-heading {
        margin-bottom: 18px;
    }

    .section-heading h2 {
        color: #102d50;
        font-size: 17px;
        margin: 0;
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

    .table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1100px;
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

    .student-name {
        color: #102d50;
        font-weight: 600;
    }

    .sub-text {
        color: #687789;
        font-size: 11px;
        margin-top: 4px;
    }

    .status-badge {
        display: inline-block;
        padding: 6px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }

    .status-submitted {
        background: #fff4df;
        color: #a16207;
    }

    .status-approved {
        background: #e8f5ed;
        color: #198754;
    }

    .action-button {
        border: none;
        padding: 9px 12px;
        border-radius: 6px;
        color: #ffffff;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
    }

    .approve-button {
        background: #1c456e;
    }

    .approve-button:hover {
        background: #102d50;
    }

    .publish-button {
        background: #198754;
    }

    .publish-button:hover {
        background: #146c43;
    }

    .details-button {
        border: 1px solid #dce5ef;
        background: #f3f7fb;
        color: #102d50;
        padding: 8px 11px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .details-button:hover {
        background: #e6eef7;
    }

    .action-form {
        display: inline-block;
        margin: 0;
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

    .workflow-note {
        color: #687789;
        font-size: 12px;
        line-height: 1.7;
        margin-top: 16px;
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
        .page-container {
            width: calc(100% - 22px);
            margin: 12px auto;
        }

        .topbar {
            flex-direction: column;
            align-items: flex-start;
            padding: 15px;
        }

        .nav-links {
            width: 100%;
        }

        .nav-links a {
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

    <div class="page-container">

        <header class="topbar">

            <div>
                <h2>DU EMBA MIS Student Portal</h2>
                <p>Academic Office</p>
            </div>

            <div class="nav-links">

                <a class="dashboard-link" href="<?= BASE_URL ?>/academic/dashboard.php">
                    Dashboard
                </a>

                <a class="logout-link" href="<?= BASE_URL ?>/auth/logout.php">
                    Logout
                </a>

            </div>

        </header>


        <section class="page-banner">

            <h1>Result Approval & Publishing</h1>

            <p>
                Review submitted results, approve academic records
                and publish final results for students.
            </p>

        </section>


        <?php if ($successMessage): ?>

        <div class="message success">
            <?= academic_result_escape($successMessage) ?>
        </div>

        <?php endif; ?>


        <?php if ($errorMessage): ?>

        <div class="message error">
            <?= academic_result_escape($errorMessage) ?>
        </div>

        <?php endif; ?>


        <!-- SUMMARY -->

        <section class="summary-grid">

            <div class="summary-card">
                <p>Pending Approval</p>
                <h2><?= number_format($submittedCount) ?></h2>
            </div>

            <div class="summary-card">
                <p>Ready to Publish</p>
                <h2><?= number_format($approvedCount) ?></h2>
            </div>

            <div class="summary-card">
                <p>Total Under Review</p>
                <h2><?= number_format(count($results)) ?></h2>
            </div>

        </section>


        <!-- RESULTS LIST -->

        <section class="content-card">

            <div class="section-heading">
                <h2>Submitted & Approved Results</h2>
            </div>


            <?php if (empty($results)): ?>

            <div class="empty-state">

                <strong>No Results Pending</strong>

                There are currently no submitted or approved results
                waiting for Academic Office action.

            </div>

            <?php else: ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Semester</th>
                            <th>Credit</th>
                            <th>Total Marks</th>
                            <th>Grade</th>
                            <th>GPA</th>
                            <th>Status</th>
                            <th>Review</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($results as $result): ?>

                        <?php
                        $resultId = (int)$result['result_id'];
                        $status = $result['status'];
                        ?>

                        <tr>

                            <td>

                                <div class="student-name">
                                    <?= academic_result_escape($result['student_name']) ?>
                                </div>

                                <div class="sub-text">
                                    ID:
                                    <?= academic_result_escape($result['student_number']) ?>
                                </div>

                            </td>


                            <td>

                                <strong>
                                    <?= academic_result_escape($result['course_code']) ?>
                                </strong>

                                <div class="sub-text">
                                    <?= academic_result_escape($result['course_title']) ?>
                                </div>

                            </td>


                            <td>
                                <?= academic_result_escape($result['semester_name']) ?>
                            </td>


                            <td>
                                <?= number_format((float)$result['credit_hours'], 1) ?>
                            </td>


                            <td>
                                <strong>
                                    <?= number_format((float)$result['total_marks'], 2) ?>
                                </strong>
                                / 100
                            </td>


                            <td>
                                <?= academic_result_escape($result['letter_grade']) ?>
                            </td>


                            <td>
                                <strong>
                                    <?= number_format((float)$result['grade_point'], 2) ?>
                                </strong>
                            </td>


                            <td>

                                <?php if ($status === 'submitted'): ?>

                                <span class="status-badge status-submitted">
                                    Submitted
                                </span>

                                <?php else: ?>

                                <span class="status-badge status-approved">
                                    Approved
                                </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <button type="button" class="details-button"
                                    onclick="toggleMarks('marks-<?= $resultId ?>', this)">

                                    View Marks

                                </button>

                            </td>


                            <td>

                                <?php if ($status === 'submitted'): ?>

                                <form method="POST" class="action-form"
                                    onsubmit="return confirm('Have you reviewed this result and confirmed the marks are correct? Approve this result?');">

                                    <input type="hidden" name="csrf_token"
                                        value="<?= academic_result_escape($csrfToken) ?>">

                                    <input type="hidden" name="result_id" value="<?= $resultId ?>">

                                    <input type="hidden" name="action" value="approve">

                                    <button type="submit" class="action-button approve-button">
                                        Approve
                                    </button>

                                </form>

                                <?php elseif ($status === 'approved'): ?>

                                <form method="POST" class="action-form"
                                    onsubmit="return confirm('Are you sure you want to publish this result? Students will be able to view it.');">

                                    <input type="hidden" name="csrf_token"
                                        value="<?= academic_result_escape($csrfToken) ?>">

                                    <input type="hidden" name="result_id" value="<?= $resultId ?>">

                                    <input type="hidden" name="action" value="publish">

                                    <button type="submit" class="action-button publish-button">
                                        Publish
                                    </button>

                                </form>

                                <?php endif; ?>

                            </td>

                        </tr>


                        <!-- ASSESSMENT DETAILS -->

                        <tr class="marks-detail" id="marks-<?= $resultId ?>">

                            <td colspan="10">

                                <div class="marks-grid">

                                    <?php

                                    $markMap = [];

                                    foreach ($result['assessment_marks'] as $mark) {
                                        $markMap[$mark['assessment_name']] = $mark;
                                    }

                                    foreach ($assessmentOrder as $component):

                                        $componentData = $markMap[$component] ?? null;

                                    ?>

                                    <div class="mark-item">

                                        <span>
                                            <?= academic_result_escape($component) ?>
                                        </span>

                                        <strong>

                                            <?php if (
                                                    $componentData &&
                                                    $componentData['marks_obtained'] !== null
                                                ): ?>

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

            <?php endif; ?>


            <p class="workflow-note">
                <strong>Workflow:</strong>
                Teacher Submitted → Academic Officer Approved →
                Academic Officer Published.
                Only published results are visible to students.
            </p>

        </section>


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