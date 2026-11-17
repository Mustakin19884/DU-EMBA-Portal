<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('teacher');

$pageTitle = 'My Students';
$userId = (int) ($_SESSION['user_id'] ?? 0);

$offerings = [];
$students = [];
$error = '';

$selectedOfferingId = filter_input(
    INPUT_GET,
    'offering_id',
    FILTER_VALIDATE_INT
);

$selectedOfferingId = (
    $selectedOfferingId && $selectedOfferingId > 0
) ? $selectedOfferingId : 0;


// -----------------------------------------
// 1. Get teacher profile
// -----------------------------------------

$teacherId = 0;

try {

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
        $error = 'Teacher profile is not linked to this account.';
    } else {
        $teacherId = (int) $teacher['id'];
    }

} catch (Throwable $e) {

    error_log('Teacher students profile: ' . $e->getMessage());

    $error = 'Unable to load teacher information.';
}


// -----------------------------------------
// 2. Load assigned course offerings
// -----------------------------------------

if ($teacherId > 0 && $error === '') {

    try {

        $sql = "
            SELECT
                co.id AS offering_id,
                c.course_code,
                c.course_title,
                c.credit_hours,
                sem.semester_name,
                sem.status AS semester_status,
                cs.class_day,
                cs.start_time,
                cs.end_time,
                cs.room

            FROM course_offerings co

            INNER JOIN courses c
                ON c.id = co.course_id

            INNER JOIN semesters sem
                ON sem.id = co.semester_id

            LEFT JOIN course_schedules cs
                ON cs.offering_id = co.id

            WHERE co.teacher_id = ?
              AND c.is_active = 1

            ORDER BY
                sem.start_date DESC,
                c.course_code ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $offerings[] = $row;
        }

        $stmt->close();

    } catch (Throwable $e) {

        error_log('Teacher offerings: ' . $e->getMessage());

        $error = 'Could not load assigned courses.';
    }
}


// -----------------------------------------
// 3. Validate selected course
// -----------------------------------------

$selectedOffering = null;

foreach ($offerings as $offering) {

    if ((int) $offering['offering_id'] === $selectedOfferingId) {

        $selectedOffering = $offering;
        break;
    }
}

if ($selectedOfferingId > 0 && !$selectedOffering) {

    $error = 'Invalid course selection or this course is not assigned to you.';
}


// -----------------------------------------
// 4. Load registered students
// -----------------------------------------

if ($selectedOffering && $error === '') {

    try {

        $sql = "
            SELECT
                s.id AS student_record_id,
                s.student_id,
                u.full_name,
                u.username,
                u.email,
                b.batch_name,
                s.phone,
                s.admission_date,
                cr.registered_at

            FROM course_registrations cr

            INNER JOIN students s
                ON s.id = cr.student_id

            INNER JOIN users u
                ON u.id = s.user_id

            LEFT JOIN batches b
                ON b.id = s.batch_id

            INNER JOIN course_offerings co
                ON co.id = cr.offering_id

            WHERE co.id = ?
              AND co.teacher_id = ?
              AND cr.status = 'registered'
              AND u.role = 'student'
              AND u.is_active = 1

            ORDER BY u.full_name ASC
        ";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            'ii',
            $selectedOfferingId,
            $teacherId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        $stmt->close();

    } catch (Throwable $e) {

        error_log('Teacher student list: ' . $e->getMessage());

        $error = 'Could not load the student list.';
    }
}

require_once __DIR__ . '/../includes/teacher-header.php';
?>

<style>
.teacher-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.teacher-page-header h1 {
    color: #102d50;
    margin: 0 0 6px;
}

.teacher-page-header p {
    color: #687789;
    margin: 0;
}

.teacher-panel {
    background: #fff;
    border: 1px solid #e1e5eb;
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 20px;
}

.teacher-panel h2 {
    color: #102d50;
    font-size: 19px;
    margin: 0 0 18px;
}

.teacher-form-group {
    margin-bottom: 16px;
}

.teacher-form-group label {
    display: block;
    margin-bottom: 8px;
    color: #263548;
    font-weight: 600;
}

.teacher-form-group select {
    width: 100%;
    max-width: 650px;
    padding: 12px;
    border: 1px solid #d6dce4;
    border-radius: 8px;
    background: #fff;
    color: #263548;
}

.teacher-button {
    display: inline-block;
    padding: 10px 16px;
    background: #102d50;
    color: #fff;
    border: none;
    border-radius: 8px;
    text-decoration: none;
    cursor: pointer;
    font-size: 14px;
}

.teacher-button:hover {
    background: #1c456e;
}

.teacher-alert {
    padding: 14px 16px;
    border-radius: 9px;
    margin-bottom: 18px;
    background: #fff4e5;
    border: 1px solid #f1d6a8;
    color: #80520b;
}

.teacher-course-info {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.teacher-info-item {
    background: #f4efe5;
    padding: 14px;
    border-radius: 9px;
}

.teacher-info-item span {
    display: block;
    font-size: 12px;
    color: #687789;
    margin-bottom: 5px;
}

.teacher-info-item strong {
    color: #102d50;
    font-size: 14px;
}

.teacher-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.teacher-table {
    width: 100%;
    min-width: 800px;
    border-collapse: collapse;
}

.teacher-table th,
.teacher-table td {
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1px solid #e8ebef;
    font-size: 14px;
}

.teacher-table th {
    background: #f4efe5;
    color: #102d50;
}

.teacher-empty {
    text-align: center;
    padding: 25px;
    color: #687789;
}

.teacher-total {
    color: #687789;
    margin-bottom: 15px;
}

@media(max-width:700px) {

    .teacher-panel {
        padding: 16px;
    }

    .teacher-course-info {
        grid-template-columns: 1fr;
    }
}
</style>


<div class="teacher-page-header">

    <div>
        <h1>My Students</h1>

        <p>
            View students registered in your assigned courses.
        </p>
    </div>

    <a href="<?= BASE_URL ?>/teacher/dashboard.php" class="teacher-button">

        Back to Dashboard

    </a>

</div>


<?php if ($error !== ''): ?>

<div class="teacher-alert">
    <?= e($error) ?>
</div>

<?php endif; ?>


<!-- Course Selection -->

<section class="teacher-panel">

    <h2>Select Course</h2>

    <?php if (empty($offerings)): ?>

    <p class="teacher-empty">
        No assigned course offerings were found.
    </p>

    <?php else: ?>

    <form method="GET" action="">

        <div class="teacher-form-group">

            <label for="offering_id">
                Course / Semester
            </label>

            <select name="offering_id" id="offering_id" required>

                <option value="">
                    -- Select a course --
                </option>

                <?php foreach ($offerings as $offering): ?>

                <option value="<?= (int) $offering['offering_id'] ?>" <?= (
                                $selectedOfferingId ===
                                (int) $offering['offering_id']
                            ) ? 'selected' : '' ?>>

                    <?= e($offering['course_code']) ?>
                    -
                    <?= e($offering['course_title']) ?>
                    (<?= e($offering['semester_name']) ?>)

                </option>

                <?php endforeach; ?>

            </select>

        </div>

        <button type="submit" class="teacher-button">
            View Students
        </button>

    </form>

    <?php endif; ?>

</section>


<!-- Selected Course Information -->

<?php if ($selectedOffering && $error === ''): ?>

<section class="teacher-panel">

    <h2>Course Information</h2>

    <div class="teacher-course-info">

        <div class="teacher-info-item">
            <span>Course Code</span>

            <strong>
                <?= e($selectedOffering['course_code']) ?>
            </strong>
        </div>

        <div class="teacher-info-item">
            <span>Course Title</span>

            <strong>
                <?= e($selectedOffering['course_title']) ?>
            </strong>
        </div>

        <div class="teacher-info-item">
            <span>Semester</span>

            <strong>
                <?= e($selectedOffering['semester_name']) ?>
            </strong>
        </div>

        <div class="teacher-info-item">
            <span>Credit Hours</span>

            <strong>
                <?= e((string) $selectedOffering['credit_hours']) ?>
            </strong>
        </div>

        <div class="teacher-info-item">
            <span>Class Day</span>

            <strong>
                <?= e($selectedOffering['class_day'] ?? 'Not set') ?>
            </strong>
        </div>

        <div class="teacher-info-item">
            <span>Class Room</span>

            <strong>
                <?= e($selectedOffering['room'] ?? 'Not set') ?>
            </strong>
        </div>

    </div>

</section>


<!-- Student List -->

<section class="teacher-panel">

    <h2>Registered Students</h2>

    <p class="teacher-total">
        Total registered students:
        <strong><?= count($students) ?></strong>
    </p>

    <div class="teacher-table-wrap">

        <table class="teacher-table">

            <thead>
                <tr>
                    <th>#</th>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Username</th>
                    <th>Batch</th>
                    <th>Phone</th>
                </tr>
            </thead>

            <tbody>

                <?php if (!empty($students)): ?>

                <?php foreach ($students as $index => $student): ?>

                <tr>

                    <td>
                        <?= $index + 1 ?>
                    </td>

                    <td>
                        <?= e($student['student_id']) ?>
                    </td>

                    <td>
                        <?= e($student['full_name']) ?>
                    </td>

                    <td>
                        <?= e($student['username']) ?>
                    </td>

                    <td>
                        <?= e($student['batch_name'] ?? 'Not assigned') ?>
                    </td>

                    <td>
                        <?= e($student['phone'] ?? 'Not available') ?>
                    </td>

                </tr>

                <?php endforeach; ?>

                <?php else: ?>

                <tr>
                    <td colspan="6" class="teacher-empty">
                        No registered students found for this course.
                    </td>
                </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

<?php endif; ?>


<?php require_once __DIR__ . '/../includes/teacher-footer.php'; ?>