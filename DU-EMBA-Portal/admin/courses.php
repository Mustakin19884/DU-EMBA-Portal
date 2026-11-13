<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

// Escape output
if (!function_exists('course_h')) {
    function course_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// CSRF token
if (empty($_SESSION['course_csrf'])) {
    $_SESSION['course_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['course_csrf'];
$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) ||
        !hash_equals($csrf, $_POST['csrf_token'])) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {

        $action = $_POST['action'] ?? '';

        try {

            /*
            | Add Course
            */
            if ($action === 'add_course') {

                $category_id = (int)($_POST['category_id'] ?? 0);
                $course_code = strtoupper(trim($_POST['course_code'] ?? ''));
                $course_title = trim($_POST['course_title'] ?? '');
                $credit_hours = (float)($_POST['credit_hours'] ?? 3);
                $description = trim($_POST['description'] ?? '');

                if (!$category_id || !$course_code || !$course_title) {
                    throw new Exception('Category, course code and title are required.');
                }

                if ($credit_hours <= 0 || $credit_hours > 99.9) {
                    throw new Exception('Enter a valid credit hour value.');
                }

                $check = $conn->prepare(
                    "SELECT id FROM course_categories
                     WHERE id = ? AND is_active = 1"
                );
                $check->bind_param("i", $category_id);
                $check->execute();

                if (!$check->get_result()->fetch_assoc()) {
                    throw new Exception('Please select a valid active category.');
                }

                $stmt = $conn->prepare(
                    "INSERT INTO courses
                    (course_code, course_title, category_id, credit_hours, description)
                    VALUES (?, ?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "ssids",
                    $course_code,
                    $course_title,
                    $category_id,
                    $credit_hours,
                    $description
                );

                $stmt->execute();

                header('Location: courses.php?success=course_added');
                exit;
            }

            /*
            | Edit Course
            */
            if ($action === 'edit_course') {

                $course_id = (int)($_POST['course_id'] ?? 0);
                $category_id = (int)($_POST['category_id'] ?? 0);
                $course_code = strtoupper(trim($_POST['course_code'] ?? ''));
                $course_title = trim($_POST['course_title'] ?? '');
                $credit_hours = (float)($_POST['credit_hours'] ?? 3);
                $description = trim($_POST['description'] ?? '');

                if (!$course_id || !$category_id || !$course_code || !$course_title) {
                    throw new Exception('Please fill in all required course fields.');
                }

                if ($credit_hours <= 0 || $credit_hours > 99.9) {
                    throw new Exception('Enter a valid credit hour value.');
                }

                $stmt = $conn->prepare(
                    "UPDATE courses
                     SET course_code = ?, course_title = ?,
                         category_id = ?, credit_hours = ?, description = ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "ssidsi",
                    $course_code,
                    $course_title,
                    $category_id,
                    $credit_hours,
                    $description,
                    $course_id
                );

                $stmt->execute();

                header('Location: courses.php?success=course_updated');
                exit;
            }

            /*
            | Activate / Deactivate Course
            */
            if ($action === 'toggle_course') {

                $course_id = (int)($_POST['course_id'] ?? 0);

                $stmt = $conn->prepare(
                    "UPDATE courses
                     SET is_active = IF(is_active = 1, 0, 1)
                     WHERE id = ?"
                );

                $stmt->bind_param("i", $course_id);
                $stmt->execute();

                header('Location: courses.php?success=course_status');
                exit;
            }

            /*
            | Create Semester-wise Course Offering
            */
            if ($action === 'add_offering') {

                $course_id = (int)($_POST['course_id'] ?? 0);
                $semester_id = (int)($_POST['semester_id'] ?? 0);
                $teacher_id = !empty($_POST['teacher_id'])
                    ? (int)$_POST['teacher_id'] : null;
                $capacity = (int)($_POST['capacity'] ?? 60);
                $registration_open = isset($_POST['registration_open']) ? 1 : 0;

                if (!$course_id || !$semester_id) {
                    throw new Exception('Please select a course and semester.');
                }

                if ($capacity < 1) {
                    throw new Exception('Capacity must be at least 1.');
                }

                $check = $conn->prepare(
                    "SELECT id FROM course_offerings
                     WHERE course_id = ? AND semester_id = ?"
                );
                $check->bind_param("ii", $course_id, $semester_id);
                $check->execute();

                if ($check->get_result()->fetch_assoc()) {
                    throw new Exception('This course is already offered in the selected semester.');
                }

                if ($teacher_id !== null) {
                    $check = $conn->prepare(
                        "SELECT t.id
                         FROM teachers t
                         JOIN users u ON u.id = t.user_id
                         WHERE t.id = ? AND u.role = 'teacher'
                         AND u.is_active = 1"
                    );
                    $check->bind_param("i", $teacher_id);
                    $check->execute();

                    if (!$check->get_result()->fetch_assoc()) {
                        throw new Exception('Selected teacher is not active.');
                    }
                }

                $stmt = $conn->prepare(
                    "INSERT INTO course_offerings
                    (course_id, semester_id, teacher_id, capacity, registration_open)
                    VALUES (?, ?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "iiiii",
                    $course_id,
                    $semester_id,
                    $teacher_id,
                    $capacity,
                    $registration_open
                );

                $stmt->execute();

                header('Location: courses.php?success=offering_added');
                exit;
            }

            /*
            | Update Offering: Teacher, Capacity, Registration
            */
            if ($action === 'update_offering') {

                $offering_id = (int)($_POST['offering_id'] ?? 0);
                $teacher_id = !empty($_POST['teacher_id'])
                    ? (int)$_POST['teacher_id'] : null;
                $capacity = (int)($_POST['capacity'] ?? 60);
                $registration_open = isset($_POST['registration_open']) ? 1 : 0;

                if (!$offering_id || $capacity < 1) {
                    throw new Exception('Please provide valid offering information.');
                }

                if ($teacher_id !== null) {
                    $check = $conn->prepare(
                        "SELECT t.id
                         FROM teachers t
                         JOIN users u ON u.id = t.user_id
                         WHERE t.id = ? AND u.role = 'teacher'
                         AND u.is_active = 1"
                    );
                    $check->bind_param("i", $teacher_id);
                    $check->execute();

                    if (!$check->get_result()->fetch_assoc()) {
                        throw new Exception('Selected teacher is not active.');
                    }
                }

                $stmt = $conn->prepare(
                    "UPDATE course_offerings
                     SET teacher_id = ?, capacity = ?, registration_open = ?
                     WHERE id = ?"
                );

                $stmt->bind_param(
                    "iiii",
                    $teacher_id,
                    $capacity,
                    $registration_open,
                    $offering_id
                );

                $stmt->execute();

                header('Location: courses.php?success=offering_updated');
                exit;
            }

        } catch (mysqli_sql_exception $ex) {
            if ((int)$ex->getCode() === 1062) {
                $error = 'This course code already exists.';
            } else {
                $error = 'Database error: ' . $ex->getMessage();
            }
        } catch (Exception $ex) {
            $error = $ex->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGES
|--------------------------------------------------------------------------
*/

$successMessages = [
    'course_added' => 'Course added successfully.',
    'course_updated' => 'Course information updated successfully.',
    'course_status' => 'Course status updated successfully.',
    'offering_added' => 'Semester-wise course offering created successfully.',
    'offering_updated' => 'Course offering updated successfully.'
];

if (isset($_GET['success'])) {
    $message = $successMessages[$_GET['success']] ?? '';
}

/*
|--------------------------------------------------------------------------
| EDIT COURSE DATA
|--------------------------------------------------------------------------
*/

$editCourse = null;

if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];

    $stmt = $conn->prepare(
        "SELECT * FROM courses WHERE id = ?"
    );
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();

    $editCourse = $stmt->get_result()->fetch_assoc();
}

/*
|--------------------------------------------------------------------------
| DROPDOWN DATA
|--------------------------------------------------------------------------
*/

// Active categories
$categories = $conn->query(
    "SELECT id, category_name
     FROM course_categories
     WHERE is_active = 1
     ORDER BY id ASC"
)->fetch_all(MYSQLI_ASSOC);

// Active courses
$activeCourses = $conn->query(
    "SELECT id, course_code, course_title
     FROM courses
     WHERE is_active = 1
     ORDER BY course_code ASC"
)->fetch_all(MYSQLI_ASSOC);

// Semesters
$semesters = $conn->query(
    "SELECT id, semester_name, status
     FROM semesters
     ORDER BY start_date DESC, id DESC"
)->fetch_all(MYSQLI_ASSOC);

// Active teachers
$teachers = $conn->query(
    "SELECT t.id, t.employee_id, t.designation, u.full_name
     FROM teachers t
     JOIN users u ON u.id = t.user_id
     WHERE u.role = 'teacher' AND u.is_active = 1
     ORDER BY u.full_name ASC"
)->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| COURSE LIST
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$categoryFilter = (int)($_GET['category'] ?? 0);

$sql = "
    SELECT c.*, cc.category_name
    FROM courses c
    LEFT JOIN course_categories cc ON cc.id = c.category_id
    WHERE 1=1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (c.course_code LIKE ? OR c.course_title LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($categoryFilter > 0) {
    $sql .= " AND c.category_id = ?";
    $params[] = $categoryFilter;
    $types .= 'i';
}

$sql .= " ORDER BY cc.id ASC, c.course_code ASC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| COURSE OFFERINGS LIST
|--------------------------------------------------------------------------
*/

$offerings = $conn->query(
    "SELECT
        co.id AS offering_id,
        co.capacity,
        co.registration_open,
        c.course_code,
        c.course_title,
        s.semester_name,
        t.id AS teacher_id,
        t.employee_id,
        u.full_name AS teacher_name
     FROM course_offerings co
     JOIN courses c ON c.id = co.course_id
     JOIN semesters s ON s.id = co.semester_id
     LEFT JOIN teachers t ON t.id = co.teacher_id
     LEFT JOIN users u ON u.id = t.user_id
     ORDER BY s.start_date DESC, c.course_code ASC"
)->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../includes/admin-header.php';
?>

<style>
.course-page {
    max-width: 1500px;
    margin: 25px auto;
    padding: 0 24px 40px;
}

.course-heading {
    margin-bottom: 22px;
}

.course-heading h1 {
    color: var(--navy, #102d50);
    margin-bottom: 7px;
}

.course-heading p {
    color: var(--muted, #687789);
    margin: 0;
}

.course-panel {
    background: var(--white, #fff);
    border: 1px solid var(--border, #e1e5eb);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 3px 12px rgba(16, 45, 80, .04);
}

.course-panel h2 {
    color: var(--navy, #102d50);
    font-size: 20px;
    margin: 0 0 18px;
}

.course-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.course-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 7px;
    color: var(--text, #263548);
}

.course-field input,
.course-field select,
.course-field textarea {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid var(--border, #e1e5eb);
    border-radius: 7px;
    font: inherit;
    background: #fff;
    color: var(--text, #263548);
}

.course-field textarea {
    min-height: 85px;
    resize: vertical;
}

.course-full {
    grid-column: 1 / -1;
}

.course-btn {
    display: inline-block;
    border: 0;
    border-radius: 7px;
    padding: 10px 17px;
    background: var(--navy, #102d50);
    color: #fff;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
}

.course-btn:hover {
    background: var(--navy-light, #1c456e);
    color: #fff;
}

.course-btn.secondary {
    background: #687789;
}

.course-btn.warning {
    background: #a66b16;
}

.course-btn.small {
    padding: 7px 11px;
    font-size: 13px;
}

.course-alert {
    padding: 13px 16px;
    border-radius: 8px;
    margin-bottom: 18px;
}

.course-alert.success {
    background: #e8f6ed;
    color: #176b37;
}

.course-alert.error {
    background: #fdecec;
    color: #a52828;
}

.course-table-wrap {
    overflow-x: auto;
}

.course-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

.course-table th,
.course-table td {
    text-align: left;
    padding: 12px 10px;
    border-bottom: 1px solid var(--border, #e1e5eb);
    vertical-align: middle;
}

.course-table th {
    background: var(--beige, #f4efe5);
    color: var(--navy, #102d50);
    white-space: nowrap;
}

.course-table td {
    color: var(--text, #263548);
}

.course-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.course-inline-form {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.course-inline-form select,
.course-inline-form input {
    padding: 8px;
    border: 1px solid var(--border, #e1e5eb);
    border-radius: 6px;
}

.course-filter {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.course-filter input,
.course-filter select {
    padding: 10px;
    border: 1px solid var(--border, #e1e5eb);
    border-radius: 7px;
}

.course-status {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.course-status.active {
    background: #e5f5e9;
    color: #18743b;
}

.course-status.inactive {
    background: #fceaea;
    color: #a12b2b;
}

@media (max-width: 700px) {
    .course-page {
        padding: 0 12px 30px;
    }

    .course-panel {
        padding: 16px;
    }

    .course-form-grid {
        grid-template-columns: 1fr;
    }

    .course-full {
        grid-column: auto;
    }
}
</style>

<div class="course-page">

    <div class="course-heading">
        <h1>Course Management</h1>
        <p>Manage course information, semester offerings and teacher assignments.</p>
    </div>

    <?php if ($message): ?>
    <div class="course-alert success">
        <?= course_h($message) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="course-alert error">
        <?= course_h($error) ?>
    </div>
    <?php endif; ?>

    <!-- ADD / EDIT COURSE -->

    <section class="course-panel" id="course-form">
        <h2><?= $editCourse ? 'Edit Course' : 'Add New Course' ?></h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= course_h($csrf) ?>">

            <input type="hidden" name="action" value="<?= $editCourse ? 'edit_course' : 'add_course' ?>">

            <?php if ($editCourse): ?>
            <input type="hidden" name="course_id" value="<?= (int)$editCourse['id'] ?>">
            <?php endif; ?>

            <div class="course-form-grid">

                <div class="course-field">
                    <label>Course Category *</label>
                    <select name="category_id" required>
                        <option value="">Select Category</option>

                        <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" <?= ($editCourse &&
                                    (int)$editCourse['category_id'] === (int)$category['id'])
                                    ? 'selected' : '' ?>>
                            <?= course_h($category['category_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="course-field">
                    <label>Course Code *</label>
                    <input type="text" name="course_code" maxlength="30" required placeholder="e.g. EMIS-501"
                        value="<?= course_h($editCourse['course_code'] ?? '') ?>">
                </div>

                <div class="course-field course-full">
                    <label>Course Title *</label>
                    <input type="text" name="course_title" maxlength="180" required placeholder="Enter course title"
                        value="<?= course_h($editCourse['course_title'] ?? '') ?>">
                </div>

                <div class="course-field">
                    <label>Credit Hours *</label>
                    <input type="number" name="credit_hours" min="0.1" max="99.9" step="0.1" required
                        value="<?= course_h($editCourse['credit_hours'] ?? '3.0') ?>">
                </div>

                <div class="course-field course-full">
                    <label>Description</label>
                    <textarea name="description"
                        placeholder="Course description (optional)"><?= course_h($editCourse['description'] ?? '') ?></textarea>
                </div>

            </div>

            <div style="margin-top:18px; display:flex; gap:10px;">
                <button type="submit" class="course-btn">
                    <?= $editCourse ? 'Update Course' : 'Add Course' ?>
                </button>

                <?php if ($editCourse): ?>
                <a href="courses.php" class="course-btn secondary">
                    Cancel
                </a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- COURSE MASTER LIST -->

    <section class="course-panel">
        <h2>Manage Existing Courses</h2>

        <form method="GET" class="course-filter">
            <input type="text" name="search" placeholder="Search course code or title" value="<?= course_h($search) ?>">

            <select name="category">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $category): ?>
                <option value="<?= (int)$category['id'] ?>"
                    <?= $categoryFilter === (int)$category['id'] ? 'selected' : '' ?>>
                    <?= course_h($category['category_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="course-btn">Search</button>
            <a href="courses.php" class="course-btn secondary">Reset</a>
        </form>

        <div class="course-table-wrap">
            <table class="course-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Course Title</th>
                        <th>Category</th>
                        <th>Credits</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($courses)): ?>
                    <tr>
                        <td colspan="6">No courses found.</td>
                    </tr>
                    <?php else: ?>

                    <?php foreach ($courses as $course): ?>
                    <tr>
                        <td><strong><?= course_h($course['course_code']) ?></strong></td>

                        <td><?= course_h($course['course_title']) ?></td>

                        <td><?= course_h($course['category_name'] ?? 'Not assigned') ?></td>

                        <td><?= course_h($course['credit_hours']) ?></td>

                        <td>
                            <span class="course-status <?= $course['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $course['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>

                        <td>
                            <div class="course-actions">

                                <a class="course-btn small"
                                    href="courses.php?edit=<?= (int)$course['id'] ?>#course-form">
                                    Edit
                                </a>

                                <form method="POST" onsubmit="return confirm('Change this course status?');">
                                    <input type="hidden" name="csrf_token" value="<?= course_h($csrf) ?>">
                                    <input type="hidden" name="action" value="toggle_course">
                                    <input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>">

                                    <button type="submit" class="course-btn small warning">
                                        <?= $course['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- CREATE COURSE OFFERING -->

    <section class="course-panel">
        <h2>Create Semester-wise Course Offering</h2>

        <p style="color:var(--muted); margin-top:-8px; margin-bottom:20px;">
            Select a course, semester and teacher. The same course can be offered
            in different semesters with different teachers.
        </p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= course_h($csrf) ?>">
            <input type="hidden" name="action" value="add_offering">

            <div class="course-form-grid">

                <div class="course-field">
                    <label>Course *</label>
                    <select name="course_id" required>
                        <option value="">Select Course</option>
                        <?php foreach ($activeCourses as $course): ?>
                        <option value="<?= (int)$course['id'] ?>">
                            <?= course_h($course['course_code'] . ' - ' . $course['course_title']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="course-field">
                    <label>Semester *</label>
                    <select name="semester_id" required>
                        <option value="">Select Semester</option>
                        <?php foreach ($semesters as $semester): ?>
                        <option value="<?= (int)$semester['id'] ?>">
                            <?= course_h($semester['semester_name'] . ' (' . $semester['status'] . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="course-field">
                    <label>Assign Teacher</label>
                    <select name="teacher_id">
                        <option value="">Not assigned yet</option>
                        <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= (int)$teacher['id'] ?>">
                            <?= course_h($teacher['full_name'] . ' - ' . $teacher['employee_id']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="course-field">
                    <label>Student Capacity</label>
                    <input type="number" name="capacity" min="1" value="60" required>
                </div>

                <div class="course-field course-full">
                    <label style="display:flex; align-items:center; gap:9px;">
                        <input type="checkbox" name="registration_open" value="1" style="width:auto;">
                        Open student registration for this offering
                    </label>
                </div>

            </div>

            <button type="submit" class="course-btn" style="margin-top:18px;">
                Create Course Offering
            </button>
        </form>
    </section>

    <!-- EXISTING OFFERINGS -->

    <section class="course-panel">
        <h2>Semester-wise Offerings & Teacher Assignments</h2>

        <div class="course-table-wrap">
            <table class="course-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Semester</th>
                        <th>Assigned Teacher</th>
                        <th>Capacity</th>
                        <th>Registration</th>
                        <th>Update</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($offerings)): ?>
                    <tr>
                        <td colspan="6">No course offerings created yet.</td>
                    </tr>
                    <?php else: ?>

                    <?php foreach ($offerings as $offering): ?>
                    <tr>
                        <td>
                            <strong><?= course_h($offering['course_code']) ?></strong><br>
                            <?= course_h($offering['course_title']) ?>
                        </td>

                        <td><?= course_h($offering['semester_name']) ?></td>

                        <td>
                            <?= course_h($offering['teacher_name'] ?? 'Not assigned') ?>
                        </td>

                        <td><?= (int)$offering['capacity'] ?></td>

                        <td>
                            <span class="course-status <?= $offering['registration_open'] ? 'active' : 'inactive' ?>">
                                <?= $offering['registration_open'] ? 'Open' : 'Closed' ?>
                            </span>
                        </td>

                        <td>
                            <form method="POST" class="course-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= course_h($csrf) ?>">
                                <input type="hidden" name="action" value="update_offering">
                                <input type="hidden" name="offering_id" value="<?= (int)$offering['offering_id'] ?>">

                                <select name="teacher_id">
                                    <option value="">Not assigned</option>

                                    <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?= (int)$teacher['id'] ?>"
                                        <?= (int)($offering['teacher_id'] ?? 0) === (int)$teacher['id'] ? 'selected' : '' ?>>
                                        <?= course_h($teacher['full_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="number" name="capacity" min="1" value="<?= (int)$offering['capacity'] ?>"
                                    style="width:75px;">

                                <label style="display:flex; align-items:center; gap:5px;">
                                    <input type="checkbox" name="registration_open" value="1"
                                        <?= $offering['registration_open'] ? 'checked' : '' ?>>
                                    Open
                                </label>

                                <button type="submit" class="course-btn small">
                                    Save
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>