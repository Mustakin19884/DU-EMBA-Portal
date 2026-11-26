<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('accounts');

$pageTitle = 'Student Outstanding & Dues';

$batchId = (int)($_GET['batch_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');

$allowedStatuses = ['all', 'unpaid', 'partial', 'paid', 'overdue'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

/*
|--------------------------------------------------------------------------
| Batch list
|--------------------------------------------------------------------------
*/
$batches = [];

$batchResult = $conn->query("
    SELECT id, batch_name, admission_year
    FROM batches
    ORDER BY admission_year DESC, batch_name ASC
");

if ($batchResult) {
    while ($row = $batchResult->fetch_assoc()) {
        $batches[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Build student filter
|--------------------------------------------------------------------------
*/
$where = [];
$params = [];
$types = '';

if ($batchId > 0) {
    $where[] = 's.batch_id = ?';
    $params[] = $batchId;
    $types .= 'i';
}

if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR s.student_id LIKE ? OR u.username LIKE ?)';
    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;

    $types .= 'sss';
}

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| Student invoice and verified payment data
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        si.id AS invoice_id,
        si.student_id AS internal_student_id,
        si.semester_id,
        si.invoice_number,
        si.total_amount,
        si.due_date,
        si.status AS invoice_status,
        si.created_at,

        s.student_id AS student_code,
        s.batch_id,

        u.full_name,
        u.username,
        u.email,

        b.batch_name,

        sem.semester_name,
        ac.session_name,

        COALESCE(paid.total_paid, 0) AS total_paid

    FROM student_invoices si

    INNER JOIN students s
        ON s.id = si.student_id

    INNER JOIN users u
        ON u.id = s.user_id

    LEFT JOIN batches b
        ON b.id = s.batch_id

    LEFT JOIN semesters sem
        ON sem.id = si.semester_id

    LEFT JOIN academic_sessions ac
        ON ac.id = sem.session_id

    LEFT JOIN (
        SELECT
            invoice_id,
            SUM(amount) AS total_paid
        FROM payments
        WHERE status = 'verified'
        GROUP BY invoice_id
    ) paid
        ON paid.invoice_id = si.id

    $whereSql

    ORDER BY
        si.due_date ASC,
        u.full_name ASC,
        si.created_at DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

$invoices = [];
$studentBalances = [];

$totalInvoiceAmount = 0;
$totalVerifiedPaid = 0;
$totalOutstanding = 0;
$totalOverdue = 0;
$totalPendingInvoices = 0;
$totalInvoiceCount = 0;

while ($row = $result->fetch_assoc()) {

    $invoiceAmount = (float)$row['total_amount'];
    $paidAmount = (float)$row['total_paid'];

    $balance = max(0, $invoiceAmount - $paidAmount);

    $dueDate = $row['due_date'];

    if ($balance <= 0.01) {
        $calculatedStatus = 'paid';
    } elseif (
        !empty($dueDate) &&
        $dueDate < date('Y-m-d')
    ) {
        $calculatedStatus = 'overdue';
    } elseif ($paidAmount > 0) {
        $calculatedStatus = 'partial';
    } else {
        $calculatedStatus = 'unpaid';
    }

    $row['balance'] = $balance;
    $row['calculated_status'] = $calculatedStatus;

    /*
    |--------------------------------------------------------------------------
    | Status filter
    |--------------------------------------------------------------------------
    */
    if (
        $statusFilter !== 'all' &&
        $calculatedStatus !== $statusFilter
    ) {
        continue;
    }

    $invoices[] = $row;

    $totalInvoiceAmount += $invoiceAmount;
    $totalVerifiedPaid += $paidAmount;
    $totalOutstanding += $balance;
    $totalInvoiceCount++;

    if ($calculatedStatus === 'overdue') {
        $totalOverdue += $balance;
    }

    if (in_array($calculatedStatus, ['unpaid', 'partial', 'overdue'], true)) {
        $totalPendingInvoices++;
    }

    $studentKey = (int)$row['internal_student_id'];

    if (!isset($studentBalances[$studentKey])) {
        $studentBalances[$studentKey] = [
            'student_id' => $row['student_code'],
            'name' => $row['full_name'],
            'username' => $row['username'],
            'batch' => $row['batch_name'] ?? 'Not assigned',
            'invoice_count' => 0,
            'total_invoice' => 0,
            'total_paid' => 0,
            'outstanding' => 0,
            'overdue' => 0
        ];
    }

    $studentBalances[$studentKey]['invoice_count']++;
    $studentBalances[$studentKey]['total_invoice'] += $invoiceAmount;
    $studentBalances[$studentKey]['total_paid'] += $paidAmount;
    $studentBalances[$studentKey]['outstanding'] += $balance;

    if ($calculatedStatus === 'overdue') {
        $studentBalances[$studentKey]['overdue'] += $balance;
    }
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Sort students by highest outstanding
|--------------------------------------------------------------------------
*/
uasort($studentBalances, function ($a, $b) {
    return $b['outstanding'] <=> $a['outstanding'];
});

function money($amount): string
{
    return '৳' . number_format((float)$amount, 2);
}

function statusClass(string $status): string
{
    return match ($status) {
        'paid' => 'paid',
        'partial' => 'partial',
        'overdue' => 'overdue',
        default => 'unpaid'
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= e($pageTitle) ?></title>

    <style>
    :root {
        --navy: #102d50;
        --navy-light: #1c456e;
        --beige: #f4efe5;
        --text: #263548;
        --muted: #687789;
        --border: #e1e5eb;
        --white: #fff;
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

    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 30px 24px;
    }

    .topbar {
        background: var(--navy);
        color: white;
        padding: 22px 28px;
        border-radius: 14px;
        margin-bottom: 25px;
    }

    .topbar h1 {
        margin: 0 0 8px;
        font-size: 25px;
    }

    .topbar p {
        margin: 0;
        opacity: .8;
        font-size: 14px;
    }

    .back-link {
        display: inline-block;
        margin-bottom: 18px;
        color: var(--navy);
        text-decoration: none;
        font-weight: bold;
    }

    .stats {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 16px;
        margin-bottom: 25px;
    }

    .stat {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 20px;
    }

    .stat-label {
        color: var(--muted);
        font-size: 13px;
        margin-bottom: 10px;
    }

    .stat-value {
        font-size: 23px;
        font-weight: bold;
        color: var(--navy);
    }

    .stat.overdue .stat-value {
        color: #b42318;
    }

    .stat.outstanding .stat-value {
        color: #b45309;
    }

    .panel {
        background: white;
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .panel h2 {
        color: var(--navy);
        margin: 0 0 18px;
        font-size: 19px;
    }

    .filters {
        display: flex;
        flex-wrap: wrap;
        align-items: end;
        gap: 12px;
        margin-bottom: 20px;
    }

    .filter-group {
        flex: 1;
        min-width: 180px;
    }

    .filter-group label {
        display: block;
        margin-bottom: 7px;
        font-size: 13px;
        font-weight: bold;
    }

    .filter-group input,
    .filter-group select {
        width: 100%;
        padding: 11px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: white;
        font-size: 14px;
    }

    .btn {
        border: none;
        border-radius: 8px;
        padding: 11px 18px;
        font-weight: bold;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }

    .btn-primary {
        background: var(--navy);
        color: white;
    }

    .btn-secondary {
        background: var(--beige);
        color: var(--navy);
    }

    .table-wrap {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 950px;
    }

    th,
    td {
        padding: 13px 12px;
        border-bottom: 1px solid var(--border);
        text-align: left;
        font-size: 13px;
    }

    th {
        background: #f5f7fa;
        color: var(--navy);
        font-size: 12px;
        white-space: nowrap;
    }

    td strong {
        display: block;
        margin-bottom: 4px;
    }

    .small {
        color: var(--muted);
        font-size: 12px;
    }

    .amount {
        font-weight: bold;
        white-space: nowrap;
    }

    .badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
    }

    .badge.paid {
        background: #dcfce7;
        color: #166534;
    }

    .badge.partial {
        background: #fef3c7;
        color: #92400e;
    }

    .badge.unpaid {
        background: #e5e7eb;
        color: #374151;
    }

    .badge.overdue {
        background: #fee2e2;
        color: #991b1b;
    }

    .empty {
        padding: 35px;
        text-align: center;
        color: var(--muted);
    }

    .student-table {
        min-width: 850px;
    }

    .student-name {
        color: var(--navy);
        font-weight: bold;
    }

    .footer {
        text-align: center;
        color: var(--muted);
        font-size: 12px;
        padding: 20px;
    }

    @media(max-width:1100px) {
        .stats {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media(max-width:700px) {
        .container {
            padding: 16px;
        }

        .stats {
            grid-template-columns: repeat(2, 1fr);
        }

        .stat-value {
            font-size: 18px;
        }

        .panel {
            padding: 16px;
        }
    }
    </style>
</head>

<body>

    <div class="container">

        <a class="back-link" href="<?= BASE_URL ?>/accounts/dashboard.php">
            ← Back to Accounts Dashboard
        </a>

        <header class="topbar">
            <h1>Student Outstanding & Dues</h1>
            <p>Monitor invoices, verified payments and outstanding balances.</p>
        </header>

        <section class="stats">

            <div class="stat">
                <div class="stat-label">Total Invoiced</div>
                <div class="stat-value"><?= money($totalInvoiceAmount) ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">Verified Collection</div>
                <div class="stat-value"><?= money($totalVerifiedPaid) ?></div>
            </div>

            <div class="stat outstanding">
                <div class="stat-label">Total Outstanding</div>
                <div class="stat-value"><?= money($totalOutstanding) ?></div>
            </div>

            <div class="stat overdue">
                <div class="stat-label">Overdue Amount</div>
                <div class="stat-value"><?= money($totalOverdue) ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">Outstanding Invoices</div>
                <div class="stat-value"><?= number_format($totalPendingInvoices) ?></div>
            </div>

        </section>

        <section class="panel">

            <h2>Filter Student Financial Records</h2>

            <form method="GET" class="filters">

                <div class="filter-group">
                    <label>Batch</label>

                    <select name="batch_id">
                        <option value="0">All Batches</option>

                        <?php foreach ($batches as $batch): ?>
                        <option value="<?= (int)$batch['id'] ?>"
                            <?= $batchId === (int)$batch['id'] ? 'selected' : '' ?>>
                            <?= e($batch['batch_name']) ?>
                            (<?= e($batch['admission_year']) ?>)
                        </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="filter-group">
                    <label>Search Student</label>

                    <input type="text" name="search" value="<?= e($search) ?>"
                        placeholder="Name, student ID or username">
                </div>

                <div class="filter-group">
                    <label>Invoice Status</label>

                    <select name="status">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                            All Statuses
                        </option>

                        <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>
                            Unpaid
                        </option>

                        <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>
                            Partial
                        </option>

                        <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>
                            Paid
                        </option>

                        <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>
                            Overdue
                        </option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    Apply Filters
                </button>

                <a href="<?= BASE_URL ?>/accounts/student-dues.php" class="btn btn-secondary">
                    Reset
                </a>

            </form>

            <div class="table-wrap">

                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Batch</th>
                            <th>Invoices</th>
                            <th>Total Invoiced</th>
                            <th>Total Paid</th>
                            <th>Outstanding</th>
                            <th>Overdue</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($studentBalances)): ?>

                        <tr>
                            <td colspan="7" class="empty">
                                No student financial records found.
                            </td>
                        </tr>

                        <?php else: ?>

                        <?php foreach ($studentBalances as $student): ?>

                        <tr>
                            <td>
                                <span class="student-name">
                                    <?= e($student['name']) ?>
                                </span>

                                <span class="small">
                                    <?= e($student['student_id']) ?>
                                </span>
                            </td>

                            <td><?= e($student['batch']) ?></td>

                            <td><?= (int)$student['invoice_count'] ?></td>

                            <td class="amount">
                                <?= money($student['total_invoice']) ?>
                            </td>

                            <td class="amount">
                                <?= money($student['total_paid']) ?>
                            </td>

                            <td class="amount">
                                <?= money($student['outstanding']) ?>
                            </td>

                            <td class="amount">
                                <?= money($student['overdue']) ?>
                            </td>
                        </tr>

                        <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>
                </table>

            </div>

        </section>

        <section class="panel">

            <h2>Invoice-wise Outstanding Details</h2>

            <div class="table-wrap">

                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Invoice Number</th>
                            <th>Semester</th>
                            <th>Invoice Amount</th>
                            <th>Verified Paid</th>
                            <th>Balance</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($invoices)): ?>

                        <tr>
                            <td colspan="8" class="empty">
                                No invoices found for the selected filters.
                            </td>
                        </tr>

                        <?php else: ?>

                        <?php foreach ($invoices as $invoice): ?>

                        <tr>

                            <td>
                                <strong><?= e($invoice['full_name']) ?></strong>

                                <span class="small">
                                    <?= e($invoice['student_code']) ?>
                                </span>

                                <span class="small">
                                    <?= e($invoice['batch_name'] ?? 'No batch') ?>
                                </span>
                            </td>

                            <td>
                                <?= e($invoice['invoice_number']) ?>
                            </td>

                            <td>
                                <?= e($invoice['semester_name'] ?? 'Unknown semester') ?>

                                <span class="small">
                                    <?= e($invoice['session_name'] ?? '') ?>
                                </span>
                            </td>

                            <td class="amount">
                                <?= money($invoice['total_amount']) ?>
                            </td>

                            <td class="amount">
                                <?= money($invoice['total_paid']) ?>
                            </td>

                            <td class="amount">
                                <?= money($invoice['balance']) ?>
                            </td>

                            <td>
                                <?= e($invoice['due_date'] ?? 'Not set') ?>
                            </td>

                            <td>
                                <span class="badge <?= statusClass($invoice['calculated_status']) ?>">
                                    <?= e(ucfirst($invoice['calculated_status'])) ?>
                                </span>
                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>
                </table>

            </div>

        </section>

        <footer class="footer">
            DU EMBA MIS Student Portal &copy; <?= date('Y') ?>
            <br>
            Accounts Department
        </footer>

    </div>

</body>

</html>