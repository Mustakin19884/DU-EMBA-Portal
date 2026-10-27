<?php
require_once __DIR__ . '/../includes/auth.php';

require_role('teacher');
require_once __DIR__ . '/../config/database.php';

$pageTitle = 'My Students';
$userId = (int) ($_SESSION['user_id'] ?? 0);

$offerings = [];
$students = [];
$error = '';
$selectedOfferingId = filter_input(INPUT_GET, 'offering_id', FILTER_VALIDATE_INT);
$selectedOfferingId = $selectedOfferingId && $selectedOfferingId > 0
    ? $selectedOfferingId
    : 0;

// Load offerings assigned to this teacher.
$sql = "
    SELECT
        co.id AS offering_id,
        c.course_code,
        c.course_title,
        c.credit_hours,
        sem.semester_name
    FROM course_offerings co
    INNER JOIN teachers t ON t.id = co.teacher_id
    INNER JOIN courses c ON c.id = co.course_id
    INNER JOIN semesters sem ON sem.id = co.semester_id
    WHERE t.user_id = ?
      AND c.is_active = 1
    ORDER BY sem.start_date DESC, c.course_code ASC
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $offerings[] = $row;
    }

    $stmt->close();
} else {
    $error = 'Could not load assigned courses.';
}

// Verify selected offering belongs to this teacher.
$validOffering = false;

foreach ($offerings as $offering) {
    if ((int) $offering['offering_id'] === $selectedOfferingId) {
        $validOffering = true;
        break;
    }
}

if ($selectedOfferingId > 0 && !$validOffering) {
    $error = 'Invalid course selection or this course is not assigned to you.';
}

// Load registered students for the selected offering.
if ($validOffering && $error === '') {
    $sql = "
        SELECT
            s.student_id,
            u.full_name,
            s.batch_id,
            s.phone,
            s.email AS unused_email
        FROM course_registrations cr
        INNER JOIN students s ON s.id = cr.student_id
        INNER JOIN users u ON u.id = s.user_id
        INNER JOIN course_offerings co ON co.id = cr.offering_id
        INNER JOIN teachers t ON t.id = co.teacher_id
        WHERE co.id = ?
          AND t.user_id = ?
          AND cr.status = 'registered'
          AND u.role = 'student'
        ORDER BY u.full_name ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param('ii', $selectedOfferingId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        $stmt->close();
    } else {
        $error = 'Could not load the student list.';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p>View students registered in your assigned courses.</p>
    </div>

    <a href="<?= BASE_URL ?>/teacher/dashboard.php" class="btn btn-secondary">
        Back to Dashboard
    </a>
</div>

<?php if ($error !== ''): ?>
<div class="alert alert-danger">
    <?= e($error) ?>
</div>
<?php endif; ?>

<section class="card">
    <h2>Select Course</h2>

    <?php if (empty($offerings)): ?>
    <p>No active assigned courses were found.</p>
    <?php else: ?>
    <form method="GET" action="">
        <div class="form-group">
            <label for="offering_id">Course / Semester</label>

            <select id="offering_id" name="offering_id" required>
                <option value="">-- Select a course --</option>

                <?php foreach ($offerings as $offering): ?>
                <option value="<?= (int) $offering['offering_id'] ?>" <?= $selectedOfferingId === (int) $offering['offering_id']
                                ? 'selected'
                                : '' ?>>
                    <?= e($offering['course_code']) ?>
                    - <?= e($offering['course_title']) ?>
                    (<?= e($offering['semester_name']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">
            View Students
        </button>
    </form>
    <?php endif; ?>
</section>

<?php if ($validOffering && $error === ''): ?>
<section class="card">
    <h2>Registered Students</h2>
    <p>Total students: <?= count($students) ?></p>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Batch</th>
                    <th>Phone</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!empty($students)): ?>
                <?php foreach ($students as $index => $student): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= e($student['student_id']) ?></td>
                    <td><?= e($student['full_name']) ?></td>
                    <td><?= e($student['batch_id'] ?? '') ?></td>
                    <td><?= e($student['phone'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="5">
                        No registered students found for this course.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>