<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('student');

$userId = (int)($_SESSION['user_id'] ?? 0);
$studentName = $_SESSION['full_name'] ?? 'Student';

$studentId = 0;
$invoices = [];
$paymentsByInvoice = [];

$totalInvoiced = 0;
$totalPaid = 0;
$totalOutstanding = 0;

$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function pay_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pay_money($amount): string
{
    return '৳ ' . number_format((float)$amount, 2);
}

function pay_date($date): string
{
    if (empty($date)) {
        return 'N/A';
    }

    return date('d M Y', strtotime((string)$date));
}

function pay_datetime($date): string
{
    if (empty($date)) {
        return 'N/A';
    }

    return date('d M Y, h:i A', strtotime((string)$date));
}

function pay_status_class(string $status): string
{
    return match (strtolower($status)) {
        'verified', 'paid' => 'status-success',
        'pending', 'partial' => 'status-warning',
        'rejected', 'overdue' => 'status-danger',
        default => 'status-neutral'
    };
}

/*
|--------------------------------------------------------------------------
| Find Student Profile
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT id
    FROM students
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$studentResult = $stmt->get_result();
$student = $studentResult->fetch_assoc();

$stmt->close();

if ($student) {

    $studentId = (int)$student['id'];

    /*
    |--------------------------------------------------------------------------
    | Fetch Student Invoices
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            si.id,
            si.invoice_number,
            si.total_amount,
            si.due_date,
            si.status,
            si.created_at,

            sem.semester_name,
            ac.session_name,

            COALESCE(
                (
                    SELECT SUM(p.amount)
                    FROM payments p
                    WHERE p.invoice_id = si.id
                      AND p.status = 'verified'
                ),
                0
            ) AS verified_paid,

            COALESCE(
                (
                    SELECT SUM(p.amount)
                    FROM payments p
                    WHERE p.invoice_id = si.id
                      AND p.status = 'pending'
                ),
                0
            ) AS pending_amount,

            (
                SELECT COUNT(*)
                FROM payments p
                WHERE p.invoice_id = si.id
            ) AS payment_count

        FROM student_invoices si

        LEFT JOIN semesters sem
            ON sem.id = si.semester_id

        LEFT JOIN academic_sessions ac
            ON ac.id = sem.session_id

        WHERE si.student_id = ?

        ORDER BY si.created_at DESC, si.id DESC
    ");

    $stmt->bind_param("i", $studentId);
    $stmt->execute();

    $invoiceResult = $stmt->get_result();

    while ($row = $invoiceResult->fetch_assoc()) {

        $invoiceAmount = (float)$row['total_amount'];
        $verifiedPaid = (float)$row['verified_paid'];

        $outstanding = max(0, $invoiceAmount - $verifiedPaid);

        $row['outstanding'] = $outstanding;

        /*
         * Display overdue if invoice is not fully paid
         * and the due date has already passed.
         */
        $displayStatus = $row['status'];

        if (
            $outstanding > 0 &&
            !empty($row['due_date']) &&
            strtotime($row['due_date']) < strtotime(date('Y-m-d')) &&
            $row['status'] !== 'paid'
        ) {
            $displayStatus = 'overdue';
        }

        $row['display_status'] = $displayStatus;

        $invoices[] = $row;

        $totalInvoiced += $invoiceAmount;
        $totalPaid += $verifiedPaid;
        $totalOutstanding += $outstanding;
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Fetch All Payment Transactions
    |--------------------------------------------------------------------------
    */

    if (!empty($invoices)) {

        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.invoice_id,
                p.amount,
                p.payment_method,
                p.transaction_reference,
                p.status,
                p.paid_at,
                p.created_at

            FROM payments p

            INNER JOIN student_invoices si
                ON si.id = p.invoice_id

            WHERE si.student_id = ?

            ORDER BY p.created_at DESC, p.id DESC
        ");

        $stmt->bind_param("i", $studentId);
        $stmt->execute();

        $paymentResult = $stmt->get_result();

        while ($payment = $paymentResult->fetch_assoc()) {

            $invoiceId = (int)$payment['invoice_id'];

            if (!isset($paymentsByInvoice[$invoiceId])) {
                $paymentsByInvoice[$invoiceId] = [];
            }

            $paymentsByInvoice[$invoiceId][] = $payment;
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Overall Summary
|--------------------------------------------------------------------------
*/

$totalPendingPayments = 0;

foreach ($paymentsByInvoice as $invoicePayments) {
    foreach ($invoicePayments as $payment) {
        if ($payment['status'] === 'pending') {
            $totalPendingPayments += (float)$payment['amount'];
        }
    }
}

$totalInvoices = count($invoices);

$totalVerifiedTransactions = 0;

foreach ($paymentsByInvoice as $invoicePayments) {
    foreach ($invoicePayments as $payment) {
        if ($payment['status'] === 'verified') {
            $totalVerifiedTransactions++;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Payments | DU EMBA MIS Portal</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    body {
        background: #f4f6f9;
        color: #263548;
    }

    .payment-page {
        width: 92%;
        max-width: 1250px;
        margin: 35px auto;
    }

    .page-header {
        background: #102d50;
        color: #ffffff;
        padding: 28px 32px;
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
        font-size: 14px;
    }

    .back-link {
        display: inline-block;
        margin-top: 18px;
        color: #ffffff;
        text-decoration: none;
        font-size: 14px;
    }

    .back-link:hover {
        text-decoration: underline;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 18px;
        margin-bottom: 28px;
    }

    .summary-card {
        background: #ffffff;
        padding: 23px;
        border-radius: 12px;
        border: 1px solid #e1e5eb;
        box-shadow: 0 3px 10px rgba(16, 45, 80, 0.04);
    }

    .summary-card .label {
        color: #687789;
        font-size: 13px;
        margin-bottom: 12px;
    }

    .summary-card .amount {
        color: #102d50;
        font-size: 24px;
        font-weight: 700;
        word-break: break-word;
    }

    .summary-card.paid .amount {
        color: #198754;
    }

    .summary-card.due .amount {
        color: #dc3545;
    }

    .section-title {
        color: #102d50;
        margin: 30px 0 18px;
        font-size: 22px;
    }

    .invoice-card {
        background: #ffffff;
        border: 1px solid #e1e5eb;
        border-radius: 14px;
        margin-bottom: 24px;
        overflow: hidden;
    }

    .invoice-header {
        padding: 23px 25px;
        background: #f8fafc;
        border-bottom: 1px solid #e1e5eb;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        flex-wrap: wrap;
    }

    .invoice-number {
        font-size: 18px;
        font-weight: 700;
        color: #102d50;
    }

    .invoice-semester {
        margin-top: 6px;
        font-size: 14px;
        color: #687789;
    }

    .invoice-body {
        padding: 25px;
    }

    .invoice-info-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 18px;
        margin-bottom: 25px;
    }

    .info-box {
        padding: 15px;
        background: #f8fafc;
        border: 1px solid #edf0f4;
        border-radius: 9px;
    }

    .info-box .info-label {
        font-size: 12px;
        color: #687789;
        margin-bottom: 8px;
    }

    .info-box .info-value {
        font-size: 17px;
        font-weight: 700;
        color: #102d50;
    }

    .info-box.remaining .info-value {
        color: #dc3545;
    }

    .status {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        text-transform: capitalize;
    }

    .status-success {
        color: #146c43;
        background: #d1e7dd;
    }

    .status-warning {
        color: #997404;
        background: #fff3cd;
    }

    .status-danger {
        color: #b02a37;
        background: #f8d7da;
    }

    .status-neutral {
        color: #495057;
        background: #e9ecef;
    }

    .payment-section-title {
        font-size: 17px;
        color: #102d50;
        font-weight: 700;
        margin: 25px 0 15px;
    }

    .table-wrapper {
        overflow-x: auto;
        width: 100%;
    }

    .payment-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 750px;
    }

    .payment-table th {
        background: #102d50;
        color: #ffffff;
        padding: 13px 14px;
        text-align: left;
        font-size: 13px;
        white-space: nowrap;
    }

    .payment-table td {
        padding: 13px 14px;
        border-bottom: 1px solid #e6eaf0;
        font-size: 13px;
        vertical-align: middle;
    }

    .payment-table tr:last-child td {
        border-bottom: none;
    }

    .no-payments {
        background: #f8fafc;
        padding: 18px;
        text-align: center;
        color: #687789;
        border-radius: 8px;
        font-size: 14px;
    }

    .empty-state {
        background: #ffffff;
        padding: 50px 25px;
        border: 1px solid #e1e5eb;
        border-radius: 14px;
        text-align: center;
    }

    .empty-state h3 {
        color: #102d50;
        margin-bottom: 12px;
    }

    .empty-state p {
        color: #687789;
        margin: 0;
    }

    .payment-note {
        background: #eef4fb;
        border-left: 4px solid #102d50;
        padding: 15px 18px;
        border-radius: 6px;
        margin-top: 20px;
        color: #42566d;
        font-size: 13px;
        line-height: 1.7;
    }

    @media (max-width: 1000px) {

        .summary-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .invoice-info-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 600px) {

        .payment-page {
            width: 95%;
            margin: 20px auto;
        }

        .page-header {
            padding: 22px;
        }

        .page-header h1 {
            font-size: 22px;
        }

        .summary-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .summary-card {
            padding: 18px;
        }

        .summary-card .amount {
            font-size: 21px;
        }

        .invoice-header {
            padding: 18px;
        }

        .invoice-body {
            padding: 18px;
        }

        .invoice-info-grid {
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .info-box {
            padding: 12px;
        }

        .info-box .info-value {
            font-size: 15px;
        }
    }
    </style>

</head>

<body>

    <div class="payment-page">

        <!-- PAGE HEADER -->

        <div class="page-header">

            <h1>My Payments & Invoices</h1>

            <p>
                Welcome, <?= pay_e($studentName) ?>.
                View your tuition invoices and payment history.
            </p>

            <a class="back-link" href="<?= BASE_URL ?>/student/dashboard.php">
                ← Back to Dashboard
            </a>

        </div>


        <!-- SUMMARY -->

        <div class="summary-grid">

            <div class="summary-card">

                <div class="label">
                    Total Invoiced
                </div>

                <div class="amount">
                    <?= pay_money($totalInvoiced) ?>
                </div>

            </div>


            <div class="summary-card paid">

                <div class="label">
                    Total Verified Paid
                </div>

                <div class="amount">
                    <?= pay_money($totalPaid) ?>
                </div>

            </div>


            <div class="summary-card due">

                <div class="label">
                    Total Outstanding
                </div>

                <div class="amount">
                    <?= pay_money($totalOutstanding) ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="label">
                    Pending Verification
                </div>

                <div class="amount">
                    <?= pay_money($totalPendingPayments) ?>
                </div>

            </div>

        </div>


        <!-- INVOICES -->

        <h2 class="section-title">
            Invoice History
        </h2>


        <?php if (!$student): ?>

        <div class="empty-state">

            <h3>Student Profile Not Found</h3>

            <p>
                Your student profile has not been linked
                with your account yet.
                Please contact the Academic Office.
            </p>

        </div>


        <?php elseif (empty($invoices)): ?>

        <div class="empty-state">

            <h3>No Invoice Available</h3>

            <p>
                No invoices have been generated for your account yet.
                Please check again later or contact the Accounts Office.
            </p>

        </div>


        <?php else: ?>

        <?php foreach ($invoices as $invoice): ?>

        <?php

            $invoiceId = (int)$invoice['id'];

            $invoicePayments = $paymentsByInvoice[$invoiceId] ?? [];

            ?>

        <div class="invoice-card">

            <!-- Invoice Header -->

            <div class="invoice-header">

                <div>

                    <div class="invoice-number">

                        Invoice:
                        <?= pay_e($invoice['invoice_number']) ?>

                    </div>

                    <div class="invoice-semester">

                        <?= pay_e($invoice['semester_name'] ?? 'Semester') ?>

                        <?php if (!empty($invoice['session_name'])): ?>

                        · <?= pay_e($invoice['session_name']) ?>

                        <?php endif; ?>

                    </div>

                </div>


                <div>

                    <span class="status
                            <?= pay_status_class($invoice['display_status']) ?>">

                        <?= pay_e($invoice['display_status']) ?>

                    </span>

                </div>

            </div>


            <!-- Invoice Details -->

            <div class="invoice-body">

                <div class="invoice-info-grid">

                    <div class="info-box">

                        <div class="info-label">
                            Invoice Amount
                        </div>

                        <div class="info-value">
                            <?= pay_money($invoice['total_amount']) ?>
                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Verified Paid
                        </div>

                        <div class="info-value">

                            <?= pay_money($invoice['verified_paid']) ?>

                        </div>

                    </div>


                    <div class="info-box remaining">

                        <div class="info-label">
                            Outstanding Balance
                        </div>

                        <div class="info-value">

                            <?= pay_money($invoice['outstanding']) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">
                            Payment Due Date
                        </div>

                        <div class="info-value">

                            <?= pay_date($invoice['due_date']) ?>

                        </div>

                    </div>

                </div>


                <!-- Payment History -->

                <div class="payment-section-title">

                    Payment Transactions

                </div>


                <?php if (empty($invoicePayments)): ?>

                <div class="no-payments">

                    No payment transactions recorded for this invoice.

                </div>


                <?php else: ?>

                <div class="table-wrapper">

                    <table class="payment-table">

                        <thead>

                            <tr>

                                <th>Payment Date</th>

                                <th>Amount</th>

                                <th>Payment Method</th>

                                <th>Transaction Reference</th>

                                <th>Status</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($invoicePayments as $payment): ?>

                            <tr>

                                <td>

                                    <?= pay_datetime(
                                                    $payment['paid_at'] ??
                                                    $payment['created_at']
                                                ) ?>

                                </td>


                                <td>

                                    <strong>

                                        <?= pay_money($payment['amount']) ?>

                                    </strong>

                                </td>


                                <td>

                                    <?= pay_e(
                                                    $payment['payment_method']
                                                    ?: 'N/A'
                                                ) ?>

                                </td>


                                <td>

                                    <?= pay_e(
                                                    $payment['transaction_reference']
                                                    ?: 'N/A'
                                                ) ?>

                                </td>


                                <td>

                                    <span class="status
                                                    <?= pay_status_class($payment['status']) ?>">

                                        <?= pay_e($payment['status']) ?>

                                    </span>

                                </td>

                            </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <?php endif; ?>


            </div>

        </div>

        <?php endforeach; ?>


        <?php endif; ?>


        <!-- INFORMATION NOTE -->

        <div class="payment-note">

            <strong>Payment Information:</strong>

            Only payments marked as <strong>Verified</strong>
            are counted towards your paid balance.

            Pending transactions are awaiting verification
            by the Accounts Office.

            If you find any discrepancy in your invoice
            or payment record, please contact the Accounts Office.

        </div>


    </div>

</body>

</html>