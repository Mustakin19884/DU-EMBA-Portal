<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('teacher');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);

$error = '';
$success = '';

$selectedOffering = (int)($_POST['offering_id'] ?? $_GET['offering_id'] ?? 0);
$selectedAssessment = (int)($_POST['assessment_id'] ?? $_GET['assessment_id'] ?? 0);

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* Get teacher profile */
$stmt = $conn->prepare("
    SELECT id
    FROM teachers
    WHERE user_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    http_response_code(403);
    exit('Teacher profile not found.');
}

$teacherId = (int)$teacher['id'];

/* Get assigned course offerings */
$stmt = $conn->prepare("
    SELECT
        co.id,
        c.course_code,
        c.course_title,
        s.semester_name
    FROM course_offerings co
    INNER JOIN courses c ON c.id = co.course_id
    INNER JOIN semesters s ON s.id = co.semester_id
    WHERE co.teacher_id = ?
    ORDER BY s.start_date DESC, c.course_code
");
$stmt->bind_param('i', $teacherId);
$stmt->execute();
$offerings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$validOfferingIds = array_map(
    static fn($item) => (int)$item['id'],
    $offerings
);

/* Verify selected course belongs to this teacher */
if ($selectedOffering > 0 &&
    !in_array($selectedOffering, $validOfferingIds, true)) {
    $error = 'You are not authorized to access this course.';
    $selectedOffering = 0;
}

/* Load assessment components */
$assessments = [];

if ($selectedOffering > 0 && !$error) {
    $stmt = $conn->prepare("
        SELECT id, assessment_name, max_marks, weight_percentage
        FROM assessments
        WHERE offering_id = ?
        ORDER BY FIELD(
            assessment_name,
            'Mid-1',
            'Mid-2',
            'Final',
            'Attendance',
            'Term Paper',
            'Presentation'
        )
    ");
    $stmt->bind_param('i', $selectedOffering);
    $stmt->execute();
    $assessments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $validAssessmentIds = array_map(
        static fn($item) => (int)$item['id'],
        $assessments
    );

    if ($selectedAssessment > 0 &&
        !in_array($selectedAssessment, $validAssessmentIds, true)) {
        $error = 'Invalid assessment selected.';
        $selectedAssessment = 0;
    }

    if ($selectedAssessment === 0 && $assessments) {
        $selectedAssessment = (int)$assessments[0]['id'];
    }
}

/* Get students registered in selected course */
$students = [];

if ($selectedOffering > 0 && $selectedAssessment > 0 && !$error) {
    $stmt = $conn->prepare("
        SELECT
            cr.id AS registration_id,
            s.student_id,
            u.full_name,
            u.username,
            b.batch_name,
            m.marks_obtained
        FROM course_registrations cr
        INNER JOIN students s ON s.id = cr.student_id
        INNER JOIN users u ON u.id = s.user_id
        LEFT JOIN batches b ON b.id = s.batch_id
        LEFT JOIN marks m
            ON m.registration_id = cr.id
            AND m.assessment_id = ?
        WHERE cr.offering_id = ?
          AND cr.status = 'registered'
        ORDER BY u.full_name
    ");
    $stmt->bind_param('ii', $selectedAssessment, $selectedOffering);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* Save marks */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_marks']) &&
    !$error) {

    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'Your session token is invalid. Please refresh and try again.';
    } elseif (!$selectedOffering || !$selectedAssessment) {
        $error = 'Please select a course and assessment.';
    } elseif (!$assessments) {
        $error = 'No assessment components are configured for this course.';
    } else {
        $assessmentInfo = null;

        foreach ($assessments as $item) {
            if ((int)$item['id'] === $selectedAssessment) {
                $assessmentInfo = $item;
                break;
            }
        }

        if (!$assessmentInfo) {
            $error = 'Invalid assessment selected.';
        } else {
            $maxMarks = (float)$assessmentInfo['max_marks'];
            $submittedMarks = $_POST['marks'] ?? [];

            if (!is_array($submittedMarks)) {
                $error = 'Invalid marks submission.';
            } else {
                /* Only allow registration IDs from this course */
                $allowedRegistrations = [];

                foreach ($students as $student) {
                    $allowedRegistrations[(int)$student['registration_id']] = true;
                }

                $validatedMarks = [];

                foreach ($submittedMarks as $registrationId => $rawMarks) {
                    $registrationId = (int)$registrationId;

                    if (!isset($allowedRegistrations[$registrationId])) {
                        $error = 'Invalid student registration detected.';
                        break;
                    }

                    /* Blank marks are left unchanged */
                    if ($rawMarks === '' || $rawMarks === null) {
                        continue;
                    }

                    if (!is_numeric($rawMarks)) {
                        $error = 'Please enter valid numeric marks.';
                        break;
                    }

                    $markValue = (float)$rawMarks;

                    if (!is_finite($markValue) ||
                        $markValue < 0 ||
                        $markValue > $maxMarks) {
                        $error = 'Marks must be between 0 and ' .
                            number_format($maxMarks, 2) . '.';
                        break;
                    }

                    $validatedMarks[] = [
                        'registration_id' => $registrationId,
                        'marks' => $markValue
                    ];
                }

                if (!$error && !$validatedMarks) {
                    $error = 'Please enter marks for at least one student.';
                }

                if (!$error) {
                    try {
                        $conn->begin_transaction();

                        $saveStmt = $conn->prepare("
                            INSERT INTO marks
                                (assessment_id, registration_id, marks_obtained, entered_by)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                marks_obtained = VALUES(marks_obtained),
                                entered_by = VALUES(entered_by),
                                updated_at = CURRENT_TIMESTAMP
                        ");

                        foreach ($validatedMarks as $entry) {
                            $registrationId = (int)$entry['registration_id'];
                            $markValue = (float)$entry['marks'];

                            $saveStmt->bind_param(
                                'iidi',
                                $selectedAssessment,
                                $registrationId,
                                $markValue,
                                $userId
                            );

                            $saveStmt->execute();
                        }

                        $saveStmt->close();
                        $conn->commit();

                        $success = 'Student marks saved successfully.';

                        /* Refresh student marks after saving */
                        $stmt = $conn->prepare("
                            SELECT
                                cr.id AS registration_id,
                                s.student_id,
                                u.full_name,
                                u.username,
                                b.batch_name,
                                m.marks_obtained
                            FROM course_registrations cr
                            INNER JOIN students s ON s.id = cr.student_id
                            INNER JOIN users u ON u.id = s.user_id
                            LEFT JOIN batches b ON b.id = s.batch_id
                            LEFT JOIN marks m
                                ON m.registration_id = cr.id
                                AND m.assessment_id = ?
                            WHERE cr.offering_id = ?
                              AND cr.status = 'registered'
                            ORDER BY u.full_name
                        ");
                        $stmt->bind_param(
                            'ii',
                            $selectedAssessment,
                            $selectedOffering
                        );
                        $stmt->execute();
                        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();

                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $csrfToken = $_SESSION['csrf_token'];

                    } catch (Throwable $e) {
                        $conn->rollback();
                        error_log($e->getMessage());
                        $error = 'Unable to save marks. Please try again.';
                    }
                }
            }
        }
    }
}

$selectedAssessmentInfo = null;

foreach ($assessments as $item) {
    if ((int)$item['id'] === $selectedAssessment) {
        $selectedAssessmentInfo = $item;
        break;
    }
}

$pageTitle = 'Marks Entry';

require_once __DIR__ . '/../includes/teacher-header.php';
?>

<div class="admin-page-heading">
    <div>
        <h1>Marks Entry</h1>
        <p>Enter and update individual student assessment marks.</p>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<!-- Course and assessment selection -->
<div class="admin-card" style="margin-bottom:22px;">
    <h3 style="margin-top:0;">Select Course and Assessment</h3>

    <form method="GET" action="">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:18px;">

            <div class="form-group">
                <label for="offering_id">Assigned Course</label>
                <select name="offering_id" id="offering_id" class="form-control"
                    onchange="this.form.querySelector('[name=assessment_id]').value=''; this.form.submit()" required>
                    <option value="">Select Course</option>

                    <?php foreach ($offerings as $offering): ?>
                    <option value=" <?= (int)$offering['id'] ?>"
                        <?= $selectedOffering === (int)$offering['id'] ? 'selected' : '' ?>>
                        <?= e($offering['course_code']) ?> -
                        <?= e($offering['course_title']) ?> |
                        <?= e($offering['semester_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="assessment_id">Assessment Component</label>
                <select name="assessment_id" id="assessment_id" class="form-control" onchange="this.form.submit()"
                    <?= !$assessments ? 'disabled' : '' ?> required>
                    <?php if (!$assessments): ?>
                    <option value="">No assessments available</option>
                    <?php else: ?>
                    <?php foreach ($assessments as $assessment): ?>
                    <option value="<?= (int)$assessment['id'] ?>"
                        <?= $selectedAssessment === (int)$assessment['id'] ? 'selected' : '' ?>>
                        <?= e($assessment['assessment_name']) ?>
                        (<?= number_format((float)$assessment['max_marks'], 0) ?> Marks)
                    </option>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

        </div>
    </form>
</div>

<?php if ($selectedOffering && !$error && !$assessments): ?>

<div class="admin-card">
    <p>No assessment components found. Open the Assessment Structure page
        first to configure the fixed six components for this course.</p>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/teacher/assessments.php?offering_id=<?= $selectedOffering ?>">
        Configure Assessment Structure
    </a>
</div>

<?php elseif ($selectedAssessmentInfo && $selectedOffering): ?>


<div class="admin-card" style="margin-bottom:22px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h3 style="margin:0 0 5px;">
                <?= e($selectedAssessmentInfo['assessment_name']) ?>
            </h3>
            <p style="margin:0;color:#687789;">
                Maximum Marks:
                <strong><?= number_format((float)$selectedAssessmentInfo['max_marks'], 2) ?></strong>
                &nbsp; | &nbsp;
                Weight:
                <strong><?= number_format((float)$selectedAssessmentInfo['weight_percentage'], 0) ?>%</strong>
            </p>
        </div>
        <span class="badge badge-info">
            <?= count($students) ?> Registered Students
        </span>
    </div>
</div>

<div class="admin-card">
    <?php if (!$students): ?>

    <p>No registered students found for this course.</p>

    <?php else: ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="offering_id" value="<?= $selectedOffering ?>">
        <input type="hidden" name="assessment_id" value="<?= $selectedAssessment ?>">

        <div
            style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
            <div>
                <h3 style="margin:0;">Student Marks Sheet</h3>
                <p style="margin:5px 0 0;color:#687789;font-size:13px;">
                    Enter marks between 0 and
                    <?= number_format((float)$selectedAssessmentInfo['max_marks'], 2) ?>.
                    Leave blank to keep existing marks unchanged.
                </p>
            </div>

            <button type="submit" name="save_marks" value="1" class="btn btn-primary">
                Save / Update Marks
            </button>
        </div>

        <div style="overflow-x:auto;">
            <table class="admin-table" style="width:100%;min-width:750px;">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Batch</th>
                        <th style="width:180px;">
                            Marks / <?= number_format((float)$selectedAssessmentInfo['max_marks'], 0) ?>
                        </th>
                        <th style="width:130px;">Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($students as $index => $student): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>

                        <td>
                            <strong><?= e($student['student_id']) ?></strong>
                        </td>

                        <td>
                            <?= e($student['full_name']) ?>
                            <div style="font-size:12px;color:#687789;">
                                <?= e($student['username']) ?>
                            </div>
                        </td>

                        <td><?= e($student['batch_name'] ?? '—') ?></td>

                        <td>
                            <input type="number" name="marks[<?= (int)$student['registration_id'] ?>]"
                                class="form-control" min="0"
                                max="<?= e((string)$selectedAssessmentInfo['max_marks']) ?>" step="0.01" value="<?= $student['marks_obtained'] !== null
                                                ? e((string)$student['marks_obtained'])
                                                : '' ?>" placeholder="Enter marks" style="min-width:120px;">
                        </td>

                        <td>
                            <?php if ($student['marks_obtained'] !== null): ?>
                            <span class="badge badge-success">Entered</span>
                            <?php else: ?>
                            <span class="badge badge-warning">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:22px;text-align:right;">
            <button type="submit" name="save_marks" value="1" class="btn btn-primary">
                Save / Update Marks
            </button>
        </div>
    </form>

    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/teacher-footer.php'; ?>