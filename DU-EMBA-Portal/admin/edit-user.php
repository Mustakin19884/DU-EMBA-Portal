<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

require_once __DIR__ . '/../includes/functions.php';

$userId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($userId <= 0) {
    die('Invalid user ID.');
}

$stmt = $conn->prepare("
    SELECT id, full_name, username, email, role, is_active
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die('User not found.');
}

$errors = [];
$success = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_edit_csrf'])) {
    $_SESSION['user_edit_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['user_edit_csrf'], $token)) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {

        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3-50 characters and contain only letters, numbers, dots, underscores or hyphens.';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if ($password !== '' && strlen($password) < 12) {
            $errors[] = 'New password must be at least 12 characters.';
        }

        if ($userId === (int) $_SESSION['user_id'] && !$isActive) {
            $errors[] = 'You cannot deactivate your own account.';
        }

        if ($user['role'] === 'admin' && !$isActive) {
            $errors[] = 'Admin accounts cannot be deactivated from this page.';
        }

        $check = $conn->prepare("
            SELECT id FROM users
            WHERE (username = ? OR (email = ? AND email IS NOT NULL AND email <> ''))
            AND id <> ?
            LIMIT 1
        ");

        $check->bind_param('ssi', $username, $email, $userId);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors[] = 'Username or email is already used by another account.';
        }

        $check->close();

        if (!$errors) {

            if ($password !== '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $update = $conn->prepare("
                    UPDATE users
                    SET full_name = ?, username = ?, email = ?,
                        password_hash = ?, is_active = ?
                    WHERE id = ?
                ");

                $update->bind_param(
                    'ssssii',
                    $fullName,
                    $username,
                    $email,
                    $passwordHash,
                    $isActive,
                    $userId
                );

            } else {

                $update = $conn->prepare("
                    UPDATE users
                    SET full_name = ?, username = ?, email = ?, is_active = ?
                    WHERE id = ?
                ");

                $update->bind_param(
                    'sssii',
                    $fullName,
                    $username,
                    $email,
                    $isActive,
                    $userId
                );
            }

            if ($update->execute()) {
                $update->close();

                header('Location: ' . BASE_URL . '/admin/view-user.php?id=' . $userId);
                exit;

            } else {
                $errors[] = 'Unable to update account: ' . $update->error;
                $update->close();
            }
        }
    }
}

$pageTitle = 'Edit User';
require_once __DIR__ . '/../includes/admin-header.php';
?>

<style>
.edit-user-container {
    max-width: 850px;
    margin: 30px auto;
    padding: 25px;
}

.edit-user-card {
    background: white;
    border: 1px solid #e5e0d7;
    border-radius: 12px;
    padding: 30px;
}

.edit-user-card h2 {
    color: #102d50;
    margin-top: 0;
}

.edit-form-group {
    margin-bottom: 20px;
}

.edit-form-group label {
    display: block;
    font-weight: 600;
    color: #102d50;
    margin-bottom: 8px;
}

.edit-form-group input {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 7px;
    box-sizing: border-box;
}

.edit-user-buttons {
    display: flex;
    gap: 12px;
    margin-top: 25px;
}

.edit-user-buttons button,
.edit-user-buttons a {
    padding: 11px 20px;
    border: 0;
    border-radius: 6px;
    text-decoration: none;
    cursor: pointer;
}

.edit-save {
    background: #102d50;
    color: white;
}

.edit-cancel {
    background: #f4efe5;
    color: #102d50;
}

.edit-error {
    background: #fbe9e7;
    color: #9b2525;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 15px;
}

.edit-checkbox {
    display: flex;
    align-items: center;
    gap: 10px;
}

.edit-checkbox input {
    width: auto;
}
</style>

<div class="edit-user-container">
    <div class="edit-user-card">

        <h2>Edit User Account</h2>
        <p>Update account information below. Role cannot be changed here.</p>

        <?php if ($errors): ?>
        <div class="edit-error">
            <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['user_edit_csrf']) ?>">

            <div class="edit-form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= e($user['full_name']) ?>" required>
            </div>

            <div class="edit-form-group">
                <label>Username</label>
                <input type="text" name="username" value="<?= e($user['username']) ?>" required>
            </div>

            <div class="edit-form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>">
            </div>

            <div class="edit-form-group">
                <label>Role</label>
                <input type="text" value="<?= e(ucfirst($user['role'])) ?>" disabled>
            </div>

            <div class="edit-form-group">
                <label>New Password</label>
                <input type="password" name="password" placeholder="Leave blank to keep current password">
                <small>Minimum 12 characters if changing password.</small>
            </div>

            <div class="edit-form-group edit-checkbox">
                <input type="checkbox" name="is_active" id="is_active" <?= $user['is_active'] ? 'checked' : '' ?>>
                <label for="is_active">Account Active</label>
            </div>

            <div class="edit-user-buttons">
                <button type="submit" class="edit-save">Save Changes</button>
                <a href="<?= BASE_URL ?>/admin/users.php" class="edit-cancel">Cancel</a>
            </div>

        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>