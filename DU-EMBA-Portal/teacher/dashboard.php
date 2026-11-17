<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('teacher');
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Teacher Dashboard';
$userId = (int) ($_SESSION['user_id'] ?? 0);

$teacher = null;
$courses = [];
$stats = [
    'courses' => 0,
    'students' => 0,
    'semesters' => 0
];
$error = '';

try {
    // Get teacher profile
    $stmt = $conn->prepare("
        SELECT id, employee_id, designation, department
        FROM teachers
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$teacher) {
        $error = 'Your teacher profile is not linked to this login. Please contact the Administrator.';
    } else {

        $teacherId = (int) $teacher['id'];

        // Get semester-wise assigned courses
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
                cs.room,
                COUNT(
                    DISTINCT CASE
                        WHEN cr.status = 'registered' THEN cr.id
                    END
                ) AS student_count

            FROM course_offerings co

            INNER JOIN courses c
                ON c.id = co.course_id

            INNER JOIN semesters sem
                ON sem.id = co.semester_id

            LEFT JOIN course_schedules cs
                ON cs.offering_id = co.id

            LEFT JOIN course_registrations cr
                ON cr.offering_id = co.id

            WHERE co.teacher_id = ?
              AND c.is_active = 1

            GROUP BY
                co.id,
                c.course_code,
                c.course_title,
                c.credit_hours,
                sem.semester_name,
                sem.status,
                cs.class_day,
                cs.start_time,
                cs.end_time,
                cs.room

            ORDER BY
                sem.start_date DESC,
                c.course_code ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }

        $stmt->close();

        $stats['courses'] = count($courses);

        // Total registered students
        $studentSql = "
            SELECT COUNT(DISTINCT cr.student_id) AS total
            FROM course_registrations cr
            INNER JOIN course_offerings co
                ON co.id = cr.offering_id
            WHERE co.teacher_id = ?
              AND cr.status = 'registered'
        ";

        $stmt = $conn->prepare($studentSql);
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();

        $stats['students'] = (int) (
            $stmt->get_result()->fetch_assoc()['total'] ?? 0
        );

        $stmt->close();

        // Total semesters with assignments
        $semesterSql = "
            SELECT COUNT(DISTINCT semester_id) AS total
            FROM course_offerings
            WHERE teacher_id = ?
        ";

        $stmt = $conn->prepare($semesterSql);
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();

        $stats['semesters'] = (int) (
            $stmt->get_result()->fetch_assoc()['total'] ?? 0
        );

        $stmt->close();
    }

} catch (Throwable $e) {

    error_log('Teacher dashboard: ' . $e->getMessage());

    $error = 'We could not load the teaching information. Please check the database configuration.';
}

require_once __DIR__ . '/../includes/teacher-header.php';
?>

<style>
.teacher-welcome {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.teacher-welcome h1 {
    margin: 0 0 6px;
    color: #102d50;
}

.teacher-welcome p {
    margin: 0;
    color: #687789;
}

.teacher-stat-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin: 20px 0;
}

.teacher-stat {
    background: #fff;
    border: 1px solid #e1e5eb;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 4px 14px rgba(16, 45, 80, .05);
}

.teacher-stat span {
    display: block;
    color: #687789;
    font-size: 13px;
}

.teacher-stat strong {
    display: block;
    color: #102d50;
    font-size: 28px;
    margin-top: 7px;
}

.teacher-panel {
    background: #fff;
    border: 1px solid #e1e5eb;
    border-radius: 14px;
    padding: 22px;
    margin-top: 18px;
    overflow: hidden;
}

.teacher-panel h2 {
    margin: 0 0 16px;
    color: #102d50;
    font-size: 20px;
}

.teacher-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.teacher-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 760px;
}

.teacher-table th,
.teacher-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #e8ebef;
    text-align: left;
    vertical-align: top;
}

.teacher-table th {
    background: #f4efe5;
    color: #102d50;
    font-size: 13px;
}

.teacher-table td {
    font-size: 14px;
}

.teacher-action {
    display: inline-block;
    padding: 8px 12px;
    background: #102d50;
    color: #fff;
    border-radius: 7px;
    text-decoration: none;
    font-size: 13px;
    white-space: nowrap;
}

.teacher-muted {
    color: #687789;
    font-size: 13px;
}

.teacher-alert {
    padding: 14px 16px;
    border-radius: 10px;
    background: #fff4e5;
    color: #80520b;
    border: 1px solid #f1d6a8;
    margin: 16px 0;
}

@media(max-width:700px) {
    .teacher-stat-grid {
        grid-template-columns: 1fr;
    }

    .teacher-panel {
        padding: 16px;
    }
}
</style>

<div class="teacher-welcome">
    <div>
        <h1>Teacher Dashboard</h1>

        <p>
            Welcome,
            <?= e($_SESSION['full_name'] ?? 'Teacher') ?>.
            Here is your teaching overview.
        </p>
    </div>

    <?php if ($teacher): ?>
    <div class="teacher-muted">
        <?= e($teacher['designation'] ?? 'Teacher') ?>

        <?= !empty($teacher['department'])
                ? ' · ' . e($teacher['department'])
                : '' ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($error !== ''): ?>
<div class="teacher-alert">
    <?= e($error) ?>
</div>
<?php endif; ?>

<div class="teacher-stat-grid">

    <div class="teacher-stat">
        <span>Assigned Course Offerings</span>
        <strong><?= $stats['courses'] ?></strong>
    </div>

    <div class="teacher-stat">
        <span>Registered Students</span>
        <strong><?= $stats['students'] ?></strong>
    </div>

    <div class="teacher-stat">
        <span>Semesters with Assignments</span>
        <strong><?= $stats['semesters'] ?></strong>
    </div>

</div>

<section class="teacher-panel">

    <h2>My Assigned Courses</h2>

    <?php if (!$error && !$courses): ?>

    <p class="teacher-muted">
        No active course offerings are currently assigned
        to your account. Please contact the Academic Officer.
    </p>

    <?php elseif ($courses): ?>

    <div class="teacher-table-wrap">

        <table class="teacher-table">

            <thead>
                <tr>
                    <th>Course</th>
                    <th>Semester</th>
                    <th>Class Schedule</th>
                    <th>Students</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($courses as $course): ?>

                <tr>

                    <td>
                        <strong>
                            <?= e($course['course_code']) ?>
                        </strong>

                        <br>

                        <?= e($course['course_title']) ?>

                        <br>

                        <span class="teacher-muted">
                            <?= e((string)$course['credit_hours']) ?>
                            credit hours
                        </span>
                    </td>

                    <td>
                        <?= e($course['semester_name']) ?>

                        <br>

                        <span class="teacher-muted">
                            <?= e(ucfirst($course['semester_status'])) ?>
                        </span>
                    </td>

                    <td>

                        <?php if ($course['class_day']): ?>

                        <?= e($course['class_day']) ?>

                        <br>

                        <?= e(substr((string)$course['start_time'], 0, 5)) ?>
                        –
                        <?= e(substr((string)$course['end_time'], 0, 5)) ?>

                        <br>

                        <span class="teacher-muted">
                            Room: <?= e($course['room']) ?>
                        </span>

                        <?php else: ?>

                        <span class="teacher-muted">
                            Schedule not set
                        </span>

                        <?php endif; ?>

                    </td>

                    <td>
                        <?= (int)$course['student_count'] ?>
                    </td>

                    <td>
                        <a class="teacher-action"
                            href="<?= BASE_URL ?>/teacher/students.php?offering_id=<?= (int)$course['offering_id'] ?>">
                            View Students
                        </a>
                    </td>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php endif; ?>

</section>

<?php require_once __DIR__ . '/../includes/teacher-footer.php'; ?>