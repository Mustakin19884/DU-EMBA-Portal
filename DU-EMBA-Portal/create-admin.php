<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$countResult = $conn->query(
    "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'"
);
$adminExists = (int)$countResult->fetch_assoc()['total'] > 0;

if ($adminExists) {
    http_response_code(403);
    exit('Administrator already exists. Setup is disabled.');
}

if (empty($_SESSION['setup_token'])) {
    $_SESSION['setup_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['setup_token'] ?? '';
    $name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!hash_equals($_SESSION['setup_token'], $token)) {
        $error = 'Invalid request. Refresh the page and try again.';
    } elseif ($name === '' || $username === '') {
        $error = 'Name and username are required.';
    } elseif (
        strlen($username) > 60 ||
        !preg_match('/\A[A-Za-z0-9_.-]+\z/', $username)
    ) {
        $error = 'Username must use letters, numbers, dots, dashes or underscores.';
    } elseif (strlen($password) < 12) {
        $error = 'Password must be at least 12 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $conn->begin_transaction();

            // Lock the user table against concurrent first-admin creation.
            $conn->query('LOCK TABLES users WRITE');

            $check = $conn->query(
                "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'"
            );
            $exists = (int)$check->fetch_assoc()['total'] > 0;

            if ($exists) {
                $conn->query('UNLOCK TABLES');
                $conn->rollback();
                $error = 'An administrator already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "INSERT INTO users
                    (full_name, username, password_hash, role, is_active)
                    VALUES (?, ?, ?, 'admin', 1)"
                );

                $stmt->bind_param('sss', $name, $username, $hash);
                $stmt->execute();
                $stmt->close();

                $conn->query('UNLOCK TABLES');
                $conn->commit();

                unset($_SESSION['setup_token']);

                header(
                    'Location: ' . BASE_URL .
                    '/auth/login.php?role=admin&created=1'
                );
                exit;
            }
        } catch (Throwable $e) {
            try {
                $conn->query('UNLOCK TABLES');
            } catch (Throwable $ignored) {
                // Ignore cleanup errors.
            }

            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
                // Ignore rollback errors.
            }

            error_log($e->getMessage());
            $error = 'Account creation failed. Check the username and database.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create First Administrator</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <main class="login-container">
        <section class="login-card">
            <div class="brand-mark">DU</div>
            <h1>Initial Administrator Setup</h1>
            <p>Create the first portal administrator.</p>

            <?php if ($error !== ''): ?>
            <div class="alert" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="setup_token" value="<?= htmlspecialchars(
                       $_SESSION['setup_token'],
                       ENT_QUOTES,
                       'UTF-8'
                   ) ?>">

                <label for="full_name">Full name</label>
                <input id="full_name" name="full_name" maxlength="150" required>

                <label for="username">Admin username</label>
                <input id="username" name="username" maxlength="60" required>

                <label for="password">Password (minimum 12 characters)</label>
                <input id="password" name="password" type="password" minlength="12" autocomplete="new-password"
                    required>

                <label for="confirm_password">Confirm password</label>
                <input id="confirm_password" name="confirm_password" type="password" minlength="12"
                    autocomplete="new-password" required>

                <button type="submit">Create Administrator</button>
            </form>
        </section>
    </main>
</body>

</html>