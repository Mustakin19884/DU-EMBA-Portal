<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$page_title = 'Course Offering & Schedule Management';

$success = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Handle Form Submissions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | Save Course Offering
        |--------------------------------------------------------------------------
        */

        if ($action === 'save_offering') {

            $offeringId = (int)($_POST['offering_id'] ?? 0);
            $courseId = (int)($_POST['course_id'] ?? 0);
            $semesterId = (int)($_POST['semester_id'] ?? 0);
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            $capacity = (int)($_POST['capacity'] ?? 60);
            $registrationOpen = isset($_POST['registration_open']) ? 1 : 0;

            if (
                $courseId <= 0 ||
                $semesterId <= 0 ||
                $teacherId < 0 ||
                $capacity <= 0 ||
                $offeringId < 0
            ) {
                $error = 'Please provide valid course, semester, teacher and capacity.';
            } else {

                // Verify active course.
                $stmt = $conn->prepare("
                    SELECT id
                    FROM courses
                    WHERE id = ? AND is_active = 1
                    LIMIT 1
                ");

                $stmt->bind_param("i", $courseId);
                $stmt->execute();

                $validCourse = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                // Verify semester.
                $stmt = $conn->prepare("
                    SELECT id
                    FROM semesters
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->bind_param("i", $semesterId);
                $stmt->execute();

                $validSemester = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                // Verify active teacher, if selected.
                $validTeacher = true;

                if ($teacherId > 0) {

                    $stmt = $conn->prepare("
                        SELECT t.id
                        FROM teachers t
                        INNER JOIN users u ON u.id = t.user_id
                        WHERE t.id = ?
                          AND u.role = 'teacher'
                          AND u.is_active = 1
                        LIMIT 1
                    ");

                    $stmt->bind_param("i", $teacherId);
                    $stmt->execute();

                    $validTeacher = $stmt->get_result()->num_rows > 0;
                    $stmt->close();
                }

                if (!$validCourse) {

                    $error = 'Selected course is invalid or inactive.';

                } elseif (!$validSemester) {

                    $error = 'Selected semester does not exist.';

                } elseif (!$validTeacher) {

                    $error = 'Selected teacher is invalid or inactive.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Prevent Duplicate Course Offering
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $conn->prepare("
                        SELECT id
                        FROM course_offerings
                        WHERE course_id = ?
                          AND semester_id = ?
                          AND id != ?
                        LIMIT 1
                    ");

                    $stmt->bind_param(
                        "iii",
                        $courseId,
                        $semesterId,
                        $offeringId
                    );

                    $stmt->execute();

                    $duplicate = $stmt->get_result()->num_rows > 0;
                    $stmt->close();

                    if ($duplicate) {

                        $error = 'This course already has an offering in the selected semester.';

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Protect Existing Student Registrations
                        |--------------------------------------------------------------------------
                        */

                        if ($offeringId > 0) {

                            $stmt = $conn->prepare("
                                SELECT
                                    course_id,
                                    semester_id,
                                    (
                                        SELECT COUNT(*)
                                        FROM course_registrations cr
                                        WHERE cr.offering_id = course_offerings.id
                                          AND cr.status = 'registered'
                                    ) AS registered_count
                                FROM course_offerings
                                WHERE id = ?
                                LIMIT 1
                            ");

                            $stmt->bind_param("i", $offeringId);
                            $stmt->execute();

                            $existingOffering = $stmt->get_result()->fetch_assoc();
                            $stmt->close();

                            if (!$existingOffering) {

                                $error = 'Course offering not found.';

                            } elseif (
                                (
                                    (int)$existingOffering['course_id'] !== $courseId ||
                                    (int)$existingOffering['semester_id'] !== $semesterId
                                ) &&
                                (int)$existingOffering['registered_count'] > 0
                            ) {

                                $error = 'Course or semester cannot be changed because students are already registered.';

                            } elseif (
                                $capacity < (int)$existingOffering['registered_count']
                            ) {

                                $error = 'Capacity cannot be lower than the current number of registered students.';
                            }
                        }

                        if ($error === '') {

                            if ($offeringId > 0) {

                                $stmt = $conn->prepare("
                                    UPDATE course_offerings
                                    SET
                                        course_id = ?,
                                        semester_id = ?,
                                        teacher_id = NULLIF(?, 0),
                                        capacity = ?,
                                        registration_open = ?
                                    WHERE id = ?
                                ");

                                $stmt->bind_param(
                                    "iiiiii",
                                    $courseId,
                                    $semesterId,
                                    $teacherId,
                                    $capacity,
                                    $registrationOpen,
                                    $offeringId
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
                                    $courseId,
                                    $semesterId,
                                    $teacherId,
                                    $capacity,
                                    $registrationOpen
                                );
                            }

                            if ($stmt->execute()) {

                                $success = $offeringId > 0
                                    ? 'Course offering updated successfully.'
                                    : 'Course offering created successfully.';

                            } else {

                                $error = 'Unable to save course offering: ' . $stmt->error;
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

        } elseif ($action === 'save_schedule') {

            $offeringId = (int)($_POST['offering_id'] ?? 0);
            $classDay = $_POST['class_day'] ?? '';
            $startTime = $_POST['start_time'] ?? '';
            $endTime = $_POST['end_time'] ?? '';
            $room = trim($_POST['room'] ?? '');

            $validDays = [
                'Saturday',
                'Sunday',
                'Monday',
                'Tuesday',
                'Wednesday',
                'Thursday',
                'Friday'
            ];

            $validStartTime = is_string($startTime) &&
                preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime);

            $validEndTime = is_string($endTime) &&
                preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime);

            if (
                $offeringId <= 0 ||
                !in_array($classDay, $validDays, true) ||
                !$validStartTime ||
                !$validEndTime ||
                $room === '' ||
                strlen($room) > 100
            ) {

                $error = 'Please provide valid schedule details in all fields.';

            } elseif ($endTime <= $startTime) {

                $error = 'Class end time must be later than start time.';

            } else {

                // Verify offering exists.
                $stmt = $conn->prepare("
                    SELECT id
                    FROM course_offerings
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->bind_param("i", $offeringId);
                $stmt->execute();

                $validOffering = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if (!$validOffering) {

                    $error = 'Selected course offering does not exist.';

                } else {

                    /*
                     * course_schedules has a UNIQUE index on offering_id.
                     * Therefore, one schedule is maintained per offering.
                     */

                    $stmt = $conn->prepare("
                        INSERT INTO course_schedules
                        (
                            offering_id,
                            class_day,
                            start_time,
                            end_time,
                            room
                        )
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            class_day = VALUES(class_day),
                            start_time = VALUES(start_time),
                            end_time = VALUES(end_time),
                            room = VALUES(room)
                    ");

                    $stmt->bind_param(
                        "issss",
                        $offeringId,
                        $classDay,
                        $startTime,
                        $endTime,
                        $room
                    );

                    if ($stmt->execute()) {

                        $success = 'Class schedule saved successfully.';

                    } else {

                        $error = 'Unable to save class schedule: ' . $stmt->error;
                    }

                    $stmt->close();
                }
            }

        } else {

            $error = 'Invalid form action.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Offering for Editing
|--------------------------------------------------------------------------
*/

$editOffering = null;

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT *
            FROM course_offerings
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $editId);
        $stmt->execute();

        $editOffering = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$editOffering) {
            $error = 'Selected course offering was not found.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Dropdown Data
|--------------------------------------------------------------------------
*/

$courses = $conn->query("
    SELECT
        id,
        course_code,
        course_title,
        credit_hours
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
    INNER JOIN academic_sessions a
        ON a.id = s.session_id
    ORDER BY a.start_date DESC, s.start_date DESC
");

$teachers = $conn->query("
    SELECT
        t.id,
        u.full_name,
        t.employee_id,
        t.designation
    FROM teachers t
    INNER JOIN users u
        ON u.id = t.user_id
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

    INNER JOIN courses c
        ON c.id = o.course_id

    INNER JOIN semesters s
        ON s.id = o.semester_id

    INNER JOIN academic_sessions a
        ON a.id = s.session_id

    LEFT JOIN teachers t
        ON t.id = o.teacher_id

    LEFT JOIN users u
        ON u.id = t.user_id

    LEFT JOIN course_schedules cs
        ON cs.offering_id = o.id

    ORDER BY
        a.start_date DESC,
        s.start_date DESC,
        c.course_code ASC
");

require_once __DIR__ . '/../includes/admin-header.php';
?>

<style>
.offering-container {
    width: 100%;
    max-width: 1450px;
    box-sizing: border-box;
    margin: 0 auto;
    padding: 26px 24px;
}

.offering-heading {
    margin-bottom: 25px;
}

.offering-heading h2 {
    color: var(--navy);
    margin: 0 0 7px;
}

.offering-heading p {
    color: var(--muted);
    margin: 0;
    line-height: 1.6;
}

.offering-card {
    background: var(--white, #fff);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 25px;
    box-sizing: border-box;
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
    background: #fff;
}

.offering-form input:focus,
.offering-form select:focus,
.schedule-form input:focus,
.schedule-form select:focus {
    outline: none;
    border-color: var(--navy-light);
    box-shadow: 0 0 0 3px rgba(28, 69, 110, .10);
}

.offering-form input[type="checkbox"] {
    width: auto;
}

.check-label {
    display: flex !important;
    align-items: center;
    gap: 8px;
}

.check-label input {
    margin: 0;
}

.offering-btn {
    display: inline-block;
    background: var(--navy);
    color: #fff;
    border: 0;
    border-radius: 7px;
    padding: 10px 17px;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
}

.offering-btn:hover {
    background: var(--navy-light);
}

.cancel-link {
    margin-left: 12px;
    color: var(--muted);
    text-decoration: none;
}

.cancel-link:hover {
    color: var(--navy);
}

.alert {
    padding: 13px 16px;
    border-radius: 7px;
    margin-bottom: 18px;
    line-height: 1.5;
}

.alert-success {
    background: #e4f5e9;
    color: #176b35;
}

.alert-error {
    background: #fde8e8;
    color: #a12626;
}

/* Search */

.offerings-search-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.offerings-search-bar .search-control {
    flex: 1 1 320px;
    max-width: 520px;
    position: relative;
}

.offerings-search-bar input[type="search"] {
    width: 100%;
    box-sizing: border-box;
    padding: 12px 14px 12px 42px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    outline: none;
    background: #fff;
}

.offerings-search-bar input[type="search"]:focus {
    border-color: var(--navy-light);
    box-shadow: 0 0 0 3px rgba(28, 69, 110, .12);
}

.offerings-search-bar .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    pointer-events: none;
}

.offerings-search-count {
    color: var(--muted);
    font-size: 13px;
    white-space: nowrap;
}

/* Table */

.offerings-list-card {
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
}

.table-responsive {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    border: 1px solid var(--border);
    border-radius: 8px;
}

.offering-table {
    width: 100%;
    min-width: 1450px;
    border-collapse: collapse;
    table-layout: auto;
}

.offering-table th,
.offering-table td {
    padding: 13px 14px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}

.offering-table th {
    background: var(--beige);
    color: var(--navy);
    font-weight: 700;
}

.offering-table tbody tr:last-child td {
    border-bottom: 0;
}

.offering-table td:first-child {
    min-width: 220px;
    white-space: normal;
    line-height: 1.6;
}

.offering-table a {
    color: var(--navy);
    font-weight: 600;
    text-decoration: none;
}

.offering-table a:hover {
    text-decoration: underline;
}

.schedule-form {
    min-width: 190px;
    width: 190px;
}

.schedule-form select,
.schedule-form input {
    width: 100%;
    padding: 8px;
    margin-bottom: 7px;
    border: 1px solid var(--border);
    border-radius: 5px;
    box-sizing: border-box;
    font-size: 13px;
    background: #fff;
}

.schedule-form button {
    width: 100%;
}

.status-open {
    color: #176b35;
    font-weight: 600;
}

.status-closed {
    color: #a12626;
    font-weight: 600;
}

.search-hidden {
    display: none !important;
}

.offerings-no-results td,
.offerings-empty td {
    text-align: center;
    padding: 24px;
    color: var(--muted);
}

.back-link {
    display: inline-block;
    margin: 0 0 20px;
    color: var(--navy);
    font-weight: 600;
    text-decoration: none;
}

.back-link:hover {
    text-decoration: underline;
}

@media (max-width: 900px) {
    .offering-container {
        padding: 22px 16px;
    }
}

@media (max-width: 650px) {
    .offering-container {
        padding: 16px 10px;
    }

    .offering-card {
        padding: 16px;
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
    <div class="alert alert-success">
        <?= e($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <?= e($error) ?>
    </div>
    <?php endif; ?>

    <!-- COURSE OFFERING FORM -->

    <div class="offering-card">

        <h3>
            <?= $editOffering ? 'Edit Course Offering' : 'Create Course Offering' ?>
        </h3>

        <form method="POST" class="offering-form">

            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <input type="hidden" name="action" value="save_offering">

            <input type="hidden" name="offering_id" value="<?= (int)($editOffering['id'] ?? 0) ?>">

            <div>
                <label>Academic Semester *</label>

                <select name="semester_id" required>
                    <option value="">Select Semester</option>

                    <?php if ($semesters): ?>
                    <?php while ($semester = $semesters->fetch_assoc()): ?>

                    <option value="<?= (int)$semester['id'] ?>"
                        <?= (int)($editOffering['semester_id'] ?? 0) === (int)$semester['id'] ? 'selected' : '' ?>>
                        <?= e($semester['session_name']) ?>
                        — <?= e($semester['semester_name']) ?>
                    </option>

                    <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label>Course *</label>

                <select name="course_id" required>
                    <option value="">Select Course</option>

                    <?php if ($courses): ?>
                    <?php while ($course = $courses->fetch_assoc()): ?>

                    <option value="<?= (int)$course['id'] ?>"
                        <?= (int)($editOffering['course_id'] ?? 0) === (int)$course['id'] ? 'selected' : '' ?>>
                        <?= e($course['course_code']) ?>
                        — <?= e($course['course_title']) ?>
                        (<?= e($course['credit_hours']) ?> Credits)
                    </option>

                    <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label>Assign Teacher</label>

                <select name="teacher_id">
                    <option value="0">Not assigned yet</option>

                    <?php if ($teachers): ?>
                    <?php while ($teacher = $teachers->fetch_assoc()): ?>

                    <option value="<?= (int)$teacher['id'] ?>"
                        <?= (int)($editOffering['teacher_id'] ?? 0) === (int)$teacher['id'] ? 'selected' : '' ?>>
                        <?= e($teacher['full_name']) ?>
                        — <?= e($teacher['employee_id']) ?>
                    </option>

                    <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label>Student Capacity *</label>

                <input type="number" name="capacity" min="1" max="10000"
                    value="<?= (int)($editOffering['capacity'] ?? 60) ?>" required>
            </div>

            <div class="full">
                <label class="check-label">
                    <input type="checkbox" name="registration_open" value="1"
                        <?= !empty($editOffering['registration_open']) ? 'checked' : '' ?>>

                    Open course registration for this offering
                </label>
            </div>

            <div class="full">

                <button type="submit" class="offering-btn">
                    <?= $editOffering ? 'Update Offering' : 'Create Offering' ?>
                </button>

                <?php if ($editOffering): ?>
                <a href="course-offerings.php" class="cancel-link">
                    Cancel
                </a>
                <?php endif; ?>

            </div>

        </form>
    </div>

    <!-- OFFERING LIST AND SCHEDULE -->

    <div class="offering-card offerings-list-card">

        <h3>Existing Course Offerings & Class Schedules</h3>

        <div class="offerings-search-bar">

            <div class="search-control">
                <span class="search-icon" aria-hidden="true">&#128269;</span>

                <input type="search" id="offeringSearch"
                    placeholder="Search course, teacher, session, semester, room or day..."
                    aria-label="Search existing course offerings" autocomplete="off">
            </div>

            <span class="offerings-search-count" id="offeringSearchCount" aria-live="polite"></span>

        </div>

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

                    <tr data-offering-row>

                        <td>
                            <strong><?= e($row['course_code']) ?></strong>
                            <br>

                            <?= e($row['course_title']) ?>
                            <br>

                            <small>
                                <?= e($row['credit_hours']) ?> Credits
                            </small>
                        </td>

                        <td>
                            <?= e($row['session_name']) ?>
                        </td>

                        <td>
                            <?= e($row['semester_name']) ?>
                        </td>

                        <td>
                            <?= e($row['teacher_name'] ?? 'Not assigned') ?>
                        </td>

                        <td>
                            <?= (int)$row['capacity'] ?>
                        </td>

                        <td>
                            <?php if ((int)$row['registration_open'] === 1): ?>

                            <span class="status-open">Open</span>

                            <?php else: ?>

                            <span class="status-closed">Closed</span>

                            <?php endif; ?>
                        </td>

                        <td>

                            <form method="POST" class="schedule-form">

                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <input type="hidden" name="action" value="save_schedule">

                                <input type="hidden" name="offering_id" value="<?= (int)$row['id'] ?>">

                                <select name="class_day" required>
                                    <option value="">Select Day</option>

                                    <?php
                                            $days = [
                                                'Saturday',
                                                'Sunday',
                                                'Monday',
                                                'Tuesday',
                                                'Wednesday',
                                                'Thursday',
                                                'Friday'
                                            ];
                                            ?>

                                    <?php foreach ($days as $day): ?>

                                    <option value="<?= e($day) ?>"
                                        <?= ($row['class_day'] ?? '') === $day ? 'selected' : '' ?>>
                                        <?= e($day) ?>
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

                    <tr class="offerings-empty">
                        <td colspan="8">
                            No course offerings found.
                            Create your first offering above.
                        </td>
                    </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>
    </div>

    <a href="<?= e(BASE_URL) ?>/admin/dashboard.php" class="back-link">
        ← Back to Admin Dashboard
    </a>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    const searchInput = document.getElementById('offeringSearch');

    const table = document.querySelector(
        '.offerings-list-card .offering-table'
    );

    const countLabel = document.getElementById('offeringSearchCount');

    if (!searchInput || !table || !countLabel) {
        return;
    }

    const tbody = table.querySelector('tbody');

    const rows = Array.from(
        tbody.querySelectorAll('tr[data-offering-row]')
    );

    const noResultsRow = document.createElement('tr');

    noResultsRow.className = 'offerings-no-results';

    noResultsRow.style.display = 'none';

    noResultsRow.innerHTML =
        '<td colspan="8">No matching course offerings found.</td>';

    tbody.appendChild(noResultsRow);

    function filterOfferings() {

        const query = searchInput.value.trim().toLocaleLowerCase();

        let visible = 0;

        rows.forEach(function(row) {

            const text = row.textContent.toLocaleLowerCase();

            const matches = !query || text.includes(query);

            row.classList.toggle('search-hidden', !matches);

            if (matches) {
                visible++;
            }
        });

        noResultsRow.style.display =
            rows.length > 0 && visible === 0 ? '' : 'none';

        if (query) {

            countLabel.textContent =
                visible + ' of ' + rows.length + ' offering(s) shown';

        } else {

            countLabel.textContent =
                rows.length + ' offering(s)';
        }
    }

    searchInput.addEventListener('input', filterOfferings);

    filterOfferings();
});
</script>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>