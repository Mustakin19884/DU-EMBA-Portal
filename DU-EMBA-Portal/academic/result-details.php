<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

require_role('academic');

$pageTitle = 'Review Student Result';

$resultId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$resultId) {
    header('Location: ' . BASE_URL . '/academic/results.php');
    exit;
}

// CSRF token
if (empty($_SESSION['academic_csrf'])) {
    $_SESSION['academic_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Approve submitted result
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_result'])) {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['academic_csrf'], $token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {

        $approveStmt = $conn->prepare("
            UPDATE results
            SET status = 'approved'
            WHERE id = ? AND status = 'submitted'
        ");

        if (!$approveStmt) {
            $error = 'Unable to process approval.';
        } else {
            $approveStmt->bind_param('i', $resultId);
            $approveStmt->execute();

            if ($approveStmt->affected_rows === 1) {
                header('Location: ' . BASE_URL . '/academic/results.php?approved=1');
                exit;
            } else {
                $error = 'This result is no longer awaiting approval.';
            }

            $approveStmt->close();
        }
    }
}

// Load result and student information
$detailStmt = $conn->prepare("
    SELECT
        r.id AS result_id,
        r.registration_id,
        r.total_marks,
        r.letter_grade,
        r.grade_point,
        r.status,
        s.student_id,
        u.full_name,
        u.username,
        u.email,
        b.batch_name,
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
    LEFT JOIN batches b
        ON b.id = s.batch_id
    INNER JOIN course_offerings co
        ON co.id = cr.offering_id
    INNER JOIN courses c
        ON c.id = co.course_id
    INNER JOIN semesters sem
        ON sem.id = co.semester_id
    WHERE r.id = ?
    LIMIT 1
");

$detailStmt->bind_param('i', $resultId);
$detailStmt->execute();

$detail = $detailStmt->get_result()->fetch_assoc();
$detailStmt->close();

if (!$detail) {
    header('Location: ' . BASE_URL . '/academic/results.php');
    exit;
}

// Load assessment-wise marks
$marksStmt = $conn->prepare("
    SELECT
        a.assessment_name,
        a.max_marks,
        a.weight_percentage,
        m.marks_obtained
    FROM assessments a
    LEFT JOIN marks m
        ON m.assessment_id = a.id
        AND m.registration_id = ?
    WHERE a.offering_id = (
        SELECT offering_id
        FROM course_registrations
        WHERE id = ?
    )
    ORDER BY a.id ASC
");

$marksStmt->bind_param('ii', $detail['registration_id'], $detail['registration_id']);
$marksStmt->execute();

$marksResult = $marksStmt->get_result();

$assessmentMarks = [];
while ($mark = $marksResult->fetch_assoc()) {
    $assessmentMarks[] = $mark;
}

$marksStmt->close();

require_once __DIR__ . '/../includes/academic-header.php';
?>

<h2>Student Result Review</h2>
<p>Verify the assessment marks and calculated result before approval.</p>

<?php if ($error): ?>
<div style="padding:12px 16px; margin:15px 0; background:#f8d7da; color:#842029; border-radius:7px;">
    <?= e($error) ?>
</div>
<?php endif; ?>

<!-- Student Information -->
<div style="margin-top:22px; padding:22px; border:1px solid #e1e5eb; border-radius:10px;">

    <h3>Student Information</h3>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:18px;">

        <div>
            <small>Student ID</small>
            <h4><?= e($detail['student_id']) ?></h4>
        </div>

        <div>
            <small>Student Name</small>
            <h4><?= e($detail['full_name']) ?></h4>
        </div>

        <div>
            <small>Username</small>
            <h4><?= e($detail['username']) ?></h4>
        </div>

        <div>
            <small>Batch</small>
            <h4><?= e($detail['batch_name'] ?? 'N/A') ?></h4>
        </div>

    </div>
</div>

<!-- Course Information -->
<div style="margin-top:20px; padding:22px; border:1px solid #e1e5eb; border-radius:10px;">

    <h3>Course Information</h3>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:18px;">

        <div>
            <small>Course Code</small>
            <h4><?= e($detail['course_code']) ?></h4>
        </div>

        <div>
            <small>Course Title</small>
            <h4><?= e($detail['course_title']) ?></h4>
        </div>

        <div>
            <small>Credit Hours</small>
            <h4><?= number_format((float)$detail['credit_hours'], 2) ?></h4>
        </div>

        <div>
            <small>Semester</small>
            <h4><?= e($detail['semester_name']) ?></h4>
        </div>

    </div>
</div>

<!-- Assessment Marks -->
<div style="margin-top:20px; padding:22px; border:1px solid #e1e5eb; border-radius:10px;">

    <h3>Assessment-wise Marks</h3>

    <?php if (count($assessmentMarks) > 0): ?>

    <div style="overflow-x:auto; margin-top:15px;">

        <table style="width:100%; border-collapse:collapse; min-width:600px;">

            <thead>
                <tr style="background:#f4efe5; text-align:left;">
                    <th style="padding:13px;">Assessment</th>
                    <th style="padding:13px;">Maximum Marks</th>
                    <th style="padding:13px;">Obtained Marks</th>
                    <th style="padding:13px;">Weight (%)</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($assessmentMarks as $mark): ?>

                <tr style="border-bottom:1px solid #e1e5eb;">
                    <td style="padding:13px;">
                        <?= e($mark['assessment_name']) ?>
                    </td>

                    <td style="padding:13px;">
                        <?= number_format((float)$mark['max_marks'], 2) ?>
                    </td>

                    <td style="padding:13px;">
                        <?php if ($mark['marks_obtained'] === null): ?>
                        <span style="color:#dc3545;">Not entered</span>
                        <?php else: ?>
                        <strong>
                            <?= number_format((float)$mark['marks_obtained'], 2) ?>
                        </strong>
                        <?php endif; ?>
                    </td>

                    <td style="padding:13px;">
                        <?= number_format((float)$mark['weight_percentage'], 2) ?>%
                    </td>
                </tr>

                <?php endforeach; ?>
            </tbody>

        </table>

    </div>

    <?php else: ?>

    <p style="color:#dc3545;">No assessment records found for this course.</p>

    <?php endif; ?>
</div>

<!-- Final Result -->
<div style="margin-top:20px; padding:22px; background:#f4efe5; border-radius:10px;">

    <h3>Calculated Final Result</h3>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:20px; margin-top:18px;">

        <div>
            <small>Total Marks</small>
            <h2><?= number_format((float)$detail['total_marks'], 2) ?></h2>
        </div>

        <div>
            <small>Letter Grade</small>
            <h2><?= e($detail['letter_grade']) ?></h2>
        </div>

        <div>
            <small>Grade Point</small>
            <h2><?= number_format((float)$detail['grade_point'], 2) ?></h2>
        </div>

        <div>
            <small>Result Status</small>
            <h3><?= e(ucfirst($detail['status'])) ?></h3>
        </div>

    </div>
</div>

<!-- Approval Action -->
<div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:25px;">

    <a href="<?= BASE_URL ?>/academic/results.php"
        style="padding:11px 20px; border:1px solid #102d50; color:#102d50; text-decoration:none; border-radius:7px;">
        Back to Results
    </a>

    <?php if ($detail['status'] === 'submitted'): ?>

    <form method="POST" onsubmit="return confirm('Are you sure you want to approve this result?');">

        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['academic_csrf']) ?>">

        <button type="submit" name="approve_result" class="btn-primary">
            Approve Result
        </button>

    </form>

    <?php elseif ($detail['status'] === 'approved'): ?>

    <span style="padding:11px 18px; background:#d1e7dd; color:#0f5132; border-radius:7px;">
        Result Approved
    </span>

    <?php elseif ($detail['status'] === 'published'): ?>

    <span style="padding:11px 18px; background:#d1e7dd; color:#0f5132; border-radius:7px;">
        Result Published
    </span>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/academic-footer.php'; ?>