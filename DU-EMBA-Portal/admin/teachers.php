<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

require_once __DIR__ . '/../config/database.php';

$page_title = 'Teacher Management';
$message = '';
$message_type = 'success';

/*
|--------------------------------------------------------------------------
| Handle Form Submission
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create teacher account
    if ($action === 'create_teacher') {
        $full_name   = trim($_POST['full_name'] ?? '');
        $username    = trim($_POST['username'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $password    = $_POST['password'] ?? '';
        $employee_id = trim($_POST['employee_id'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department  = trim($_POST['department'] ?? 'MIS');
        $phone       = trim($_POST['phone'] ?? '');

        if (
            $full_name === '' ||
            $username === '' ||
            $password === '' ||
            $employee_id === ''
        ) {
            $message = 'Please fill in all required fields.';
            $message_type = 'error';
        } elseif (strlen($password) < 8) {
            $message = 'Password must contain at least 8 characters.';
            $message_type = 'error';
        } else {
            // Check username, email and employee ID duplicates
            $check = $conn->prepare(
                "SELECT id FROM users WHERE username = ?
                 UNION
                 SELECT id FROM users WHERE email = ? AND email IS NOT NULL"
            );

            $check->bind_param('ss', $username, $email);
            $check->execute();
            $duplicate_user = $check->get_result()->num_rows > 0;
            $check->close();

            $check_teacher = $conn->prepare(
                "SELECT id FROM teachers WHERE employee_id = ?"
            );
            $check_teacher->bind_param('s', $employee_id);
            $check_teacher->execute();
            $duplicate_employee =
                $check_teacher->get_result()->num_rows > 0;
            $check_teacher->close();

            if ($duplicate_user) {
                $message = 'Username or email already exists.';
                $message_type = 'error';
            } elseif ($duplicate_employee) {
                $message = 'This Employee ID already exists.';
                $message_type = 'error';
            } else {
                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $email_value = $email !== '' ? $email : null;

                try {
                    $conn->begin_transaction();

                    // Create login account
                    $insert_user = $conn->prepare(
                        "INSERT INTO users
                         (full_name, username, email, password_hash, role, is_active)
                         VALUES (?, ?, ?, ?, 'teacher', 1)"
                    );

                    $insert_user->bind_param(
                        'ssss',
                        $full_name,
                        $username,
                        $email_value,
                        $password_hash
                    );

                    $insert_user->execute();
                    $user_id = $conn->insert_id;
                    $insert_user->close();

                    // Create teacher profile
                    $insert_teacher = $conn->prepare(
                        "INSERT INTO teachers
                         (user_id, employee_id, designation, department, phone)
                         VALUES (?, ?, ?, ?, ?)"
                    );

                    $insert_teacher->bind_param(
                        'issss',
                        $user_id,
                        $employee_id,
                        $designation,
                        $department,
                        $phone
                    );

                    $insert_teacher->execute();
                    $insert_teacher->close();

                    $conn->commit();

                    $message = 'Teacher account created successfully.';
                    $message_type = 'success';
                } catch (Throwable $e) {
                    $conn->rollback();

                    $message = 'Could not create teacher account. Check that the submitted information is valid and unique.';
                    $message_type = 'error';
                }
            }
        }
    }

    // Activate or deactivate teacher login
    if ($action === 'toggle_status') {
        $user_id = filter_input(
            INPUT_POST,
            'user_id',
            FILTER_VALIDATE_INT
        );

        if ($user_id && $user_id > 0) {
            $update = $conn->prepare(
                "UPDATE users
                 SET is_active = IF(is_active = 1, 0, 1)
                 WHERE id = ? AND role = 'teacher'"
            );

            $update->bind_param('i', $user_id);
            $update->execute();

            if ($update->affected_rows > 0) {
                $message = 'Teacher account status updated.';
                $message_type = 'success';
            } else {
                $message = 'No account status was changed.';
                $message_type = 'error';
            }

            $update->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Teachers
|--------------------------------------------------------------------------
*/
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
    ORDER BY t.id DESC
";

$result = $conn->query($sql);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Teacher Management</h1>
        <p>Create teacher accounts and manage teacher information.</p>
    </div>
</div>

<?php if ($message !== ''): ?>
<div class="alert <?= $message_type === 'error' ? 'alert-danger' : 'alert-success' ?>">
    <?= e($message) ?>
</div>
<?php endif; ?>

<section class="card">
    <h2>Add New Teacher</h2>

    <form method="POST" action="">
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
                <input type="text" id="designation" name="designation" placeholder="e.g. Lecturer" maxlength="100">
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
                    <th>Action</th>
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
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="user_id" value="<?= (int) $teacher['user_id'] ?>">

                            <button type="submit" class="btn btn-secondary">
                                <?= (int) $teacher['is_active'] === 1
                                            ? 'Deactivate'
                                            : 'Activate' ?>
                            </button>
                        </form>
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

<div style="margin-top: 20px;">
    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-secondary">
        Back to Admin Dashboard
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>