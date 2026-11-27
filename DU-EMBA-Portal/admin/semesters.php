<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$page_title = 'Semester Management';

$success = '';
$error = '';

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Default Form Data
|--------------------------------------------------------------------------
*/
$formData = [
    'id' => 0,
    'session_id' => 0,
    'semester_name' => '',
    'start_date' => '',
    'end_date' => '',
    'status' => 'upcoming',
    'registration_open' => 0
];

$allowedStatuses = ['upcoming', 'active', 'completed'];

/*
|--------------------------------------------------------------------------
| Date Validation
|--------------------------------------------------------------------------
*/
function semesterValidDate(?string $date): bool
{
    if ($date === null || $date === '') {
        return true;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parts = explode('-', $date);

    return checkdate(
        (int)$parts[1],
        (int)$parts[2],
        (int)$parts[0]
    );
}

/*
|--------------------------------------------------------------------------
| Handle Form Submission
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Preserve submitted values if validation fails.
    $formData = [
        'id' => (int)($_POST['id'] ?? 0),
        'session_id' => (int)($_POST['session_id'] ?? 0),
        'semester_name' => trim($_POST['semester_name'] ?? ''),
        'start_date' => trim($_POST['start_date'] ?? ''),
        'end_date' => trim($_POST['end_date'] ?? ''),
        'status' => $_POST['status'] ?? 'upcoming',
        'registration_open' => isset($_POST['registration_open']) ? 1 : 0
    ];

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') !== 'save') {
        $error = 'Invalid form action.';
    } else {

        $id = $formData['id'];
        $sessionId = $formData['session_id'];
        $semesterName = $formData['semester_name'];
        $startDate = $formData['start_date'] !== ''
            ? $formData['start_date']
            : null;
        $endDate = $formData['end_date'] !== ''
            ? $formData['end_date']
            : null;
        $status = $formData['status'];
        $registrationOpen = $formData['registration_open'];

        /*
        |--------------------------------------------------------------------------
        | Basic Validation
        |--------------------------------------------------------------------------
        */
        if ($sessionId <= 0) {
            $error = 'Please select an academic session.';

        } elseif ($semesterName === '') {
            $error = 'Semester name is required.';

        } elseif (mb_strlen($semesterName) > 120) {
            $error = 'Semester name cannot exceed 120 characters.';

        } elseif (!in_array($status, $allowedStatuses, true)) {
            $error = 'Invalid semester status.';

        } elseif (
            !semesterValidDate($startDate) ||
            !semesterValidDate($endDate)
        ) {
            $error = 'Please provide valid dates.';

        } elseif (
            $startDate !== null &&
            $endDate !== null &&
            $startDate > $endDate
        ) {
            $error = 'End date cannot be earlier than start date.';
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Academic Session
        |--------------------------------------------------------------------------
        */
        if ($error === '') {

            $check = $conn->prepare("
                SELECT id
                FROM academic_sessions
                WHERE id = ?
                LIMIT 1
            ");

            if (!$check) {
                $error = 'Unable to verify academic session.';
            } else {

                $check->bind_param("i", $sessionId);
                $check->execute();

                $sessionExists = $check->get_result()->num_rows > 0;

                $check->close();

                if (!$sessionExists) {
                    $error = 'Selected academic session does not exist.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Existing Semester During Edit
        |--------------------------------------------------------------------------
        */
        if ($error === '' && $id > 0) {

            $check = $conn->prepare("
                SELECT id
                FROM semesters
                WHERE id = ?
                LIMIT 1
            ");

            if (!$check) {
                $error = 'Unable to verify semester record.';
            } else {

                $check->bind_param("i", $id);
                $check->execute();

                $semesterExists = $check->get_result()->num_rows > 0;

                $check->close();

                if (!$semesterExists) {
                    $error = 'Semester record not found.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Semester Names
        |--------------------------------------------------------------------------
        */
        if ($error === '') {

            $duplicate = $conn->prepare("
                SELECT id
                FROM semesters
                WHERE session_id = ?
                  AND semester_name = ?
                  AND id != ?
                LIMIT 1
            ");

            if (!$duplicate) {
                $error = 'Unable to check duplicate semester.';
            } else {

                $duplicate->bind_param(
                    "isi",
                    $sessionId,
                    $semesterName,
                    $id
                );

                $duplicate->execute();

                $hasDuplicate = $duplicate->get_result()->num_rows > 0;

                $duplicate->close();

                if ($hasDuplicate) {
                    $error = 'This semester already exists in the selected academic session.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save Semester
        |--------------------------------------------------------------------------
        */
        if ($error === '') {

            if ($id > 0) {

                $stmt = $conn->prepare("
                    UPDATE semesters
                    SET
                        session_id = ?,
                        semester_name = ?,
                        start_date = ?,
                        end_date = ?,
                        status = ?,
                        registration_open = ?
                    WHERE id = ?
                ");

                if ($stmt) {

                    $stmt->bind_param(
                        "issssii",
                        $sessionId,
                        $semesterName,
                        $startDate,
                        $endDate,
                        $status,
                        $registrationOpen,
                        $id
                    );
                }

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO semesters (
                        session_id,
                        semester_name,
                        start_date,
                        end_date,
                        status,
                        registration_open
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                if ($stmt) {

                    $stmt->bind_param(
                        "issssi",
                        $sessionId,
                        $semesterName,
                        $startDate,
                        $endDate,
                        $status,
                        $registrationOpen
                    );
                }
            }

            if (!$stmt) {
                $error = 'Unable to prepare database query.';
            } else {

                if ($stmt->execute()) {

                    $success = $id > 0
                        ? 'Semester updated successfully.'
                        : 'Semester created successfully.';

                    // Reset form after successful save.
                    $formData = [
                        'id' => 0,
                        'session_id' => 0,
                        'semester_name' => '',
                        'start_date' => '',
                        'end_date' => '',
                        'status' => 'upcoming',
                        'registration_open' => 0
                    ];

                    // Generate a fresh CSRF token.
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                } else {

                    $error = $stmt->errno === 1062
                        ? 'This semester already exists in the selected academic session.'
                        : 'Database error: ' . $stmt->error;
                }

                $stmt->close();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Semester for Editing
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' &&
    isset($_GET['edit'])
) {

    $editId = (int)$_GET['edit'];

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT *
            FROM semesters
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {

            $stmt->bind_param("i", $editId);
            $stmt->execute();

            $editSemester = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if ($editSemester) {

                $formData = [
                    'id' => (int)$editSemester['id'],
                    'session_id' => (int)$editSemester['session_id'],
                    'semester_name' => $editSemester['semester_name'],
                    'start_date' => $editSemester['start_date'] ?? '',
                    'end_date' => $editSemester['end_date'] ?? '',
                    'status' => $editSemester['status'],
                    'registration_open' => (int)$editSemester['registration_open']
                ];

            } else {
                $error = 'Semester not found.';
            }

        } else {
            $error = 'Unable to load semester information.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Academic Sessions
|--------------------------------------------------------------------------
*/
$academicSessions = $conn->query("
    SELECT id, session_name, status
    FROM academic_sessions
    ORDER BY start_date DESC, id DESC
");

/*
|--------------------------------------------------------------------------
| Load All Semesters
|--------------------------------------------------------------------------
*/
$semesters = $conn->query("
    SELECT
        s.*,
        a.session_name
    FROM semesters s
    INNER JOIN academic_sessions a
        ON s.session_id = a.id
    ORDER BY
        a.start_date DESC,
        s.start_date DESC,
        s.id DESC
");

/*
|--------------------------------------------------------------------------
| Admin Header
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../includes/admin-header.php';
?>

<style>
.semester-container {
    width: 100%;
    max-width: 1450px;
    margin: 0 auto;
    padding: 5px 0 30px;
    box-sizing: border-box;
}

.semester-heading {
    margin-bottom: 24px;
}

.semester-heading h2 {
    color: var(--navy, #102d50);
    margin: 0 0 7px;
    font-size: 25px;
}

.semester-heading p {
    color: var(--muted, #687789);
    margin: 0;
    font-size: 14px;
}

.semester-card {
    background: var(--white, #fff);
    border: 1px solid var(--border, #e1e5eb);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-sizing: border-box;
}

.semester-card h3 {
    color: var(--navy, #102d50);
    font-size: 17px;
    margin: 0 0 20px;
}

.semester-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.semester-form .full-width {
    grid-column: 1 / -1;
}

.semester-form label {
    display: block;
    font-weight: 600;
    margin-bottom: 7px;
    color: var(--text, #263548);
    font-size: 13px;
}

.semester-form input:not([type="checkbox"]),
.semester-form select {
    width: 100%;
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid var(--border, #e1e5eb);
    border-radius: 7px;
    font-size: 14px;
    box-sizing: border-box;
    background: #fff;
    color: var(--text, #263548);
}

.semester-form input:focus,
.semester-form select:focus {
    outline: none;
    border-color: var(--navy, #102d50);
    box-shadow: 0 0 0 3px rgba(16, 45, 80, .08);
}

.checkbox-label {
    display: flex !important;
    align-items: center;
    gap: 8px;
    font-weight: 500 !important;
    cursor: pointer;
    margin-top: 10px;
}

.checkbox-label input {
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: var(--navy, #102d50);
}

.semester-btn {
    display: inline-block;
    background: var(--navy, #102d50);
    color: #fff;
    border: none;
    padding: 11px 22px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
}

.semester-btn:hover {
    background: var(--navy-light, #1c456e);
}

.cancel-btn {
    display: inline-block;
    margin-left: 12px;
    color: var(--muted, #687789);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.cancel-btn:hover {
    color: var(--navy, #102d50);
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.semester-table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

.semester-table th,
.semester-table td {
    padding: 13px 12px;
    border-bottom: 1px solid var(--border, #e1e5eb);
    text-align: left;
    font-size: 13px;
}

.semester-table th {
    background: var(--beige, #f4efe5);
    color: var(--navy, #102d50);
    font-weight: 700;
    white-space: nowrap;
}

.semester-table td {
    color: var(--text, #263548);
}

.semester-table tbody tr:hover {
    background: #fafbfd;
}

.semester-table a {
    color: var(--navy, #102d50);
    font-weight: 700;
    text-decoration: none;
}

.semester-table a:hover {
    text-decoration: underline;
}

.status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.status-upcoming {
    background: #fff2d6;
    color: #8a5b00;
}

.status-active {
    background: #dff4e5;
    color: #176b35;
}

.status-completed {
    background: #e8edf3;
    color: #455468;
}

.registration-open {
    color: #176b35;
    font-weight: 700;
}

.registration-closed {
    color: #a12626;
    font-weight: 700;
}

.alert {
    padding: 12px 16px;
    border-radius: 7px;
    margin-bottom: 18px;
    font-size: 13px;
}

.alert-success {
    background: #e4f5e9;
    color: #176b35;
    border: 1px solid #c7e9d0;
}

.alert-error {
    background: #fde8e8;
    color: #a12626;
    border: 1px solid #f5cccc;
}

.empty-state {
    text-align: center !important;
    padding: 30px !important;
    color: var(--muted, #687789) !important;
}

@media (max-width: 650px) {
    .semester-form {
        grid-template-columns: 1fr;
    }

    .semester-form .full-width {
        grid-column: auto;
    }

    .semester-card {
        padding: 17px;
    }

    .semester-heading h2 {
        font-size: 21px;
    }
}
</style>

<div class="semester-container">

    <div class="semester-heading">
        <h2>Semester Management</h2>
        <p>Create and manage semesters under different academic sessions.</p>
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

    <!-- Create / Edit Semester -->
    <div class="semester-card">

        <h3>
            <?= $formData['id'] > 0 ? 'Edit Semester' : 'Create New Semester' ?>
        </h3>

        <?php if ($academicSessions && $academicSessions->num_rows > 0): ?>

        <form method="POST" class="semester-form">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="id" value="<?= (int)$formData['id'] ?>">

            <div class="full-width">

                <label for="session_id">Academic Session *</label>

                <select name="session_id" id="session_id" required>

                    <option value="">Select Academic Session</option>

                    <?php while ($session = $academicSessions->fetch_assoc()): ?>

                    <option value="<?= (int)$session['id'] ?>"
                        <?= (int)$formData['session_id'] === (int)$session['id'] ? 'selected' : '' ?>>
                        <?= e($session['session_name']) ?>
                        (<?= e($session['status']) ?>)
                    </option>

                    <?php endwhile; ?>

                </select>

            </div>

            <div class="full-width">

                <label for="semester_name">Semester Name *</label>

                <input type="text" id="semester_name" name="semester_name" maxlength="120"
                    placeholder="Example: January-April 2027" value="<?= e($formData['semester_name']) ?>" required>

            </div>

            <div>

                <label for="start_date">Start Date</label>

                <input type="date" id="start_date" name="start_date" value="<?= e($formData['start_date']) ?>">

            </div>

            <div>

                <label for="end_date">End Date</label>

                <input type="date" id="end_date" name="end_date" value="<?= e($formData['end_date']) ?>">

            </div>

            <div>

                <label for="status">Semester Status</label>

                <select name="status" id="status">

                    <option value="upcoming" <?= $formData['status'] === 'upcoming' ? 'selected' : '' ?>>
                        Upcoming
                    </option>

                    <option value="active" <?= $formData['status'] === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="completed" <?= $formData['status'] === 'completed' ? 'selected' : '' ?>>
                        Completed
                    </option>

                </select>

            </div>

            <div>

                <label>Course Registration</label>

                <label class="checkbox-label">

                    <input type="checkbox" name="registration_open" value="1"
                        <?= !empty($formData['registration_open']) ? 'checked' : '' ?>>

                    Open course registration

                </label>

            </div>

            <div class="full-width">

                <button type="submit" class="semester-btn">
                    <?= $formData['id'] > 0 ? 'Update Semester' : 'Create Semester' ?>
                </button>

                <?php if ($formData['id'] > 0): ?>

                <a href="semesters.php" class="cancel-btn">
                    Cancel
                </a>

                <?php endif; ?>

            </div>

        </form>

        <?php else: ?>

        <div class="alert alert-error">
            No academic sessions found. Please create an academic session first.
        </div>

        <?php endif; ?>

    </div>

    <!-- All Semesters -->
    <div class="semester-card">

        <h3>All Semesters</h3>

        <div class="table-responsive">

            <table class="semester-table">

                <thead>
                    <tr>
                        <th>SL</th>
                        <th>Academic Session</th>
                        <th>Semester</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                        <th>Registration</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($semesters && $semesters->num_rows > 0): ?>

                    <?php $sl = 1; ?>

                    <?php while ($row = $semesters->fetch_assoc()): ?>

                    <?php
                            $rowStatus = in_array(
                                $row['status'],
                                $allowedStatuses,
                                true
                            ) ? $row['status'] : 'upcoming';
                            ?>

                    <tr>

                        <td><?= $sl++ ?></td>

                        <td>
                            <?= e($row['session_name']) ?>
                        </td>

                        <td>
                            <strong><?= e($row['semester_name']) ?></strong>
                        </td>

                        <td>
                            <?= e($row['start_date'] ?? '—') ?>
                        </td>

                        <td>
                            <?= e($row['end_date'] ?? '—') ?>
                        </td>

                        <td>
                            <span class="status-badge status-<?= e($rowStatus) ?>">
                                <?= e($row['status']) ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!empty($row['registration_open'])): ?>
                            <span class="registration-open">Open</span>
                            <?php else: ?>
                            <span class="registration-closed">Closed</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <a href="semesters.php?edit=<?= (int)$row['id'] ?>">
                                Edit
                            </a>
                        </td>

                    </tr>

                    <?php endwhile; ?>

                    <?php else: ?>

                    <tr>
                        <td colspan="8" class="empty-state">
                            No semesters found.
                        </td>
                    </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>