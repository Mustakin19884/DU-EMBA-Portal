<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Notices & Notifications';

$userId = (int)($_SESSION['user_id'] ?? 0);
$studentName = $_SESSION['full_name'] ?? 'Student';

if ($userId <= 0) {
    die('Invalid user session. Please log in again.');
}

/* =====================================================
   CSRF TOKEN
===================================================== */

if (empty($_SESSION['student_notice_csrf'])) {
    $_SESSION['student_notice_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['student_notice_csrf'];

$successMessage = '';
$errorMessage = '';

/* =====================================================
   HELPER FUNCTIONS
===================================================== */

function student_notice_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* =====================================================
   HANDLE NOTIFICATION ACTIONS
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {

        $errorMessage = 'Invalid request. Please refresh the page and try again.';

    } else {

        try {

            if ($action === 'mark_read') {

                $notificationId = (int)($_POST['notification_id'] ?? 0);

                if ($notificationId <= 0) {
                    throw new Exception('Invalid notification.');
                }

                $stmt = $conn->prepare(
                    "UPDATE notifications
                     SET read_at = NOW()
                     WHERE id = ?
                       AND user_id = ?
                       AND read_at IS NULL"
                );

                if (!$stmt) {
                    throw new Exception('Unable to update notification.');
                }

                $stmt->bind_param("ii", $notificationId, $userId);

                if (!$stmt->execute()) {
                    throw new Exception('Unable to mark notification as read.');
                }

                $stmt->close();

                $successMessage = 'Notification marked as read.';

            } elseif ($action === 'mark_all_read') {

                $stmt = $conn->prepare(
                    "UPDATE notifications
                     SET read_at = NOW()
                     WHERE user_id = ?
                       AND read_at IS NULL"
                );

                if (!$stmt) {
                    throw new Exception('Unable to update notifications.');
                }

                $stmt->bind_param("i", $userId);

                if (!$stmt->execute()) {
                    throw new Exception('Unable to mark notifications as read.');
                }

                $stmt->close();

                $successMessage = 'All notifications marked as read.';

            } else {

                throw new Exception('Invalid action.');
            }

        } catch (Throwable $e) {

            $errorMessage = $e->getMessage();

            error_log('Student Notice Error: ' . $e->getMessage());
        }
    }
}

/* =====================================================
   GET PUBLISHED NOTICES
===================================================== */

$notices = [];

$stmt = $conn->prepare(
    "SELECT
        id,
        title,
        body,
        audience,
        published_at,
        created_at
     FROM notices
     WHERE is_published = 1
       AND audience IN ('all', 'student')
     ORDER BY
        COALESCE(published_at, created_at) DESC,
        id DESC"
);

if ($stmt) {

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $notices[] = $row;
        }
    }

    $stmt->close();

} else {

    error_log('Student Notices Query Error: ' . $conn->error);
}

/* =====================================================
   GET STUDENT NOTIFICATIONS
===================================================== */

$notifications = [];
$unreadCount = 0;

$stmt = $conn->prepare(
    "SELECT
        id,
        title,
        message,
        read_at,
        created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT 100"
);

if ($stmt) {

    $stmt->bind_param("i", $userId);

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            if (empty($row['read_at'])) {
                $unreadCount++;
            }

            $notifications[] = $row;
        }
    }

    $stmt->close();

} else {

    error_log('Student Notifications Query Error: ' . $conn->error);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= student_notice_escape($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    :root {
        --navy: #102d50;
        --navy-light: #1c456e;
        --beige: #f4efe5;
        --white: #ffffff;
        --text: #263548;
        --muted: #687789;
        --border: #e1e5eb;
        --green: #198754;
        --red: #dc3545;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background: #f5f7fa;
        color: var(--text);
        font-family: Arial, Helvetica, sans-serif;
    }

    .notice-container {
        width: calc(100% - 40px);
        max-width: 1200px;
        margin: 30px auto;
    }

    .topbar {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        margin-bottom: 22px;
    }

    .topbar h2 {
        color: var(--navy);
        font-size: 20px;
        margin: 0;
    }

    .topbar p {
        color: var(--muted);
        font-size: 13px;
        margin: 5px 0 0;
    }

    .topbar-links {
        display: flex;
        gap: 10px;
    }

    .topbar-links a {
        display: inline-block;
        padding: 9px 14px;
        border-radius: 7px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
    }

    .dashboard-link {
        background: #edf4fb;
        color: var(--navy);
    }

    .logout-link {
        background: var(--navy);
        color: var(--white);
    }

    .page-banner {
        background: linear-gradient(115deg, var(--navy), var(--navy-light));
        color: var(--white);
        border-radius: 12px;
        padding: 25px 28px;
        margin-bottom: 22px;
    }

    .page-banner h1 {
        font-size: 24px;
        margin: 0 0 8px;
    }

    .page-banner p {
        font-size: 13px;
        color: #e0e8f1;
        margin: 0;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        margin-bottom: 22px;
    }

    .stat-card {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 18px;
    }

    .stat-card span {
        display: block;
        color: var(--muted);
        font-size: 12px;
        margin-bottom: 8px;
    }

    .stat-card strong {
        color: var(--navy);
        font-size: 25px;
    }

    .content-card {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 22px;
        margin-bottom: 22px;
    }

    .section-heading {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 18px;
    }

    .section-heading h2 {
        color: var(--navy);
        font-size: 17px;
        margin: 0;
    }

    .badge {
        display: inline-block;
        padding: 6px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }

    .badge.published {
        background: #e8f5ed;
        color: var(--green);
    }

    .badge.unread {
        background: #fdebec;
        color: var(--red);
    }

    .badge.read {
        background: #edf1f5;
        color: var(--muted);
    }

    .notice-item {
        border: 1px solid var(--border);
        border-left: 4px solid var(--navy);
        border-radius: 8px;
        padding: 17px;
        margin-bottom: 13px;
    }

    .notice-item:last-child {
        margin-bottom: 0;
    }

    .notice-item h3 {
        color: var(--navy);
        font-size: 15px;
        margin: 0 0 9px;
    }

    .notice-body {
        color: var(--text);
        font-size: 13px;
        line-height: 1.7;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .notice-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
        margin-top: 13px;
        color: var(--muted);
        font-size: 11px;
    }

    .notification-item {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 12px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 15px;
    }

    .notification-item:last-child {
        margin-bottom: 0;
    }

    .notification-item.unread-item {
        background: #f5f8fc;
        border-left: 4px solid var(--navy);
    }

    .notification-content {
        flex: 1;
        min-width: 0;
    }

    .notification-content h3 {
        color: var(--navy);
        font-size: 14px;
        margin: 0 0 7px;
    }

    .notification-content p {
        color: var(--text);
        font-size: 12px;
        line-height: 1.6;
        margin: 0;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .notification-date {
        display: block;
        color: var(--muted);
        font-size: 11px;
        margin-top: 9px;
    }

    .mark-read-form {
        margin: 0;
    }

    .mark-read-button,
    .mark-all-button {
        border: none;
        background: var(--navy);
        color: var(--white);
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
    }

    .mark-read-button:hover,
    .mark-all-button:hover {
        background: var(--navy-light);
    }

    .message {
        padding: 13px 16px;
        border-radius: 8px;
        font-size: 13px;
        margin-bottom: 18px;
    }

    .message.success {
        background: #e8f5ed;
        border: 1px solid #c6e7d1;
        color: #146c43;
    }

    .message.error {
        background: #fdebec;
        border: 1px solid #f5c2c7;
        color: #842029;
    }

    .empty-state {
        text-align: center;
        background: #f8fafc;
        border: 1px dashed #d8e0e8;
        padding: 28px 15px;
        border-radius: 8px;
        color: var(--muted);
        font-size: 13px;
    }

    .empty-state strong {
        display: block;
        color: var(--navy);
        margin-bottom: 7px;
        font-size: 14px;
    }

    .footer {
        text-align: center;
        padding: 18px 10px;
        color: var(--muted);
        font-size: 12px;
    }

    @media (max-width: 650px) {

        .notice-container {
            width: calc(100% - 22px);
            margin: 12px auto;
        }

        .topbar {
            flex-direction: column;
            align-items: flex-start;
            padding: 15px;
        }

        .topbar-links {
            width: 100%;
        }

        .topbar-links a {
            flex: 1;
            text-align: center;
        }

        .page-banner {
            padding: 22px 18px;
        }

        .page-banner h1 {
            font-size: 21px;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .content-card {
            padding: 15px;
        }

        .notification-item {
            flex-direction: column;
        }

        .section-heading {
            align-items: flex-start;
            flex-direction: column;
        }
    }
    </style>

</head>

<body>

    <div class="notice-container">

        <header class="topbar">

            <div>
                <h2>DU EMBA MIS Student Portal</h2>
                <p>Notices & Notifications</p>
            </div>

            <div class="topbar-links">

                <a class="dashboard-link" href="<?= BASE_URL ?>/student/dashboard.php">
                    Dashboard
                </a>

                <a class="logout-link" href="<?= BASE_URL ?>/auth/logout.php">
                    Logout
                </a>

            </div>

        </header>

        <section class="page-banner">

            <h1>Notices & Notifications</h1>

            <p>
                Stay updated with academic announcements and personal notifications.
            </p>

        </section>

        <?php if ($successMessage): ?>
        <div class="message success">
            <?= student_notice_escape($successMessage) ?>
        </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
        <div class="message error">
            <?= student_notice_escape($errorMessage) ?>
        </div>
        <?php endif; ?>

        <section class="stats-grid">

            <div class="stat-card">
                <span>Published Notices</span>
                <strong><?= count($notices) ?></strong>
            </div>

            <div class="stat-card">
                <span>Total Notifications</span>
                <strong><?= count($notifications) ?></strong>
            </div>

            <div class="stat-card">
                <span>Unread Notifications</span>
                <strong><?= $unreadCount ?></strong>
            </div>

        </section>

        <!-- PUBLISHED NOTICES -->

        <section class="content-card">

            <div class="section-heading">
                <h2>Official Notices</h2>
                <span class="badge published">Published</span>
            </div>

            <?php if (empty($notices)): ?>

            <div class="empty-state">
                <strong>No Published Notices</strong>
                There are currently no notices available for students.
            </div>

            <?php else: ?>

            <?php foreach ($notices as $notice): ?>

            <article class="notice-item">

                <h3>
                    <?= student_notice_escape($notice['title']) ?>
                </h3>

                <div class="notice-body"><?= student_notice_escape($notice['body']) ?></div>

                <div class="notice-meta">

                    <span class="badge published">Official Notice</span>

                    <span>
                        Published:
                        <?= student_notice_escape(
                                !empty($notice['published_at'])
                                    ? date('d M Y, h:i A', strtotime($notice['published_at']))
                                    : date('d M Y, h:i A', strtotime($notice['created_at']))
                            ) ?>
                    </span>

                </div>

            </article>

            <?php endforeach; ?>

            <?php endif; ?>

        </section>

        <!-- PERSONAL NOTIFICATIONS -->

        <section class="content-card">

            <div class="section-heading">

                <h2>My Notifications</h2>

                <?php if ($unreadCount > 0): ?>

                <form method="POST" style="margin:0;">

                    <input type="hidden" name="csrf_token" value="<?= student_notice_escape($csrfToken) ?>">

                    <input type="hidden" name="action" value="mark_all_read">

                    <button type="submit" class="mark-all-button">
                        Mark All as Read
                    </button>

                </form>

                <?php endif; ?>

            </div>

            <?php if (empty($notifications)): ?>

            <div class="empty-state">
                <strong>No Notifications Yet</strong>
                Your personal notifications will appear here.
            </div>

            <?php else: ?>

            <?php foreach ($notifications as $notification): ?>

            <?php $isUnread = empty($notification['read_at']); ?>

            <article class="notification-item <?= $isUnread ? 'unread-item' : '' ?>">

                <div class="notification-content">

                    <h3>
                        <?= student_notice_escape($notification['title']) ?>
                    </h3>

                    <p><?= student_notice_escape($notification['message']) ?></p>

                    <span class="notification-date">
                        <?= student_notice_escape(
                                !empty($notification['created_at'])
                                    ? date('d M Y, h:i A', strtotime($notification['created_at']))
                                    : 'Date unavailable'
                            ) ?>
                    </span>

                </div>

                <div>

                    <?php if ($isUnread): ?>

                    <form method="POST" class="mark-read-form">

                        <input type="hidden" name="csrf_token" value="<?= student_notice_escape($csrfToken) ?>">

                        <input type="hidden" name="action" value="mark_read">

                        <input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>">

                        <button type="submit" class="mark-read-button">
                            Mark as Read
                        </button>

                    </form>

                    <?php else: ?>

                    <span class="badge read">Read</span>

                    <?php endif; ?>

                </div>

            </article>

            <?php endforeach; ?>

            <?php endif; ?>

        </section>

        <footer class="footer">
            DU EMBA MIS Student Portal
            <br>
            Department of Management Information Systems, University of Dhaka
        </footer>

    </div>

</body>

</html>