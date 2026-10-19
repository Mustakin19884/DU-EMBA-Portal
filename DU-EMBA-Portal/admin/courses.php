<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

require_once __DIR__ . '/../config/database.php';

$message = '';
$error = '';

/* Load teacher accounts */
$teachers = [];

$teacherResult = $conn->query(
    "SELECT t.id, u.full_name, u.is_active
     FROM teachers t
     INNER JOIN users u ON u.id = t.user_id
     WHERE u.role = 'teacher'
     ORDER BY u.full_name ASC"
);

if (!$teacherResult) {
    die('Unable to load teachers.');
}

while ($teacher = $teacherResult->fetch_assoc()) {
    $teachers[] = $teacher;
}

/* Validate that a selected teacher is active */
function is_active_teacher(mysqli $conn, int $teacherId): bool
{
    $stmt = $conn->prepare(
        "SELECT t.id
         FROM teachers t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.id = ?
           AND u.role = 'teacher'
           AND u.is_active = 1
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $stmt->store_result();

    $valid = $stmt->num_rows === 1;
    $stmt->close();

    return $valid;
}

/* Add a new course */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['add_course'])
) {
    $courseCode = strtoupper(trim($_POST['course_code'] ?? ''));
    $courseTitle = trim($_POST['course_title'] ?? '');
    $creditHours = $_POST['credit_hours'] ?? '3.0';
    $description = trim($_POST['description'] ?? '');

    $teacherId = filter_var(
        $_POST['teacher_id'] ?? '0',
        FILTER_VALIDATE_INT
    );

    if ($teacherId === false || $teacherId < 0) {
        $error = 'Please select a valid teacher.';
    } elseif ($courseCode === '' || $courseTitle === '') {
        $error = 'Course code and course title are required.';
    } elseif (
        !is_numeric($creditHours)
        || (float) $creditHours <= 0
        || (float) $creditHours > 99.9
    ) {
        $error = 'Enter valid credit hours.';
    } elseif (
        $teacherId > 0
        && !is_active_teacher($conn, $teacherId)
    ) {
        $error = 'The selected teacher is invalid or inactive.';
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO courses
                (course_code, course_title, teacher_id,
                 credit_hours, description)
             VALUES (?, ?, NULLIF(?, 0), ?, ?)"
        );

        if (!$stmt) {
            $error = 'Unable to prepare course creation.';
        } else {
            $credits = (float) $creditHours;

            $stmt->bind_param(
                'ssids',
                $courseCode,
                $courseTitle,
                $teacherId,
                $credits,
                $description
            );

            if ($stmt->execute()) {
                $message = 'Course added successfully.';
            } elseif ($stmt->errno === 1062) {
                $error = 'This course code already exists.';
            } else {
                $error = 'Unable to add course. Please try again.';
            }

            $stmt->close();
        }
    }
}

/* Assign or change the teacher for an existing course */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['assign_teacher'])
) {
    $courseId = filter_var(
        $_POST['course_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $teacherId = filter_var(
        $_POST['teacher_id'] ?? '0',
        FILTER_VALIDATE_INT
    );

    if (
        !$courseId
        || $courseId < 1
        || $teacherId === false
        || $teacherId < 0
    ) {
        $error = 'Invalid course or teacher selection.';
    } elseif (
        $teacherId > 0
        && !is_active_teacher($conn, $teacherId)
    ) {
        $error = 'Please select an active teacher.';
    } else {
        if ($teacherId === 0) {
            $stmt = $conn->prepare(
                "UPDATE courses SET teacher_id = NULL WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('i', $courseId);
            }
        } else {
            $stmt = $conn->prepare(
                "UPDATE courses SET teacher_id = ? WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('ii', $teacherId, $courseId);
            }
        }

        if (!$stmt) {
            $error = 'Unable to prepare teacher assignment.';
        } else {
            if ($stmt->execute()) {
                $message = 'Course teacher assignment updated.';
            } else {
                $error = 'Unable to update teacher assignment.';
            }

            $stmt->close();
        }
    }
}

/* Activate or deactivate a course */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['toggle_course'])
) {
    $courseId = filter_var(
        $_POST['course_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $newStatus = filter_var(
        $_POST['new_status'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $courseId
        && $courseId > 0
        && in_array($newStatus, [0, 1], true)
    ) {
        $stmt = $conn->prepare(
            "UPDATE courses SET is_active = ? WHERE id = ?"
        );

        if ($stmt) {
            $stmt->bind_param('ii', $newStatus, $courseId);

            if ($stmt->execute()) {
                $message = 'Course status updated.';
            } else {
                $error = 'Unable to update course status.';
            }

            $stmt->close();
        } else {
            $error = 'Unable to prepare course status update.';
        }
    } else {
        $error = 'Invalid course information.';
    }
}

/* Load courses with assigned teacher names */
$courses = $conn->query(
    "SELECT
        c.id,
        c.course_code,
        c.course_title,
        c.credit_hours,
        c.description,
        c.is_active,
        c.teacher_id,
        u.full_name AS teacher_name,
        u.is_active AS teacher_active
     FROM courses c
     LEFT JOIN teachers t ON t.id = c.teacher_id
     LEFT JOIN users u ON u.id = t.user_id
     ORDER BY c.course_code ASC"
);

if (!$courses) {
    die('Unable to load courses.');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Course Management</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
</head>

<body>
    <main class="dashboard">

        <header class="dashboard-header">
            <div>
                <h1>Course Management</h1>
                <p>Add courses and assign teachers</p>
            </div>

            <a class="btn-logout" href="<?= e(BASE_URL) ?>/admin/dashboard.php">
                Admin Dashboard
            </a>
        </header>

        <section class="dashboard-content">

            <?php if ($message !== ''): ?>
            <p class="alert-success" role="status">
                <?= e($message) ?>
            </p>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
            <p class="alert-danger" role="alert">
                <?= e($error) ?>
            </p>
            <?php endif; ?>

            <h2>Add New Course</h2>

            <form method="post" action="<?= e(BASE_URL) ?>/admin/courses.php">

                <div class="form-grid">

                    <div class="form-group">
                        <label for="course_code">Course Code</label>
                        <input id="course_code" name="course_code" type="text" maxlength="30" required>
                    </div>

                    <div class="form-group">
                        <label for="course_title">Course Title</label>
                        <input id="course_title" name="course_title" type="text" maxlength="180" required>
                    </div>

                    <div class="form-group">
                        <label for="credit_hours">Credit Hours</label>
                        <input id="credit_hours" name="credit_hours" type="number" min="0.1" max="99.9" step="0.1"
                            value="3.0" required>
                    </div>

                    <div class="form-group">
                        <label for="teacher_id">Assign Teacher (Optional)</label>
                        <select id="teacher_id" name="teacher_id">
                            <option value="0">-- Not assigned --</option>

                            <?php foreach ($teachers as $teacher): ?>
                            <?php if ((int) $teacher['is_active'] === 1): ?>
                            <option value="<?= (int) $teacher['id'] ?>">
                                <?= e($teacher['full_name']) ?>
                            </option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3"></textarea>
                    </div>

                </div>

                <button type="submit" name="add_course" value="1">
                    Add Course
                </button>
            </form>
        </section>

        <section class="dashboard-content">
            <h2>Existing Courses</h2>

            <?php if ($courses->num_rows === 0): ?>
            <p>No courses have been added yet.</p>
            <?php else: ?>

            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Course Title</th>
                            <th>Credits</th>
                            <th>Assigned Teacher</th>
                            <th>Status</th>
                            <th>Assign / Change Teacher</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while ($course = $courses->fetch_assoc()): ?>
                        <tr>
                            <td><?= e($course['course_code']) ?></td>
                            <td><?= e($course['course_title']) ?></td>
                            <td><?= e($course['credit_hours']) ?></td>

                            <td>
                                <?php if (!empty($course['teacher_id'])): ?>
                                <?= e($course['teacher_name'] ?? 'Unknown') ?>

                                <?php if ((int) $course['teacher_active'] !== 1): ?>
                                <small>(Inactive account)</small>
                                <?php endif; ?>
                                <?php else: ?>
                                <span>Not assigned</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= (int) $course['is_active'] === 1
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </td>

                            <td>
                                <form method="post" action="<?= e(BASE_URL) ?>/admin/courses.php">

                                    <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">

                                    <select name="teacher_id" aria-label="Teacher for <?= e($course['course_code']) ?>">

                                        <option value="0">
                                            -- Not assigned --
                                        </option>

                                        <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?= (int) $teacher['id'] ?>" <?= (int) $course['teacher_id'] === (int) $teacher['id']
                                                    ? 'selected'
                                                    : '' ?>>
                                            <?= e($teacher['full_name']) ?>
                                            <?= (int) $teacher['is_active'] === 1
                                                    ? ''
                                                    : ' (Inactive)' ?>
                                        </option>
                                        <?php endforeach; ?>

                                    </select>

                                    <button type="submit" name="assign_teacher" value="1">
                                        Save Teacher
                                    </button>
                                </form>
                            </td>

                            <td>
                                <form method="post" action="<?= e(BASE_URL) ?>/admin/courses.php">

                                    <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">

                                    <input type="hidden" name="new_status"
                                        value="<?= (int) $course['is_active'] === 1 ? 0 : 1 ?>">

                                    <button type="submit" name="toggle_course" value="1">
                                        <?= (int) $course['is_active'] === 1
                                            ? 'Deactivate'
                                            : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>
        </section>

    </main>
</body>

</html>