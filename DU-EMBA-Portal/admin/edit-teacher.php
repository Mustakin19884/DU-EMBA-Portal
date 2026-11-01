<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

require_once __DIR__ . '/../includes/functions.php';

$teacherId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($teacherId <= 0) {
    die('Invalid teacher ID.');
}

// Fetch teacher and account information
$stmt = $conn->prepare("
    SELECT
        t.id,
        t.user_id,
        t.employee_id,
        t.designation,
        t.department,
        t.phone,
        u.full_name,
        u.username
    FROM teachers t
    INNER JOIN users u ON u.id = t.user_id
    WHERE t.id = ?
    LIMIT 1
");

if (!$stmt) {
    die('Database error: ' . $conn->error);
}

$stmt->bind_param('i', $teacherId);
$stmt->execute();

$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    die('Teacher profile not found.');
}

$errors = [];

if (empty($_SESSION['teacher_edit_csrf'])) {
    $_SESSION['teacher_edit_csrf'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['teacher_edit_csrf'], $token)) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {

        $employeeId = trim($_POST['employee_id'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($employeeId === '') {
            $errors[] = 'Employee ID is required.';
        }

        if ($designation === '') {
            $errors[] = 'Designation is required.';
        }

        if ($department === '') {
            $errors[] = 'Department is required.';
        }

        // Check duplicate Employee ID
        if (!$errors) {
            $check = $conn->prepare("
                SELECT id
                FROM teachers
                WHERE employee_id = ?
                AND id <> ?
                LIMIT 1
            ");

            $check->bind_param('si', $employeeId, $teacherId);
            $check->execute();

            if ($check->get_result()->num_rows > 0) {
                $errors[] = 'This Employee ID is already assigned to another teacher.';
            }

            $check->close();
        }

        // Update teacher profile
        if (!$errors) {

            $update = $conn->prepare("
                UPDATE teachers
                SET employee_id = ?,
                    designation = ?,
                    department = ?,
                    phone = ?
                WHERE id = ?
            ");

            if (!$update) {
                $errors[] = 'Database error: ' . $conn->error;
            } else {

                $update->bind_param(
                    'ssssi',
                    $employeeId,
                    $designation,
                    $department,
                    $phone,
                    $teacherId
                );

                if ($update->execute()) {
                    $update->close();

                    header(
                        'Location: ' . BASE_URL .
                        '/admin/view-user.php?id=' . (int)$teacher['user_id']
                    );
                    exit;

                } else {
                    $errors[] = 'Unable to update teacher information.';
                    $update->close();
                }
            }
        }
    }
}

$pageTitle = 'Edit Teacher Information';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.teacher-edit-container {
    max-width: 900px;
    margin: 30px auto;
    padding: 25px;
}

.teacher-edit-card {
    background: #fff;
    border: 1px solid #e5e0d7;
    border-radius: 12px;
    padding: 30px;
}

.teacher-edit-card h2 {
    color: #102d50;
    margin-top: 0;
}

.teacher-edit-card .subtitle {
    color: #666;
    margin-bottom: 25px;
}

.teacher-info-box {
    background: #f4efe5;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 25px;
}

.teacher-info-box strong {
    color: #102d50;
}

.teacher-form-group {
    margin-bottom: 20px;
}

.teacher-form-group label {
    display: block;
    font-weight: 600;
    color: #102d50;
    margin-bottom: 8px;
}

.teacher-form-group input {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 7px;
    box-sizing: border-box;
}

.teacher-form-buttons {
    display: flex;
    gap: 12px;
    margin-top: 25px;
}

.teacher-form-buttons button,
.teacher-form-buttons a {
    padding: 11px 20px;
    border: none;
    border-radius: 6px;
    text-decoration: none;
    cursor: pointer;
}

.teacher-save {
    background: #102d50;
    color: white;
}

.teacher-cancel {
    background: #f4efe5;
    color: #102d50;
}

.teacher-errors {
    background: #fbe9e7;
    color: #9b2525;
    padding: 14px;
    border-radius: 7px;
    margin-bottom: 20px;
}

@media(max-width: 600px) {
    .teacher-edit-container {
        padding: 12px;
    }

    .teacher-edit-card {
        padding: 20px;
    }
}
</style>

<div class="teacher-edit-container">

    <div class="teacher-edit-card">

        <h2>Edit Teacher Information</h2>

        <p class="subtitle">
            Update the professional information of this teacher.
        </p>

        <div class="teacher-info-box">
            <strong>Teacher Name:</strong>
            <?= e($teacher['full_name']) ?>
            <br>

            <strong>Username:</strong>
            <?= e($teacher['username']) ?>
        </div>

        <?php if ($errors): ?>
        <div class="teacher-errors">
            <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['teacher_edit_csrf']) ?>">

            <div class="teacher-form-group">
                <label>Employee ID *</label>
                <input type="text" name="employee_id" value="<?= e($teacher['employee_id'] ?? '') ?>" required>
            </div>

            <div class="teacher-form-group">
                <label>Designation *</label>
                <input type="text" name="designation" value="<?= e($teacher['designation'] ?? '') ?>" required>
            </div>

            <div class="teacher-form-group">
                <label>Department *</label>
                <input type="text" name="department" value="<?= e($teacher['department'] ?? '') ?>" required>
            </div>

            <div class="teacher-form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" value="<?= e($teacher['phone'] ?? '') ?>">
            </div>

            <div class="teacher-form-buttons">
                <button type="submit" class="teacher-save">
                    Save Changes
                </button>

                <a href="<?= BASE_URL ?>/admin/view-user.php?id=<?= (int)$teacher['user_id'] ?>" class="teacher-cancel">
                    Cancel
                </a>
            </div>

        </form>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>