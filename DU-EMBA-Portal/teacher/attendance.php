<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('teacher');

$pageTitle = 'Attendance Management';

$userId = (int) $_SESSION['user_id'];

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['attendance_csrf'])) {
    $_SESSION['attendance_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['attendance_csrf'];

$teacherId = 0;
$offerings = [];
$students = [];
$attendanceRecords = [];

$error = '';
$success = '';

$selectedOfferingId = (int) ($_REQUEST['offering_id'] ?? 0);

$selectedDate = $_REQUEST['class_date'] ?? date('Y-m-d');

if (
    !is_string($selectedDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)
) {
    $selectedDate = date('Y-m-d');
}

$dateObject = DateTime::createFromFormat('!Y-m-d', $selectedDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}


// --------------------------------------------------
// 1. Get teacher profile
// --------------------------------------------------

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
        $error = 'Teacher profile is not linked to your account.';
    } else {
        $teacherId = (int) $teacher['id'];
    }

} catch (Throwable $e) {
    error_log('Attendance teacher profile: ' . $e->getMessage());
    $error = 'Unable to load teacher information.';
}


// --------------------------------------------------
// 2. Get assigned course offerings
// --------------------------------------------------

if ($teacherId > 0 && $error === '') {
    try {
        $stmt = $conn->prepare("
            SELECT
                co.id AS offering_id,
                c.course_code,
                c.course_title,
                sem.semester_name

            FROM course_offerings co

            INNER JOIN courses c
                ON c.id = co.course_id

            INNER JOIN semesters sem
                ON sem.id = co.semester_id

            WHERE co.teacher_id = ?
              AND c.is_active = 1

            ORDER BY sem.start_date DESC, c.course_code ASC
        ");

        $stmt->bind_param('i', $teacherId);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $offerings[] = $row;
        }

        $stmt->close();

    } catch (Throwable $e) {
        error_log('Attendance offerings: ' . $e->getMessage());
        $error = 'Unable to load assigned courses.';
    }
}


// --------------------------------------------------
// 3. Validate selected offering
// --------------------------------------------------

$selectedOffering = null;

foreach ($offerings as $offering) {
    if ((int) $offering['offering_id'] === $selectedOfferingId) {
        $selectedOffering = $offering;
        break;
    }
}

if ($selectedOfferingId > 0 && !$selectedOffering) {
    $error = 'You are not authorized to manage attendance for this course.';
}


// --------------------------------------------------
// 4. Load registered students
// --------------------------------------------------

function loadAttendanceStudents(
    mysqli $conn,
    int $offeringId,
    string $classDate
): array {

    $sql = "
        SELECT
            cr.id AS registration_id,
            s.student_id,
            u.full_name,
            u.username,
            b.batch_name,
            a.id AS attendance_id,
            a.status AS attendance_status

        FROM course_registrations cr

        INNER JOIN students s
            ON s.id = cr.student_id

        INNER JOIN users u
            ON u.id = s.user_id

        LEFT JOIN batches b
            ON b.id = s.batch_id

        LEFT JOIN attendance a
            ON a.registration_id = cr.id
           AND a.class_date = ?

        WHERE cr.offering_id = ?
          AND cr.status = 'registered'
          AND u.role = 'student'
          AND u.is_active = 1

        ORDER BY u.full_name ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $classDate, $offeringId);
    $stmt->execute();

    $result = $stmt->get_result();

    $students = [];

    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    $stmt->close();

    return $students;
}

if ($selectedOffering && $error === '') {
    try {
        $students = loadAttendanceStudents(
            $conn,
            $selectedOfferingId,
            $selectedDate
        );
    } catch (Throwable $e) {
        error_log('Attendance student loading: ' . $e->getMessage());
        $error = 'Unable to load registered students.';
    }
}


// --------------------------------------------------
// 5. Save attendance
// --------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $selectedOffering &&
    $error === ''
) {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (empty($students)) {
        $error = 'There are no registered students to mark attendance.';
    } else {

        $allowedStatuses = [
            'present',
            'absent',
            'late',
            'excused'
        ];

        $submittedAttendance = $_POST['attendance'] ?? [];

        if (!is_array($submittedAttendance)) {
            $submittedAttendance = [];
        }

        $validRegistrationIds = [];

        foreach ($students as $student) {
            $validRegistrationIds[] = (int) $student['registration_id'];
        }

        $validatedAttendance = [];
        $validationFailed = false;

        foreach ($validRegistrationIds as $registrationId) {

            $status = $submittedAttendance[$registrationId] ?? '';

            if (!in_array($status, $allowedStatuses, true)) {
                $validationFailed = true;
                break;
            }

            $validatedAttendance[$registrationId] = $status;
        }

        if ($validationFailed) {
            $error = 'Please select a valid attendance status for every student.';
        } else {

            try {

                $conn->begin_transaction();

                // Verify offering ownership again.
                $check = $conn->prepare("
                    SELECT id
                    FROM course_offerings
                    WHERE id = ?
                      AND teacher_id = ?
                    FOR UPDATE
                ");

                $check->bind_param(
                    'ii',
                    $selectedOfferingId,
                    $teacherId
                );

                $check->execute();

                $authorizedOffering = $check->get_result()->fetch_assoc();
                $check->close();

                if (!$authorizedOffering) {
                    throw new RuntimeException('Course authorization failed.');
                }

                // Update existing record or insert a new record.
                $updateStmt = $conn->prepare("
                    UPDATE attendance
                    SET status = ?, recorded_by = ?
                    WHERE registration_id = ?
                      AND class_date = ?
                ");

                $insertStmt = $conn->prepare("
                    INSERT INTO attendance
                    (
                        registration_id,
                        class_date,
                        status,
                        recorded_by
                    )
                    VALUES (?, ?, ?, ?)
                ");

                $savedCount = 0;

                foreach ($validatedAttendance as $registrationId => $status) {

                    // Confirm registration still belongs to this offering.
                    if (!in_array($registrationId, $validRegistrationIds, true)) {
                        continue;
                    }

                    $updateStmt->bind_param(
                        'siis',
                        $status,
                        $userId,
                        $registrationId,
                        $selectedDate
);

                    $updateStmt->execute();

                    if ($updateStmt->affected_rows === 0) {

                        $existsStmt = $conn->prepare("
                            SELECT id
                            FROM attendance
                            WHERE registration_id = ?
                              AND class_date = ?
                            LIMIT 1
                        ");

                        $existsStmt->bind_param(
                            'is',
                            $registrationId,
                            $selectedDate
                        );

                        $existsStmt->execute();

                        $existing = $existsStmt->get_result()->fetch_assoc();
                        $existsStmt->close();

                        if ($existing) {
                            // Record exists with the same value; no insert needed.
                        } else {
                            $insertStmt->bind_param(
                                'issi',
                                $registrationId,
                                $selectedDate,
                                $status,
                                $userId
                            );

                            $insertStmt->execute();
                        }
                    }

                    $savedCount++;
                }

                $updateStmt->close();
                $insertStmt->close();

                $conn->commit();

                $success = 'Attendance saved successfully for ' .
                    $savedCount . ' student(s).';

                // Reload saved attendance.
                $students = loadAttendanceStudents(
                    $conn,
                    $selectedOfferingId,
                    $selectedDate
                );

                // Refresh token after successful submission.
                $_SESSION['attendance_csrf'] = bin2hex(random_bytes(32));
                $csrfToken = $_SESSION['attendance_csrf'];

            } catch (Throwable $e) {

                $conn->rollback();

                error_log('Attendance save error: ' . $e->getMessage());

                $error = 'Attendance could not be saved. Please try again.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/teacher-header.php';
?>

<style>
.attendance-header {
    margin-bottom: 22px;
}

.attendance-header h1 {
    color: #102d50;
    margin: 0 0 6px;
}

.attendance-header p {
    color: #687789;
    margin: 0;
}

.attendance-panel {
    background: #fff;
    border: 1px solid #e1e5eb;
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 20px;
}

.attendance-panel h2 {
    color: #102d50;
    font-size: 19px;
    margin: 0 0 18px;
}

.attendance-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.attendance-field label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #263548;
}

.attendance-field select,
.attendance-field input {
    width: 100%;
    padding: 12px;
    border: 1px solid #d6dce4;
    border-radius: 8px;
    background: #fff;
}

.attendance-button {
    display: inline-block;
    padding: 11px 18px;
    background: #102d50;
    color: #fff;
    border: 0;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    font-size: 14px;
    margin-top: 16px;
}

.attendance-button:hover {
    background: #1c456e;
}

.attendance-alert {
    padding: 14px 16px;
    border-radius: 9px;
    margin-bottom: 18px;
}

.attendance-alert.success {
    background: #e9f7ef;
    border: 1px solid #b8e2c8;
    color: #17643a;
}

.attendance-alert.error {
    background: #fff4e5;
    border: 1px solid #f1d6a8;
    color: #80520b;
}

.attendance-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.attendance-table {
    width: 100%;
    min-width: 760px;
    border-collapse: collapse;
}

.attendance-table th,
.attendance-table td {
    padding: 13px 12px;
    border-bottom: 1px solid #e8ebef;
    text-align: left;
    font-size: 14px;
}

.attendance-table th {
    background: #f4efe5;
    color: #102d50;
}

.attendance-table select {
    width: 100%;
    min-width: 125px;
    padding: 9px;
    border: 1px solid #d6dce4;
    border-radius: 7px;
    background: #fff;
}

.attendance-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}

.attendance-summary span {
    padding: 9px 13px;
    background: #f4efe5;
    color: #102d50;
    border-radius: 8px;
    font-size: 13px;
}

.attendance-empty {
    text-align: center;
    color: #687789;
    padding: 25px;
}

.attendance-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 18px;
}

@media(max-width:650px) {
    .attendance-form-grid {
        grid-template-columns: 1fr;
    }

    .attendance-panel {
        padding: 16px;
    }
}
</style>

<div class="attendance-header">
    <h1>Attendance Management</h1>
    <p>Record and update attendance for your assigned courses.</p>
</div>

<?php if ($success !== ''): ?>
<div class="attendance-alert success">
    <?= e($success) ?>
</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="attendance-alert error">
    <?= e($error) ?>
</div>
<?php endif; ?>

<section class="attendance-panel">

    <h2>Select Course and Class Date</h2>

    <form method="GET" action="">

        <div class="attendance-form-grid">

            <div class="attendance-field">
                <label for="offering_id">Assigned Course</label>

                <select name="offering_id" id="offering_id" required>
                    <option value="">-- Select Course --</option>

                    <?php foreach ($offerings as $offering): ?>
                    <option value="<?= (int)$offering['offering_id'] ?>"
                        <?= $selectedOfferingId === (int)$offering['offering_id'] ? 'selected' : '' ?>>

                        <?= e($offering['course_code']) ?>
                        -
                        <?= e($offering['course_title']) ?>
                        (<?= e($offering['semester_name']) ?>)

                    </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="attendance-field">
                <label for="class_date">Class Date</label>

                <input type="date" name="class_date" id="class_date" value="<?= e($selectedDate) ?>" required>
            </div>

        </div>

        <button type="submit" class="attendance-button">
            Load Student List
        </button>

    </form>

</section>

<?php if ($selectedOffering && $error === ''): ?>

<section class="attendance-panel">

    <h2>
        <?= e($selectedOffering['course_code']) ?>
        - <?= e($selectedOffering['course_title']) ?>
    </h2>

    <p>
        <strong>Semester:</strong>
        <?= e($selectedOffering['semester_name']) ?>
    </p>

    <p>
        <strong>Class Date:</strong>
        <?= e(date('d M Y', strtotime($selectedDate))) ?>
    </p>

    <?php if (empty($students)): ?>

    <div class="attendance-empty">
        No registered students found for this course.
        Attendance cannot be recorded until students register.
    </div>

    <?php else: ?>

    <div class="attendance-summary">
        <span>
            Total Students: <strong><?= count($students) ?></strong>
        </span>
    </div>

    <form method="POST" action="">

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <input type="hidden" name="offering_id" value="<?= $selectedOfferingId ?>">

        <input type="hidden" name="class_date" value="<?= e($selectedDate) ?>">

        <div class="attendance-table-wrap">

            <table class="attendance-table">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Batch</th>
                        <th>Attendance Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($students as $index => $student): ?>

                    <?php
                                $currentStatus = $student['attendance_status'] ?? 'present';
                                ?>

                    <tr>

                        <td><?= $index + 1 ?></td>

                        <td><?= e($student['student_id']) ?></td>

                        <td>
                            <strong><?= e($student['full_name']) ?></strong>
                            <br>
                            <small><?= e($student['username']) ?></small>
                        </td>

                        <td>
                            <?= e($student['batch_name'] ?? 'Not assigned') ?>
                        </td>

                        <td>
                            <select name="attendance[<?= (int)$student['registration_id'] ?>]" required>

                                <option value="present" <?= $currentStatus === 'present' ? 'selected' : '' ?>>
                                    Present
                                </option>

                                <option value="absent" <?= $currentStatus === 'absent' ? 'selected' : '' ?>>
                                    Absent
                                </option>

                                <option value="late" <?= $currentStatus === 'late' ? 'selected' : '' ?>>
                                    Late
                                </option>

                                <option value="excused" <?= $currentStatus === 'excused' ? 'selected' : '' ?>>
                                    Excused
                                </option>

                            </select>
                        </td>

                    </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <div class="attendance-actions">

            <span style="color:#687789;font-size:13px;">
                Existing attendance can be updated for this date.
            </span>

            <button type="submit" class="attendance-button">
                Save Attendance
            </button>

        </div>

    </form>

    <?php endif; ?>

</section>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/teacher-footer.php'; ?>