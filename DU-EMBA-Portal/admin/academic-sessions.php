<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$page_title = 'Academic Session Management';

$success = '';
$error = '';

// CSRF Token
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
            $session_name = trim($_POST['session_name'] ?? '');
            $start_date = $_POST['start_date'] ?: null;
            $end_date = $_POST['end_date'] ?: null;
            $status = $_POST['status'] ?? 'planned';

            $allowed_statuses = ['planned', 'active', 'completed'];

            if ($session_name === '') {
                $error = 'Academic session name is required.';
            } elseif (!in_array($status, $allowed_statuses, true)) {
                $error = 'Invalid session status.';
            } elseif (
                $start_date && $end_date &&
                $start_date > $end_date
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
                        $session_name,
                        $start_date,
                        $end_date,
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
                        $session_name,
                        $start_date,
                        $end_date,
                        $status
                    );
                }

                if ($stmt->execute()) {
                    $success = $id > 0
                        ? 'Academic session updated successfully.'
                        : 'Academic session created successfully.';
                } else {
                    $error = $stmt->errno === 1062
                        ? 'This academic session already exists.'
                        : 'Database error: ' . $stmt->error;
                }

                $stmt->close();
            }
        }
    }
}

// Load session for editing
$edit_session = null;

if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];

    $stmt = $conn->prepare("
        SELECT * FROM academic_sessions WHERE id = ?
    ");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();

    $edit_session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$edit_session) {
        $error = 'Academic session not found.';
    }
}

// Load all academic sessions
$sessions = $conn->query("
    SELECT *
    FROM academic_sessions
    ORDER BY start_date DESC, id DESC
");

require_once __DIR__ . '/../includes/admin-header.php';
?>

<style>
.session-container {
    max-width: 1450px;
    margin: 30px auto;
    padding: 0 24px;
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
}

.session-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 25px;
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

.session-table {
    width: 100%;
    border-collapse: collapse;
}

.session-table th,
.session-table td {
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
}

.session-table th {
    background: var(--beige);
    color: var(--navy);
}

.session-table a {
    color: var(--navy);
    font-weight: 600;
    text-decoration: none;
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

.table-responsive {
    overflow-x: auto;
}

@media (max-width: 650px) {
    .session-form {
        grid-template-columns: 1fr;
    }

    .session-form .full-width {
        grid-column: auto;
    }

    .session-container {
        padding: 0 12px;
    }
}
</style>

<div class="session-container">

    <div class="session-heading">
        <h2>Academic Session Management</h2>
        <p>Create and manage academic sessions for the DU EMBA MIS Portal.</p>
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
            <?= $edit_session ? 'Edit Academic Session' : 'Create New Academic Session' ?>
        </h3>

        <form method="POST" class="session-form">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden" name="id" value="<?= (int)($edit_session['id'] ?? 0) ?>">

            <div class="full-width">
                <label>Academic Session Name *</label>
                <input type="text" name="session_name" placeholder="Example: 2027 Academic Session"
                    value="<?= e($edit_session['session_name'] ?? '') ?>" required>
            </div>

            <div>
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?= e($edit_session['start_date'] ?? '') ?>">
            </div>

            <div>
                <label>End Date</label>
                <input type="date" name="end_date" value="<?= e($edit_session['end_date'] ?? '') ?>">
            </div>

            <div class="full-width">
                <label>Status</label>
                <select name="status">
                    <?php
                    $current_status = $edit_session['status'] ?? 'planned';
                    ?>
                    <option value="planned" <?= $current_status === 'planned' ? 'selected' : '' ?>>
                        Planned
                    </option>

                    <option value="active" <?= $current_status === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="completed" <?= $current_status === 'completed' ? 'selected' : '' ?>>
                        Completed
                    </option>
                </select>
            </div>

            <div class="full-width">
                <button type="submit" class="session-btn">
                    <?= $edit_session ? 'Update Session' : 'Create Session' ?>
                </button>

                <?php if ($edit_session): ?>
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
                            <strong><?= e($row['session_name']) ?></strong>
                        </td>

                        <td><?= e($row['start_date'] ?? '—') ?></td>

                        <td><?= e($row['end_date'] ?? '—') ?></td>

                        <td>
                            <span class="status-badge status-<?= e($row['status']) ?>">
                                <?= e($row['status']) ?>
                            </span>
                        </td>

                        <td>
                            <a href="academic-sessions.php?edit=<?= (int)$row['id'] ?>">
                                Edit
                            </a>
                        </td>
                    </tr>

                    <?php endwhile; ?>

                    <?php else: ?>

                    <tr>
                        <td colspan="6">No academic sessions found.</td>
                    </tr>

                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>