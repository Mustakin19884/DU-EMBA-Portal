<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('accounts');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Financial Reports - Accounts Office
|--------------------------------------------------------------------------
| Tables used:
| student_invoices, students, users, semesters, payments
|--------------------------------------------------------------------------
*/

$pageTitle = 'Financial Reports';

function report_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function report_money($amount): string
{
    return '৳ ' . number_format((float)$amount, 2);
}

function report_query(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_result
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Database query preparation failed: ' . $conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Database query execution failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();

    if (!$result) {
        throw new RuntimeException('Could not retrieve query result.');
    }

    return $result;
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$semesterId = (int)($_GET['semester_id'] ?? 0);
$fromDate   = trim($_GET['from_date'] ?? '');
$toDate     = trim($_GET['to_date'] ?? '');
$status     = trim($_GET['status'] ?? '');
$search     = trim($_GET['search'] ?? '');

$allowedStatuses = ['paid', 'partial', 'unpaid', 'overdue'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = '';
}

if ($toDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = '';
}

if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

/*
|--------------------------------------------------------------------------
| Build common invoice filters
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];
$types = '';

if ($semesterId > 0) {
    $where[] = 'si.semester_id = ?';
    $params[] = $semesterId;
    $types .= 'i';
}

if ($fromDate !== '') {
    $where[] = 'DATE(si.created_at) >= ?';
    $params[] = $fromDate;
    $types .= 's';
}

if ($toDate !== '') {
    $where[] = 'DATE(si.created_at) <= ?';
    $params[] = $toDate;
    $types .= 's';
}

if ($status !== '') {
    $where[] = 'si.status = ?';
    $params[] = $status;
    $types .= 's';
}

if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR s.student_id LIKE ? OR si.invoice_number LIKE ?)';
    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;

    $types .= 'sss';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| Load semesters for filter
|--------------------------------------------------------------------------
*/

$semesters = report_query(
    $conn,
    "SELECT id, semester_name
     FROM semesters
     ORDER BY id DESC"
)->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Main invoice query
|--------------------------------------------------------------------------
*/

$invoiceSql = "
    SELECT
        si.id,
        si.invoice_number,
        si.student_id,
        si.semester_id,
        si.total_amount,
        si.due_date,
        si.status,
        si.created_at,

        s.student_id AS student_code,
        u.full_name,

        sem.semester_name,

        COALESCE(
            SUM(
                CASE
                    WHEN p.status = 'verified' THEN p.amount
                    ELSE 0
                END
            ), 0
        ) AS paid_amount

    FROM student_invoices si

    INNER JOIN students s
        ON s.id = si.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    LEFT JOIN semesters sem
        ON sem.id = si.semester_id

    LEFT JOIN payments p
        ON p.invoice_id = si.id

    $whereSql

    GROUP BY
        si.id,
        si.invoice_number,
        si.student_id,
        si.semester_id,
        si.total_amount,
        si.due_date,
        si.status,
        si.created_at,
        s.student_id,
        u.full_name,
        sem.semester_name

    ORDER BY si.created_at DESC
";

$invoiceResult = report_query($conn, $invoiceSql, $types, $params);
$invoices = $invoiceResult->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Calculate financial summary from filtered invoices
|--------------------------------------------------------------------------
*/

$totalInvoices = count($invoices);
$totalInvoiced = 0;
$totalCollected = 0;
$totalOutstanding = 0;
$totalOverdue = 0;

$paidCount = 0;
$partialCount = 0;
$unpaidCount = 0;

foreach ($invoices as $invoice) {
    $invoiceAmount = (float)$invoice['total_amount'];
    $paidAmount = (float)$invoice['paid_amount'];
    $balance = max(0, $invoiceAmount - $paidAmount);

    $totalInvoiced += $invoiceAmount;
    $totalCollected += $paidAmount;
    $totalOutstanding += $balance;

    if ($balance > 0 && !empty($invoice['due_date']) && $invoice['due_date'] < date('Y-m-d')) {
        $totalOverdue += $balance;
    }

    if ($invoice['status'] === 'paid') {
        $paidCount++;
    } elseif ($invoice['status'] === 'partial') {
        $partialCount++;
    } else {
        $unpaidCount++;
    }
}

$collectionRate = $totalInvoiced > 0
    ? ($totalCollected / $totalInvoiced) * 100
    : 0;

/*
|--------------------------------------------------------------------------
| Payment verification summary
|--------------------------------------------------------------------------
| Uses the same invoice filters, including the new student/user JOINs.
|--------------------------------------------------------------------------
*/

$paymentSummarySql = "
    SELECT
        SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN p.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count

    FROM payments p

    INNER JOIN student_invoices si
        ON si.id = p.invoice_id

    INNER JOIN students s
        ON s.id = si.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    $whereSql
";

$paymentSummary = report_query(
    $conn,
    $paymentSummarySql,
    $types,
    $params
)->fetch_assoc();

$pendingPayments = (int)($paymentSummary['pending_count'] ?? 0);
$rejectedPayments = (int)($paymentSummary['rejected_count'] ?? 0);

/*
|--------------------------------------------------------------------------
| Semester-wise financial summary
|--------------------------------------------------------------------------
| IMPORTANT: students and users JOINs are included here.
| This allows the search filter to work without SQL errors.
|--------------------------------------------------------------------------
*/

$semesterSql = "
    SELECT
        sem.id,
        sem.semester_name,

        COUNT(DISTINCT si.id) AS invoice_count,

        COALESCE(SUM(si.total_amount), 0) AS invoiced_amount,

        COALESCE(
            SUM(
                CASE
                    WHEN p.status = 'verified' THEN p.amount
                    ELSE 0
                END
            ), 0
        ) AS collected_amount

    FROM student_invoices si

    INNER JOIN students s
        ON s.id = si.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    LEFT JOIN semesters sem
        ON sem.id = si.semester_id

    LEFT JOIN payments p
        ON p.invoice_id = si.id

    $whereSql

    GROUP BY sem.id, sem.semester_name

    ORDER BY sem.id DESC
";

$semesterSummary = report_query(
    $conn,
    $semesterSql,
    $types,
    $params
)->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Payment method summary
|--------------------------------------------------------------------------
*/

$paymentMethodSql = "
    SELECT
        COALESCE(p.payment_method, 'Not specified') AS payment_method,
        COUNT(p.id) AS payment_count,
        COALESCE(SUM(p.amount), 0) AS total_amount

    FROM payments p

    INNER JOIN student_invoices si
        ON si.id = p.invoice_id

    INNER JOIN students s
        ON s.id = si.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    $whereSql

    AND p.status = 'verified'

    GROUP BY p.payment_method

    ORDER BY total_amount DESC
";

if (!$where) {
    $paymentMethodSql = str_replace(
        '$whereSql',
        "WHERE p.status = 'verified'",
        $paymentMethodSql
    );
} else {
    $paymentMethodSql = str_replace(
        '$whereSql',
        $whereSql . " AND p.status = 'verified'",
        $paymentMethodSql
    );
}

$paymentMethods = report_query(
    $conn,
    $paymentMethodSql,
    $types,
    $params
)->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= report_escape($pageTitle) ?></title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    .report-container {
        max-width: 1250px;
        margin: 30px auto;
        padding: 0 22px 40px;
    }

    .report-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 24px;
    }

    .report-header h1 {
        color: #102d50;
        margin: 0 0 6px;
    }

    .report-header p {
        color: #687789;
        margin: 0;
    }

    .report-card {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 22px;
    }

    .report-card h2 {
        font-size: 19px;
        color: #102d50;
        margin: 0 0 17px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 14px;
        align-items: end;
    }

    .filter-grid label {
        display: block;
        color: #263548;
        font-weight: 600;
        font-size: 13px;
        margin-bottom: 6px;
    }

    .filter-grid input,
    .filter-grid select {
        width: 100%;
        min-height: 42px;
        border: 1px solid #d5dce5;
        border-radius: 7px;
        padding: 8px 10px;
        box-sizing: border-box;
    }

    .report-btn {
        display: inline-block;
        border: 0;
        border-radius: 7px;
        background: #102d50;
        color: white;
        padding: 11px 17px;
        text-decoration: none;
        cursor: pointer;
        font-weight: 600;
    }

    .report-btn.secondary {
        background: #687789;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 15px;
        margin-bottom: 22px;
    }

    .summary-card {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 11px;
        padding: 18px;
    }

    .summary-card span {
        display: block;
        color: #687789;
        font-size: 13px;
        margin-bottom: 9px;
    }

    .summary-card strong {
        display: block;
        color: #102d50;
        font-size: 23px;
        overflow-wrap: anywhere;
    }

    .report-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .report-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 700px;
    }

    .report-table th,
    .report-table td {
        padding: 12px 10px;
        border-bottom: 1px solid #e7ebf0;
        text-align: left;
        font-size: 13px;
    }

    .report-table th {
        background: #f4efe5;
        color: #102d50;
        white-space: nowrap;
    }

    .status {
        display: inline-block;
        border-radius: 20px;
        padding: 4px 9px;
        font-size: 11px;
        font-weight: 700;
        text-transform: capitalize;
    }

    .status.paid {
        background: #e2f5e9;
        color: #176b37;
    }

    .status.partial {
        background: #fff1d6;
        color: #8b5b00;
    }

    .status.unpaid,
    .status.overdue {
        background: #fde8e8;
        color: #a32a2a;
    }

    .empty-state {
        text-align: center;
        color: #687789;
        padding: 22px;
    }

    .section-heading {
        margin: 28px 0 14px;
        color: #102d50;
    }

    @media print {
        body {
            background: white !important;
        }

        .no-print,
        .filter-card,
        nav,
        header,
        footer {
            display: none !important;
        }

        .report-container {
            max-width: 100%;
            margin: 0;
            padding: 0;
        }

        .report-card,
        .summary-card {
            break-inside: avoid;
        }

        .report-table {
            min-width: 0;
        }
    }



    /* =====================================
   FINANCIAL REPORT HEADER
===================================== */

    .financial-header {
        width: 100%;
        background: #102d50;
        color: #ffffff;
        box-shadow: 0 3px 12px rgba(16, 45, 80, 0.15);
    }

    .financial-header-inner {
        max-width: 1350px;
        min-height: 82px;
        margin: 0 auto;
        padding: 12px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 25px;
        box-sizing: border-box;
    }

    .financial-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        color: #ffffff;
        flex-shrink: 0;
    }

    .brand-logo {
        width: 43px;
        height: 43px;
        background: #f4efe5;
        color: #102d50;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 17px;
    }

    .brand-text strong,
    .brand-text small {
        display: block;
    }

    .brand-text strong {
        font-size: 16px;
        letter-spacing: 0.3px;
    }

    .brand-text small {
        color: #d2dce8;
        font-size: 12px;
        margin-top: 4px;
    }

    .financial-nav {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        flex-wrap: wrap;
    }

    .financial-nav a {
        color: #e6edf5;
        text-decoration: none;
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        transition: 0.2s ease;
    }

    .financial-nav a:hover,
    .financial-nav a.active {
        background: #f4efe5;
        color: #102d50;
    }

    .financial-user {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }

    .user-avatar {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #f4efe5;
        color: #102d50;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
    }

    .financial-user strong,
    .financial-user small {
        display: block;
    }

    .financial-user strong {
        font-size: 13px;
        color: #ffffff;
    }

    .financial-user small {
        font-size: 11px;
        color: #d2dce8;
        margin-top: 3px;
    }

    @media (max-width: 1050px) {
        .financial-header-inner {
            flex-wrap: wrap;
            justify-content: center;
        }

        .financial-nav {
            order: 3;
            width: 100%;
        }
    }

    @media (max-width: 600px) {
        .financial-header-inner {
            padding: 15px;
            gap: 15px;
        }

        .financial-nav {
            justify-content: flex-start;
        }

        .financial-nav a {
            font-size: 12px;
            padding: 8px;
        }

        .financial-user {
            margin-left: auto;
        }
    }
    </style>
</head>

<body>


    <header class="financial-header no-print">
        <div class="financial-header-inner">

            <a href="<?= BASE_URL ?>/accounts/dashboard.php" class="financial-brand">
                <span class="brand-logo">DU</span>
                <span class="brand-text">
                    <strong>DU EMBA MIS</strong>
                    <small>Accounts Workspace</small>
                </span>
            </a>

            <nav class="financial-nav">
                <a href="<?= BASE_URL ?>/accounts/dashboard.php">Dashboard</a>
                <a href="<?= BASE_URL ?>/accounts/invoices.php">Invoices</a>
                <a href="<?= BASE_URL ?>/accounts/fee-structures.php">Fee Structures</a>
                <a href="<?= BASE_URL ?>/accounts/student-dues.php">Student Dues</a>
                <a href="<?= BASE_URL ?>/accounts/reports.php" class="active">Financial Reports</a>
            </nav>

            <div class="financial-user">
                <span class="user-avatar">A</span>
                <span>
                    <strong><?= report_escape($_SESSION['full_name'] ?? 'Accounts Officer') ?></strong>
                    <small>Accounts Officer</small>
                </span>
            </div>

        </div>
    </header>


    <main class="report-container">

        <div class="report-header">
            <div>
                <h1>Financial Reports</h1>
                <p>Invoice, collection and outstanding payment reports</p>
            </div>

            <div class="no-print">
                <button class="report-btn" onclick="window.print()">
                    Print / Save PDF
                </button>
                <a class="report-btn secondary" href="<?= BASE_URL ?>/accounts/dashboard.php">
                    Back to Dashboard
                </a>
            </div>
        </div>

        <!-- FILTERS -->
        <section class="report-card filter-card no-print">
            <h2>Report Filters</h2>

            <form method="GET">
                <div class="filter-grid">

                    <div>
                        <label for="semester_id">Semester</label>
                        <select name="semester_id" id="semester_id">
                            <option value="0">All Semesters</option>

                            <?php foreach ($semesters as $semester): ?>
                            <option value="<?= (int)$semester['id'] ?>"
                                <?= $semesterId === (int)$semester['id'] ? 'selected' : '' ?>>
                                <?= report_escape($semester['semester_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="from_date">Invoice From</label>
                        <input type="date" name="from_date" id="from_date" value="<?= report_escape($fromDate) ?>">
                    </div>

                    <div>
                        <label for="to_date">Invoice To</label>
                        <input type="date" name="to_date" id="to_date" value="<?= report_escape($toDate) ?>">
                    </div>

                    <div>
                        <label for="status">Invoice Status</label>
                        <select name="status" id="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($allowedStatuses as $item): ?>
                            <option value="<?= report_escape($item) ?>" <?= $status === $item ? 'selected' : '' ?>>
                                <?= ucfirst($item) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="search">Search</label>
                        <input type="text" name="search" id="search" placeholder="Student / ID / Invoice"
                            value="<?= report_escape($search) ?>">
                    </div>

                    <div>
                        <button type="submit" class="report-btn">
                            Apply Filters
                        </button>

                        <a class="report-btn secondary" href="<?= BASE_URL ?>/accounts/reports.php">
                            Reset
                        </a>
                    </div>

                </div>
            </form>
        </section>

        <!-- FINANCIAL SUMMARY -->
        <h2 class="section-heading">Financial Overview</h2>

        <section class="summary-grid">

            <div class="summary-card">
                <span>Total Invoices</span>
                <strong><?= number_format($totalInvoices) ?></strong>
            </div>

            <div class="summary-card">
                <span>Total Invoiced</span>
                <strong><?= report_money($totalInvoiced) ?></strong>
            </div>

            <div class="summary-card">
                <span>Total Collected</span>
                <strong><?= report_money($totalCollected) ?></strong>
            </div>

            <div class="summary-card">
                <span>Total Outstanding</span>
                <strong><?= report_money($totalOutstanding) ?></strong>
            </div>

            <div class="summary-card">
                <span>Overdue Balance</span>
                <strong><?= report_money($totalOverdue) ?></strong>
            </div>

            <div class="summary-card">
                <span>Collection Rate</span>
                <strong><?= number_format($collectionRate, 2) ?>%</strong>
            </div>

            <div class="summary-card">
                <span>Pending Payments</span>
                <strong><?= number_format($pendingPayments) ?></strong>
            </div>

            <div class="summary-card">
                <span>Rejected Payments</span>
                <strong><?= number_format($rejectedPayments) ?></strong>
            </div>

        </section>

        <!-- INVOICE STATUS -->
        <section class="report-card">
            <h2>Invoice Status Summary</h2>

            <div class="summary-grid">
                <div class="summary-card">
                    <span>Paid Invoices</span>
                    <strong><?= number_format($paidCount) ?></strong>
                </div>

                <div class="summary-card">
                    <span>Partial Invoices</span>
                    <strong><?= number_format($partialCount) ?></strong>
                </div>

                <div class="summary-card">
                    <span>Unpaid / Overdue Invoices</span>
                    <strong><?= number_format($unpaidCount) ?></strong>
                </div>
            </div>
        </section>

        <!-- SEMESTER SUMMARY -->
        <section class="report-card">
            <h2>Semester-wise Financial Summary</h2>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Semester</th>
                            <th>Invoices</th>
                            <th>Total Invoiced</th>
                            <th>Total Collected</th>
                            <th>Outstanding</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($semesterSummary): ?>
                        <?php foreach ($semesterSummary as $row): ?>
                        <?php
                            $balance = max(
                                0,
                                (float)$row['invoiced_amount'] - (float)$row['collected_amount']
                            );
                        ?>
                        <tr>
                            <td><?= report_escape($row['semester_name'] ?? 'Unknown') ?></td>
                            <td><?= number_format((int)$row['invoice_count']) ?></td>
                            <td><?= report_money($row['invoiced_amount']) ?></td>
                            <td><?= report_money($row['collected_amount']) ?></td>
                            <td><?= report_money($balance) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-state">No semester data found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- PAYMENT METHOD SUMMARY -->
        <section class="report-card">
            <h2>Payment Method Summary (Verified Payments)</h2>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Payment Method</th>
                            <th>Number of Payments</th>
                            <th>Total Collection</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($paymentMethods): ?>
                        <?php foreach ($paymentMethods as $method): ?>
                        <tr>
                            <td><?= report_escape($method['payment_method']) ?></td>
                            <td><?= number_format((int)$method['payment_count']) ?></td>
                            <td><?= report_money($method['total_amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="3" class="empty-state">No verified payment data found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- INVOICE DETAILS -->
        <section class="report-card">
            <h2>Invoice-wise Detailed Report</h2>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Invoice No.</th>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Semester</th>
                            <th>Invoice Amount</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($invoices): ?>
                        <?php foreach ($invoices as $invoice): ?>
                        <?php
                            $amount = (float)$invoice['total_amount'];
                            $paid = (float)$invoice['paid_amount'];
                            $balance = max(0, $amount - $paid);
                        ?>
                        <tr>
                            <td><?= report_escape($invoice['invoice_number']) ?></td>
                            <td><?= report_escape($invoice['full_name']) ?></td>
                            <td><?= report_escape($invoice['student_code']) ?></td>
                            <td><?= report_escape($invoice['semester_name'] ?? 'N/A') ?></td>
                            <td><?= report_money($amount) ?></td>
                            <td><?= report_money($paid) ?></td>
                            <td><?= report_money($balance) ?></td>
                            <td><?= report_escape($invoice['due_date']) ?></td>
                            <td>
                                <span class="status <?= report_escape($invoice['status']) ?>">
                                    <?= report_escape($invoice['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                No invoices found for the selected filters.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p style="text-align:center;color:#687789;font-size:12px;">
            DU EMBA MIS Student Portal | Financial Report |
            Generated on <?= date('d M Y, h:i A') ?>
        </p>

    </main>

    <?php
$footerFile = __DIR__ . '/../includes/footer.php';

if (file_exists($footerFile)) {
    require $footerFile;
}
?>

</body>

</html>