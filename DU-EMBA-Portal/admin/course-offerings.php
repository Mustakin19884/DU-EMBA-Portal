<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$page_title = 'Course Offering & Schedule Management';

$success = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Save Course Offering
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please try again.';
    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'save_offering') {

            $offering_id = (int)($_POST['offering_id'] ?? 0);
            $course_id = (int)($_POST['course_id'] ?? 0);
            $semester_id = (int)($_POST['semester_id'] ?? 0);
            $teacher_id = (int)($_POST['teacher_id'] ?? 0);
            $capacity = (int)($_POST['capacity'] ?? 60);
            $registration_open = isset($_POST['registration_open']) ? 1 : 0;

            if (
                $course_id <= 0 ||
                $semester_id <= 0 ||
                $capacity <= 0 ||
                $teacher_id < 0
            ) {
                $error = 'Please provide valid course, semester, teacher and capacity.';
            } else {

                // Verify course
                $stmt = $conn->prepare("
                    SELECT id FROM courses
                    WHERE id = ? AND is_active = 1
                ");
                $stmt->bind_param("i", $course_id);
                $stmt->execute();
                $valid_course = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                // Verify semester
                $stmt = $conn->prepare("
                    SELECT id FROM semesters WHERE id = ?
                ");
                $stmt->bind_param("i", $semester_id);
                $stmt->execute();
                $valid_semester = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                // Verify active teacher if selected
                $valid_teacher = true;

                if ($teacher_id > 0) {
                    $stmt = $conn->prepare("
                        SELECT t.id
                        FROM teachers t
                        INNER JOIN users u ON u.id = t.user_id
                        WHERE t.id = ?
                          AND u.role = 'teacher'
                          AND u.is_active = 1
                    ");
                    $stmt->bind_param("i", $teacher_id);
                    $stmt->execute();
                    $valid_teacher = $stmt->get_result()->num_rows > 0;
                    $stmt->close();
                }

                if (!$valid_course) {
                    $error = 'Selected course is invalid or inactive.';
                } elseif (!$valid_semester) {
                    $error = 'Selected semester does not exist.';
                } elseif (!$valid_teacher) {
                    $error = 'Selected teacher is invalid or inactive.';
                } else {

                    // Prevent duplicate course offering in same semester
                    $stmt = $conn->prepare("
                        SELECT id
                        FROM course_offerings
                        WHERE course_id = ?
                          AND semester_id = ?
                          AND id != ?
                    ");
                    $stmt->bind_param(
                        "iii",
                        $course_id,
                        $semester_id,
                        $offering_id
                    );
                    $stmt->execute();
                    $duplicate = $stmt->get_result()->num_rows > 0;
                    $stmt->close();

                    if ($duplicate) {
                        $error = 'This course already has an offering in this semester.';
                    } else {

                        if ($offering_id > 0) {

                            $stmt = $conn->prepare("
                                UPDATE course_offerings
                                SET course_id = ?,
                                    semester_id = ?,
                                    teacher_id = NULLIF(?, 0),
                                    capacity = ?,
                                    registration_open = ?
                                WHERE id = ?
                            ");

                            $stmt->bind_param(
                                "iiiiii",
                                $course_id,
                                $semester_id,
                                $teacher_id,
                                $capacity,
                                $registration_open,
                                $offering_id
                            );

                        } else {

                            $stmt = $conn->prepare("
                                INSERT INTO course_offerings
                                (
                                    course_id,
                                    semester_id,
                                    teacher_id,
                                    capacity,
                                    registration_open
                                )
                                VALUES (?, ?, NULLIF(?, 0), ?, ?)
                            ");

                            $stmt->bind_param(
                                "iiiii",
                                $course_id,
                                $semester_id,
                                $teacher_id,
                                $capacity,
                                $registration_open
                            );
                        }

                        if ($stmt->execute()) {
                            $success = $offering_id > 0
                                ? 'Course offering updated successfully.'
                                : 'Course offering created successfully.';
                        } else {
                            $error = 'Database error: ' . $stmt->error;
                        }

                        $stmt->close();
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save Class Schedule
        |--------------------------------------------------------------------------
        */

        if ($action === 'save_schedule' && $error === '') {

            $offering_id = (int)($_POST['offering_id'] ?? 0);
            $class_day = $_POST['class_day'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $room = trim($_POST['room'] ?? '');

            $days = [
                'Saturday', 'Sunday', 'Monday', 'Tuesday',
                'Wednesday', 'Thursday', 'Friday'
            ];

            if (
                $offering_id <= 0 ||
                !in_array($class_day, $days, true) ||
                $start_time === '' ||
                $end_time === '' ||
                $room === ''
            ) {
                $error = 'Please complete all schedule fields.';
            } elseif ($end_time <= $start_time) {
                $error = 'Class end time must be later than start time.';
            } else {

                // Verify offering exists
                $stmt = $conn->prepare("
                    SELECT id FROM course_offerings WHERE id = ?
                ");
                $stmt->bind_param("i", $offering_id);
                $stmt->execute();
                $valid_offering = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if (!$valid_offering) {
                    $error = 'Selected course offering does not exist.';
                } else {

                    $stmt = $conn->prepare("
                        INSERT INTO course_schedules
                            (offering_id, class_day, start_time, end_time, room)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            class_day = VALUES(class_day),
                            start_time = VALUES(start_time),
                            end_time = VALUES(end_time),
                            room = VALUES(room)
                    ");

                    $stmt->bind_param(
                        "issss",
                        $offering_id,
                        $class_day,
                        $start_time,
                        $end_time,
                        $room
                    );

                    if ($stmt->execute()) {
                        $success = 'Class schedule saved successfully.';
                    } else {
                        $error = 'Unable to save schedule: ' . $stmt->error;
                    }

                    $stmt->close();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Edit Data
|--------------------------------------------------------------------------
*/

$edit_offering = null;

if (isset($_GET['edit'])) {

    $edit_id = (int)$_GET['edit'];

    $stmt = $conn->prepare("
        SELECT * FROM course_offerings WHERE id = ?
    ");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_offering = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Dropdown Data
|--------------------------------------------------------------------------
*/

$courses = $conn->query("
    SELECT id, course_code, course_title, credit_hours
    FROM courses
    WHERE is_active = 1
    ORDER BY course_code
");

$semesters = $conn->query("
    SELECT
        s.id,
        s.semester_name,
        a.session_name
    FROM semesters s
    INNER JOIN academic_sessions a ON a.id = s.session_id
    ORDER BY a.start_date DESC, s.start_date DESC
");

$teachers = $conn->query("
    SELECT
        t.id,
        u.full_name,
        t.employee_id,
        t.designation
    FROM teachers t
    INNER JOIN users u ON u.id = t.user_id
    WHERE u.role = 'teacher'
      AND u.is_active = 1
    ORDER BY u.full_name
");

/*
|--------------------------------------------------------------------------
| Existing Offerings and Schedules
|--------------------------------------------------------------------------
*/

$offerings = $conn->query("
    SELECT
        o.id,
        o.capacity,
        o.registration_open,
        c.course_code,
        c.course_title,
        c.credit_hours,
        s.semester_name,
        a.session_name,
        u.full_name AS teacher_name,
        cs.class_day,
        cs.start_time,
        cs.end_time,
        cs.room
    FROM course_offerings o
    INNER JOIN courses c ON c.id = o.course_id
    INNER JOIN semesters s ON s.id = o.semester_id
    INNER JOIN academic_sessions a ON a.id = s.session_id
    LEFT JOIN teachers t ON t.id = o.teacher_id
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN course_schedules cs ON cs.offering_id = o.id
    ORDER BY a.start_date DESC, s.start_date DESC, c.course_code
");

include __DIR__ . '/../includes/header.php';
?>

<style>
.offering-container {
    max-width: 1500px;
    margin: 30px auto;
    padding: 0 24px;
}

.offering-heading {
    margin-bottom: 25px;
}

.offering-heading h2 {
    color: var(--navy);
    margin-bottom: 6px;
}

.offering-heading p {
    color: var(--muted);
}

.offering-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 25px;
}

.offering-card h3 {
    color: var(--navy);
    margin: 0 0 20px;
}

.offering-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.offering-form .full {
    grid-column: 1 / -1;
}

.offering-form label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
    color: var(--text);
}

.offering-form input,
.offering-form select {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 7px;
    box-sizing: border-box;
    font-size: 14px;
}

.offering-form input[type="checkbox"] {
    width: auto;
}

.check-label {
    display: flex !important;
    align-items: center;
    gap: 8px;
}

.offering-btn {
    background: var(--navy);
    color: #fff;
    border: 0;
    border-radius: 7px;
    padding: 11px 22px;
    cursor: pointer;
    font-weight: 600;
}

.offering-btn:hover {
    background: var(--navy-light);
}

.cancel-link {
    margin-left: 12px;
    color: var(--muted);
    text-decoration: none;
}

.alert {
    padding: 13px 16px;
    border-radius: 7px;
    margin-bottom: 18px;
}

.alert-success {
    background: #e4f5e9;
    color: #176b35;
}

.alert-error {
    background: #fde8e8;
    color: #a12626;
}

.table-responsive {
    overflow-x: auto;
}

.offering-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1100px;
}

.offering-table th,
.offering-table td {
    padding: 13px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}

.offering-table th {
    background: var(--beige);
    color: var(--navy);
}

.offering-table a {
    color: var(--navy);
    font-weight: 600;
    text-decoration: none;
}

.schedule-form {
    min-width: 220px;
}

.schedule-form select,
.schedule-form input {
    width: 100%;
    padding: 7px;
    margin-bottom: 6px;
    border: 1px solid var(--border);
    border-radius: 5px;
    box-sizing: border-box;
}

.status-open {
    color: #176b35;
    font-weight: 600;
}

.status-closed {
    color: #a12626;
    font-weight: 600;
}

@media(max-width: 650px) {
    .offering-container {
        padding: 0 12px;
    }

    .offering-form {
        grid-template-columns: 1fr;
    }

    .offering-form .full {
        grid-column: auto;
    }
}
</style>

<div class="offering-container">

    <div class="offering-heading">
        <h2>Course Offering & Class Schedule</h2>
        <p>
            Manage semester-wise course offerings, teacher assignments
            and weekly class schedules.
        </p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- COURSE OFFERING FORM -->

    <div class="offering-card">

        <h3>
            <?= $edit_offering ? 'Edit Course Offering' : 'Create Course Offering' ?>
        </h3>

        <form method="POST" class="offering-form">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="save_offering">

            <input type="hidden" name="offering_id" value="<?= (int)($edit_offering['id'] ?? 0) ?>">

            <div>
                <label>Academic Semester *</label>
                <select name="semester_id" required>
                    <option value="">Select Semester</option>

                    <?php while ($semester = $semesters->fetch_assoc()): ?>
                    <option value="<?= (int)$semester['id'] ?>"
                        <?= (int)($edit_offering['semester_id'] ?? 0) === (int)$semester['id'] ? 'selected' : '' ?>>
                        <?= e($semester['session_name']) ?>
                        — <?= e($semester['semester_name']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label>Course *</label>
                <select name="course_id" required>
                    <option value="">Select Course</option>

                    <?php while ($course = $courses->fetch_assoc()): ?>
                    <option value="<?= (int)$course['id'] ?>"
                        <?= (int)($edit_offering['course_id'] ?? 0) === (int)$course['id'] ? 'selected' : '' ?>>
                        <?= e($course['course_code']) ?> —
                        <?= e($course['course_title']) ?>
                        (<?= e($course['credit_hours']) ?> Credits)
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label>Assign Teacher</label>
                <select name="teacher_id">
                    <option value="0">Not assigned yet</option>

                    <?php while ($teacher = $teachers->fetch_assoc()): ?>
                    <option value="<?= (int)$teacher['id'] ?>"
                        <?= (int)($edit_offering['teacher_id'] ?? 0) === (int)$teacher['id'] ? 'selected' : '' ?>>
                        <?= e($teacher['full_name']) ?>
                        — <?= e($teacher['employee_id']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label>Student Capacity *</label>
                <input type="number" name="capacity" min="1" value="<?= (int)($edit_offering['capacity'] ?? 60) ?>"
                    required>
            </div>

            <div class="full">
                <label class="check-label">
                    <input type="checkbox" name="registration_open" value="1"
                        <?= !empty($edit_offering['registration_open']) ? 'checked' : '' ?>>
                    Open course registration for this offering
                </label>
            </div>

            <div class="full">
                <button type="submit" class="offering-btn">
                    <?= $edit_offering ? 'Update Offering' : 'Create Offering' ?>
                </button>

                <?php if ($edit_offering): ?>
                <a href="course-offerings.php" class="cancel-link">Cancel</a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <!-- OFFERING LIST AND SCHEDULE -->

    <div class="offering-card">

        <h3>Existing Course Offerings & Class Schedules</h3>

        <div class="table-responsive">

            <table class="offering-table">

                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Academic Session</th>
                        <th>Semester</th>
                        <th>Teacher</th>
                        <th>Capacity</th>
                        <th>Registration</th>
                        <th>Class Schedule</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($offerings && $offerings->num_rows > 0): ?>

                    <?php while ($row = $offerings->fetch_assoc()): ?>

                    <tr>
                        <td>
                            <strong><?= e($row['course_code']) ?></strong><br>
                            <?= e($row['course_title']) ?><br>
                            <small><?= e($row['credit_hours']) ?> Credits</small>
                        </td>

                        <td><?= e($row['session_name']) ?></td>

                        <td><?= e($row['semester_name']) ?></td>

                        <td><?= e($row['teacher_name'] ?? 'Not assigned') ?></td>

                        <td><?= (int)$row['capacity'] ?></td>

                        <td>
                            <?php if ((int)$row['registration_open'] === 1): ?>
                            <span class="status-open">Open</span>
                            <?php else: ?>
                            <span class="status-closed">Closed</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <form method="POST" class="schedule-form">

                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                                <input type="hidden" name="action" value="save_schedule">

                                <input type="hidden" name="offering_id" value="<?= (int)$row['id'] ?>">

                                <select name="class_day" required>
                                    <option value="">Select Day</option>

                                    <?php
                                            $days = [
                                                'Saturday', 'Sunday', 'Monday',
                                                'Tuesday', 'Wednesday',
                                                'Thursday', 'Friday'
                                            ];
                                            ?>

                                    <?php foreach ($days as $day): ?>
                                    <option value="<?= $day ?>"
                                        <?= ($row['class_day'] ?? '') === $day ? 'selected' : '' ?>>
                                        <?= $day ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="time" name="start_time" value="<?= e($row['start_time'] ?? '') ?>"
                                    required>

                                <input type="time" name="end_time" value="<?= e($row['end_time'] ?? '') ?>" required>

                                <input type="text" name="room" placeholder="Room number"
                                    value="<?= e($row['room'] ?? '') ?>" maxlength="100" required>

                                <button type="submit" class="offering-btn">
                                    Save Schedule
                                </button>

                            </form>
                        </td>

                        <td>
                            <a href="course-offerings.php?edit=<?= (int)$row['id'] ?>">
                                Edit Offering
                            </a>
                        </td>
                    </tr>

                    <?php endwhile; ?>

                    <?php else: ?>

                    <tr>
                        <td colspan="8">
                            No course offerings found. Create your first offering above.
                        </td>
                    </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>
    </div>

    <p>
        <a href="<?= e(BASE_URL) ?>/admin/dashboard.php">
            ← Back to Admin Dashboard
        </a>
    </p>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>