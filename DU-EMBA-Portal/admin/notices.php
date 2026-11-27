<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

require_once __DIR__ . '/../config/database.php';

$page_title = 'Notice Management';
$message = '';
$message_type = 'success';

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;

$allowedAudiences = [
    'all',
    'student',
    'teacher',
    'accounts',
    'academic'
];

$adminId = (int)($_SESSION['user_id'] ?? 0);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $message = 'Invalid request. Please refresh the page and try again.';
        $message_type = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | CREATE NOTICE
        |--------------------------------------------------------------------------
        */
        if ($action === 'create_notice') {

            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');
            $audience = $_POST['audience'] ?? 'all';
            $publish = isset($_POST['publish']) ? 1 : 0;

            if ($title === '' || $body === '') {

                $message = 'Notice title and message are required.';
                $message_type = 'error';

            } elseif (!in_array($audience, $allowedAudiences, true)) {

                $message = 'Invalid notice audience.';
                $message_type = 'error';

            } else {

                try {

                    $stmt = $conn->prepare(
                        "INSERT INTO notices
                        (
                            title,
                            body,
                            audience,
                            published_by,
                            published_at,
                            is_published
                        )
                        VALUES (
                            ?, ?, ?, ?,
                            IF(? = 1, NOW(), NULL),
                            ?
                        )"
                    );

                    $stmt->bind_param(
                        'sssiii',
                        $title,
                        $body,
                        $audience,
                        $adminId,
                        $publish,
                        $publish
                    );

                    $stmt->execute();
                    $stmt->close();

                    $message = $publish
                        ? 'Notice published successfully.'
                        : 'Notice saved as draft successfully.';

                    $message_type = 'success';

                } catch (Throwable $e) {

                    $message = 'Could not create notice. Please try again.';
                    $message_type = 'error';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE NOTICE
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'update_notice') {

            $noticeId = filter_input(
                INPUT_POST,
                'notice_id',
                FILTER_VALIDATE_INT
            );

            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');
            $audience = $_POST['audience'] ?? 'all';

            if (
                !$noticeId ||
                $title === '' ||
                $body === ''
            ) {

                $message = 'Please fill in all required fields.';
                $message_type = 'error';
                $editId = $noticeId ?: 0;

            } elseif (!in_array($audience, $allowedAudiences, true)) {

                $message = 'Invalid notice audience.';
                $message_type = 'error';
                $editId = $noticeId;

            } else {

                try {

                    $stmt = $conn->prepare(
                        "UPDATE notices
                         SET title = ?,
                             body = ?,
                             audience = ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param(
                        'sssi',
                        $title,
                        $body,
                        $audience,
                        $noticeId
                    );

                    $stmt->execute();

                    if ($stmt->affected_rows >= 0) {
                        $message = 'Notice updated successfully.';
                        $message_type = 'success';
                        $editId = 0;
                    }

                    $stmt->close();

                } catch (Throwable $e) {

                    $message = 'Could not update notice.';
                    $message_type = 'error';
                    $editId = $noticeId;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | PUBLISH / UNPUBLISH
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'toggle_publish') {

            $noticeId = filter_input(
                INPUT_POST,
                'notice_id',
                FILTER_VALIDATE_INT
            );

            if ($noticeId && $noticeId > 0) {

                $stmt = $conn->prepare(
                    "UPDATE notices
                     SET is_published =
                            IF(is_published = 1, 0, 1),
                         published_at =
                            IF(is_published = 1, NULL, NOW())
                     WHERE id = ?"
                );

                $stmt->bind_param('i', $noticeId);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $message = 'Notice publication status updated.';
                    $message_type = 'success';
                } else {
                    $message = 'No publication status was changed.';
                    $message_type = 'error';
                }

                $stmt->close();

            } else {
                $message = 'Invalid notice selected.';
                $message_type = 'error';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE NOTICE
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'delete_notice') {

            $noticeId = filter_input(
                INPUT_POST,
                'notice_id',
                FILTER_VALIDATE_INT
            );

            if ($noticeId && $noticeId > 0) {

                $stmt = $conn->prepare(
                    "DELETE FROM notices WHERE id = ?"
                );

                $stmt->bind_param('i', $noticeId);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $message = 'Notice deleted successfully.';
                    $message_type = 'success';
                    $editId = 0;
                } else {
                    $message = 'Notice not found.';
                    $message_type = 'error';
                }

                $stmt->close();

            } else {
                $message = 'Invalid notice selected.';
                $message_type = 'error';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD NOTICE FOR EDITING
|--------------------------------------------------------------------------
*/
$editNotice = null;

if ($editId > 0) {

    $stmt = $conn->prepare(
        "SELECT id, title, body, audience, is_published
         FROM notices
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->bind_param('i', $editId);
    $stmt->execute();

    $editNotice = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$editNotice) {
        $message = 'Notice record not found.';
        $message_type = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| NOTICE LIST
|--------------------------------------------------------------------------
*/
$result = $conn->query(
    "SELECT
        n.id,
        n.title,
        n.body,
        n.audience,
        n.is_published,
        n.published_at,
        n.created_at,
        u.full_name AS publisher_name
     FROM notices n
     LEFT JOIN users u ON u.id = n.published_by
     ORDER BY n.id DESC"
);

/*
|--------------------------------------------------------------------------
| PAGE HEADER
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../includes/admin-header.php';
?>

<div class="page-header">
    <div>
        <h1>Notice Management</h1>
        <p>Create, publish and manage notices for DU EMBA MIS users.</p>
    </div>
</div>

<?php if ($message !== ''): ?>
<div class="alert <?= $message_type === 'error' ? 'alert-danger' : 'alert-success' ?>">
    <?= e($message) ?>
</div>
<?php endif; ?>


<!-- EDIT NOTICE -->
<?php if ($editNotice): ?>

<section class="card" id="edit-notice">

    <h2>Edit Notice</h2>

    <form method="POST" action="<?= BASE_URL ?>/admin/notices.php?edit=<?= (int)$editNotice['id'] ?>">

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <input type="hidden" name="action" value="update_notice">

        <input type="hidden" name="notice_id" value="<?= (int)$editNotice['id'] ?>">

        <div class="form-grid">

            <div class="form-group">
                <label for="edit_title">Notice Title *</label>

                <input type="text" id="edit_title" name="title" maxlength="200" required
                    value="<?= e($editNotice['title']) ?>">
            </div>

            <div class="form-group">
                <label for="edit_audience">Audience *</label>

                <select id="edit_audience" name="audience" required>

                    <?php foreach ($allowedAudiences as $audience): ?>

                    <option value="<?= e($audience) ?>" <?= $editNotice['audience'] === $audience ? 'selected' : '' ?>>

                        <?= e(ucfirst($audience === 'all' ? 'Everyone' : $audience)) ?>

                    </option>

                    <?php endforeach; ?>

                </select>
            </div>

        </div>

        <div class="form-group">
            <label for="edit_body">Notice Message *</label>

            <textarea id="edit_body" name="body" rows="7" required><?= e($editNotice['body']) ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">
            Save Changes
        </button>

        <a href="<?= BASE_URL ?>/admin/notices.php" class="btn btn-secondary">
            Cancel
        </a>

    </form>

</section>

<?php endif; ?>


<!-- CREATE NOTICE -->
<section class="card">

    <h2>Create New Notice</h2>

    <form method="POST" action="<?= BASE_URL ?>/admin/notices.php">

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <input type="hidden" name="action" value="create_notice">

        <div class="form-grid">

            <div class="form-group">
                <label for="title">Notice Title *</label>

                <input type="text" id="title" name="title" maxlength="200" required placeholder="Enter notice title">
            </div>

            <div class="form-group">
                <label for="audience">Audience *</label>

                <select id="audience" name="audience" required>

                    <option value="all">Everyone</option>
                    <option value="student">Students</option>
                    <option value="teacher">Teachers</option>
                    <option value="accounts">Accounts Officer</option>
                    <option value="academic">Academic Officer</option>

                </select>
            </div>

        </div>

        <div class="form-group">
            <label for="body">Notice Message *</label>

            <textarea id="body" name="body" rows="7" required placeholder="Write your notice here..."></textarea>
        </div>

        <div class="form-group notice-publish-option">

            <label>
                <input type="checkbox" name="publish" value="1">
                Publish immediately
            </label>

            <small>
                Leave unchecked to save this notice as a draft.
            </small>

        </div>

        <button type="submit" class="btn btn-primary">
            Save Notice
        </button>

    </form>

</section>


<!-- NOTICE LIST -->
<section class="card">

    <h2>All Notices</h2>

    <div class="table-responsive">

        <table>

            <thead>
                <tr>
                    <th>Title</th>
                    <th>Audience</th>
                    <th>Created By</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

                <?php if ($result && $result->num_rows > 0): ?>

                <?php while ($notice = $result->fetch_assoc()): ?>

                <tr>

                    <td>
                        <strong><?= e($notice['title']) ?></strong>
                    </td>

                    <td>
                        <?= e(ucfirst($notice['audience'] === 'all'
                                    ? 'Everyone'
                                    : $notice['audience'])) ?>
                    </td>

                    <td>
                        <?= e($notice['publisher_name'] ?? 'Admin') ?>
                    </td>

                    <td>
                        <?= e($notice['created_at'] ?? '') ?>
                    </td>

                    <td>

                        <?php if ((int)$notice['is_published'] === 1): ?>

                        <span class="status-active">
                            Published
                        </span>

                        <?php else: ?>

                        <span class="status-inactive">
                            Draft
                        </span>

                        <?php endif; ?>

                    </td>

                    <td>

                        <div class="notice-actions">

                            <a class="btn btn-primary"
                                href="<?= BASE_URL ?>/admin/notices.php?edit=<?= (int)$notice['id'] ?>#edit-notice">
                                Edit
                            </a>

                            <form method="POST" action="<?= BASE_URL ?>/admin/notices.php">

                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <input type="hidden" name="action" value="toggle_publish">

                                <input type="hidden" name="notice_id" value="<?= (int)$notice['id'] ?>">

                                <button type="submit" class="btn btn-secondary">

                                    <?= (int)$notice['is_published'] === 1
                                                ? 'Unpublish'
                                                : 'Publish' ?>

                                </button>

                            </form>

                            <form method="POST" action="<?= BASE_URL ?>/admin/notices.php"
                                onsubmit="return confirm('Are you sure you want to delete this notice?');">

                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                                <input type="hidden" name="action" value="delete_notice">

                                <input type="hidden" name="notice_id" value="<?= (int)$notice['id'] ?>">

                                <button type="submit" class="btn btn-danger">
                                    Delete
                                </button>

                            </form>

                        </div>

                    </td>

                </tr>

                <?php endwhile; ?>

                <?php else: ?>

                <tr>
                    <td colspan="6">
                        No notices found. Create your first notice above.
                    </td>
                </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<div class="admin-page-footer">

    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-secondary">
        ← Back to Admin Dashboard
    </a>

</div>


<style>
.notice-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.notice-actions form {
    margin: 0;
}

.notice-actions .btn {
    white-space: nowrap;
}

.notice-publish-option {
    margin-top: 12px;
    margin-bottom: 20px;
}

.notice-publish-option label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.notice-publish-option input {
    width: auto;
}

.notice-publish-option small {
    display: block;
    margin-top: 6px;
    color: #687789;
}

.admin-page-footer {
    margin-top: 20px;
    padding-bottom: 20px;
}

@media (max-width: 700px) {
    .notice-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .notice-actions form,
    .notice-actions a,
    .notice-actions button {
        width: 100%;
    }
}
</style>