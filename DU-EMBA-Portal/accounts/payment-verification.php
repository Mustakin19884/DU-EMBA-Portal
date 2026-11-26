<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('accounts');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$accountsUserId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['payment_verification_csrf'])) {
    $_SESSION['payment_verification_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['payment_verification_csrf'];

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function pv_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pv_money($amount): string
{
    return '৳ ' . number_format((float)$amount, 2);
}

function pv_datetime($date): string
{
    if (!$date) {
        return 'N/A';
    }

    return date('d M Y, h:i A', strtotime((string)$date));
}

function pv_status_class(string $status): string
{
    return match ($status) {
        'verified' => 'status-verified',
        'pending' => 'status-pending',
        'rejected' => 'status-rejected',
        default => 'status-neutral'
    };
}

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, (string)$postedToken)) {

        $error = 'Invalid security token. Please refresh and try again.';

    } else {

        $action = $_POST['action'] ?? '';
        $paymentId = (int)($_POST['payment_id'] ?? 0);

        if (!in_array($action, ['verify', 'reject'], true)) {

            $error = 'Invalid action requested.';

        } elseif ($paymentId <= 0) {

            $error = 'Invalid payment transaction.';

        } else {

            try {

                $conn->begin_transaction();

                /*
                |--------------------------------------------------------------------------
                | Lock Payment Row
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT
                        id,
                        invoice_id,
                        amount,
                        status

                    FROM payments

                    WHERE id = ?

                    FOR UPDATE
                ");

                $stmt->bind_param("i", $paymentId);
                $stmt->execute();

                $paymentResult = $stmt->get_result();
                $payment = $paymentResult->fetch_assoc();

                $stmt->close();

                if (!$payment) {

                    throw new Exception('Payment transaction not found.');

                }

                if ($payment['status'] !== 'pending') {

                    throw new Exception(
                        'This payment has already been processed.'
                    );

                }

                $invoiceId = (int)$payment['invoice_id'];
                $paymentAmount = (float)$payment['amount'];

                /*
                |--------------------------------------------------------------------------
                | Lock Invoice
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT
                        id,
                        total_amount,
                        status,
                        due_date

                    FROM student_invoices

                    WHERE id = ?

                    FOR UPDATE
                ");

                $stmt->bind_param("i", $invoiceId);
                $stmt->execute();

                $invoiceResult = $stmt->get_result();
                $invoice = $invoiceResult->fetch_assoc();

                $stmt->close();

                if (!$invoice) {

                    throw new Exception('Related invoice not found.');

                }

                $invoiceAmount = (float)$invoice['total_amount'];

                /*
                |--------------------------------------------------------------------------
                | VERIFY PAYMENT
                |--------------------------------------------------------------------------
                */

                if ($action === 'verify') {

                    /*
                     * Calculate previously verified amount.
                     */

                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(amount), 0) AS verified_total

                        FROM payments

                        WHERE invoice_id = ?
                          AND status = 'verified'
                    ");

                    $stmt->bind_param("i", $invoiceId);
                    $stmt->execute();

                    $verifiedResult = $stmt->get_result();
                    $verifiedRow = $verifiedResult->fetch_assoc();

                    $stmt->close();

                    $alreadyVerified = (float)$verifiedRow['verified_total'];

                    $newVerifiedTotal = $alreadyVerified + $paymentAmount;

                    /*
                     * Prevent verification beyond invoice amount.
                     */

                    if ($newVerifiedTotal > $invoiceAmount + 0.01) {

                        throw new Exception(
                            'Verification rejected: total verified payments '
                            . 'would exceed the invoice amount.'
                        );

                    }

                    /*
                     * Mark payment as verified.
                     */

                    $stmt = $conn->prepare("
                        UPDATE payments

                        SET
                            status = 'verified',
                            verified_by = ?,
                            paid_at = COALESCE(paid_at, NOW())

                        WHERE id = ?
                          AND status = 'pending'
                    ");

                    $stmt->bind_param(
                        "ii",
                        $accountsUserId,
                        $paymentId
                    );

                    $stmt->execute();

                    if ($stmt->affected_rows !== 1) {

                        $stmt->close();

                        throw new Exception(
                            'Payment verification failed. '
                            . 'The transaction may already have been processed.'
                        );

                    }

                    $stmt->close();

                    /*
                     * Calculate invoice status.
                     */

                  if ($verifiedTotal >= $invoiceAmount - 0.01) {

    $newInvoiceStatus = 'paid';

} elseif (
    !empty($invoice['due_date']) &&
    $invoice['due_date'] < date('Y-m-d')
) {

    $newInvoiceStatus = 'overdue';

} elseif ($verifiedTotal > 0) {

    $newInvoiceStatus = 'partial';

} else {

    $newInvoiceStatus = 'unpaid';

}

                    /*
                     * Update Invoice Status.
                     */

                    $stmt = $conn->prepare("
                        UPDATE student_invoices

                        SET status = ?

                        WHERE id = ?
                    ");

                    $stmt->bind_param(
                        "si",
                        $newInvoiceStatus,
                        $invoiceId
                    );

                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    $message = 'Payment successfully verified. '
                        . 'Invoice status updated to '
                        . ucfirst($newInvoiceStatus) . '.';

                }

                /*
                |--------------------------------------------------------------------------
                | REJECT PAYMENT
                |--------------------------------------------------------------------------
                */

                elseif ($action === 'reject') {

                    $stmt = $conn->prepare("
                        UPDATE payments

                        SET
                            status = 'rejected',
                            verified_by = ?

                        WHERE id = ?
                          AND status = 'pending'
                    ");

                    $stmt->bind_param(
                        "ii",
                        $accountsUserId,
                        $paymentId
                    );

                    $stmt->execute();

                    if ($stmt->affected_rows !== 1) {

                        $stmt->close();

                        throw new Exception(
                            'Unable to reject this payment.'
                        );

                    }

                    $stmt->close();

                    /*
                     * Recalculate invoice status based on
                     * verified payments.
                     */

                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(amount), 0) AS verified_total

                        FROM payments

                        WHERE invoice_id = ?
                          AND status = 'verified'
                    ");

                    $stmt->bind_param("i", $invoiceId);
                    $stmt->execute();

                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();

                    $stmt->close();

                    $verifiedTotal = (float)$row['verified_total'];

                    if ($verifiedTotal >= $invoiceAmount - 0.01) {

                        $newInvoiceStatus = 'paid';

                    } elseif ($verifiedTotal > 0) {

                        $newInvoiceStatus = 'partial';

                    } else {

                        $newInvoiceStatus = 'unpaid';

                    }

                    $stmt = $conn->prepare("
                        UPDATE student_invoices

                        SET status = ?

                        WHERE id = ?
                    ");

                    $stmt->bind_param(
                        "si",
                        $newInvoiceStatus,
                        $invoiceId
                    );

                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    $message = 'Payment transaction rejected successfully.';

                }

            } catch (Throwable $exception) {

                $conn->rollback();

                $error = $exception->getMessage();

            }

        }

    }

}

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$filter = $_GET['filter'] ?? 'pending';

$allowedFilters = [
    'pending',
    'verified',
    'rejected',
    'all'
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'pending';
}

/*
|--------------------------------------------------------------------------
| PAYMENT SUMMARY
|--------------------------------------------------------------------------
*/

$pendingCount = 0;
$verifiedCount = 0;
$rejectedCount = 0;

$pendingAmount = 0;
$verifiedAmount = 0;
$rejectedAmount = 0;

$result = $conn->query("
    SELECT
        status,
        COUNT(*) AS transaction_count,
        COALESCE(SUM(amount), 0) AS total_amount

    FROM payments

    GROUP BY status
");

if ($result) {

    while ($row = $result->fetch_assoc()) {

        switch ($row['status']) {

            case 'pending':

                $pendingCount = (int)$row['transaction_count'];
                $pendingAmount = (float)$row['total_amount'];

                break;

            case 'verified':

                $verifiedCount = (int)$row['transaction_count'];
                $verifiedAmount = (float)$row['total_amount'];

                break;

            case 'rejected':

                $rejectedCount = (int)$row['transaction_count'];
                $rejectedAmount = (float)$row['total_amount'];

                break;

        }

    }

}

/*
|--------------------------------------------------------------------------
| PAYMENT TRANSACTIONS
|--------------------------------------------------------------------------
*/

$payments = [];

$sql = "
    SELECT

        p.id AS payment_id,
        p.invoice_id,
        p.amount,
        p.payment_method,
        p.transaction_reference,
        p.status AS payment_status,
        p.paid_at,
        p.created_at,

        si.invoice_number,
        si.total_amount AS invoice_amount,
        si.due_date,
        si.status AS invoice_status,

        s.student_id AS academic_student_id,

        u.full_name,
        u.username,
        u.email,

        sem.semester_name,

        verifier.full_name AS verified_by_name

    FROM payments p

    INNER JOIN student_invoices si
        ON si.id = p.invoice_id

    INNER JOIN students s
        ON s.id = si.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    LEFT JOIN semesters sem
        ON sem.id = si.semester_id

    LEFT JOIN users verifier
        ON verifier.id = p.verified_by
";

if ($filter !== 'all') {

    $sql .= " WHERE p.status = '" . $conn->real_escape_string($filter) . "' ";

}

$sql .= "
    ORDER BY
        CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END,
        p.created_at DESC,
        p.id DESC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $payments[] = $row;

    }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payment Verification | DU EMBA MIS Portal</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    body {
        background: #f4f6f9;
        color: #263548;
    }

    .verification-page {
        width: 94%;
        max-width: 1450px;
        margin: 30px auto;
    }

    .page-header {
        background: #102d50;
        color: #ffffff;
        padding: 27px 32px;
        border-radius: 14px;
        margin-bottom: 25px;
    }

    .page-header h1 {
        margin: 0 0 8px;
        font-size: 27px;
    }

    .page-header p {
        margin: 0;
        color: #dbe6f1;
    }

    .back-link {
        display: inline-block;
        color: white;
        margin-top: 18px;
        text-decoration: none;
        font-size: 14px;
    }

    .back-link:hover {
        text-decoration: underline;
    }

    /* SUMMARY */

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 28px;
    }

    .summary-card {
        background: white;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 23px;
    }

    .summary-label {
        color: #687789;
        font-size: 13px;
        margin-bottom: 10px;
    }

    .summary-value {
        color: #102d50;
        font-size: 25px;
        font-weight: 700;
    }

    .summary-card.pending .summary-value {
        color: #d97706;
    }

    .summary-card.verified .summary-value {
        color: #198754;
    }

    .summary-card.rejected .summary-value {
        color: #dc3545;
    }

    .summary-count {
        margin-top: 8px;
        color: #8995a3;
        font-size: 12px;
    }

    /* NOTIFICATIONS */

    .notice {
        padding: 15px 18px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .notice-success {
        background: #d1e7dd;
        color: #146c43;
        border: 1px solid #badbcc;
    }

    .notice-error {
        background: #f8d7da;
        color: #b02a37;
        border: 1px solid #f5c2c7;
    }

    /* FILTERS */

    .filter-section {
        background: white;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 18px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .filter-btn {
        display: inline-block;
        text-decoration: none;
        padding: 10px 17px;
        border-radius: 7px;
        font-size: 13px;
        font-weight: 600;
        border: 1px solid #dce2e9;
        color: #263548;
        background: #ffffff;
    }

    .filter-btn.active {
        background: #102d50;
        color: white;
        border-color: #102d50;
    }

    /* TABLE */

    .table-card {
        background: white;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        overflow: hidden;
    }

    .table-heading {
        padding: 22px;
        border-bottom: 1px solid #e1e5eb;
    }

    .table-heading h2 {
        margin: 0 0 6px;
        color: #102d50;
        font-size: 21px;
    }

    .table-heading p {
        margin: 0;
        color: #687789;
        font-size: 13px;
    }

    .table-wrapper {
        overflow-x: auto;
    }

    .payment-table {
        width: 100%;
        min-width: 1250px;
        border-collapse: collapse;
    }

    .payment-table th {
        background: #102d50;
        color: white;
        text-align: left;
        padding: 14px;
        font-size: 13px;
        white-space: nowrap;
    }

    .payment-table td {
        padding: 14px;
        border-bottom: 1px solid #e6eaf0;
        vertical-align: top;
        font-size: 13px;
    }

    .payment-table tr:last-child td {
        border-bottom: none;
    }

    .payment-table tr:hover {
        background: #f8fafc;
    }

    .status {
        display: inline-block;
        padding: 6px 11px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: capitalize;
        white-space: nowrap;
    }

    .status-pending {
        background: #fff3cd;
        color: #997404;
    }

    .status-verified {
        background: #d1e7dd;
        color: #146c43;
    }

    .status-rejected {
        background: #f8d7da;
        color: #b02a37;
    }

    .status-neutral {
        background: #e9ecef;
        color: #495057;
    }

    .student-name {
        font-weight: 700;
        color: #102d50;
    }

    .small-text {
        color: #687789;
        font-size: 12px;
        margin-top: 5px;
    }

    .amount {
        font-weight: 700;
        color: #102d50;
        white-space: nowrap;
    }

    /* ACTIONS */

    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .action-form {
        display: inline-block;
    }

    .action-btn {
        border: none;
        padding: 9px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }

    .verify-btn {
        background: #198754;
        color: white;
    }

    .reject-btn {
        background: #dc3545;
        color: white;
    }

    .action-btn:hover {
        opacity: .88;
    }

    .processed-text {
        color: #687789;
        font-size: 12px;
    }

    .empty-state {
        padding: 50px 20px;
        text-align: center;
        color: #687789;
    }

    .empty-state h3 {
        color: #102d50;
        margin-bottom: 10px;
    }

    .page-footer {
        margin-top: 30px;
        padding: 20px;
        border-top: 1px solid #e1e5eb;
        text-align: center;
        color: #687789;
        font-size: 13px;
    }

    @media (max-width: 900px) {

        .summary-grid {
            grid-template-columns: 1fr;
        }

    }

    @media (max-width: 600px) {

        .verification-page {
            width: 95%;
            margin: 20px auto;
        }

        .page-header {
            padding: 22px;
        }

        .page-header h1 {
            font-size: 22px;
        }

        .filter-section {
            gap: 7px;
        }

        .filter-btn {
            padding: 9px 12px;
        }

    }
    </style>

</head>

<body>

    <main class="verification-page">

        <!-- HEADER -->

        <header class="page-header">

            <h1>Payment Verification</h1>

            <p>
                Review student transactions and verify or reject payments.
            </p>

            <a class="back-link" href="<?= BASE_URL ?>/accounts/dashboard.php">

                ← Back to Accounts Dashboard

            </a>

        </header>


        <!-- ALERTS -->

        <?php if ($message !== ''): ?>

        <div class="notice notice-success">

            <?= pv_e($message) ?>

        </div>

        <?php endif; ?>


        <?php if ($error !== ''): ?>

        <div class="notice notice-error">

            <?= pv_e($error) ?>

        </div>

        <?php endif; ?>


        <!-- SUMMARY -->

        <section class="summary-grid">

            <article class="summary-card pending">

                <div class="summary-label">
                    Pending Payments
                </div>

                <div class="summary-value">
                    <?= pv_money($pendingAmount) ?>
                </div>

                <div class="summary-count">

                    <?= number_format($pendingCount) ?>
                    transactions awaiting review

                </div>

            </article>


            <article class="summary-card verified">

                <div class="summary-label">
                    Verified Payments
                </div>

                <div class="summary-value">
                    <?= pv_money($verifiedAmount) ?>
                </div>

                <div class="summary-count">

                    <?= number_format($verifiedCount) ?>
                    verified transactions

                </div>

            </article>


            <article class="summary-card rejected">

                <div class="summary-label">
                    Rejected Payments
                </div>

                <div class="summary-value">
                    <?= pv_money($rejectedAmount) ?>
                </div>

                <div class="summary-count">

                    <?= number_format($rejectedCount) ?>
                    rejected transactions

                </div>

            </article>

        </section>


        <!-- FILTERS -->

        <section class="filter-section">

            <a href="?filter=pending" class="filter-btn <?= $filter === 'pending' ? 'active' : '' ?>">

                Pending

                (<?= number_format($pendingCount) ?>)

            </a>


            <a href="?filter=verified" class="filter-btn <?= $filter === 'verified' ? 'active' : '' ?>">

                Verified

            </a>


            <a href="?filter=rejected" class="filter-btn <?= $filter === 'rejected' ? 'active' : '' ?>">

                Rejected

            </a>


            <a href="?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">

                All Transactions

            </a>

        </section>


        <!-- PAYMENT TABLE -->

        <section class="table-card">

            <div class="table-heading">

                <h2>

                    <?= ucfirst($filter) ?> Transactions

                </h2>

                <p>
                    Review transaction details before processing.
                </p>

            </div>


            <div class="table-wrapper">

                <table class="payment-table">

                    <thead>

                        <tr>

                            <th>Student Information</th>

                            <th>Invoice</th>

                            <th>Invoice Amount</th>

                            <th>Payment Amount</th>

                            <th>Method</th>

                            <th>Transaction Reference</th>

                            <th>Submitted Date</th>

                            <th>Status</th>

                            <th>Action</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (!empty($payments)): ?>

                        <?php foreach ($payments as $payment): ?>

                        <tr>

                            <!-- STUDENT -->

                            <td>

                                <div class="student-name">

                                    <?= pv_e($payment['full_name']) ?>

                                </div>

                                <div class="small-text">

                                    ID:
                                    <?= pv_e(
                                        $payment['academic_student_id'] ?? 'N/A'
                                    ) ?>

                                </div>

                                <div class="small-text">

                                    Username:
                                    <?= pv_e($payment['username']) ?>

                                </div>

                                <div class="small-text">

                                    <?= pv_e($payment['email'] ?? '') ?>

                                </div>

                            </td>


                            <!-- INVOICE -->

                            <td>

                                <strong>

                                    <?= pv_e($payment['invoice_number']) ?>

                                </strong>

                                <div class="small-text">

                                    <?= pv_e(
                                        $payment['semester_name'] ?? 'N/A'
                                    ) ?>

                                </div>

                                <div class="small-text">

                                    Invoice Status:

                                    <?= pv_e($payment['invoice_status']) ?>

                                </div>

                            </td>


                            <!-- INVOICE AMOUNT -->

                            <td>

                                <?= pv_money($payment['invoice_amount']) ?>

                            </td>


                            <!-- PAYMENT AMOUNT -->

                            <td>

                                <span class="amount">

                                    <?= pv_money($payment['amount']) ?>

                                </span>

                            </td>


                            <!-- PAYMENT METHOD -->

                            <td>

                                <?= pv_e($payment['payment_method']) ?>

                            </td>


                            <!-- REFERENCE -->

                            <td>

                                <?= pv_e(
                                    $payment['transaction_reference'] ?: 'N/A'
                                ) ?>

                            </td>


                            <!-- DATE -->

                            <td>

                                <?= pv_datetime($payment['created_at']) ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span class="status
                                    <?= pv_status_class($payment['payment_status']) ?>">

                                    <?= pv_e($payment['payment_status']) ?>

                                </span>

                                <?php if (
                                    $payment['payment_status'] !== 'pending'
                                    && !empty($payment['verified_by_name'])
                                ): ?>

                                <div class="small-text">

                                    Processed by:

                                    <?= pv_e($payment['verified_by_name']) ?>

                                </div>

                                <?php endif; ?>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <?php if ($payment['payment_status'] === 'pending'): ?>

                                <div class="action-buttons">


                                    <!-- VERIFY -->

                                    <form method="POST" class="action-form" onsubmit="return confirm(
                                                'Are you sure you want to VERIFY this payment? This will update the student invoice balance.'
                                              );">

                                        <input type="hidden" name="csrf_token" value="<?= pv_e($csrfToken) ?>">

                                        <input type="hidden" name="action" value="verify">

                                        <input type="hidden" name="payment_id"
                                            value="<?= (int)$payment['payment_id'] ?>">

                                        <button type="submit" class="action-btn verify-btn">

                                            Verify

                                        </button>

                                    </form>


                                    <!-- REJECT -->

                                    <form method="POST" class="action-form" onsubmit="return confirm(
                                                'Are you sure you want to REJECT this payment transaction?'
                                              );">

                                        <input type="hidden" name="csrf_token" value="<?= pv_e($csrfToken) ?>">

                                        <input type="hidden" name="action" value="reject">

                                        <input type="hidden" name="payment_id"
                                            value="<?= (int)$payment['payment_id'] ?>">

                                        <button type="submit" class="action-btn reject-btn">

                                            Reject

                                        </button>

                                    </form>


                                </div>

                                <?php else: ?>

                                <span class="processed-text">

                                    Already processed

                                </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                        <?php endforeach; ?>


                        <?php else: ?>

                        <tr>

                            <td colspan="9">

                                <div class="empty-state">

                                    <h3>No Transactions Found</h3>

                                    <p>
                                        There are no transactions
                                        matching the selected filter.
                                    </p>

                                </div>

                            </td>

                        </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- FOOTER -->

        <footer class="page-footer">

            DU EMBA MIS Student Portal

            <br>

            Accounts Office — Payment Verification

        </footer>

    </main>

</body>

</html>