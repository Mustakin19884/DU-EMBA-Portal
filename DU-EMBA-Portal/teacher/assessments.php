<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('teacher');

$userId = (int)($_SESSION['user_id'] ?? 0);

$components = [
    'Mid-1' => 15,
    'Mid-2' => 15,
    'Final' => 40,
    'Attendance' => 10,
    'Term Paper' => 10,
    'Presentation' => 10,
];

$error = '';
$success = '';
$selectedOffering = (int)($_GET['offering_id'] ?? 0);

/* Find teacher profile */
$stmt = $conn->prepare("SELECT id FROM teachers WHERE user_id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    http_response_code(403);
    exit('Teacher profile not found.');
}

$teacherId = (int)$teacher['id'];

/* Assigned courses */
$stmt = $conn->prepare("
    SELECT co.id, c.course_code, c.course_title, s.semester_name
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

if ($selectedOffering && !in_array($selectedOffering, $validOfferingIds, true)) {
    $error = 'You are not authorized to access this course.';
    $selectedOffering = 0;
}

/* Create the six fixed assessment components if missing */
if ($selectedOffering && !$error) {
    try {
        $conn->begin_transaction();

        foreach ($components as $name => $marks) {
            $check = $conn->prepare("
                SELECT id FROM assessments
                WHERE offering_id = ? AND assessment_name = ?
                LIMIT 1
            ");
            $check->bind_param('is', $selectedOffering, $name);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$exists) {
                $insert = $conn->prepare("
                    INSERT INTO assessments
                    (offering_id, assessment_name, max_marks, weight_percentage)
                    VALUES (?, ?, ?, ?)
                ");
                $weight = (float)$marks;
                $insert->bind_param(
                    'isdd',
                    $selectedOffering,
                    $name,
                    $weight,
                    $weight
                );
                $insert->execute();
                $insert->close();
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log($e->getMessage());
        $error = 'Unable to configure assessment components. Please try again.';
    }
}

/* Load assessment summary */
$assessmentRows = [];
if ($selectedOffering && !$error) {
    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.assessment_name,
            a.max_marks,
            a.weight_percentage,
            COUNT(m.id) AS marks_entered
        FROM assessments a
        LEFT JOIN marks m ON m.assessment_id = a.id
        WHERE a.offering_id = ?
        GROUP BY a.id, a.assessment_name, a.max_marks, a.weight_percentage
        ORDER BY FIELD(
            a.assessment_name,
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
    $assessmentRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$pageTitle = 'Assessment Structure';
require_once __DIR__ . '/../includes/teacher-header.php';
?>

<div class="admin-page-heading">
    <div>
        <h1>Assessment Structure</h1>
        <p>Fixed assessment components for each assigned course.</p>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom:22px;">
    <h3 style="margin-top:0;">Select Course</h3>

    <form method="GET">
        <div class="form-group">
            <label for="offering_id">Assigned Course</label>
            <select name="offering_id" id="offering_id" class="form-control" onchange="this.form.submit()" required>
                <option value="">Select a course</option>
                <?php foreach ($offerings as $offering): ?>
                <option value="<?= (int)$offering['id'] ?>"
                    <?= $selectedOffering === (int)$offering['id'] ? 'selected' : '' ?>>
                    <?= e($offering['course_code']) ?> -
                    <?= e($offering['course_title']) ?> |
                    <?= e($offering['semester_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if (!$offerings): ?>
<div class="admin-card">
    <p>No courses have been assigned to you yet.</p>
</div>
<?php elseif ($selectedOffering && !$error): ?>

<div class="admin-card" style="margin-bottom:22px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h3 style="margin:0 0 5px;">Assessment Components</h3>
            <p style="margin:0;color:#687789;">Each course carries a total of 100 marks.</p>
        </div>
        <a href="<?= BASE_URL ?>/teacher/marks.php?offering_id=<?= $selectedOffering ?>" class="btn btn-primary">
            Enter Student Marks
        </a>
    </div>
</div>

<div class="admin-card">
    <div style="overflow-x:auto;">
        <table class="admin-table" style="width:100%;min-width:650px;">
            <thead>
                <tr>
                    <th>Assessment</th>
                    <th>Maximum Marks</th>
                    <th>Weight</th>
                    <th>Students Marked</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assessmentRows as $row): ?>
                <tr>
                    <td><strong><?= e($row['assessment_name']) ?></strong></td>
                    <td><?= number_format((float)$row['max_marks'], 0) ?></td>
                    <td><?= number_format((float)$row['weight_percentage'], 0) ?>%</td>
                    <td><?= (int)$row['marks_entered'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th>100</th>
                    <th>100%</th>
                    <th>—</th>
                </tr>
            </tfoot>
        </table>
    </div>

    <p style="margin:16px 0 0;color:#687789;font-size:13px;">
        These six components are fixed. Individual student marks can be
        entered or updated from the Marks Entry page.
    </p>
</div>

<?php else: ?>
<div class="admin-card">
    <p>Select an assigned course to view its assessment structure.</p>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/teacher-footer.php'; ?>