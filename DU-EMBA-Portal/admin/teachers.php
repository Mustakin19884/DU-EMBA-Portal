<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

require_once __DIR__ . '/../config/database.php';

$page_title = 'Teacher Management';
$message = '';
$message_type = 'success';
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREATE TEACHER
    if ($action === 'create_teacher') {
        $full_name   = trim($_POST['full_name'] ?? '');
        $username    = trim($_POST['username'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $password    = $_POST['password'] ?? '';
        $employee_id = trim($_POST['employee_id'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department  = trim($_POST['department'] ?? 'MIS');
        $phone       = trim($_POST['phone'] ?? '');

        if ($full_name === '' || $username === '' ||
            $password === '' || $employee_id === '') {
            $message = 'Please fill in all required fields.';
            $message_type = 'error';
        } elseif (strlen($password) < 8) {
            $message = 'Password must contain at least 8 characters.';
            $message_type = 'error';
        } else {
            $email_value = $email !== '' ? $email : null;

            $check = $conn->prepare(
                "SELECT id FROM users
                 WHERE username = ?
                    OR (email = ? AND email IS NOT NULL)
                 LIMIT 1"
            );
            $check->bind_param('ss', $username, $email_value);
            $check->execute();
            $duplicate_user = $check->get_result()->num_rows > 0;
            $check->close();

            $check = $conn->prepare(
                "SELECT id FROM teachers WHERE employee_id = ? LIMIT 1"
            );
            $check->bind_param('s', $employee_id);
            $check->execute();
            $duplicate_employee = $check->get_result()->num_rows > 0;
            $check->close();

            if ($duplicate_user) {
                $message = 'Username or email already exists.';
                $message_type = 'error';
            } elseif ($duplicate_employee) {
                $message = 'This Employee ID already exists.';
                $message_type = 'error';
            } else {
                try {
                    $conn->begin_transaction();

                    $password_hash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $conn->prepare(
                        "INSERT INTO users
                         (full_name, username, email, password_hash, role, is_active)
                         VALUES (?, ?, ?, ?, 'teacher', 1)"
                    );
                    $stmt->bind_param(
                        'ssss',
                        $full_name,
                        $username,
                        $email_value,
                        $password_hash
                    );
                    $stmt->execute();
                    $user_id = $conn->insert_id;
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "INSERT INTO teachers
                         (user_id, employee_id, designation, department, phone)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        'issss',
                        $user_id,
                        $employee_id,
                        $designation,
                        $department,
                        $phone
                    );
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    $message = 'Teacher account created successfully.';
                    $message_type = 'success';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = 'Could not create teacher account. Check the information and try again.';
                    $message_type = 'error';
                }
            }
        }
    }

    // UPDATE EXISTING TEACHER
    elseif ($action === 'update_teacher') {
        $teacher_id  = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
        $user_id     = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $full_name   = trim($_POST['full_name'] ?? '');
        $username    = trim($_POST['username'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $employee_id = trim($_POST['employee_id'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department  = trim($_POST['department'] ?? 'MIS');
        $phone       = trim($_POST['phone'] ?? '');

        $email_value = $email !== '' ? $email : null;

        if (!$teacher_id || !$user_id || $full_name === '' ||
            $username === '' || $employee_id === '') {
            $message = 'Please fill in all required fields.';
            $message_type = 'error';
            $editId = $teacher_id ?: 0;
        } else {
            // Confirm that this teacher profile belongs to this user account.
            $check = $conn->prepare(
                "SELECT t.id
                 FROM teachers t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.id = ? AND t.user_id = ? AND u.role = 'teacher'
                 LIMIT 1"
            );
            $check->bind_param('ii', $teacher_id, $user_id);
            $check->execute();
            $valid_teacher = $check->get_result()->num_rows > 0;
            $check->close();

            // Check username/email duplicates excluding this account.
            $check = $conn->prepare(
                "SELECT id FROM users
                 WHERE (username = ? OR (email = ? AND email IS NOT NULL))
                   AND id <> ?
                 LIMIT 1"
            );
            $check->bind_param('ssi', $username, $email_value, $user_id);
            $check->execute();
            $duplicate_user = $check->get_result()->num_rows > 0;
            $check->close();

            // Check Employee ID duplicates excluding this teacher.
            $check = $conn->prepare(
                "SELECT id FROM teachers
                 WHERE employee_id = ? AND id <> ?
                 LIMIT 1"
            );
            $check->bind_param('si', $employee_id, $teacher_id);
            $check->execute();
            $duplicate_employee = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$valid_teacher) {
                $message = 'Teacher account could not be verified.';
                $message_type = 'error';
                $editId = $teacher_id;
            } elseif ($duplicate_user) {
                $message = 'That username or email is already used by another account.';
                $message_type = 'error';
                $editId = $teacher_id;
            } elseif ($duplicate_employee) {
                $message = 'That Employee ID is already used by another teacher.';
                $message_type = 'error';
                $editId = $teacher_id;
            } else {
                try {
                    $conn->begin_transaction();

                    $stmt = $conn->prepare(
                        "UPDATE users
                         SET full_name = ?, username = ?, email = ?
                         WHERE id = ? AND role = 'teacher'"
                    );
                    $stmt->bind_param(
                        'sssi',
                        $full_name,
                        $username,
                        $email_value,
                        $user_id
                    );
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare(
                        "UPDATE teachers
                         SET employee_id = ?, designation = ?,
                             department = ?, phone = ?
                         WHERE id = ? AND user_id = ?"
                    );
                    $stmt->bind_param(
                        'ssssii',
                        $employee_id,
                        $designation,
                        $department,
                        $phone,
                        $teacher_id,
                        $user_id
                    );
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    $message = 'Teacher information updated successfully.';
                    $message_type = 'success';
                    $editId = 0;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = 'Could not update teacher information. Please try again.';
                    $message_type = 'error';
                    $editId = $teacher_id;
                }
            }
        }
    }

    // ACTIVATE / DEACTIVATE TEACHER ACCOUNT
    elseif ($action === 'toggle_status') {
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if ($user_id && $user_id > 0) {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET is_active = IF(is_active = 1, 0, 1)
                 WHERE id = ? AND role = 'teacher'"
            );
            $stmt->bind_param('i', $user_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $message = 'Teacher account status updated.';
                $message_type = 'success';
            } else {
                $message = 'No account status was changed.';
                $message_type = 'error';
            }

            $stmt->close();
        }
    }
}

// Load teacher information when Edit is selected.
$editTeacher = null;

if ($editId > 0) {
    $stmt = $conn->prepare(
        "SELECT
            t.id AS teacher_id,
            t.user_id,
            t.employee_id,
            t.designation,
            t.department,
            t.phone,
            u.full_name,
            u.username,
            u.email
         FROM teachers t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.id = ? AND u.role = 'teacher'
         LIMIT 1"
    );
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editTeacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$editTeacher) {
        $message = 'Teacher record not found.';
        $message_type = 'error';
    }
}

// Fetch teacher list.
$sql = "
    SELECT
        t.id AS teacher_id,
        t.user_id,
        t.employee_id,
        t.designation,
        t.department,
        t.phone,
        u.full_name,
        u.username,
        u.email,
        u.is_active
    FROM teachers t
    INNER JOIN users u ON u.id = t.user_id
    WHERE u.role = 'teacher'
    ORDER BY t.id DESC
";
$result = $conn->query($sql);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Teacher Management</h1>
        <p>Create teacher accounts and update teacher information.</p>
    </div>
</div>

<?php if ($message !== ''): ?>
<div class="alert <?= $message_type === 'error' ? 'alert-danger' : 'alert-success' ?>">
    <?= e($message) ?>
</div>
<?php endif; ?>

<?php if ($editTeacher): ?>
<section class="card" id="edit-teacher">
    <h2>Edit Teacher Information</h2>
    <p>Update the details below and save your changes.</p>

    <form method="POST" action="<?= BASE_URL ?>/admin/teachers.php?edit=<?= (int) $editTeacher['teacher_id'] ?>">
        <input type="hidden" name="action" value="update_teacher">
        <input type="hidden" name="teacher_id" value="<?= (int) $editTeacher['teacher_id'] ?>">
        <input type="hidden" name="user_id" value="<?= (int) $editTeacher['user_id'] ?>">

        <div class="form-grid">
            <div class="form-group">
                <label for="edit_full_name">Full Name *</label>
                <input id="edit_full_name" name="full_name" type="text" maxlength="150" required
                    value="<?= e($editTeacher['full_name']) ?>">
            </div>

            <div class="form-group">
                <label for="edit_employee_id">Employee ID *</label>
                <input id="edit_employee_id" name="employee_id" type="text" maxlength="50" required
                    value="<?= e($editTeacher['employee_id']) ?>">
            </div>

            <div class="form-group">
                <label for="edit_username">Username *</label>
                <input id="edit_username" name="username" type="text" maxlength="100" required
                    value="<?= e($editTeacher['username']) ?>">
            </div>

            <div class="form-group">
                <label for="edit_email">Email</label>
                <input id="edit_email" name="email" type="email" maxlength="150"
                    value="<?= e($editTeacher['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="edit_designation">Designation</label>
                <input id="edit_designation" name="designation" type="text" maxlength="100"
                    value="<?= e($editTeacher['designation'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="edit_department">Department</label>
                <input id="edit_department" name="department" type="text" maxlength="100"
                    value="<?= e($editTeacher['department'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="edit_phone">Phone</label>
                <input id="edit_phone" name="phone" type="text" maxlength="25"
                    value="<?= e($editTeacher['phone'] ?? '') ?>">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="<?= BASE_URL ?>/admin/teachers.php" class="btn btn-secondary">
            Cancel
        </a>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2>Add New Teacher</h2>

    <form method="POST" action="<?= BASE_URL ?>/admin/teachers.php">
        <input type="hidden" name="action" value="create_teacher">

        <div class="form-grid">
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" id="full_name" name="full_name" required maxlength="150">
            </div>

            <div class="form-group">
                <label for="employee_id">Employee ID *</label>
                <input type="text" id="employee_id" name="employee_id" required maxlength="50">
            </div>

            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" required maxlength="100">
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="150">
            </div>

            <div class="form-group">
                <label for="password">Initial Password *</label>
                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="designation">Designation</label>
                <input type="text" id="designation" name="designation" maxlength="100" placeholder="e.g. Lecturer">
            </div>

            <div class="form-group">
                <label for="department">Department</label>
                <input type="text" id="department" name="department" value="MIS" maxlength="100">
            </div>

            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" maxlength="25">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            Create Teacher Account
        </button>
    </form>
</section>

<section class="card">
    <h2>Teacher List</h2>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Designation</th>
                    <th>Department</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($teacher = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= e($teacher['employee_id']) ?></td>
                    <td><?= e($teacher['full_name']) ?></td>
                    <td><?= e($teacher['username']) ?></td>
                    <td><?= e($teacher['designation'] ?? '') ?></td>
                    <td><?= e($teacher['department'] ?? '') ?></td>
                    <td><?= e($teacher['phone'] ?? '') ?></td>
                    <td>
                        <?php if ((int) $teacher['is_active'] === 1): ?>
                        <span class="status-active">Active</span>
                        <?php else: ?>
                        <span class="status-inactive">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <a class="btn btn-primary"
                                href="<?= BASE_URL ?>/admin/teachers.php?edit=<?= (int) $teacher['teacher_id'] ?>#edit-teacher">
                                Edit
                            </a>

                            <form method="POST" action="<?= BASE_URL ?>/admin/teachers.php">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= (int) $teacher['user_id'] ?>">

                                <button type="submit" class="btn btn-secondary">
                                    <?= (int) $teacher['is_active'] === 1
                                            ? 'Deactivate'
                                            : 'Activate' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="8">No teachers found. Add the first teacher above.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div style="margin-top:20px;">
    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-secondary">
        Back to Admin Dashboard
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>