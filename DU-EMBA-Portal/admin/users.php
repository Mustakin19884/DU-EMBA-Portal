<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

$roles = [
    'student'  => 'Student',
    'teacher'  => 'Teacher',
    'accounts' => 'Accounts Officer',
    'academic' => 'Academic Officer',
];

/*
|--------------------------------------------------------------------------
| Load batches and semesters
|--------------------------------------------------------------------------
*/
$batchesResult = $conn->query(
    "SELECT id, batch_name, admission_year
     FROM batches
     WHERE status = 'active'
     ORDER BY admission_year DESC, batch_name ASC"
);

$batches = $batchesResult->fetch_all(MYSQLI_ASSOC);

$semestersResult = $conn->query(
    "SELECT id, semester_name, status
     FROM semesters
     ORDER BY id DESC"
);

$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Handle POST requests
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Create account
        |--------------------------------------------------------------------------
        */
        if ($action === 'create') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            $studentId = trim((string) ($_POST['student_id'] ?? ''));

            $batchId = filter_var(
                $_POST['batch_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $admissionSemesterId = filter_var(
                $_POST['admission_semester_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            /*
            |--------------------------------------------------------------------------
            | Basic validation
            |--------------------------------------------------------------------------
            */
            if ($fullName === '' || strlen($fullName) > 150) {
                $error = 'Enter a valid full name (maximum 150 characters).';

            } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
                $error = 'Username must be 3–60 characters using letters, numbers, dot, dash or underscore.';

            } elseif (
                $email !== '' &&
                (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)
            ) {
                $error = 'Enter a valid email address.';

            } elseif (!isset($roles[$role])) {
                $error = 'Select a valid user role.';

            } elseif (
                $role === 'student' &&
                ($studentId === '' || strlen($studentId) > 50)
            ) {
                $error = 'Enter a valid Student ID (maximum 50 characters).';

            } elseif (
                $role === 'student' &&
                (!$batchId || $batchId < 1)
            ) {
                $error = 'Please select a batch for the student.';

            } elseif (
                $role === 'student' &&
                (!$admissionSemesterId || $admissionSemesterId < 1)
            ) {
                $error = 'Please select the admission semester.';

            } elseif (strlen($password) < 12) {
                $error = 'Password must be at least 12 characters.';

            } elseif ($password !== $confirmPassword) {
                $error = 'Passwords do not match.';
            }

            /*
            |--------------------------------------------------------------------------
            | Check duplicate username and email
            |--------------------------------------------------------------------------
            */
            if ($error === '') {
                $emailValue = $email === '' ? null : $email;

                $check = $conn->prepare(
                    'SELECT id
                     FROM users
                     WHERE username = ?
                        OR (email IS NOT NULL AND email = ?)
                     LIMIT 1'
                );

                $check->bind_param('ss', $username, $emailValue);
                $check->execute();

                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($exists) {
                    $error = 'That username or email is already in use.';
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Validate student information
            |--------------------------------------------------------------------------
            */
            if ($error === '' && $role === 'student') {
                // Check duplicate Student ID
                $check = $conn->prepare(
                    'SELECT id
                     FROM students
                     WHERE student_id = ?
                     LIMIT 1'
                );

                $check->bind_param('s', $studentId);
                $check->execute();

                $studentExists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($studentExists) {
                    $error = 'This Student ID is already registered.';
                }

                // Check selected batch
                if ($error === '') {
                    $check = $conn->prepare(
                        "SELECT id
                         FROM batches
                         WHERE id = ? AND status = 'active'
                         LIMIT 1"
                    );

                    $check->bind_param('i', $batchId);
                    $check->execute();

                    $batchExists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if (!$batchExists) {
                        $error = 'Selected batch is invalid or inactive.';
                    }
                }

                // Check selected admission semester
                if ($error === '') {
                    $check = $conn->prepare(
                        'SELECT id
                         FROM semesters
                         WHERE id = ?
                         LIMIT 1'
                    );

                    $check->bind_param('i', $admissionSemesterId);
                    $check->execute();

                    $semesterExists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if (!$semesterExists) {
                        $error = 'Selected admission semester does not exist.';
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Save account and student profile in one transaction
            |--------------------------------------------------------------------------
            */
            if ($error === '') {
                $transactionStarted = false;

                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;

                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $active = 1;

                    // Create user account
                    $stmt = $conn->prepare(
                        'INSERT INTO users
                            (full_name, username, email, password_hash, role, is_active)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );

                    $stmt->bind_param(
                        'sssssi',
                        $fullName,
                        $username,
                        $emailValue,
                        $passwordHash,
                        $role,
                        $active
                    );

                    $stmt->execute();
                    $userId = (int) $conn->insert_id;
                    $stmt->close();

                    // Create student profile
                    if ($role === 'student') {
                        $stmt = $conn->prepare(
                            'INSERT INTO students
                                (user_id, student_id, batch_id, admission_semester_id)
                             VALUES (?, ?, ?, ?)'
                        );

                        $stmt->bind_param(
                            'isii',
                            $userId,
                            $studentId,
                            $batchId,
                            $admissionSemesterId
                        );

                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();
                    $transactionStarted = false;

                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    $message = $role === 'student'
                        ? 'Student account created successfully.'
                        : 'User account created successfully.';

                } catch (Throwable $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }

                    error_log(
                        'User account creation failed: ' .
                        $exception->getMessage()
                    );

                    $error = 'Account creation failed. Please verify the database structure and submitted information.';
                }
            }

        /*
        |--------------------------------------------------------------------------
        | Activate or deactivate account
        |--------------------------------------------------------------------------
        */
        } elseif ($action === 'toggle') {
            $userId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$userId || $userId < 1) {
                $error = 'Invalid user selected.';

            } elseif ($userId === (int) $_SESSION['user_id']) {
                $error = 'You cannot deactivate your own account here.';

            } else {
                $stmt = $conn->prepare(
                    'SELECT id, role, is_active
                     FROM users
                     WHERE id = ?'
                );

                $stmt->bind_param('i', $userId);
                $stmt->execute();

                $target = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$target) {
                    $error = 'User not found.';

                } else {
                    $newStatus = (int) !(bool) $target['is_active'];

                    if ($target['role'] === 'admin' && $newStatus === 0) {
                        $error = 'Admin accounts cannot be deactivated from this page.';

                    } else {
                        $stmt = $conn->prepare(
                            'UPDATE users
                             SET is_active = ?
                             WHERE id = ?'
                        );

                        $stmt->bind_param('ii', $newStatus, $userId);
                        $stmt->execute();
                        $stmt->close();

                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        $message = $newStatus
                            ? 'Account activated successfully.'
                            : 'Account deactivated successfully.';
                    }
                }
            }

        } else {
            $error = 'Unknown action.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch user list
|--------------------------------------------------------------------------
*/
$result = $conn->query(
    'SELECT id, full_name, username, email, role, is_active, created_at
     FROM users
     ORDER BY id DESC'
);

$users = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>User Management | DU EMBA MIS Portal</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    .users-container {
        width: calc(100% - 32px);
        max-width: 1100px;
        margin: 35px auto;
    }

    .users-panel {
        background: #fff;
        padding: 24px;
        margin: 22px 0;
        border-radius: 12px;
        box-shadow: 0 4px 18px rgba(16, 45, 80, .08);
    }

    .users-form {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .users-form label {
        display: block;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .users-form input,
    .users-form select {
        box-sizing: border-box;
        width: 100%;
        padding: 11px;
        border: 1px solid #ccd3dc;
        border-radius: 6px;
    }

    .users-form button,
    .status-button {
        background: #102d50;
        color: #fff;
        border: 0;
        border-radius: 6px;
        padding: 10px 15px;
        cursor: pointer;
    }

    .form-full {
        grid-column: 1 / -1;
    }

    .student-fields-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .users-table-wrap {
        overflow-x: auto;
    }

    .users-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 760px;
    }

    .users-table th,
    .users-table td {
        text-align: left;
        padding: 12px;
        border-bottom: 1px solid #e5e7eb;
    }

    .notice {
        padding: 12px;
        border-radius: 6px;
        margin: 12px 0;
    }

    .success {
        background: #e7f6ec;
        color: #176534;
    }

    .failure {
        background: #fdecec;
        color: #9b2020;
    }

    .status-active {
        color: #176534;
        font-weight: 700;
    }

    .status-inactive {
        color: #9b2020;
        font-weight: 700;
    }

    .muted {
        color: #64748b;
        font-size: 13px;
        margin-top: 6px;
    }

    @media (max-width: 650px) {

        .users-form,
        .student-fields-grid {
            grid-template-columns: 1fr;
        }

        .form-full {
            grid-column: auto;
        }

        .users-panel {
            padding: 16px;
        }
    }
    </style>
</head>

<body>
    <main class="users-container">

        <header>
            <p>
                <a href="<?= BASE_URL ?>/admin/dashboard.php">
                    &larr; Back to Admin Dashboard
                </a>
            </p>

            <h1>User Management</h1>
            <p>Create and manage portal user accounts.</p>
        </header>

        <?php if ($message !== ''): ?>
        <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="notice failure"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="users-panel">
            <h2>Create New Account</h2>
            <p>Create Student, Teacher, Accounts or Academic Office accounts.</p>

            <form method="post" class="users-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                <input type="hidden" name="action" value="create">

                <div>
                    <label for="full_name">Full Name *</label>
                    <input id="full_name" name="full_name" maxlength="150" autocomplete="name" required>
                </div>

                <div>
                    <label for="username">Username *</label>
                    <input id="username" name="username" minlength="3" maxlength="60" pattern="[A-Za-z0-9_.-]+"
                        autocomplete="off" required>
                </div>

                <div>
                    <label for="email">Email (optional)</label>
                    <input id="email" name="email" type="email" maxlength="254" autocomplete="email">
                </div>

                <div>
                    <label for="role">User Role *</label>
                    <select id="role" name="role" required>
                        <option value="">Select role</option>

                        <?php foreach ($roles as $value => $label): ?>
                        <option value="<?= e($value) ?>">
                            <?= e($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Student-only fields -->
                <div id="student-fields" class="form-full" hidden>
                    <div class="student-fields-grid">

                        <div>
                            <label for="student_id">Student ID *</label>
                            <input id="student_id" name="student_id" maxlength="50" autocomplete="off"
                                placeholder="Enter official Student ID" disabled>
                        </div>

                        <div>
                            <label for="batch_id">Batch *</label>
                            <select id="batch_id" name="batch_id" disabled>
                                <option value="">Select Batch</option>

                                <?php foreach ($batches as $batch): ?>
                                <option value="<?= (int) $batch['id'] ?>">
                                    <?= e($batch['batch_name']) ?>
                                    <?php if (!empty($batch['admission_year'])): ?>
                                    (<?= (int) $batch['admission_year'] ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if (empty($batches)): ?>
                            <p class="muted" style="color:#9b2020;">
                                No active batches found. Add a batch first.
                            </p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="admission_semester_id">
                                Admission Semester *
                            </label>

                            <select id="admission_semester_id" name="admission_semester_id" disabled>
                                <option value="">Select Admission Semester</option>

                                <?php foreach ($semesters as $semester): ?>
                                <option value="<?= (int) $semester['id'] ?>">
                                    <?= e($semester['semester_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if (empty($semesters)): ?>
                            <p class="muted" style="color:#9b2020;">
                                No semesters found. Add a semester first.
                            </p>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

                <div>
                    <label for="password">Initial Password *</label>
                    <input id="password" name="password" type="password" minlength="12" autocomplete="new-password"
                        required>
                </div>

                <div>
                    <label for="confirm_password">Confirm Password *</label>
                    <input id="confirm_password" name="confirm_password" type="password" minlength="12"
                        autocomplete="new-password" required>
                </div>

                <div class="form-full">
                    <button type="submit">Create Account</button>
                </div>
            </form>

            <script>
            const roleSelect = document.getElementById('role');
            const studentFields = document.getElementById('student-fields');
            const studentIdInput = document.getElementById('student_id');
            const batchSelect = document.getElementById('batch_id');

            const admissionSemesterSelect =
                document.getElementById('admission_semester_id');

            function toggleStudentFields() {
                const isStudent = roleSelect.value === 'student';

                studentFields.hidden = !isStudent;

                studentIdInput.disabled = !isStudent;
                studentIdInput.required = isStudent;

                batchSelect.disabled = !isStudent;
                batchSelect.required = isStudent;

                admissionSemesterSelect.disabled = !isStudent;
                admissionSemesterSelect.required = isStudent;
            }

            roleSelect.addEventListener('change', toggleStudentFields);
            toggleStudentFields();
            </script>
        </section>

        <section class="users-panel">
            <h2>All User Accounts (<?= count($users) ?>)</h2>

            <div class="users-table-wrap">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= (int) $user['id'] ?></td>
                            <td><?= e($user['full_name']) ?></td>
                            <td><?= e($user['username']) ?></td>

                            <td>
                                <?= e(
                                    $roles[$user['role']] ??
                                    ($user['role'] === 'admin'
                                        ? 'Administrator'
                                        : $user['role'])
                                ) ?>
                            </td>

                            <td class="<?= $user['is_active']
                                ? 'status-active'
                                : 'status-inactive' ?>">
                                <?= $user['is_active']
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </td>

                            <td>
                                <?php if (
                                    $user['role'] !== 'admin' &&
                                    (int) $user['id'] !== (int) $_SESSION['user_id']
                                ): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                                    <input type="hidden" name="action" value="toggle">

                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">

                                    <button class="status-button" type="submit">
                                        <?= $user['is_active']
                                                ? 'Deactivate'
                                                : 'Activate' ?>
                                    </button>
                                </form>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (count($users) === 0): ?>
                        <tr>
                            <td colspan="6">No user accounts found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</body>

</html>