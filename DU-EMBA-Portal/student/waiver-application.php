<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Prerequisite Waiver Application';

$userId = (int)($_SESSION['user_id'] ?? 0);
$studentName = $_SESSION['full_name'] ?? 'Student';

$baseUrl = defined('BASE_URL') ? BASE_URL : '';

function waiver_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function waiver_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function waiver_flash(string $type, string $message): void
{
    $_SESSION['waiver_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function waiver_get_student(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, student_id
         FROM students
         WHERE user_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $student = $result->fetch_assoc() ?: null;

    $stmt->close();

    return $student;
}

$student = waiver_get_student($conn, $userId);

if (!$student) {
    die('Student profile not found.');
}

$studentDbId = (int)$student['id'];

if (empty($_SESSION['waiver_csrf_token'])) {
    $_SESSION['waiver_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['waiver_csrf_token'];

$flash = $_SESSION['waiver_flash'] ?? null;
unset($_SESSION['waiver_flash']);

$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Fetch prerequisite courses
|--------------------------------------------------------------------------
*/

$prerequisiteCourses = [];

$stmt = $conn->prepare(
    "SELECT c.id, c.course_code, c.course_title, c.credit_hours
     FROM courses c
     INNER JOIN course_categories cc ON cc.id = c.category_id
     WHERE c.is_active = 1
       AND LOWER(cc.category_name) = 'prerequisite'
       AND cc.is_active = 1
     ORDER BY c.course_code"
);

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $prerequisiteCourses[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Submit waiver application
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage = 'Invalid session request. Please refresh and try again.';
    } else {

        $courseId = (int)($_POST['course_id'] ?? 0);
        $applicationReason = trim($_POST['application_reason'] ?? '');

        if ($courseId <= 0) {
            $errorMessage = 'Please select a prerequisite course.';
        } elseif (strlen($applicationReason) < 20) {
            $errorMessage = 'Please provide a detailed reason (minimum 20 characters).';
        } elseif (strlen($applicationReason) > 10000) {
            $errorMessage = 'Application reason is too long.';
        }

        /*
        |--------------------------------------------------------------------------
        | Validate selected prerequisite course
        |--------------------------------------------------------------------------
        */

        $selectedCourse = null;

        if (!$errorMessage) {
            $stmt = $conn->prepare(
                "SELECT c.id, c.course_code, c.course_title, c.credit_hours
                 FROM courses c
                 INNER JOIN course_categories cc ON cc.id = c.category_id
                 WHERE c.id = ?
                   AND c.is_active = 1
                   AND LOWER(cc.category_name) = 'prerequisite'
                   AND cc.is_active = 1
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $courseId);
                $stmt->execute();

                $selectedCourse = $stmt->get_result()->fetch_assoc();

                $stmt->close();
            }

            if (!$selectedCourse) {
                $errorMessage = 'The selected course is not a valid active prerequisite course.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate optional document
        |--------------------------------------------------------------------------
        */

        $uploadedRelativePath = null;
        $uploadedAbsolutePath = null;

        if (
            !$errorMessage &&
            isset($_FILES['supporting_document']) &&
            $_FILES['supporting_document']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            $file = $_FILES['supporting_document'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'Document upload failed. Please try again.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errorMessage = 'Maximum document size is 5 MB.';
            } else {

                $allowedMimeTypes = [
                    'application/pdf' => 'pdf',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png'
                ];

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);

                if (!isset($allowedMimeTypes[$mimeType])) {
                    $errorMessage = 'Only PDF, JPG and PNG files are allowed.';
                } else {

                    $extension = $allowedMimeTypes[$mimeType];

                    $uploadDirectory = __DIR__ . '/../uploads/waivers/';

                    if (!is_dir($uploadDirectory)) {
                        mkdir($uploadDirectory, 0755, true);
                    }

                    $randomFileName = bin2hex(random_bytes(16)) . '.' . $extension;

                    $uploadedAbsolutePath = $uploadDirectory . $randomFileName;

                    $uploadedRelativePath = 'uploads/waivers/' . $randomFileName;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Submit application
        |--------------------------------------------------------------------------
        */

        if (!$errorMessage) {

            $conn->begin_transaction();

            try {

                /*
                 * Lock student row to prevent simultaneous submissions
                 * exceeding the maximum application limit.
                 */

                $lockStmt = $conn->prepare(
                    "SELECT id
                     FROM students
                     WHERE id = ?
                     FOR UPDATE"
                );

                $lockStmt->bind_param('i', $studentDbId);
                $lockStmt->execute();
                $lockStmt->get_result()->fetch_assoc();
                $lockStmt->close();

                /*
                 * Count all applications:
                 * Pending + Approved + Rejected.
                 */

                $countStmt = $conn->prepare(
                    "SELECT COUNT(*) AS total_applications
                     FROM prerequisite_waivers
                     WHERE student_id = ?"
                );

                $countStmt->bind_param('i', $studentDbId);
                $countStmt->execute();

                $applicationCount = (int)(
                    $countStmt->get_result()->fetch_assoc()['total_applications'] ?? 0
                );

                $countStmt->close();

                if ($applicationCount >= 4) {
                    throw new Exception(
                        'You have reached the maximum limit of 4 waiver applications.'
                    );
                }

                /*
                 * Prevent duplicate application for the same course.
                 */

                $duplicateStmt = $conn->prepare(
                    "SELECT id
                     FROM prerequisite_waivers
                     WHERE student_id = ?
                       AND course_id = ?
                     LIMIT 1"
                );

                $duplicateStmt->bind_param(
                    'ii',
                    $studentDbId,
                    $courseId
                );

                $duplicateStmt->execute();

                $duplicate = $duplicateStmt->get_result()->fetch_assoc();

                $duplicateStmt->close();

                if ($duplicate) {
                    throw new Exception(
                        'You have already submitted an application for this course.'
                    );
                }

                /*
                 * Move optional file into upload directory.
                 */

                if ($uploadedAbsolutePath !== null) {

                    if (
                        !move_uploaded_file(
                            $_FILES['supporting_document']['tmp_name'],
                            $uploadedAbsolutePath
                        )
                    ) {
                        throw new Exception(
                            'Unable to save the uploaded document.'
                        );
                    }
                }

                /*
                 * Insert application.
                 */

                $insertStmt = $conn->prepare(
                    "INSERT INTO prerequisite_waivers
                        (
                            student_id,
                            course_id,
                            application_reason,
                            supporting_document,
                            status
                        )
                     VALUES (?, ?, ?, ?, 'pending')"
                );

                $insertStmt->bind_param(
                    'iiss',
                    $studentDbId,
                    $courseId,
                    $applicationReason,
                    $uploadedRelativePath
                );

                $insertStmt->execute();
                $insertStmt->close();

                $conn->commit();

                waiver_flash(
                    'success',
                    'Your prerequisite waiver application has been submitted successfully.'
                );

                waiver_redirect(
                    $baseUrl . '/student/waiver-application.php'
                );

            } catch (Throwable $e) {

                $conn->rollback();

                /*
                 * Remove uploaded file if the database operation failed.
                 */

                if (
                    $uploadedAbsolutePath !== null &&
                    file_exists($uploadedAbsolutePath)
                ) {
                    unlink($uploadedAbsolutePath);
                }

                $errorMessage = $e->getMessage();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch student's submitted applications
|--------------------------------------------------------------------------
*/

$myApplications = [];

$stmt = $conn->prepare(
    "SELECT
        w.id,
        w.course_id,
        w.application_reason,
        w.supporting_document,
        w.status,
        w.review_note,
        w.created_at,
        w.reviewed_at,
        c.course_code,
        c.course_title,
        c.credit_hours,
        reviewer.full_name AS reviewer_name
     FROM prerequisite_waivers w
     INNER JOIN courses c ON c.id = w.course_id
     LEFT JOIN users reviewer ON reviewer.id = w.reviewed_by
     WHERE w.student_id = ?
     ORDER BY w.created_at DESC"
);

if ($stmt) {
    $stmt->bind_param('i', $studentDbId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $myApplications[] = $row;
    }

    $stmt->close();
}

$totalApplications = count($myApplications);
$remainingApplications = max(0, 4 - $totalApplications);

$appliedCourseIds = [];

foreach ($myApplications as $application) {
    $appliedCourseIds[] = (int)$application['course_id'];
}

function waiver_status_badge(string $status): string
{
    $status = strtolower($status);

    if ($status === 'approved') {
        return '<span class="status-badge approved">Approved</span>';
    }

    if ($status === 'rejected') {
        return '<span class="status-badge rejected">Rejected</span>';
    }

    return '<span class="status-badge pending">Pending</span>';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= waiver_escape($pageTitle) ?></title>

    <style>
    :root {
        --navy: #102d50;
        --navy-light: #1c456e;
        --beige: #f4efe5;
        --text: #263548;
        --muted: #687789;
        --border: #e1e5eb;
        --white: #ffffff;
        --green: #198754;
        --red: #dc3545;
        --orange: #d97706;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: Arial, Helvetica, sans-serif;
        background: #f5f7fa;
        color: var(--text);
    }

    .page-container {
        max-width: 1150px;
        margin: 35px auto;
        padding: 0 20px 40px;
    }

    .top-bar {
        background: var(--navy);
        color: white;
        border-radius: 12px;
        padding: 22px 26px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 25px;
    }

    .top-bar h1 {
        font-size: 23px;
        margin: 0 0 8px;
    }

    .top-bar p {
        margin: 0;
        color: #d8e3ef;
        font-size: 14px;
    }

    .back-button {
        display: inline-block;
        background: white;
        color: var(--navy);
        text-decoration: none;
        font-weight: bold;
        padding: 10px 16px;
        border-radius: 7px;
    }

    .card {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 24px;
        box-shadow: 0 3px 12px rgba(16, 45, 80, 0.04);
    }

    .card h2 {
        margin: 0 0 8px;
        font-size: 20px;
        color: var(--navy);
    }

    .card-description {
        color: var(--muted);
        font-size: 14px;
        margin-bottom: 22px;
        line-height: 1.6;
    }

    .application-limit {
        display: flex;
        gap: 18px;
        flex-wrap: wrap;
        margin-bottom: 22px;
    }

    .limit-box {
        background: var(--beige);
        border-radius: 9px;
        padding: 15px 20px;
        min-width: 145px;
    }

    .limit-box strong {
        display: block;
        font-size: 24px;
        color: var(--navy);
        margin-bottom: 5px;
    }

    .limit-box span {
        color: var(--muted);
        font-size: 13px;
    }

    .alert {
        padding: 14px 17px;
        border-radius: 8px;
        margin-bottom: 20px;
        line-height: 1.5;
    }

    .alert-success {
        background: #e8f5ec;
        color: #17663a;
        border: 1px solid #b8e0c7;
    }

    .alert-error {
        background: #fdebec;
        color: #a12832;
        border: 1px solid #f2c1c5;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .form-group small {
        display: block;
        margin-top: 6px;
        color: var(--muted);
        font-size: 13px;
    }

    input,
    select,
    textarea {
        width: 100%;
        padding: 12px 13px;
        border: 1px solid #ccd4de;
        border-radius: 7px;
        background: white;
        color: var(--text);
        font-size: 14px;
        font-family: inherit;
    }

    textarea {
        resize: vertical;
        min-height: 150px;
        line-height: 1.6;
    }

    input:focus,
    select:focus,
    textarea:focus {
        outline: none;
        border-color: var(--navy-light);
        box-shadow: 0 0 0 3px rgba(28, 69, 110, 0.10);
    }

    .submit-button {
        background: var(--navy);
        color: white;
        border: none;
        border-radius: 7px;
        padding: 12px 22px;
        cursor: pointer;
        font-size: 14px;
        font-weight: bold;
    }

    .submit-button:hover {
        background: var(--navy-light);
    }

    .submit-button:disabled {
        background: #8b99a9;
        cursor: not-allowed;
    }

    .table-wrapper {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 750px;
    }

    th,
    td {
        text-align: left;
        padding: 13px 12px;
        border-bottom: 1px solid var(--border);
        vertical-align: top;
        font-size: 14px;
    }

    th {
        background: #f5f7fa;
        color: var(--navy);
        font-size: 13px;
    }

    .status-badge {
        display: inline-block;
        border-radius: 20px;
        padding: 6px 11px;
        font-size: 12px;
        font-weight: bold;
    }

    .status-badge.pending {
        background: #fff2d8;
        color: #9a6400;
    }

    .status-badge.approved {
        background: #e4f5eb;
        color: #187244;
    }

    .status-badge.rejected {
        background: #fde8e8;
        color: #b42332;
    }

    .document-link {
        color: var(--navy-light);
        text-decoration: none;
        font-weight: bold;
    }

    .document-link:hover {
        text-decoration: underline;
    }

    .reason-text {
        max-width: 300px;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .review-note {
        background: #f5f7fa;
        border-radius: 6px;
        padding: 10px;
        margin-top: 8px;
        font-size: 13px;
        white-space: pre-wrap;
    }

    .empty-state {
        text-align: center;
        padding: 30px 15px;
        color: var(--muted);
    }

    @media(max-width: 650px) {
        .page-container {
            margin-top: 20px;
            padding: 0 12px 30px;
        }

        .top-bar {
            padding: 18px;
        }

        .top-bar h1 {
            font-size: 20px;
        }

        .card {
            padding: 18px;
        }
    }
    </style>
</head>

<body>

    <div class="page-container">

        <div class="top-bar">
            <div>
                <h1>Prerequisite Waiver Application</h1>
                <p>
                    Student: <?= waiver_escape($studentName) ?>
                    &nbsp;|&nbsp;
                    Student ID: <?= waiver_escape($student['student_id']) ?>
                </p>
            </div>

            <a href="<?= waiver_escape($baseUrl) ?>/student/dashboard.php" class="back-button">
                ← Student Dashboard
            </a>
        </div>

        <?php if ($flash): ?>
        <div class="alert alert-success">
            <?= waiver_escape($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
        <div class="alert alert-error">
            <?= waiver_escape($errorMessage) ?>
        </div>
        <?php endif; ?>

        <div class="card">

            <h2>Application Limit</h2>

            <p class="card-description">
                You may apply for a waiver for a maximum of four prerequisite courses.
                Each course can only be submitted once.
                Applications are subject to Academic Office approval.
            </p>

            <div class="application-limit">

                <div class="limit-box">
                    <strong><?= $totalApplications ?> / 4</strong>
                    <span>Applications Submitted</span>
                </div>

                <div class="limit-box">
                    <strong><?= $remainingApplications ?></strong>
                    <span>Applications Remaining</span>
                </div>

            </div>

            <?php if ($remainingApplications > 0): ?>

            <form method="POST" action="" enctype="multipart/form-data">

                <input type="hidden" name="csrf_token" value="<?= waiver_escape($csrfToken) ?>">

                <div class="form-group">

                    <label for="course_id">
                        Select Prerequisite Course *
                    </label>

                    <select name="course_id" id="course_id" required>

                        <option value="">-- Select a course --</option>

                        <?php foreach ($prerequisiteCourses as $course): ?>

                        <?php
                            $alreadyApplied = in_array(
                                (int)$course['id'],
                                $appliedCourseIds,
                                true
                            );
                            ?>

                        <option value="<?= (int)$course['id'] ?>" <?= $alreadyApplied ? 'disabled' : '' ?>>

                            <?= waiver_escape($course['course_code']) ?>
                            -
                            <?= waiver_escape($course['course_title']) ?>
                            (<?= waiver_escape($course['credit_hours']) ?> Credits)

                            <?= $alreadyApplied ? ' - Already Applied' : '' ?>

                        </option>

                        <?php endforeach; ?>

                    </select>

                    <small>
                        Courses for which you have already applied cannot be selected again.
                    </small>

                </div>

                <div class="form-group">

                    <label for="application_reason">
                        Application Reason *
                    </label>

                    <textarea name="application_reason" id="application_reason" minlength="20" maxlength="10000"
                        required
                        placeholder="Explain why you are requesting a prerequisite course waiver. Mention relevant academic background, previous course completion, professional experience or other supporting reasons."></textarea>

                    <small>
                        Minimum 20 characters. Provide sufficient details for Academic Office review.
                    </small>

                </div>

                <div class="form-group">

                    <label for="supporting_document">
                        Supporting Document (Optional)
                    </label>

                    <input type="file" name="supporting_document" id="supporting_document"
                        accept=".pdf,.jpg,.jpeg,.png">

                    <small>
                        Accepted formats: PDF, JPG and PNG. Maximum size: 5 MB.
                    </small>

                </div>

                <button type="submit" class="submit-button">
                    Submit Waiver Application
                </button>

            </form>

            <?php else: ?>

            <div class="alert alert-error">
                You have reached the maximum limit of four waiver applications.
                You cannot submit another application.
            </div>

            <?php endif; ?>

        </div>

        <div class="card">

            <h2>My Waiver Applications</h2>

            <p class="card-description">
                Review your application history and Academic Office decisions.
            </p>

            <?php if (empty($myApplications)): ?>

            <div class="empty-state">
                You have not submitted any prerequisite waiver applications yet.
            </div>

            <?php else: ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Credits</th>
                            <th>Application Date</th>
                            <th>Status</th>
                            <th>Document</th>
                            <th>Review Information</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($myApplications as $application): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= waiver_escape($application['course_code']) ?>
                                </strong>
                                <br>
                                <?= waiver_escape($application['course_title']) ?>
                            </td>

                            <td>
                                <?= waiver_escape($application['credit_hours']) ?>
                            </td>

                            <td>
                                <?= waiver_escape(
                                    date(
                                        'd M Y',
                                        strtotime($application['created_at'])
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= waiver_status_badge($application['status']) ?>
                            </td>

                            <td>

                                <?php if (!empty($application['supporting_document'])): ?>

                                <a class="document-link"
                                    href="<?= waiver_escape($baseUrl . '/' . $application['supporting_document']) ?>"
                                    target="_blank" rel="noopener">
                                    View Document
                                </a>

                                <?php else: ?>

                                <span style="color:#687789;">
                                    Not submitted
                                </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if (!empty($application['review_note'])): ?>

                                <div class="review-note">
                                    <strong>Review Note:</strong><br>
                                    <?= waiver_escape($application['review_note']) ?>
                                </div>

                                <?php endif; ?>

                                <?php if (!empty($application['reviewer_name'])): ?>

                                <small>
                                    Reviewed by:
                                    <?= waiver_escape($application['reviewer_name']) ?>
                                </small>

                                <br>

                                <?php endif; ?>

                                <?php if (!empty($application['reviewed_at'])): ?>

                                <small>
                                    <?= waiver_escape(
                                            date(
                                                'd M Y, h:i A',
                                                strtotime($application['reviewed_at'])
                                            )
                                        ) ?>
                                </small>

                                <?php endif; ?>

                                <?php if (
                                    empty($application['review_note']) &&
                                    empty($application['reviewed_at'])
                                ): ?>

                                <span style="color:#687789;">
                                    Awaiting review
                                </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <?php endif; ?>

        </div>

    </div>

</body>

</html>