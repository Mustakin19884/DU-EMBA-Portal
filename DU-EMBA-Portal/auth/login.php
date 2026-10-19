<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$error = '';
$roleLabels = [
    'student'  => 'Student',
    'teacher'  => 'Teacher',
    'accounts' => 'Accounts Officer',
    'academic' => 'Academic Office',
    'admin'    => 'Administrator',
];

$requestedRole = $_GET['role'] ?? $_POST['role'] ?? 'student';

if (!isset($roleLabels[$requestedRole])) {
    $requestedRole = 'student';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $postedRole = $_POST['role'] ?? '';

    if (!isset($roleLabels[$postedRole])) {
        $error = 'Invalid login request.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $stmt = $conn->prepare(
            'SELECT id, full_name, username, password_hash, role, is_active
             FROM users
             WHERE username = ?
             LIMIT 1'
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();

        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            $user &&
            (int)$user['is_active'] === 1 &&
            $user['role'] === $postedRole &&
            password_verify($password, $user['password_hash'])
        ) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            $update = $conn->prepare(
                'UPDATE users SET last_login = NOW() WHERE id = ?'
            );
            $update->bind_param('i', $user['id']);
            $update->execute();
            $update->close();

            header('Location: ' . dashboard_url($user['role']));
            exit;
        }

        $error = 'Invalid login details, inactive account, or incorrect role.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($roleLabels[$requestedRole]) ?> Login | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <main class="login-container">
        <section class="login-card">
            <div class="brand-mark">DU</div>
            <h1>DU EMBA MIS Portal</h1>
            <p class="subtitle"><?= e($roleLabels[$requestedRole]) ?> Login</p>

            <?php if ($error !== ''): ?>
            <div class="alert" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="role" value="<?= e($requestedRole) ?>">

                <label for="username">Username</label>
                <input id="username" name="username" type="text" maxlength="60" autocomplete="username" required>

                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>

                <button type="submit" class="btn-primary">Login</button>
            </form>

            <p class="forgot">
                <a href="<?= BASE_URL ?>/auth/reset-password.php">Forgot Password?</a>
            </p>

            <div class="role-links">
                <p>Other login portals</p>
                <a href="?role=student">Student</a>
                <a href="?role=teacher">Teacher</a>
                <a href="?role=accounts">Accounts</a>
                <a href="?role=academic">Academic Office</a>
                <a href="?role=admin">Admin</a>
            </div>
        </section>
    </main>
</body>

</html>