<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('accounts');

$accountsName = $_SESSION['full_name'] ?? 'Accounts Officer';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function acc_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function acc_money($amount): string
{
    return '৳ ' . number_format((float)$amount, 2);
}

function acc_date($date): string
{
    if (empty($date)) {
        return 'N/A';
    }

    return date('d M Y', strtotime((string)$date));
}

/*
|--------------------------------------------------------------------------
| Financial Dashboard Statistics
|--------------------------------------------------------------------------
*/

$totalInvoices = 0;
$totalInvoiced = 0;
$totalVerified = 0;
$totalPending = 0;
$totalRejected = 0;
$totalOutstanding = 0;
$studentsWithDue = 0;
$pendingPaymentCount = 0;

$recentPendingPayments = [];
$recentInvoices = [];

/*
|--------------------------------------------------------------------------
| Invoice Statistics
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        COUNT(*) AS invoice_count,
        COALESCE(SUM(total_amount), 0) AS total_invoiced
    FROM student_invoices
";

$result = $conn->query($sql);

if ($result) {
    $row = $result->fetch_assoc();

    $totalInvoices = (int)$row['invoice_count'];
    $totalInvoiced = (float)$row['total_invoiced'];
}

/*
|--------------------------------------------------------------------------
| Verified Payments
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT COALESCE(SUM(amount), 0) AS total_verified
    FROM payments
    WHERE status = 'verified'
";

$result = $conn->query($sql);

if ($result) {
    $row = $result->fetch_assoc();
    $totalVerified = (float)$row['total_verified'];
}

/*
|--------------------------------------------------------------------------
| Pending Payments
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        COUNT(*) AS pending_count,
        COALESCE(SUM(amount), 0) AS pending_amount
    FROM payments
    WHERE status = 'pending'
";

$result = $conn->query($sql);

if ($result) {
    $row = $result->fetch_assoc();

    $pendingPaymentCount = (int)$row['pending_count'];
    $totalPending = (float)$row['pending_amount'];
}

/*
|--------------------------------------------------------------------------
| Rejected Payments
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT COALESCE(SUM(amount), 0) AS rejected_amount
    FROM payments
    WHERE status = 'rejected'
";

$result = $conn->query($sql);

if ($result) {
    $row = $result->fetch_assoc();
    $totalRejected = (float)$row['rejected_amount'];
}

/*
|--------------------------------------------------------------------------
| Outstanding Balance
|--------------------------------------------------------------------------
| Outstanding = invoice amount - verified payments
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        COALESCE(
            SUM(
                GREATEST(
                    si.total_amount - COALESCE(p.verified_amount, 0),
                    0
                )
            ),
            0
        ) AS outstanding_amount

    FROM student_invoices si

    LEFT JOIN (
        SELECT
            invoice_id,
            SUM(amount) AS verified_amount
        FROM payments
        WHERE status = 'verified'
        GROUP BY invoice_id
    ) p ON p.invoice_id = si.id
";

$result = $conn->query($sql);

if ($result) {
    $row = $result->fetch_assoc();
    $totalOutstanding = (float)$row['outstanding_amount'];
}

/*
|--------------------------------------------------------------------------
| Students with Outstanding Balance
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT COUNT(*) AS student_count
    FROM (
        SELECT
            si.student_id

        FROM student_invoices si

        LEFT JOIN (
            SELECT
                invoice_id,
                SUM(amount) AS verified_amount
            FROM payments
            WHERE status = 'verified'
            GROUP BY invoice_id
        ) p ON p.invoice_id = si.id

        GROUP BY si.student_id

        HAVING SUM(
            GREATEST(
                si.total_amount - COALESCE(p.verified_amount, 0),
                0
            )
        ) > 0

    ) AS students_due
";

$result = $conn->query($sql);

if ($result) {
    $row = $result->fetch_assoc();
    $studentsWithDue = (int)$row['student_count'];
}

/*
|--------------------------------------------------------------------------
| Recent Pending Payments
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.id,
        p.invoice_id,
        p.amount,
        p.payment_method,
        p.transaction_reference,
        p.created_at,

        si.invoice_number,

        s.student_id AS academic_student_id,

        u.full_name,
        u.username

    FROM payments p

    INNER JOIN student_invoices si
        ON si.id = p.invoice_id

    INNER JOIN students s
        ON s.id = si.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    WHERE p.status = 'pending'

    ORDER BY p.created_at DESC, p.id DESC

    LIMIT 10
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recentPendingPayments[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Recent Invoices
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        si.id,
        si.invoice_number,
        si.total_amount,
        si.due_date,
        si.status,
        si.created_at,

        s.student_id AS academic_student_id,

        u.full_name,
        u.username,

        sem.semester_name

    FROM student_invoices si

    INNER JOIN students s
        ON s.id = si.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    LEFT JOIN semesters sem
        ON sem.id = si.semester_id

    ORDER BY si.created_at DESC, si.id DESC

    LIMIT 8
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recentInvoices[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Accounts Dashboard | DU EMBA MIS Portal</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    body {
        background: #f4f6f9;
        color: #263548;
    }

    .accounts-dashboard {
        width: 94%;
        max-width: 1450px;
        margin: 30px auto;
    }

    /* HEADER */

    .dashboard-header {
        background: #102d50;
        color: white;
        padding: 28px 32px;
        border-radius: 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 28px;
    }

    .dashboard-header h1 {
        margin: 0 0 8px;
        font-size: 28px;
    }

    .dashboard-header p {
        margin: 0;
        color: #dbe6f1;
    }

    .header-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .header-btn {
        display: inline-block;
        text-decoration: none;
        padding: 10px 18px;
        border-radius: 7px;
        font-weight: 600;
        font-size: 13px;
    }

    .logout-btn {
        background: white;
        color: #102d50;
    }

    /* STATISTICS */

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 23px;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        box-shadow: 0 3px 10px rgba(16, 45, 80, .04);
    }

    .stat-label {
        font-size: 13px;
        color: #687789;
        margin-bottom: 12px;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 700;
        color: #102d50;
        word-break: break-word;
    }

    .stat-card.green .stat-value {
        color: #198754;
    }

    .stat-card.orange .stat-value {
        color: #d97706;
    }

    .stat-card.red .stat-value {
        color: #dc3545;
    }

    .stat-description {
        font-size: 12px;
        color: #8995a3;
        margin-top: 9px;
    }

    /* SECTIONS */

    .dashboard-section {
        margin-bottom: 32px;
    }

    .section-heading {
        margin-bottom: 18px;
    }

    .section-heading h2 {
        color: #102d50;
        margin: 0 0 7px;
        font-size: 22px;
    }

    .section-heading p {
        color: #687789;
        margin: 0;
        font-size: 14px;
    }

    /* MODULE CARDS */

    .module-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
    }

    .module-card {
        background: white;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 23px;
        text-decoration: none;
        display: block;
        transition: .2s ease;
    }

    .module-card:hover {
        border-color: #102d50;
        transform: translateY(-3px);
        box-shadow: 0 5px 18px rgba(16, 45, 80, .08);
    }

    .module-icon {
        width: 43px;
        height: 43px;
        background: #edf3f9;
        color: #102d50;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        margin-bottom: 15px;
    }

    .module-card h3 {
        color: #102d50;
        font-size: 17px;
        margin: 0 0 9px;
    }

    .module-card p {
        color: #687789;
        font-size: 13px;
        line-height: 1.6;
        margin: 0;
    }

    .module-arrow {
        display: block;
        color: #102d50;
        font-size: 20px;
        margin-top: 16px;
    }

    /* TABLES */

    .table-card {
        background: white;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        overflow: hidden;
    }

    .table-wrapper {
        width: 100%;
        overflow-x: auto;
    }

    .dashboard-table {
        width: 100%;
        min-width: 850px;
        border-collapse: collapse;
    }

    .dashboard-table th {
        background: #102d50;
        color: white;
        text-align: left;
        padding: 14px;
        font-size: 13px;
        white-space: nowrap;
    }

    .dashboard-table td {
        padding: 13px 14px;
        border-bottom: 1px solid #e6eaf0;
        font-size: 13px;
        vertical-align: middle;
    }

    .dashboard-table tbody tr:last-child td {
        border-bottom: none;
    }

    .dashboard-table tbody tr:hover {
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

    .status-paid {
        background: #d1e7dd;
        color: #146c43;
    }

    .status-unpaid {
        background: #fff3cd;
        color: #997404;
    }

    .status-overdue {
        background: #f8d7da;
        color: #b02a37;
    }

    .view-link {
        color: #102d50;
        font-weight: 600;
        text-decoration: none;
    }

    .view-link:hover {
        text-decoration: underline;
    }

    .empty-table {
        padding: 35px;
        text-align: center;
        color: #687789;
    }

    .dashboard-footer {
        margin-top: 35px;
        padding: 20px;
        border-top: 1px solid #e1e5eb;
        text-align: center;
        color: #687789;
        font-size: 13px;
    }

    @media (max-width: 1100px) {

        .stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .module-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

    }

    @media (max-width: 650px) {

        .accounts-dashboard {
            width: 95%;
            margin: 20px auto;
        }

        .dashboard-header {
            padding: 22px;
        }

        .dashboard-header h1 {
            font-size: 22px;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .module-grid {
            grid-template-columns: 1fr;
        }

        .stat-value {
            font-size: 22px;
        }

    }
    </style>

</head>

<body>

    <main class="accounts-dashboard">

        <!-- HEADER -->

        <header class="dashboard-header">

            <div>

                <h1>Accounts Officer Dashboard</h1>

                <p>
                    Welcome, <?= acc_e($accountsName) ?>
                </p>

            </div>

            <div class="header-actions">

                <a class="header-btn logout-btn" href="<?= BASE_URL ?>/auth/logout.php">
                    Logout
                </a>

            </div>

        </header>


        <!-- FINANCIAL SUMMARY -->

        <section class="stats-grid">

            <article class="stat-card">

                <div class="stat-label">
                    Total Invoices
                </div>

                <div class="stat-value">
                    <?= number_format($totalInvoices) ?>
                </div>

                <div class="stat-description">
                    All invoices generated
                </div>

            </article>


            <article class="stat-card">

                <div class="stat-label">
                    Total Invoiced
                </div>

                <div class="stat-value">
                    <?= acc_money($totalInvoiced) ?>
                </div>

                <div class="stat-description">
                    Total billed to students
                </div>

            </article>


            <article class="stat-card green">

                <div class="stat-label">
                    Verified Collection
                </div>

                <div class="stat-value">
                    <?= acc_money($totalVerified) ?>
                </div>

                <div class="stat-description">
                    Confirmed payments
                </div>

            </article>


            <article class="stat-card red">

                <div class="stat-label">
                    Outstanding Dues
                </div>

                <div class="stat-value">
                    <?= acc_money($totalOutstanding) ?>
                </div>

                <div class="stat-description">
                    Unpaid invoice balances
                </div>

            </article>


            <article class="stat-card orange">

                <div class="stat-label">
                    Pending Verification
                </div>

                <div class="stat-value">
                    <?= acc_money($totalPending) ?>
                </div>

                <div class="stat-description">
                    <?= number_format($pendingPaymentCount) ?>
                    transactions awaiting review
                </div>

            </article>


            <article class="stat-card">

                <div class="stat-label">
                    Students with Dues
                </div>

                <div class="stat-value">
                    <?= number_format($studentsWithDue) ?>
                </div>

                <div class="stat-description">
                    Students with outstanding balances
                </div>

            </article>


            <article class="stat-card">

                <div class="stat-label">
                    Rejected Transactions
                </div>

                <div class="stat-value">
                    <?= acc_money($totalRejected) ?>
                </div>

                <div class="stat-description">
                    Total rejected payment amount
                </div>

            </article>


            <article class="stat-card">

                <div class="stat-label">
                    Collection Rate
                </div>

                <div class="stat-value">

                    <?= $totalInvoiced > 0
                    ? number_format(($totalVerified / $totalInvoiced) * 100, 1) . '%'
                    : '0.0%' ?>

                </div>

                <div class="stat-description">
                    Verified collection against total invoiced
                </div>

            </article>

        </section>


        <!-- MANAGEMENT MODULES -->

        <section class="dashboard-section">

            <div class="section-heading">

                <h2>Financial Management</h2>

                <p>
                    Manage invoices, student payments and financial records.
                </p>

            </div>


            <div class="module-grid">

                <a class="module-card" href="<?= BASE_URL ?>/accounts/payment-verification.php">

                    <div class="module-icon">✓</div>

                    <h3>Payment Verification</h3>

                    <p>
                        Review student payment submissions
                        and verify or reject transactions.
                    </p>

                    <span class="module-arrow">→</span>

                </a>


                <a class="module-card" href="<?= BASE_URL ?>/accounts/invoices.php">

                    <div class="module-icon">▤</div>

                    <h3>Invoice Management</h3>

                    <p>
                        Create invoices, manage semester fees
                        and review generated invoices.
                    </p>

                    <span class="module-arrow">→</span>

                </a>


                <a class="module-card" href="<?= BASE_URL ?>/accounts/student-dues.php">

                    <div class="module-icon">৳</div>

                    <h3>Student Dues</h3>

                    <p>
                        View student-wise outstanding balances
                        and payment status.
                    </p>

                    <span class="module-arrow">→</span>

                </a>


                <a class="module-card" href="<?= BASE_URL ?>/accounts/fee-structures.php">

                    <div class="module-icon">⚙</div>

                    <h3>Fee Structures</h3>

                    <p>
                        Manage semester fee amounts
                        and fee components.
                    </p>

                    <span class="module-arrow">→</span>

                </a>


                <a class="module-card" href="<?= BASE_URL ?>/accounts/reports.php">

                    <div class="module-icon">▥</div>

                    <h3>Financial Reports</h3>

                    <p>
                        Review collection, pending payments,
                        outstanding dues and financial summaries.
                    </p>

                    <span class="module-arrow">→</span>

                </a>

            </div>

        </section>


        <!-- PENDING PAYMENTS -->

        <section class="dashboard-section">

            <div class="section-heading">

                <h2>Recent Pending Payments</h2>

                <p>
                    Latest student transactions waiting for verification.
                </p>

            </div>


            <div class="table-card">

                <div class="table-wrapper">

                    <table class="dashboard-table">

                        <thead>

                            <tr>

                                <th>Student</th>

                                <th>Student ID</th>

                                <th>Invoice</th>

                                <th>Amount</th>

                                <th>Payment Method</th>

                                <th>Transaction Reference</th>

                                <th>Submitted</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php if (!empty($recentPendingPayments)): ?>

                            <?php foreach ($recentPendingPayments as $payment): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= acc_e($payment['full_name']) ?>
                                    </strong>

                                    <br>

                                    <small>
                                        <?= acc_e($payment['username']) ?>
                                    </small>

                                </td>


                                <td>

                                    <?= acc_e(
                                        $payment['academic_student_id'] ?? 'N/A'
                                    ) ?>

                                </td>


                                <td>

                                    <?= acc_e($payment['invoice_number']) ?>

                                </td>


                                <td>

                                    <strong>
                                        <?= acc_money($payment['amount']) ?>
                                    </strong>

                                </td>


                                <td>

                                    <?= acc_e(
                                        $payment['payment_method'] ?? 'N/A'
                                    ) ?>

                                </td>


                                <td>

                                    <?= acc_e(
                                        $payment['transaction_reference'] ?? 'N/A'
                                    ) ?>

                                </td>


                                <td>

                                    <?= acc_date($payment['created_at']) ?>

                                </td>

                            </tr>

                            <?php endforeach; ?>


                            <?php else: ?>

                            <tr>

                                <td colspan="7" class="empty-table">

                                    No pending payments available.

                                </td>

                            </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </section>


        <!-- RECENT INVOICES -->

        <section class="dashboard-section">

            <div class="section-heading">

                <h2>Recent Invoices</h2>

                <p>
                    Latest invoices generated in the portal.
                </p>

            </div>


            <div class="table-card">

                <div class="table-wrapper">

                    <table class="dashboard-table">

                        <thead>

                            <tr>

                                <th>Invoice Number</th>

                                <th>Student</th>

                                <th>Student ID</th>

                                <th>Semester</th>

                                <th>Amount</th>

                                <th>Due Date</th>

                                <th>Status</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php if (!empty($recentInvoices)): ?>

                            <?php foreach ($recentInvoices as $invoice): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= acc_e($invoice['invoice_number']) ?>
                                    </strong>

                                </td>


                                <td>

                                    <?= acc_e($invoice['full_name']) ?>

                                </td>


                                <td>

                                    <?= acc_e(
                                        $invoice['academic_student_id'] ?? 'N/A'
                                    ) ?>

                                </td>


                                <td>

                                    <?= acc_e(
                                        $invoice['semester_name'] ?? 'N/A'
                                    ) ?>

                                </td>


                                <td>

                                    <?= acc_money($invoice['total_amount']) ?>

                                </td>


                                <td>

                                    <?= acc_date($invoice['due_date']) ?>

                                </td>


                                <td>

                                    <span class="status status-<?= acc_e($invoice['status']) ?>">

                                        <?= acc_e($invoice['status']) ?>

                                    </span>

                                </td>

                            </tr>

                            <?php endforeach; ?>


                            <?php else: ?>

                            <tr>

                                <td colspan="7" class="empty-table">

                                    No invoices have been generated yet.

                                </td>

                            </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </section>


        <!-- FOOTER -->

        <footer class="dashboard-footer">

            DU EMBA MIS Student Portal

            <br>

            Accounts Officer Workspace

        </footer>

    </main>

</body>

</html>