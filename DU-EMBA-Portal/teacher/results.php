<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('teacher');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$selectedOffering = (int)($_POST['offering_id'] ?? $_GET['offering_id'] ?? 0);

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Grade Calculation
|--------------------------------------------------------------------------
*/
function calculateGrade(float $marks): array
{
    if ($marks >= 80) return ['A+', 4.00];
    if ($marks >= 75) return ['A', 3.75];
    if ($marks >= 70) return ['A-', 3.50];
    if ($marks >= 65) return ['B+', 3.25];
    if ($marks >= 60) return ['B', 3.00];
    if ($marks >= 55) return ['B-', 2.75];
    if ($marks >= 50) return ['C+', 2.50];
    if ($marks >= 45) return ['C', 2.25];
    if ($marks >= 40) return ['D', 2.00];

    return ['F', 0.00];
}

/*
|--------------------------------------------------------------------------
| CSRF Protection
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Teacher Profile
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Assigned Courses
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT
        co.id,
        c.course_code,
        c.course_title,
        c.credit_hours,
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

/*
|--------------------------------------------------------------------------
| Validate Selected Course
|--------------------------------------------------------------------------
*/
if (
    $selectedOffering &&
    !in_array($selectedOffering, $validOfferingIds, true)
) {
    $error = 'You are not authorized to access this course.';
    $selectedOffering = 0;
}

/*
|--------------------------------------------------------------------------
| Selected Course Information
|--------------------------------------------------------------------------
*/
$selectedCourse = null;

foreach ($offerings as $offering) {
    if ((int)$offering['id'] === $selectedOffering) {
        $selectedCourse = $offering;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Check Assessment Structure
|--------------------------------------------------------------------------
*/
if ($selectedOffering && !$error) {

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM assessments
        WHERE offering_id = ?
          AND assessment_name IN (
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

    $assessmentCount = (int)$stmt->get_result()->fetch_assoc()['total'];

    $stmt->close();

    if ($assessmentCount !== 6) {
        $error = 'The six assessment components are not configured. Please visit Assessment Structure first.';
    }
}

/*
|--------------------------------------------------------------------------
| Load Registered Students and Marks
|--------------------------------------------------------------------------
| Latest result record is used if duplicate result records already exist.
|--------------------------------------------------------------------------
*/
$students = [];

if ($selectedOffering && !$error) {

    $stmt = $conn->prepare("
        SELECT
            cr.id AS registration_id,
            s.student_id,
            u.full_name,
            u.username,
            b.batch_name,

            COUNT(DISTINCT a.id) AS total_components,

            COUNT(DISTINCT CASE
                WHEN m.id IS NOT NULL THEN a.id
            END) AS completed_components,

            COALESCE(SUM(m.marks_obtained), 0) AS total_marks,

            r.id AS result_id,
            r.letter_grade,
            r.grade_point,
            r.status AS result_status

        FROM course_registrations cr

        INNER JOIN students s
            ON s.id = cr.student_id

        INNER JOIN users u
            ON u.id = s.user_id

        LEFT JOIN batches b
            ON b.id = s.batch_id

        INNER JOIN assessments a
            ON a.offering_id = cr.offering_id
            AND a.assessment_name IN (
                'Mid-1',
                'Mid-2',
                'Final',
                'Attendance',
                'Term Paper',
                'Presentation'
            )

        LEFT JOIN marks m
            ON m.assessment_id = a.id
            AND m.registration_id = cr.id

        LEFT JOIN (
            SELECT r1.*
            FROM results r1
            INNER JOIN (
                SELECT
                    registration_id,
                    MAX(id) AS latest_id
                FROM results
                GROUP BY registration_id
            ) latest
                ON latest.latest_id = r1.id
        ) r
            ON r.registration_id = cr.id

        WHERE cr.offering_id = ?
          AND cr.status = 'registered'

        GROUP BY
            cr.id,
            s.student_id,
            u.full_name,
            u.username,
            b.batch_name,
            r.id,
            r.letter_grade,
            r.grade_point,
            r.status

        ORDER BY u.full_name
    ");

    $stmt->bind_param('i', $selectedOffering);
    $stmt->execute();

    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Validate POST Requests
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['save_results']) &&
        !isset($_POST['submit_results'])
    ) {
        $error = 'Invalid request.';
    }

    if (!$error && !hash_equals(
        $csrfToken,
        (string)($_POST['csrf_token'] ?? '')
    )) {
        $error = 'Invalid session token. Please refresh the page.';
    }

    if (!$error && (!$selectedOffering || !$selectedCourse)) {
        $error = 'Please select a valid assigned course.';
    }
}

/*
|--------------------------------------------------------------------------
| Calculate & Save Draft Results
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_results']) &&
    !$error
) {

    if (!$students) {
        $error = 'No registered students found.';
    } else {

        try {
            $conn->begin_transaction();

            $savedCount = 0;
            $skippedCount = 0;

            foreach ($students as $student) {

                $complete =
                    (int)$student['total_components'] === 6 &&
                    (int)$student['completed_components'] === 6;

                if (!$complete) {
                    $skippedCount++;
                    continue;
                }

                $registrationId = (int)$student['registration_id'];
                $totalMarks = (float)$student['total_marks'];

                [$letterGrade, $gradePoint] =
                    calculateGrade($totalMarks);

                $status = $student['result_status'] ?? '';

                // Never modify submitted, approved or published results.
                if (in_array(
                    $status,
                    ['submitted', 'approved', 'published'],
                    true
                )) {
                    $skippedCount++;
                    continue;
                }

                if ($status === 'draft' && !empty($student['result_id'])) {

                    $resultId = (int)$student['result_id'];

                    $update = $conn->prepare("
                        UPDATE results
                        SET
                            total_marks = ?,
                            letter_grade = ?,
                            grade_point = ?,
                            status = 'draft'
                        WHERE id = ?
                          AND registration_id = ?
                          AND status = 'draft'
                    ");

                    $update->bind_param(
                        'dsdii',
                        $totalMarks,
                        $letterGrade,
                        $gradePoint,
                        $resultId,
                        $registrationId
                    );

                    $update->execute();
                    $update->close();

                    $savedCount++;

                } elseif ($status === '') {

                    $insert = $conn->prepare("
                        INSERT INTO results (
                            registration_id,
                            total_marks,
                            letter_grade,
                            grade_point,
                            status
                        )
                        VALUES (?, ?, ?, ?, 'draft')
                    ");

                    $insert->bind_param(
                        'idsd',
                        $registrationId,
                        $totalMarks,
                        $letterGrade,
                        $gradePoint
                    );

                    $insert->execute();
                    $insert->close();

                    $savedCount++;

                } else {
                    $skippedCount++;
                }
            }

            $conn->commit();

            header(
                'Location: ' . BASE_URL .
                '/teacher/results.php?offering_id=' .
                $selectedOffering .
                '&saved=' . $savedCount .
                '&skipped=' . $skippedCount
            );

            exit;

        } catch (Throwable $e) {

            $conn->rollback();

            error_log($e->getMessage());

            $error = 'Unable to save results. Please check the database and try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Submit Results to Academic Office
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_results']) &&
    !$error
) {

    if (!$students) {
        $error = 'No registered students found.';
    } else {

        // All students must have all six assessment marks.
        $incompleteStudents = [];

        foreach ($students as $student) {

            if (
                (int)$student['total_components'] !== 6 ||
                (int)$student['completed_components'] !== 6
            ) {
                $incompleteStudents[] = $student['student_id'];
            }
        }

        if ($incompleteStudents) {

            $error = 'Cannot submit results. Incomplete marks found for: ' .
                implode(', ', $incompleteStudents);

        } else {

            try {
                $conn->begin_transaction();

                $submittedCount = 0;

                foreach ($students as $student) {

                    $registrationId = (int)$student['registration_id'];
                    $totalMarks = (float)$student['total_marks'];

                    [$letterGrade, $gradePoint] =
                        calculateGrade($totalMarks);

                    $status = $student['result_status'] ?? '';

                    // Do not modify already submitted or finalized results.
                    if (in_array(
                        $status,
                        ['submitted', 'approved', 'published'],
                        true
                    )) {
                        continue;
                    }

                    if ($status === 'draft' && !empty($student['result_id'])) {

                        $resultId = (int)$student['result_id'];

                        $update = $conn->prepare("
                            UPDATE results
                            SET
                                total_marks = ?,
                                letter_grade = ?,
                                grade_point = ?,
                                status = 'submitted'
                            WHERE id = ?
                              AND registration_id = ?
                              AND status = 'draft'
                        ");

                        $update->bind_param(
                            'dsdii',
                            $totalMarks,
                            $letterGrade,
                            $gradePoint,
                            $resultId,
                            $registrationId
                        );

                        $update->execute();

                        if ($update->affected_rows === 1) {
                            $submittedCount++;
                        }

                        $update->close();

                    } elseif ($status === '') {

                        $insert = $conn->prepare("
                            INSERT INTO results (
                                registration_id,
                                total_marks,
                                letter_grade,
                                grade_point,
                                status
                            )
                            VALUES (?, ?, ?, ?, 'submitted')
                        ");

                        $insert->bind_param(
                            'idsd',
                            $registrationId,
                            $totalMarks,
                            $letterGrade,
                            $gradePoint
                        );

                        $insert->execute();
                        $insert->close();

                        $submittedCount++;
                    }
                }

                $conn->commit();

                header(
                    'Location: ' . BASE_URL .
                    '/teacher/results.php?offering_id=' .
                    $selectedOffering .
                    '&submitted=' . $submittedCount
                );

                exit;

            } catch (Throwable $e) {

                $conn->rollback();

                error_log($e->getMessage());

                $error = 'Unable to submit results. Please try again.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success Messages
|--------------------------------------------------------------------------
*/
if (isset($_GET['saved'])) {

    $success = (int)$_GET['saved'] .
        ' student result(s) saved as Draft.';

    if (isset($_GET['skipped']) && (int)$_GET['skipped'] > 0) {
        $success .= ' ' . (int)$_GET['skipped'] .
            ' student result(s) were skipped because marks were incomplete or results were already submitted.';
    }
}

if (isset($_GET['submitted'])) {

    $success = (int)$_GET['submitted'] .
        ' student result(s) submitted to Academic Office.';

    if ((int)$_GET['submitted'] === 0) {
        $success = 'No new results were submitted. Results may already have been submitted or finalized.';
    }
}

/*
|--------------------------------------------------------------------------
| Page Layout
|--------------------------------------------------------------------------
*/
$pageTitle = 'Result Calculation';

require_once __DIR__ . '/../includes/teacher-header.php';
?>

<div class="admin-page-heading">
    <div>
        <h1>Result Calculation</h1>
        <p>Calculate total marks, letter grade and grade point.</p>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <?= e($error) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    <?= e($success) ?>
</div>
<?php endif; ?>

<!-- Course Selection -->
<div class="admin-card" style="margin-bottom:22px;">
    <h3 style="margin-top:0;">Select Course</h3>

    <form method="GET">
        <div class="form-group">
            <label for="offering_id">Assigned Course</label>

            <select name="offering_id" id="offering_id" class="form-control" onchange="this.form.submit()" required>
                <option value="">Select Course</option>

                <?php foreach ($offerings as $offering): ?>
                <option value="<?= (int)$offering['id'] ?>"
                    <?= $selectedOffering === (int)$offering['id'] ? 'selected' : '' ?>>
                    <?= e($offering['course_code']) ?> -
                    <?= e($offering['course_title']) ?>
                    (<?= e($offering['semester_name']) ?>)
                </option>
                <?php endforeach; ?>

            </select>
        </div>
    </form>
</div>

<?php if ($selectedCourse && !$error): ?>

<!-- Selected Course -->
<div class="admin-card" style="margin-bottom:22px;">
    <h3 style="margin-top:0;">
        <?= e($selectedCourse['course_code']) ?> -
        <?= e($selectedCourse['course_title']) ?>
    </h3>

    <p style="margin-bottom:0;">
        Semester: <?= e($selectedCourse['semester_name']) ?>
        &nbsp; | &nbsp;
        Credit Hours: <?= e((string)$selectedCourse['credit_hours']) ?>
        &nbsp; | &nbsp;
        Maximum Marks: 100
    </p>
</div>

<!-- Grade Scale -->
<div class="admin-card" style="margin-bottom:22px;">
    <h3 style="margin-top:0;">Grade Scale</h3>

    <div style="overflow-x:auto;">
        <table class="admin-table" style="min-width:650px;width:100%;">
            <thead>
                <tr>
                    <th>Marks Range</th>
                    <th>Letter Grade</th>
                    <th>Grade Point</th>
                </tr>
            </thead>

            <tbody>
                <?php
                    $gradeScale = [
                        ['80–100', 'A+', '4.00'],
                        ['75–79', 'A', '3.75'],
                        ['70–74', 'A-', '3.50'],
                        ['65–69', 'B+', '3.25'],
                        ['60–64', 'B', '3.00'],
                        ['55–59', 'B-', '2.75'],
                        ['50–54', 'C+', '2.50'],
                        ['45–49', 'C', '2.25'],
                        ['40–44', 'D', '2.00'],
                        ['0–39', 'F', '0.00']
                    ];

                    foreach ($gradeScale as $grade):
                    ?>
                <tr>
                    <td><?= $grade[0] ?></td>
                    <td><strong><?= $grade[1] ?></strong></td>
                    <td><?= $grade[2] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Student Results -->
<div class="admin-card">

    <div style="margin-bottom:18px;">
        <h3 style="margin:0;">Student Results</h3>
        <p style="margin:5px 0 0;color:#687789;">
            Results are calculated after all six assessment components are marked.
        </p>
    </div>

    <?php if (!$students): ?>

    <p>No registered students found for this course.</p>

    <?php else: ?>

    <?php
            $allMarksComplete = !empty($students);
            $hasUnsubmittedResults = false;

            foreach ($students as $student) {

                if (
                    (int)$student['total_components'] !== 6 ||
                    (int)$student['completed_components'] !== 6
                ) {
                    $allMarksComplete = false;
                }

                $status = $student['result_status'] ?? '';

                if (!in_array(
                    $status,
                    ['submitted', 'approved', 'published'],
                    true
                )) {
                    $hasUnsubmittedResults = true;
                }
            }
            ?>

    <form method="POST">

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <input type="hidden" name="offering_id" value="<?= $selectedOffering ?>">

        <div style="overflow-x:auto;">
            <table class="admin-table" style="width:100%;min-width:950px;">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Batch</th>
                        <th>Marks Status</th>
                        <th>Total / 100</th>
                        <th>Grade</th>
                        <th>GPA</th>
                        <th>Result Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($students as $index => $student): ?>

                    <?php
                                $complete =
                                    (int)$student['total_components'] === 6 &&
                                    (int)$student['completed_components'] === 6;

                                $total = (float)$student['total_marks'];

                                [$grade, $point] = calculateGrade($total);

                                $status = $student['result_status'] ?? 'pending';
                                ?>

                    <tr>
                        <td><?= $index + 1 ?></td>

                        <td>
                            <strong>
                                <?= e($student['student_id']) ?>
                            </strong>
                        </td>

                        <td>
                            <?= e($student['full_name']) ?>
                        </td>

                        <td>
                            <?= e($student['batch_name'] ?? '—') ?>
                        </td>

                        <td>
                            <?php if ($complete): ?>
                            <span class="badge badge-success">
                                Completed
                            </span>
                            <?php else: ?>
                            <span class="badge badge-warning">
                                <?= (int)$student['completed_components'] ?>/6 Completed
                            </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= $complete
                                            ? number_format($total, 2)
                                            : '—' ?>
                        </td>

                        <td>
                            <?= $complete ? e($grade) : '—' ?>
                        </td>

                        <td>
                            <?= $complete
                                            ? number_format($point, 2)
                                            : '—' ?>
                        </td>

                        <td>
                            <?= e(ucfirst($status)) ?>
                        </td>
                    </tr>

                    <?php endforeach; ?>

                </tbody>
            </table>
        </div>

        <!-- Result Actions -->
        <div style="margin-top:22px;display:flex;gap:12px;flex-wrap:wrap;">

            <button type="submit" name="save_results" value="1" class="btn btn-primary"
                <?= !$students ? 'disabled' : '' ?>>
                Calculate & Save Draft Results
            </button>

            <button type="submit" name="submit_results" value="1" class="btn btn-primary"
                style="background:#198754;border-color:#198754;"
                <?= (!$allMarksComplete || !$hasUnsubmittedResults) ? 'disabled' : '' ?>
                onclick="return confirm('Submit all completed student results to Academic Office for approval?');">
                Submit for Approval
            </button>

        </div>

        <?php if (!$allMarksComplete): ?>

        <p style="color:#dc3545;margin-top:14px;">
            Complete all six assessment marks for every registered student before submitting results.
        </p>

        <?php elseif (!$hasUnsubmittedResults): ?>

        <p style="color:#198754;margin-top:14px;">
            All results have already been submitted or finalized.
        </p>

        <?php endif; ?>

    </form>

    <?php endif; ?>

</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/teacher-footer.php'; ?>