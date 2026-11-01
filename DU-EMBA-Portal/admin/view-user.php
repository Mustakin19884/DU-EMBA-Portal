<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

require_once __DIR__ . '/../includes/functions.php';

// Enable error reporting during development
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Get User ID
$userId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($userId <= 0) {
    die('Invalid user ID.');
}

// Fetch user and related profile information
$sql = "
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.email,
        u.role,
        u.is_active,
        u.last_login,
        u.created_at,

        s.student_id,
        s.id AS student_profile_id,
        b.batch_name,
        sem.semester_name AS admission_semester,

        t.id AS teacher_profile_id,
        t.employee_id,
        t.designation,
        t.department,
        t.phone

    FROM users u

    LEFT JOIN students s
        ON s.user_id = u.id

    LEFT JOIN batches b
        ON b.id = s.batch_id

    LEFT JOIN semesters sem
        ON sem.id = s.admission_semester_id

    LEFT JOIN teachers t
        ON t.user_id = u.id

    WHERE u.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Database query error: ' . $conn->error);
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$user) {
    die('User not found.');
}

$pageTitle = 'View User';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.user-view-container {
    max-width: 1150px;
    margin: 30px auto;
    padding: 25px;
}

.user-view-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.user-view-heading h2 {
    color: #102d50;
    margin: 0;
}

.user-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.user-actions a {
    display: inline-block;
    padding: 10px 16px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}

.btn-back {
    background: #f4efe5;
    color: #102d50;
}

.btn-edit {
    background: #102d50;
    color: white;
}

.btn-teacher-edit {
    background: #1c456e;
    color: white;
}

.user-view-card {
    background: #fff;
    border: 1px solid #e5e0d7;
    border-radius: 12px;
    padding: 28px;
    margin-bottom: 22px;
}

.user-view-card h3 {
    color: #102d50;
    margin: 0 0 22px;
    padding-bottom: 12px;
    border-bottom: 1px solid #eee;
}

.user-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.user-info-item {
    padding: 15px;
    background: #f4efe5;
    border-radius: 8px;
    min-width: 0;
    overflow-wrap: anywhere;
}

.user-info-item small {
    display: block;
    color: #666;
    font-size: 13px;
    margin-bottom: 7px;
}

.user-info-item strong {
    color: #102d50;
    font-size: 15px;
}

.status-active {
    color: #198754 !important;
}

.status-inactive {
    color: #dc3545 !important;
}

.role-label {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    background: #e6edf5;
    color: #102d50;
    font-size: 13px;
    font-weight: 600;
    text-transform: capitalize;
}

@media(max-width: 700px) {
    .user-view-container {
        padding: 12px;
    }

    .user-view-card {
        padding: 20px;
    }

    .user-info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="user-view-container">

    <!-- Page Heading -->
    <div class="user-view-heading">

        <h2>User Profile Details</h2>

        <div class="user-actions">

            <a class="btn-back" href="<?= BASE_URL ?>/admin/users.php">
                ← Back to Users
            </a>

            <a class="btn-edit" href="<?= BASE_URL ?>/admin/edit-user.php?id=<?= (int)$user['id'] ?>">
                Edit Account
            </a>

            <?php if (
                $user['role'] === 'teacher'
                && !empty($user['teacher_profile_id'])
            ): ?>

            <a class="btn-teacher-edit"
                href="<?= BASE_URL ?>/admin/edit-teacher.php?id=<?= (int)$user['teacher_profile_id'] ?>">
                Edit Teacher Information
            </a>

            <?php endif; ?>

        </div>

    </div>

    <!-- Account Information -->
    <div class="user-view-card">

        <h3>Account Information</h3>

        <div class="user-info-grid">

            <div class="user-info-item">
                <small>Full Name</small>
                <strong><?= e($user['full_name']) ?></strong>
            </div>

            <div class="user-info-item">
                <small>Username</small>
                <strong><?= e($user['username']) ?></strong>
            </div>

            <div class="user-info-item">
                <small>Email Address</small>
                <strong><?= e($user['email'] ?: 'Not provided') ?></strong>
            </div>

            <div class="user-info-item">
                <small>Account Role</small>
                <strong>
                    <span class="role-label">
                        <?= e($user['role']) ?>
                    </span>
                </strong>
            </div>

            <div class="user-info-item">
                <small>Account Status</small>

                <?php if ((int)$user['is_active'] === 1): ?>
                <strong class="status-active">Active</strong>
                <?php else: ?>
                <strong class="status-inactive">Inactive</strong>
                <?php endif; ?>

            </div>

            <div class="user-info-item">
                <small>Last Login</small>
                <strong>
                    <?= e($user['last_login'] ?: 'Never logged in') ?>
                </strong>
            </div>

            <div class="user-info-item">
                <small>Account Created</small>
                <strong>
                    <?= e($user['created_at'] ?: '-') ?>
                </strong>
            </div>

            <div class="user-info-item">
                <small>User ID</small>
                <strong><?= (int)$user['id'] ?></strong>
            </div>

        </div>

    </div>

    <!-- Student Information -->
    <?php if ($user['role'] === 'student'): ?>

    <div class="user-view-card">

        <h3>Student Information</h3>

        <?php if (!empty($user['student_profile_id'])): ?>

        <div class="user-info-grid">

            <div class="user-info-item">
                <small>Student ID</small>
                <strong>
                    <?= e($user['student_id'] ?? '-') ?>
                </strong>
            </div>

            <div class="user-info-item">
                <small>Batch</small>
                <strong>
                    <?= e($user['batch_name'] ?? '-') ?>
                </strong>
            </div>

            <div class="user-info-item">
                <small>Admission Semester</small>
                <strong>
                    <?= e($user['admission_semester'] ?? '-') ?>
                </strong>
            </div>

        </div>

        <?php else: ?>

        <p>Student profile information is not available.</p>

        <?php endif; ?>

    </div>

    <?php endif; ?>

    <!-- Teacher Information -->
    <?php if ($user['role'] === 'teacher'): ?>

    <div class="user-view-card">

        <h3>Teacher Information</h3>

        <?php if (!empty($user['teacher_profile_id'])): ?>

        <div class="user-info-grid">

            <div class="user-info-item">
                <small>Employee ID</small>
                <strong>
                    <?= e($user['employee_id'] ?? '-') ?>
                </strong>
            </div>

            <div class="user-info-item">
                <small>Designation</small>
                <strong>
                    <?= e($user['designation'] ?? '-') ?>
                </strong>
            </div>

            <div class="user-info-item">
                <small>Department</small>
                <strong>
                    <?= e($user['department'] ?? '-') ?>
                </strong>
            </div>

            <div class="user-info-item">
                <small>Phone Number</small>
                <strong>
                    <?= e($user['phone'] ?? '-') ?>
                </strong>
            </div>

        </div>

        <?php else: ?>

        <p>
            Teacher profile information is not available.
        </p>

        <?php endif; ?>

    </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>