<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$page_title = 'Semester Management';

$success = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please try again.';
    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'save') {

            $id = (int)($_POST['id'] ?? 0);
            $session_id = (int)($_POST['session_id'] ?? 0);
            $semester_name = trim($_POST['semester_name'] ?? '');
            $start_date = $_POST['start_date'] ?: null;
            $end_date = $_POST['end_date'] ?: null;
            $status = $_POST['status'] ?? 'upcoming';
            $registration_open = isset($_POST['registration_open']) ? 1 : 0;

            $allowed_statuses = ['upcoming', 'active', 'completed'];

            if ($session_id <= 0) {
                $error = 'Please select an academic session.';
            } elseif ($semester_name === '') {
                $error = 'Semester name is required.';
            } elseif (!in_array($status, $allowed_statuses, true)) {
                $error = 'Invalid semester status.';
            } elseif (
                $start_date && $end_date &&
                $start_date > $end_date
            ) {
                $error = 'End date cannot be earlier than start date.';
            } else {

                // Verify selected academic session exists
                $check = $conn->prepare("
                    SELECT id FROM academic_sessions WHERE id = ?
                ");
                $check->bind_param("i", $session_id);
                $check->execute();
                $session_exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$session_exists) {
                    $error = 'Selected academic session does not exist.';
                } else {

                    // Prevent duplicate semester names within the same session
                    $duplicate = $conn->prepare("
                        SELECT id
                        FROM semesters
                        WHERE session_id = ?
                        AND semester_name = ?
                        AND id != ?
                    ");
                    $duplicate->bind_param(
                        "isi",
                        $session_id,
                        $semester_name,
                        $id
                    );
                    $duplicate->execute();

                    $has_duplicate = $duplicate->get_result()->num_rows > 0;
                    $duplicate->close();

                    if ($has_duplicate) {
                        $error = 'This semester already exists in the selected academic session.';
                    } else {

                        if ($id > 0) {

                            $stmt = $conn->prepare("
                                UPDATE semesters
                                SET session_id = ?,
                                    semester_name = ?,
                                    start_date = ?,
                                    end_date = ?,
                                    status = ?,
                                    registration_open = ?
                                WHERE id = ?
                            ");

                            $stmt->bind_param(
                                "issssii",
                                $session_id,
                                $semester_name,
                                $start_date,
                                $end_date,
                                $status,
                                $registration_open,
                                $id
                            );

                        } else {

                            $stmt = $conn->prepare("
                                INSERT INTO semesters
                                (
                                    session_id,
                                    semester_name,
                                    start_date,
                                    end_date,
                                    status,
                                    registration_open
                                )
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");

                            $stmt->bind_param(
                                "issssi",
                                $session_id,
                                $semester_name,
                                $start_date,
                                $end_date,
                                $status,
                                $registration_open
                            );
                        }

                        if ($stmt->execute()) {
                            $success = $id > 0
                                ? 'Semester updated successfully.'
                                : 'Semester created successfully.';
                        } else {
                            $error = 'Database error: ' . $stmt->error;
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }
}

// Load semester for editing
$edit_semester = null;

if (isset($_GET['edit'])) {

    $edit_id = (int)$_GET['edit'];

    $stmt = $conn->prepare("
        SELECT * FROM semesters WHERE id = ?
    ");

    $stmt->bind_param("i", $edit_id);
    $stmt->execute();

    $edit_semester = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$edit_semester) {
        $error = 'Semester not found.';
    }
}

// Load academic sessions
$academic_sessions = $conn->query("
    SELECT id, session_name, status
    FROM academic_sessions
    ORDER BY start_date DESC, id DESC
");

// Load all semesters with session names
$semesters = $conn->query("
    SELECT
        s.*,
        a.session_name
    FROM semesters s
    INNER JOIN academic_sessions a
        ON s.session_id = a.id
    ORDER BY a.start_date DESC, s.start_date DESC, s.id DESC
");

include __DIR__ . '/../includes/header.php';
?>

<style>
.semester-container {
    max-width: 1450px;
    margin: 30px auto;
    padding: 0 24px;
}

.semester-heading {
    margin-bottom: 24px;
}

.semester-heading h2 {
    color: var(--navy);
    margin-bottom: 6px;
}

.semester-heading p {
    color: var(--muted);
}

.semester-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 25px;
}

.semester-card h3 {
    color: var(--navy);
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
    color: var(--text);
}

.semester-form input,
.semester-form select {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 7px;
    font-size: 14px;
    box-sizing: border-box;
}

.semester-form input[type="checkbox"] {
    width: auto;
    margin-right: 8px;
}

.checkbox-label {
    display: flex !important;
    align-items: center;
    gap: 5px;
}

.semester-btn {
    display: inline-block;
    background: var(--navy);
    color: white;
    border: none;
    padding: 11px 22px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
}

.semester-btn:hover {
    background: var(--navy-light);
}

.cancel-btn {
    margin-left: 10px;
    color: var(--muted);
    text-decoration: none;
}

.semester-table {
    width: 100%;
    border-collapse: collapse;
}

.semester-table th,
.semester-table td {
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    white-space: nowrap;
}

.semester-table th {
    background: var(--beige);
    color: var(--navy);
}

.semester-table a {
    color: var(--navy);
    font-weight: 600;
    text-decoration: none;
}

.table-responsive {
    overflow-x: auto;
}

.status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
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

.alert {
    padding: 12px 16px;
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

@media (max-width: 650px) {
    .semester-form {
        grid-template-columns: 1fr;
    }

    .semester-form .full-width {
        grid-column: auto;
    }

    .semester-container {
        padding: 0 12px;
    }
}
</style>

<div class="semester-container">

    <div class="semester-heading">
        <h2>Semester Management</h2>
        <p>Create and manage semesters under different academic sessions.</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="semester-card">

        <h3>
            <?= $edit_semester ? 'Edit Semester' : 'Create New Semester' ?>
        </h3>

        <form method="POST" class="semester-form">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="id" value="<?= (int)($edit_semester['id'] ?? 0) ?>">

            <div class="full-width">
                <label>Academic Session *</label>

                <select name="session_id" required>
                    <option value="">Select Academic Session</option>

                    <?php
                    $selected_session = (int)($edit_semester['session_id'] ?? 0);
                    ?>

                    <?php while ($session = $academic_sessions->fetch_assoc()): ?>
                    <option value="<?= (int)$session['id'] ?>"
                        <?= $selected_session === (int)$session['id'] ? 'selected' : '' ?>>
                        <?= e($session['session_name']) ?>
                        (<?= e($session['status']) ?>)
                    </option>
                    <?php endwhile; ?>

                </select>
            </div>

            <div class="full-width">
                <label>Semester Name *</label>

                <input type="text" name="semester_name" placeholder="Example: January-April 2027"
                    value="<?= e($edit_semester['semester_name'] ?? '') ?>" required>
            </div>

            <div>
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?= e($edit_semester['start_date'] ?? '') ?>">
            </div>

            <div>
                <label>End Date</label>
                <input type="date" name="end_date" value="<?= e($edit_semester['end_date'] ?? '') ?>">
            </div>

            <div>
                <label>Status</label>

                <?php $current_status = $edit_semester['status'] ?? 'upcoming'; ?>

                <select name="status">
                    <option value="upcoming" <?= $current_status === 'upcoming' ? 'selected' : '' ?>>
                        Upcoming
                    </option>

                    <option value="active" <?= $current_status === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="completed" <?= $current_status === 'completed' ? 'selected' : '' ?>>
                        Completed
                    </option>
                </select>
            </div>

            <div>
                <label>Course Registration</label>

                <label class="checkbox-label">
                    <input type="checkbox" name="registration_open" value="1"
                        <?= !empty($edit_semester['registration_open']) ? 'checked' : '' ?>>
                    Open course registration
                </label>
            </div>

            <div class="full-width">

                <button type="submit" class="semester-btn">
                    <?= $edit_semester ? 'Update Semester' : 'Create Semester' ?>
                </button>

                <?php if ($edit_semester): ?>
                <a href="semesters.php" class="cancel-btn">Cancel</a>
                <?php endif; ?>

            </div>

        </form>
    </div>

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

                    <tr>
                        <td><?= $sl++ ?></td>

                        <td><?= e($row['session_name']) ?></td>

                        <td><strong><?= e($row['semester_name']) ?></strong></td>

                        <td><?= e($row['start_date'] ?? '—') ?></td>

                        <td><?= e($row['end_date'] ?? '—') ?></td>

                        <td>
                            <span class="status-badge status-<?= e($row['status']) ?>">
                                <?= e($row['status']) ?>
                            </span>
                        </td>

                        <td>
                            <?= !empty($row['registration_open']) ? 'Open' : 'Closed' ?>
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
                        <td colspan="8">No semesters found.</td>
                    </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>
    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>