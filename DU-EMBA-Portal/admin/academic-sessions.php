<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$page_title = 'Academic Session Management';

$success = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function validSessionDate(?string $date): bool
{
    if ($date === null || $date === '') {
        return true;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $error = 'Invalid request. Please try again.';
    } else {

        $action = $_POST['action'] ?? '';

        // Create or update session
        if ($action === 'save') {

            $id = (int)($_POST['id'] ?? 0);
            $sessionName = trim($_POST['session_name'] ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');
            $status = $_POST['status'] ?? 'planned';

            $allowedStatuses = ['planned', 'active', 'completed'];

            $startDate = $startDate !== '' ? $startDate : null;
            $endDate = $endDate !== '' ? $endDate : null;

            if ($sessionName === '') {
                $error = 'Academic session name is required.';
            } elseif (strlen($sessionName) > 120) {
                $error = 'Session name cannot exceed 120 characters.';
            } elseif (!in_array($status, $allowedStatuses, true)) {
                $error = 'Invalid session status.';
            } elseif (
                !validSessionDate($startDate) ||
                !validSessionDate($endDate)
            ) {
                $error = 'Please provide valid dates.';
            } elseif (
                $startDate && $endDate &&
                $startDate > $endDate
            ) {
                $error = 'End date cannot be earlier than start date.';
            } else {

                if ($id > 0) {

                    $stmt = $conn->prepare("
                        UPDATE academic_sessions
                        SET session_name = ?,
                            start_date = ?,
                            end_date = ?,
                            status = ?
                        WHERE id = ?
                    ");

                    $stmt->bind_param(
                        "ssssi",
                        $sessionName,
                        $startDate,
                        $endDate,
                        $status,
                        $id
                    );

                } else {

                    $stmt = $conn->prepare("
                        INSERT INTO academic_sessions
                        (session_name, start_date, end_date, status)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->bind_param(
                        "ssss",
                        $sessionName,
                        $startDate,
                        $endDate,
                        $status
                    );
                }

                if ($stmt->execute()) {

                    $success = $id > 0
                        ? 'Academic session updated successfully.'
                        : 'Academic session created successfully.';

                } else {

                    if ($stmt->errno === 1062) {
                        $error = 'This academic session already exists.';
                    } else {
                        $error = 'Database error: ' . $stmt->error;
                    }
                }

                $stmt->close();
            }
        }

        // Delete session
        elseif ($action === 'delete') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                $error = 'Invalid academic session.';
            } else {

                // Prevent deletion if semesters already use this session
                $check = $conn->prepare("
                    SELECT COUNT(*) AS total
                    FROM semesters
                    WHERE session_id = ?
                ");

                $check->bind_param("i", $id);
                $check->execute();

                $used = (int)$check->get_result()
                    ->fetch_assoc()['total'];

                $check->close();

                if ($used > 0) {

                    $error = 'This session cannot be deleted because semesters are linked to it.';

                } else {

                    $stmt = $conn->prepare("
                        DELETE FROM academic_sessions
                        WHERE id = ?
                    ");

                    $stmt->bind_param("i", $id);

                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $success = 'Academic session deleted successfully.';
                    } else {
                        $error = 'Academic session could not be deleted.';
                    }

                    $stmt->close();
                }
            }
        }
    }
}

// Load session for editing
$editSession = null;

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    if ($editId > 0) {

        $stmt = $conn->prepare("
            SELECT *
            FROM academic_sessions
            WHERE id = ?
        ");

        $stmt->bind_param("i", $editId);
        $stmt->execute();

        $editSession = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$editSession) {
            $error = 'Academic session not found.';
        }
    }
}

// Load all academic sessions
$sessions = $conn->query("
    SELECT
        a.*,
        (
            SELECT COUNT(*)
            FROM semesters s
            WHERE s.session_id = a.id
        ) AS semester_count
    FROM academic_sessions a
    ORDER BY a.start_date DESC, a.id DESC
");

require_once __DIR__ . '/../includes/admin-header.php';
?>

<style>
.session-container {
    width: 100%;
    max-width: 1450px;
    margin: 20px auto;
    padding: 0 10px;
    box-sizing: border-box;
}

.session-heading {
    margin-bottom: 24px;
}

.session-heading h2 {
    color: var(--navy);
    margin-bottom: 6px;
}

.session-heading p {
    color: var(--muted);
    margin-top: 0;
}

.session-card {
    background: var(--white, #fff);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 25px;
    box-sizing: border-box;
}

.session-card h3 {
    color: var(--navy);
    margin: 0 0 20px;
}

.session-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.session-form .full-width {
    grid-column: 1 / -1;
}

.session-form label {
    display: block;
    font-weight: 600;
    margin-bottom: 7px;
    color: var(--text);
}

.session-form input,
.session-form select {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 7px;
    font-size: 14px;
    box-sizing: border-box;
    background: #fff;
}

.session-form input:focus,
.session-form select:focus {
    outline: none;
    border-color: var(--navy);
}

.session-btn {
    display: inline-block;
    background: var(--navy);
    color: white;
    border: none;
    padding: 11px 22px;
    border-radius: 7px;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
}

.session-btn:hover {
    background: var(--navy-light);
}

.cancel-btn {
    display: inline-block;
    margin-left: 10px;
    color: var(--muted);
    text-decoration: none;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.session-table {
    width: 100%;
    min-width: 750px;
    border-collapse: collapse;
}

.session-table th,
.session-table td {
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    vertical-align: middle;
}

.session-table th {
    background: var(--beige);
    color: var(--navy);
    white-space: nowrap;
}

.session-table tbody tr:hover {
    background: #fafbfd;
}

.status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.status-planned {
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

.action-links {
    display: flex;
    align-items: center;
    gap: 12px;
}

.edit-link {
    color: var(--navy);
    font-weight: 600;
    text-decoration: none;
}

.delete-form {
    display: inline;
    margin: 0;
}

.delete-btn {
    background: none;
    border: none;
    padding: 0;
    color: #b42318;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
}

.empty-state {
    padding: 25px;
    text-align: center;
    color: var(--muted);
}

@media (max-width: 650px) {
    .session-form {
        grid-template-columns: 1fr;
    }

    .session-form .full-width {
        grid-column: auto;
    }

    .session-container {
        padding: 0;
    }

    .session-card {
        padding: 16px;
    }
}
</style>

<div class="session-container">

    <div class="session-heading">
        <h2>Academic Session Management</h2>
        <p>
            Create and manage academic sessions for the DU EMBA MIS Portal.
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

    <div class="session-card">

        <h3>
            <?= $editSession
                ? 'Edit Academic Session'
                : 'Create New Academic Session' ?>
        </h3>

        <form method="POST" class="session-form">

            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="id" value="<?= (int)($editSession['id'] ?? 0) ?>">

            <div class="full-width">
                <label>Academic Session Name *</label>

                <input type="text" name="session_name" maxlength="120" placeholder="Example: 2027 Academic Session"
                    value="<?= e($editSession['session_name'] ?? '') ?>" required>
            </div>

            <div>
                <label>Start Date</label>

                <input type="date" name="start_date" value="<?= e($editSession['start_date'] ?? '') ?>">
            </div>

            <div>
                <label>End Date</label>

                <input type="date" name="end_date" value="<?= e($editSession['end_date'] ?? '') ?>">
            </div>

            <div class="full-width">
                <label>Status</label>

                <?php
                $currentStatus = $editSession['status'] ?? 'planned';
                ?>

                <select name="status">

                    <option value="planned" <?= $currentStatus === 'planned' ? 'selected' : '' ?>>
                        Planned
                    </option>

                    <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="completed" <?= $currentStatus === 'completed' ? 'selected' : '' ?>>
                        Completed
                    </option>

                </select>
            </div>

            <div class="full-width">

                <button type="submit" class="session-btn">
                    <?= $editSession ? 'Update Session' : 'Create Session' ?>
                </button>

                <?php if ($editSession): ?>
                <a href="academic-sessions.php" class="cancel-btn">
                    Cancel
                </a>
                <?php endif; ?>

            </div>

        </form>

    </div>

    <div class="session-card">

        <h3>All Academic Sessions</h3>

        <div class="table-responsive">

            <table class="session-table">

                <thead>
                    <tr>
                        <th>SL</th>
                        <th>Session Name</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Semesters</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($sessions && $sessions->num_rows > 0): ?>

                    <?php $sl = 1; ?>

                    <?php while ($row = $sessions->fetch_assoc()): ?>

                    <tr>

                        <td><?= $sl++ ?></td>

                        <td>
                            <strong>
                                <?= e($row['session_name']) ?>
                            </strong>
                        </td>

                        <td>
                            <?= e($row['start_date'] ?? '—') ?>
                        </td>

                        <td>
                            <?= e($row['end_date'] ?? '—') ?>
                        </td>

                        <td>
                            <?= (int)$row['semester_count'] ?>
                        </td>

                        <td>
                            <span class="status-badge status-<?= e($row['status']) ?>">
                                <?= e($row['status']) ?>
                            </span>
                        </td>

                        <td>
                            <div class="action-links">

                                <a class="edit-link" href="academic-sessions.php?edit=<?= (int)$row['id'] ?>">
                                    Edit
                                </a>

                                <?php if ((int)$row['semester_count'] === 0): ?>

                                <form method="POST" class="delete-form"
                                    onsubmit="return confirm('Are you sure you want to delete this academic session?');">

                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                    <input type="hidden" name="action" value="delete">

                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

                                    <button type="submit" class="delete-btn">
                                        Delete
                                    </button>

                                </form>

                                <?php else: ?>

                                <span class="muted">
                                    In Use
                                </span>

                                <?php endif; ?>

                            </div>
                        </td>

                    </tr>

                    <?php endwhile; ?>

                    <?php else: ?>

                    <tr>
                        <td colspan="7" class="empty-state">
                            No academic sessions found.
                        </td>
                    </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>