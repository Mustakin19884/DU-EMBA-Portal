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

$uploadDirectory = __DIR__ . '/../uploads/notices/';
$uploadRelativePath = 'uploads/notices/';

$maxFileSize = 5 * 1024 * 1024;

/*
|--------------------------------------------------------------------------
| PDF UPLOAD HELPER
|--------------------------------------------------------------------------
*/
function uploadNoticePdf(
    array $file,
    string $uploadDirectory,
    string $relativePath,
    int $maxFileSize
): ?string {

    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('PDF upload failed. Please try again.');
    }

    if ((int)$file['size'] <= 0 || (int)$file['size'] > $maxFileSize) {
        throw new RuntimeException('PDF file must not exceed 5 MB.');
    }

    $extension = strtolower(
        pathinfo($file['name'], PATHINFO_EXTENSION)
    );

    if ($extension !== 'pdf') {
        throw new RuntimeException('Only PDF files are allowed.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException(
            'PHP Fileinfo extension is required for PDF uploads.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, ['application/pdf', 'application/x-pdf'], true)) {
        throw new RuntimeException('The uploaded file is not a valid PDF.');
    }

    $handle = fopen($file['tmp_name'], 'rb');

    if ($handle === false) {
        throw new RuntimeException('Could not read the uploaded file.');
    }

    $signature = fread($handle, 5);
    fclose($handle);

    if ($signature !== '%PDF-') {
        throw new RuntimeException('Invalid PDF file.');
    }

    if (!is_dir($uploadDirectory)) {
        if (!mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Could not create upload directory.');
        }
    }

    if (!is_writable($uploadDirectory)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.pdf';

    $destination = $uploadDirectory . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save PDF file.');
    }

    return $relativePath . $fileName;
}


/*
|--------------------------------------------------------------------------
| DELETE PDF HELPER
|--------------------------------------------------------------------------
*/
function deleteNoticePdf(
    ?string $relativePath,
    string $uploadDirectory,
    string $relativeDirectory
): void {

    if (!$relativePath || !str_starts_with($relativePath, $relativeDirectory)) {
        return;
    }

    $fileName = basename($relativePath);

    if (
        $fileName === '' ||
        !preg_match('/^[a-f0-9]{32}\.pdf$/', $fileName)
    ) {
        return;
    }

    $filePath = $uploadDirectory . $fileName;

    if (is_file($filePath)) {
        unlink($filePath);
    }
}


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/
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

        $message = 'Invalid request. Please refresh the page.';
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

            $newAttachment = null;

            if ($title === '' || $body === '') {

                $message = 'Notice title and message are required.';
                $message_type = 'error';

            } elseif (!in_array($audience, $allowedAudiences, true)) {

                $message = 'Invalid notice audience.';
                $message_type = 'error';

            } else {

                try {

                    $newAttachment = uploadNoticePdf(
                        $_FILES['attachment'] ?? [],
                        $uploadDirectory,
                        $uploadRelativePath,
                        $maxFileSize
                    );

                    $stmt = $conn->prepare(
                        "INSERT INTO notices
                        (
                            title,
                            body,
                            attachment_path,
                            audience,
                            published_by,
                            published_at,
                            is_published
                        )
                        VALUES (
                            ?, ?, ?, ?, ?,
                            IF(? = 1, NOW(), NULL),
                            ?
                        )"
                    );

                    $stmt->bind_param(
                        'ssssiii',
                        $title,
                        $body,
                        $newAttachment,
                        $audience,
                        $adminId,
                        $publish,
                        $publish
                    );

                    $stmt->execute();
                    $stmt->close();

                    $message = $publish
                        ? 'Notice with attachment published successfully.'
                        : 'Notice with attachment saved as draft.';

                    $message_type = 'success';

                } catch (Throwable $e) {

                    if ($newAttachment !== null) {
                        deleteNoticePdf(
                            $newAttachment,
                            $uploadDirectory,
                            $uploadRelativePath
                        );
                    }

                    $message = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'Could not create notice. Please try again.';

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

            $newAttachment = null;

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

                    // Load existing attachment.
                    $stmt = $conn->prepare(
                        "SELECT attachment_path
                         FROM notices
                         WHERE id = ?
                         LIMIT 1"
                    );

                    $stmt->bind_param('i', $noticeId);
                    $stmt->execute();

                    $existingNotice = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$existingNotice) {
                        throw new RuntimeException('Notice not found.');
                    }

                    $oldAttachment = $existingNotice['attachment_path'];

                    // Upload replacement PDF if provided.
                    $newAttachment = uploadNoticePdf(
                        $_FILES['attachment'] ?? [],
                        $uploadDirectory,
                        $uploadRelativePath,
                        $maxFileSize
                    );

                    $removeAttachment = isset($_POST['remove_attachment']);

                    if ($newAttachment !== null) {
                        $attachmentToSave = $newAttachment;
                    } elseif ($removeAttachment) {
                        $attachmentToSave = null;
                    } else {
                        $attachmentToSave = $oldAttachment;
                    }

                    $stmt = $conn->prepare(
                        "UPDATE notices
                         SET title = ?,
                             body = ?,
                             attachment_path = ?,
                             audience = ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param(
                        'ssssi',
                        $title,
                        $body,
                        $attachmentToSave,
                        $audience,
                        $noticeId
                    );

                    $stmt->execute();
                    $stmt->close();

                    // Remove old PDF only after successful database update.
                    if (
                        $oldAttachment &&
                        $oldAttachment !== $attachmentToSave
                    ) {
                        deleteNoticePdf(
                            $oldAttachment,
                            $uploadDirectory,
                            $uploadRelativePath
                        );
                    }

                    $message = 'Notice updated successfully.';
                    $message_type = 'success';
                    $editId = 0;

                } catch (Throwable $e) {

                    if ($newAttachment !== null) {
                        deleteNoticePdf(
                            $newAttachment,
                            $uploadDirectory,
                            $uploadRelativePath
                        );
                    }

                    $message = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'Could not update notice. Please try again.';

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
                     SET published_at =
                            IF(is_published = 1, NULL, NOW()),
                         is_published =
                            IF(is_published = 1, 0, 1)
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
                    "SELECT attachment_path
                     FROM notices
                     WHERE id = ?
                     LIMIT 1"
                );

                $stmt->bind_param('i', $noticeId);
                $stmt->execute();

                $notice = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($notice) {

                    $stmt = $conn->prepare(
                        "DELETE FROM notices WHERE id = ?"
                    );

                    $stmt->bind_param('i', $noticeId);
                    $stmt->execute();

                    $deleted = $stmt->affected_rows > 0;
                    $stmt->close();

                    if ($deleted) {

                        deleteNoticePdf(
                            $notice['attachment_path'],
                            $uploadDirectory,
                            $uploadRelativePath
                        );

                        $message = 'Notice and its attachment deleted successfully.';
                        $message_type = 'success';
                        $editId = 0;

                    } else {

                        $message = 'Could not delete notice.';
                        $message_type = 'error';
                    }

                } else {

                    $message = 'Notice not found.';
                    $message_type = 'error';
                }

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
        "SELECT
            id,
            title,
            body,
            audience,
            attachment_path,
            is_published
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
        n.attachment_path,
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
        <p>Create, publish and manage notices with PDF attachments.</p>
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

    <form method="POST" enctype="multipart/form-data"
        action="<?= BASE_URL ?>/admin/notices.php?edit=<?= (int)$editNotice['id'] ?>">

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

        <div class="form-group attachment-group">

            <label>Current PDF Attachment</label>

            <?php if (!empty($editNotice['attachment_path'])): ?>

            <p>
                <a href="<?= BASE_URL ?>/<?= e($editNotice['attachment_path']) ?>" target="_blank" rel="noopener">
                    <span class="pdf-icon">PDF</span>
                    View Current Attachment
                </a>
            </p>

            <label class="remove-pdf">
                <input type="checkbox" name="remove_attachment" value="1">
                Remove current PDF attachment
            </label>

            <?php else: ?>

            <p class="muted-text">No PDF currently attached.</p>

            <?php endif; ?>

        </div>

        <div class="form-group">

            <label for="edit_attachment">
                Upload Replacement PDF (Optional)
            </label>

            <input type="file" id="edit_attachment" name="attachment" accept=".pdf,application/pdf">

            <small>PDF only. Maximum file size: 5 MB.</small>

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

    <form method="POST" enctype="multipart/form-data" action="<?= BASE_URL ?>/admin/notices.php">

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

        <div class="form-group">

            <label for="attachment">PDF Attachment (Optional)</label>

            <input type="file" id="attachment" name="attachment" accept=".pdf,application/pdf">

            <small>Only PDF files are allowed. Maximum file size: 5 MB.</small>

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
                    <th>Attachment</th>
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

                        <?php if (!empty($notice['attachment_path'])): ?>

                        <a href="<?= BASE_URL ?>/<?= e($notice['attachment_path']) ?>" target="_blank" rel="noopener">
                            View PDF
                        </a>

                        <?php else: ?>

                        <span class="muted-text">No PDF</span>

                        <?php endif; ?>

                    </td>

                    <td>
                        <?= e($notice['publisher_name'] ?? 'Admin') ?>
                    </td>

                    <td>
                        <?= e($notice['created_at'] ?? '') ?>
                    </td>

                    <td>

                        <?php if ((int)$notice['is_published'] === 1): ?>

                        <span class="status-active">Published</span>

                        <?php else: ?>

                        <span class="status-inactive">Draft</span>

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
                                onsubmit="return confirm('Are you sure you want to delete this notice and its PDF attachment?');">

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
                    <td colspan="7">
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

.notice-publish-option label,
.remove-pdf {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.notice-publish-option input,
.remove-pdf input {
    width: auto;
}

.notice-publish-option small,
.attachment-group small,
.form-group small {
    display: block;
    margin-top: 6px;
    color: #687789;
}

.attachment-group {
    padding: 14px;
    background: #f8f7f3;
    border: 1px solid #e1e5eb;
    border-radius: 8px;
}

.attachment-group a {
    color: #102d50;
    font-weight: 600;
}

.pdf-icon {
    display: inline-block;
    padding: 4px 7px;
    margin-right: 5px;
    background: #102d50;
    color: white;
    border-radius: 4px;
    font-size: 11px;
}

.muted-text {
    color: #687789;
    font-size: 13px;
}

.remove-pdf {
    margin-top: 12px;
    font-size: 13px;
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