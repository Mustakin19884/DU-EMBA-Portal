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

$allRoles = $roles + ['admin' => 'Administrator'];

/* ---------------------------------------------------------
   Load batches and semesters
--------------------------------------------------------- */

$batchesResult = $conn->query("
    SELECT id, batch_name, admission_year
    FROM batches
    WHERE status = 'active'
    ORDER BY admission_year DESC, batch_name ASC
");

$batches = $batchesResult->fetch_all(MYSQLI_ASSOC);

$semestersResult = $conn->query("
    SELECT id, semester_name, status
    FROM semesters
    ORDER BY id DESC
");

$semesters = $semestersResult->fetch_all(MYSQLI_ASSOC);


/* ---------------------------------------------------------
   Handle POST requests
--------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {

        $error = 'Invalid request. Please refresh the page and try again.';

    } else {

        $action = (string) ($_POST['action'] ?? '');

        /* -------------------------------------------------
           CREATE ACCOUNT
        ------------------------------------------------- */

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

            $emailValue = $email === '' ? null : $email;

            /* Check duplicate username and email */

            if ($error === '') {

                $check = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE username = ?
                       OR (email IS NOT NULL AND email = ?)
                    LIMIT 1
                ");

                $check->bind_param('ss', $username, $emailValue);
                $check->execute();

                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($exists) {
                    $error = 'That username or email is already in use.';
                }
            }

            /* Validate student information */

            if ($error === '' && $role === 'student') {

                $check = $conn->prepare("
                    SELECT id
                    FROM students
                    WHERE student_id = ?
                    LIMIT 1
                ");

                $check->bind_param('s', $studentId);
                $check->execute();

                $studentExists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($studentExists) {
                    $error = 'This Student ID is already registered.';
                }

                if ($error === '') {

                    $check = $conn->prepare("
                        SELECT id
                        FROM batches
                        WHERE id = ? AND status = 'active'
                        LIMIT 1
                    ");

                    $check->bind_param('i', $batchId);
                    $check->execute();

                    $batchExists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if (!$batchExists) {
                        $error = 'Selected batch is invalid or inactive.';
                    }
                }

                if ($error === '') {

                    $check = $conn->prepare("
                        SELECT id
                        FROM semesters
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $check->bind_param('i', $admissionSemesterId);
                    $check->execute();

                    $semesterExists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if (!$semesterExists) {
                        $error = 'Selected admission semester does not exist.';
                    }
                }
            }

            /* Save account and student profile */

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

                    $stmt = $conn->prepare("
                        INSERT INTO users
                            (full_name, username, email, password_hash, role, is_active)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

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

                    if ($role === 'student') {

                        $stmt = $conn->prepare("
                            INSERT INTO students
                                (user_id, student_id, batch_id, admission_semester_id)
                            VALUES (?, ?, ?, ?)
                        ");

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

        }

        /* -------------------------------------------------
           ACTIVATE / DEACTIVATE ACCOUNT
        ------------------------------------------------- */

        elseif ($action === 'toggle') {

            $userId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$userId || $userId < 1) {

                $error = 'Invalid user selected.';

            } elseif ($userId === (int) $_SESSION['user_id']) {

                $error = 'You cannot deactivate your own account here.';

            } else {

                $stmt = $conn->prepare("
                    SELECT id, role, is_active
                    FROM users
                    WHERE id = ?
                ");

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

                        $stmt = $conn->prepare("
                            UPDATE users
                            SET is_active = ?
                            WHERE id = ?
                        ");

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


/* ---------------------------------------------------------
   Search and role filter
--------------------------------------------------------- */

$search = trim((string) ($_GET['search'] ?? ''));
$filterRole = (string) ($_GET['role'] ?? '');

if (!isset($allRoles[$filterRole])) {
    $filterRole = '';
}

$sql = "
    SELECT
        id,
        full_name,
        username,
        email,
        role,
        is_active,
        created_at
    FROM users
    WHERE 1 = 1
";

if ($search !== '') {

    $sql .= "
        AND (
            full_name LIKE ?
            OR username LIKE ?
            OR email LIKE ?
            OR CAST(id AS CHAR) LIKE ?
        )
    ";
}

if ($filterRole !== '') {
    $sql .= " AND role = ? ";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);

if ($search !== '' && $filterRole !== '') {

    $term = '%' . $search . '%';

    $stmt->bind_param(
        'sssss',
        $term,
        $term,
        $term,
        $term,
        $filterRole
    );

} elseif ($search !== '') {

    $term = '%' . $search . '%';

    $stmt->bind_param(
        'ssss',
        $term,
        $term,
        $term,
        $term
    );

} elseif ($filterRole !== '') {

    $stmt->bind_param('s', $filterRole);
}

$stmt->execute();

$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


/* ---------------------------------------------------------
   User statistics
--------------------------------------------------------- */

$statsResult = $conn->query("
    SELECT
        COUNT(*) AS total_users,
        SUM(is_active = 1) AS active_users,
        SUM(is_active = 0) AS inactive_users,
        SUM(role = 'student') AS students,
        SUM(role = 'teacher') AS teachers
    FROM users
");

$stats = $statsResult->fetch_assoc();

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
        width: calc(100% - 48px);
        max-width: 1550px;
        margin: 35px auto;
        box-sizing: border-box;
    }

    .users-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 25px;
    }

    .users-header h1 {
        color: #102d50;
        margin-bottom: 7px;
    }

    .users-header p {
        color: #687789;
        margin: 0;
    }

    .back-link {
        color: #102d50;
        text-decoration: none;
        font-weight: 600;
    }

    .users-panel {
        background: #fff;
        padding: 28px;
        margin: 24px 0;
        border-radius: 14px;
        box-shadow: 0 4px 20px rgba(16, 45, 80, .08);
        box-sizing: border-box;
    }

    .users-panel h2 {
        color: #102d50;
        margin-top: 0;
        margin-bottom: 8px;
    }

    .panel-description {
        color: #687789;
        margin-bottom: 22px;
    }

    /* Statistics */

    .user-stats {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 16px;
        margin: 24px 0;
    }

    .stat-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 3px 12px rgba(16, 45, 80, .04);
    }

    .stat-card span {
        display: block;
        color: #687789;
        font-size: 13px;
        margin-bottom: 9px;
    }

    .stat-card strong {
        color: #102d50;
        font-size: 26px;
    }

    /* Messages */

    .notice {
        padding: 14px 17px;
        border-radius: 8px;
        margin: 15px 0;
    }

    .success {
        background: #e7f6ec;
        color: #176534;
    }

    .failure {
        background: #fdecec;
        color: #9b2020;
    }

    /* Create form */

    .users-form {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    .users-form label {
        display: block;
        font-weight: 600;
        color: #263548;
        margin-bottom: 7px;
    }

    .users-form input,
    .users-form select {
        box-sizing: border-box;
        width: 100%;
        padding: 12px 14px;
        border: 1px solid #ccd3dc;
        border-radius: 7px;
        background: #fff;
        font-size: 14px;
    }

    .users-form input:focus,
    .users-form select:focus,
    .user-filter input:focus,
    .user-filter select:focus {
        outline: none;
        border-color: #1c456e;
        box-shadow: 0 0 0 3px rgba(28, 69, 110, .10);
    }

    .form-full {
        grid-column: 1 / -1;
    }

    .student-fields-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        padding: 20px;
        background: #f8f7f3;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
    }

    .muted {
        color: #64748b;
        font-size: 13px;
        margin-top: 6px;
    }

    .primary-button,
    .status-button {
        display: inline-block;
        background: #102d50;
        color: #fff;
        border: 0;
        border-radius: 7px;
        padding: 11px 17px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
    }

    .primary-button:hover,
    .status-button:hover {
        background: #1c456e;
    }

    /* Search and filters */

    .user-filter {
        display: flex;
        align-items: end;
        gap: 14px;
        flex-wrap: wrap;
        margin: 22px 0;
    }

    .user-filter>div {
        flex: 1;
        min-width: 200px;
    }

    .user-filter label {
        display: block;
        font-weight: 600;
        font-size: 13px;
        margin-bottom: 7px;
    }

    .user-filter input,
    .user-filter select {
        width: 100%;
        box-sizing: border-box;
        padding: 11px 13px;
        border: 1px solid #ccd3dc;
        border-radius: 7px;
    }

    .filter-reset {
        display: inline-block;
        padding: 11px 15px;
        background: #f4efe5;
        color: #102d50;
        text-decoration: none;
        border-radius: 7px;
        font-weight: 600;
    }

    /* User table */

    .users-table-wrap {
        overflow-x: auto;
        width: 100%;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
    }

    .users-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1150px;
    }

    .users-table th,
    .users-table td {
        text-align: left;
        padding: 15px 16px;
        border-bottom: 1px solid #e5e7eb;
        white-space: nowrap;
    }

    .users-table th {
        background: #f4efe5;
        color: #102d50;
        font-size: 13px;
        font-weight: 700;
    }

    .users-table td {
        color: #263548;
        font-size: 14px;
    }

    .users-table tbody tr:hover {
        background: #faf9f6;
    }

    .users-table tbody tr:last-child td {
        border-bottom: 0;
    }

    /* Role and status badges */

    .role-badge,
    .status-badge {
        display: inline-block;
        padding: 6px 11px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
    }

    .role-student {
        background: #e8f0fa;
        color: #174a7e;
    }

    .role-teacher {
        background: #f0eafa;
        color: #623b8e;
    }

    .role-accounts {
        background: #fff3df;
        color: #93600b;
    }

    .role-academic {
        background: #e5f5ef;
        color: #176747;
    }

    .role-admin {
        background: #e9e9e9;
        color: #333;
    }

    .status-badge.active {
        background: #e7f6ec;
        color: #176534;
    }

    .status-badge.inactive {
        background: #fdecec;
        color: #9b2020;
    }

    /* Actions */

    .user-actions {
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .user-action {
        display: inline-block;
        padding: 8px 12px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        border: 0;
        cursor: pointer;
        white-space: nowrap;
    }

    .user-view {
        background: #e8eef5;
        color: #102d50;
    }

    .user-edit {
        background: #102d50;
        color: #fff;
    }

    .user-toggle {
        background: #f4efe5;
        color: #102d50;
    }

    .empty-state {
        text-align: center;
        padding: 35px;
        color: #687789;
    }

    @media (max-width: 1100px) {
        .user-stats {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 750px) {
        .users-container {
            width: calc(100% - 24px);
        }

        .users-panel {
            padding: 18px;
        }

        .user-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .users-form,
        .student-fields-grid {
            grid-template-columns: 1fr;
        }

        .form-full {
            grid-column: auto;
        }
    }

    @media (max-width: 450px) {
        .user-stats {
            grid-template-columns: 1fr;
        }
    }
    </style>
</head>

<body>

    <main class="users-container">

        <!-- PAGE HEADER -->

        <header class="users-header">
            <div>
                <a class="back-link" href="<?= BASE_URL ?>/admin/dashboard.php">
                    &larr; Back to Admin Dashboard
                </a>

                <h1>User Management</h1>

                <p>Create, view and manage all portal user accounts.</p>
            </div>
        </header>


        <!-- NOTIFICATIONS -->

        <?php if ($message !== ''): ?>
        <div class="notice success">
            <?= e($message) ?>
        </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="notice failure">
            <?= e($error) ?>
        </div>
        <?php endif; ?>


        <!-- STATISTICS -->

        <section class="user-stats">

            <div class="stat-card">
                <span>Total Users</span>
                <strong><?= (int) ($stats['total_users'] ?? 0) ?></strong>
            </div>

            <div class="stat-card">
                <span>Active Accounts</span>
                <strong><?= (int) ($stats['active_users'] ?? 0) ?></strong>
            </div>

            <div class="stat-card">
                <span>Inactive Accounts</span>
                <strong><?= (int) ($stats['inactive_users'] ?? 0) ?></strong>
            </div>

            <div class="stat-card">
                <span>Students</span>
                <strong><?= (int) ($stats['students'] ?? 0) ?></strong>
            </div>

            <div class="stat-card">
                <span>Teachers</span>
                <strong><?= (int) ($stats['teachers'] ?? 0) ?></strong>
            </div>

        </section>


        <!-- CREATE NEW ACCOUNT -->

        <section class="users-panel">

            <h2>Create New Account</h2>

            <p class="panel-description">
                Create Student, Teacher, Accounts or Academic Office accounts.
            </p>

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


                <!-- STUDENT-ONLY FIELDS -->

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
                            <p class="muted">
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
                            <p class="muted">
                                No semesters found. Add a semester first.
                            </p>
                            <?php endif; ?>
                        </div>

                    </div>

                </div>


                <div>
                    <label for="password">Password *</label>

                    <input id="password" name="password" type="password" minlength="12" autocomplete="new-password"
                        required>
                </div>

                <div>
                    <label for="confirm_password">Confirm Password *</label>

                    <input id="confirm_password" name="confirm_password" type="password" minlength="12"
                        autocomplete="new-password" required>
                </div>

                <div class="form-full">
                    <button type="submit" class="primary-button">
                        Create User Account
                    </button>
                </div>

            </form>

        </section>


        <!-- ALL USER ACCOUNTS -->

        <section class="users-panel">

            <h2>All User Accounts (<?= count($users) ?>)</h2>

            <p class="panel-description">
                Search, filter, view, edit and manage portal accounts.
            </p>


            <!-- SEARCH AND FILTER -->

            <form method="GET" class="user-filter">

                <div>
                    <label for="search">Search Users</label>

                    <input type="text" id="search" name="search" placeholder="Name, username, email or ID"
                        value="<?= e($search) ?>">
                </div>

                <div>
                    <label for="role_filter">Filter by Role</label>

                    <select name="role" id="role_filter">

                        <option value="">All Roles</option>

                        <?php foreach ($allRoles as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $filterRole === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <button type="submit" class="primary-button">
                    Search
                </button>

                <a href="<?= BASE_URL ?>/admin/users.php" class="filter-reset">
                    Reset
                </a>

            </form>


            <!-- USER TABLE -->

            <div class="users-table-wrap">

                <table class="users-table">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($users as $user): ?>

                        <?php
                            $roleClass = 'role-' . $user['role'];
                            $isActive = (int) $user['is_active'] === 1;
                        ?>

                        <tr>

                            <td><?= (int) $user['id'] ?></td>

                            <td>
                                <strong><?= e($user['full_name']) ?></strong>
                            </td>

                            <td><?= e($user['username']) ?></td>

                            <td><?= e($user['email'] ?? '—') ?></td>

                            <td>
                                <span class="role-badge <?= e($roleClass) ?>">
                                    <?= e($allRoles[$user['role']] ?? $user['role']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="status-badge <?= $isActive ? 'active' : 'inactive' ?>">
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>

                            <td>
                                <?= e(
                                    $user['created_at']
                                        ? date('d M Y', strtotime($user['created_at']))
                                        : '—'
                                ) ?>
                            </td>

                            <td>

                                <div class="user-actions">

                                    <!-- VIEW -->

                                    <a class="user-action user-view"
                                        href="<?= BASE_URL ?>/admin/view-user.php?id=<?= (int) $user['id'] ?>">
                                        View
                                    </a>

                                    <!-- EDIT -->

                                    <a class="user-action user-edit"
                                        href="<?= BASE_URL ?>/admin/edit-user.php?id=<?= (int) $user['id'] ?>">
                                        Edit
                                    </a>

                                    <!-- ACTIVATE / DEACTIVATE -->

                                    <?php if (
                                        $user['role'] !== 'admin' &&
                                        (int) $user['id'] !== (int) $_SESSION['user_id']
                                    ): ?>

                                    <form method="post">

                                        <input type="hidden" name="csrf_token"
                                            value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden" name="action" value="toggle">

                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">

                                        <button class="user-action user-toggle" type="submit"
                                            onclick="return confirm('Are you sure you want to <?= $isActive ? 'deactivate' : 'activate' ?> this account?');">
                                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                        </button>

                                    </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                        <?php endforeach; ?>


                        <?php if (count($users) === 0): ?>

                        <tr>
                            <td colspan="8" class="empty-state">
                                No user accounts found.
                            </td>
                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>


    <script>
    document.addEventListener('DOMContentLoaded', function() {

        const roleSelect = document.getElementById('role');

        const studentFields = document.getElementById('student-fields');

        const studentId = document.getElementById('student_id');

        const batchId = document.getElementById('batch_id');

        const admissionSemester = document.getElementById('admission_semester_id');


        function toggleStudentFields() {

            const isStudent = roleSelect.value === 'student';

            studentFields.hidden = !isStudent;

            studentId.disabled = !isStudent;
            batchId.disabled = !isStudent;
            admissionSemester.disabled = !isStudent;

            studentId.required = isStudent;
            batchId.required = isStudent;
            admissionSemester.required = isStudent;
        }

        roleSelect.addEventListener('change', toggleStudentFields);

        toggleStudentFields();

    });
    </script>

</body>

</html>