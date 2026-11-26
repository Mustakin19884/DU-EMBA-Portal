<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('accounts');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if (!isset($_SESSION['invoice_csrf'])) {
    $_SESSION['invoice_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['invoice_csrf'];

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function invoice_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function invoice_money($amount): string
{
    return '৳ ' . number_format((float)$amount, 2);
}

/*
|--------------------------------------------------------------------------
| Get Semester List
|--------------------------------------------------------------------------
*/

$semesters = [];

$semesterQuery = "
    SELECT
        s.id,
        s.semester_name,
        s.start_date,
        s.end_date,
        s.status,
        ac.session_name
    FROM semesters s
    LEFT JOIN academic_sessions ac
        ON ac.id = s.session_id
    ORDER BY s.start_date DESC, s.id DESC
";

$semesterResult = $conn->query($semesterQuery);

if ($semesterResult) {
    while ($row = $semesterResult->fetch_assoc()) {
        $semesters[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Get Batch List
|--------------------------------------------------------------------------
*/

$batches = [];

$batchResult = $conn->query("
    SELECT id, batch_name, admission_year, status
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
| Get Student List
|--------------------------------------------------------------------------
*/

$students = [];

$studentResult = $conn->query("
    SELECT
        st.id,
        st.student_id,
        st.batch_id,
        u.full_name,
        u.username,
        b.batch_name
    FROM students st
    INNER JOIN users u
        ON u.id = st.user_id
    LEFT JOIN batches b
        ON b.id = st.batch_id
    WHERE u.is_active = 1
    ORDER BY st.student_id ASC
");

if ($studentResult) {
    while ($row = $studentResult->fetch_assoc()) {
        $students[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Invoice Generation
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'generate') {

            $semesterId = (int)($_POST['semester_id'] ?? 0);
            $targetType = $_POST['target_type'] ?? '';
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $studentId = (int)($_POST['student_id'] ?? 0);
            $dueDate = $_POST['due_date'] ?? '';
            $amountMode = $_POST['amount_mode'] ?? 'fee_structure';
            $customAmount = (float)($_POST['custom_amount'] ?? 0);

            if ($semesterId <= 0) {
                $error = 'Please select a semester.';
            } elseif (!in_array($targetType, ['batch', 'individual', 'all'], true)) {
                $error = 'Invalid invoice target selection.';
            } elseif ($dueDate === '') {
                $error = 'Please select an invoice due date.';
            } elseif (!in_array($amountMode, ['fee_structure', 'custom'], true)) {
                $error = 'Invalid fee amount selection.';
            } elseif ($amountMode === 'custom' && $customAmount <= 0) {
                $error = 'Custom invoice amount must be greater than zero.';
            } elseif ($targetType === 'batch' && $batchId <= 0) {
                $error = 'Please select a batch.';
            } elseif ($targetType === 'individual' && $studentId <= 0) {
                $error = 'Please select a student.';
            } else {

                /*
                |--------------------------------------------------------------------------
                | Validate Semester
                |--------------------------------------------------------------------------
                */

                $semesterStmt = $conn->prepare("
                    SELECT id, semester_name
                    FROM semesters
                    WHERE id = ?
                    LIMIT 1
                ");

                $semesterStmt->bind_param("i", $semesterId);
                $semesterStmt->execute();

                $semesterData = $semesterStmt->get_result()->fetch_assoc();

                $semesterStmt->close();

                if (!$semesterData) {
                    $error = 'Selected semester was not found.';
                }

                /*
                |--------------------------------------------------------------------------
                | Determine Invoice Amount
                |--------------------------------------------------------------------------
                */

                $invoiceAmount = 0;

                if (!$error && $amountMode === 'fee_structure') {

                    $feeStmt = $conn->prepare("
                        SELECT COALESCE(SUM(amount), 0) AS total_fee
                        FROM fee_structures
                        WHERE semester_id = ?
                    ");

                    $feeStmt->bind_param("i", $semesterId);
                    $feeStmt->execute();

                    $feeData = $feeStmt->get_result()->fetch_assoc();

                    $feeStmt->close();

                    $invoiceAmount = (float)($feeData['total_fee'] ?? 0);

                    if ($invoiceAmount <= 0) {
                        $error = 'No fee structure found for this semester. Please create fee structures first or use Custom Amount.';
                    }

                } elseif (!$error && $amountMode === 'custom') {

                    $invoiceAmount = $customAmount;

                }

                /*
                |--------------------------------------------------------------------------
                | Determine Students
                |--------------------------------------------------------------------------
                */

                $targetStudents = [];

                if (!$error) {

                    if ($targetType === 'batch') {

                        $studentStmt = $conn->prepare("
                            SELECT
                                st.id,
                                st.student_id,
                                u.full_name
                            FROM students st
                            INNER JOIN users u
                                ON u.id = st.user_id
                            WHERE st.batch_id = ?
                              AND u.is_active = 1
                            ORDER BY st.student_id
                        ");

                        $studentStmt->bind_param("i", $batchId);
                        $studentStmt->execute();

                        $studentResult = $studentStmt->get_result();

                        while ($row = $studentResult->fetch_assoc()) {
                            $targetStudents[] = $row;
                        }

                        $studentStmt->close();

                    } elseif ($targetType === 'individual') {

                        $studentStmt = $conn->prepare("
                            SELECT
                                st.id,
                                st.student_id,
                                u.full_name
                            FROM students st
                            INNER JOIN users u
                                ON u.id = st.user_id
                            WHERE st.id = ?
                              AND u.is_active = 1
                            LIMIT 1
                        ");

                        $studentStmt->bind_param("i", $studentId);
                        $studentStmt->execute();

                        $studentData = $studentStmt->get_result()->fetch_assoc();

                        $studentStmt->close();

                        if ($studentData) {
                            $targetStudents[] = $studentData;
                        }

                    } elseif ($targetType === 'all') {

                        $allStudentsResult = $conn->query("
                            SELECT
                                st.id,
                                st.student_id,
                                u.full_name
                            FROM students st
                            INNER JOIN users u
                                ON u.id = st.user_id
                            WHERE u.is_active = 1
                            ORDER BY st.student_id
                        ");

                        if ($allStudentsResult) {
                            while ($row = $allStudentsResult->fetch_assoc()) {
                                $targetStudents[] = $row;
                            }
                        }

                    }

                    if (empty($targetStudents)) {
                        $error = 'No eligible students found for the selected target.';
                    }

                }

                /*
                |--------------------------------------------------------------------------
                | Create Invoices
                |--------------------------------------------------------------------------
                */

                if (!$error) {

                    $createdCount = 0;
                    $skippedCount = 0;

                    try {

                        $conn->begin_transaction();

                        $duplicateStmt = $conn->prepare("
                            SELECT id
                            FROM student_invoices
                            WHERE student_id = ?
                              AND semester_id = ?
                            LIMIT 1
                        ");

                        $insertStmt = $conn->prepare("
                            INSERT INTO student_invoices
                            (
                                student_id,
                                semester_id,
                                invoice_number,
                                total_amount,
                                due_date,
                                status
                            )
                            VALUES (?, ?, ?, ?, ?, 'unpaid')
                        ");

                        foreach ($targetStudents as $student) {

                            $currentStudentId = (int)$student['id'];

                            /*
                            |--------------------------------------------------------------------------
                            | Prevent Duplicate Invoice
                            |--------------------------------------------------------------------------
                            */

                            $duplicateStmt->bind_param(
                                "ii",
                                $currentStudentId,
                                $semesterId
                            );

                            $duplicateStmt->execute();

                            $duplicateResult = $duplicateStmt->get_result();

                            if ($duplicateResult->num_rows > 0) {
                                $skippedCount++;
                                continue;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Generate Unique Invoice Number
                            |--------------------------------------------------------------------------
                            */

                            $invoiceNumber = sprintf(
                                'DU-%s-%s-%s',
                                date('Ymd'),
                                $semesterId,
                                strtoupper(bin2hex(random_bytes(4)))
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | Insert Invoice
                            |--------------------------------------------------------------------------
                            */

                            $insertStmt->bind_param(
                                "iisds",
                                $currentStudentId,
                                $semesterId,
                                $invoiceNumber,
                                $invoiceAmount,
                                $dueDate
                            );

                            $insertStmt->execute();

                            $createdCount++;
                        }

                        $duplicateStmt->close();
                        $insertStmt->close();

                        $conn->commit();

                        $message = $createdCount . ' invoice(s) generated successfully.';

                        if ($skippedCount > 0) {
                            $message .= ' ' . $skippedCount . ' student(s) skipped because invoice already exists for this semester.';
                        }

                    } catch (Throwable $exception) {

                        $conn->rollback();

                        $error = 'Invoice generation failed. Please check your database and try again.';
                    }

                }

            }

        }

    }

}
$conn->query("
    UPDATE student_invoices
    SET status = 'overdue'
    WHERE status IN ('unpaid', 'partial')
      AND due_date IS NOT NULL
      AND due_date < CURDATE()
");
/*
|--------------------------------------------------------------------------
| Invoice Filters
|--------------------------------------------------------------------------
*/

$filterSemester = (int)($_GET['semester_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$allowedStatuses = ['unpaid', 'partial', 'paid', 'overdue'];

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

/*
|--------------------------------------------------------------------------
| Invoice List
|--------------------------------------------------------------------------
*/

$invoiceSql = "
    SELECT
        i.id,
        i.invoice_number,
        i.total_amount,
        i.due_date,
        i.status,
        i.created_at,

        st.student_id AS student_code,
        st.id AS student_record_id,

        u.full_name,
        u.username,
        u.email,

        b.batch_name,

        s.semester_name,
        ac.session_name,

        COALESCE(
            (
                SELECT SUM(p.amount)
                FROM payments p
                WHERE p.invoice_id = i.id
                  AND p.status = 'verified'
            ),
            0
        ) AS verified_paid

    FROM student_invoices i

    INNER JOIN students st
        ON st.id = i.student_id

    INNER JOIN users u
        ON u.id = st.user_id

    LEFT JOIN batches b
        ON b.id = st.batch_id

    INNER JOIN semesters s
        ON s.id = i.semester_id

    LEFT JOIN academic_sessions ac
        ON ac.id = s.session_id

    WHERE 1 = 1
";

$params = [];
$types = '';

if ($filterSemester > 0) {
    $invoiceSql .= " AND i.semester_id = ?";
    $params[] = $filterSemester;
    $types .= 'i';
}

if ($filterStatus !== '') {
    $invoiceSql .= " AND i.status = ?";
    $params[] = $filterStatus;
    $types .= 's';
}

if ($search !== '') {

    $invoiceSql .= "
        AND (
            i.invoice_number LIKE ?
            OR st.student_id LIKE ?
            OR u.full_name LIKE ?
            OR u.username LIKE ?
            OR u.email LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;

    $types .= 'sssss';
}

$invoiceSql .= "
    ORDER BY i.created_at DESC, i.id DESC
    LIMIT 500
";

$invoiceRows = [];

$invoiceStmt = $conn->prepare($invoiceSql);

if (!empty($params)) {
    $invoiceStmt->bind_param($types, ...$params);
}

$invoiceStmt->execute();

$invoiceResult = $invoiceStmt->get_result();

while ($row = $invoiceResult->fetch_assoc()) {
    $invoiceRows[] = $row;
}

$invoiceStmt->close();

/*
|--------------------------------------------------------------------------
| Summary Statistics
|--------------------------------------------------------------------------
*/

$summary = [
    'total_invoices' => 0,
    'total_amount' => 0,
    'verified_amount' => 0,
    'outstanding' => 0,
    'unpaid_count' => 0,
    'partial_count' => 0,
    'paid_count' => 0,
    'overdue_count' => 0
];

$summaryResult = $conn->query("
    SELECT
        COUNT(*) AS total_invoices,
        COALESCE(SUM(total_amount), 0) AS total_amount,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'unpaid' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS unpaid_count,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'partial' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS partial_count,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'paid' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS paid_count,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'overdue' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS overdue_count

    FROM student_invoices
");

if ($summaryResult) {
    $summary = array_merge(
        $summary,
        $summaryResult->fetch_assoc()
    );
}

$verifiedResult = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS verified_amount
    FROM payments
    WHERE status = 'verified'
");

if ($verifiedResult) {
    $verifiedData = $verifiedResult->fetch_assoc();
    $summary['verified_amount'] = (float)$verifiedData['verified_amount'];
}

$summary['outstanding'] =
    (float)$summary['total_amount'] -
    (float)$summary['verified_amount'];

if ($summary['outstanding'] < 0) {
    $summary['outstanding'] = 0;
}

/*
|--------------------------------------------------------------------------
| Get Fee Structure Preview
|--------------------------------------------------------------------------
*/

$feePreview = [];

$feePreviewResult = $conn->query("
    SELECT
        fs.semester_id,
        SUM(fs.amount) AS total_fee,
        COUNT(*) AS fee_items
    FROM fee_structures fs
    GROUP BY fs.semester_id
");

if ($feePreviewResult) {
    while ($row = $feePreviewResult->fetch_assoc()) {
        $feePreview[(int)$row['semester_id']] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Invoice Management | Accounts</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    * {
        box-sizing: border-box;
    }

    body {
        background: #f4efe5;
        color: #263548;
        font-family: Arial, Helvetica, sans-serif;
        margin: 0;
    }

    .page-container {
        width: 95%;
        max-width: 1450px;
        margin: 30px auto;
        padding: 20px;
    }

    .page-header {
        background: #102d50;
        color: white;
        border-radius: 12px;
        padding: 25px 30px;
        margin-bottom: 25px;
    }

    .page-header h1 {
        margin: 0 0 8px;
        font-size: 27px;
    }

    .page-header p {
        margin: 0;
        color: #dce6f0;
    }

    .back-link {
        display: inline-block;
        margin-top: 15px;
        color: #fff;
        text-decoration: none;
        font-weight: bold;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 18px;
        margin-bottom: 25px;
    }

    .stat-card {
        background: #fff;
        padding: 22px;
        border-radius: 12px;
        border: 1px solid #e1e5eb;
        box-shadow: 0 3px 10px rgba(16, 45, 80, 0.04);
    }

    .stat-card h3 {
        font-size: 14px;
        color: #687789;
        margin: 0 0 12px;
    }

    .stat-card .value {
        font-size: 25px;
        font-weight: bold;
        color: #102d50;
    }

    .section-card {
        background: #fff;
        padding: 25px;
        border-radius: 12px;
        margin-bottom: 25px;
        border: 1px solid #e1e5eb;
    }

    .section-card h2 {
        margin-top: 0;
        color: #102d50;
        font-size: 21px;
        margin-bottom: 20px;
    }

    .alert {
        padding: 15px 18px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .alert-success {
        background: #e8f7ed;
        color: #176534;
        border: 1px solid #b7e3c5;
    }

    .alert-error {
        background: #fdecec;
        color: #a52828;
        border: 1px solid #f2c1c1;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 18px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: #263548;
    }

    .form-group input,
    .form-group select {
        width: 100%;
        padding: 12px;
        border: 1px solid #d5dce5;
        border-radius: 7px;
        background: #fff;
        font-size: 14px;
    }

    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #1c456e;
        box-shadow: 0 0 0 2px rgba(28, 69, 110, 0.1);
    }

    .hint {
        font-size: 12px;
        color: #687789;
        margin-top: 6px;
    }

    .amount-preview {
        background: #f4efe5;
        border: 1px solid #e1e5eb;
        padding: 14px;
        border-radius: 8px;
        margin: 5px 0 20px;
        color: #102d50;
        font-weight: bold;
    }

    .btn {
        border: none;
        padding: 12px 20px;
        border-radius: 7px;
        font-size: 14px;
        font-weight: bold;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .btn-primary {
        background: #102d50;
        color: white;
    }

    .btn-primary:hover {
        background: #1c456e;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: 2fr 2fr 2fr auto;
        gap: 12px;
        align-items: end;
    }

    .filter-grid .form-group {
        margin-bottom: 0;
    }

    .table-wrapper {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1100px;
    }

    th,
    td {
        padding: 12px 10px;
        border-bottom: 1px solid #e1e5eb;
        text-align: left;
        vertical-align: top;
        font-size: 13px;
    }

    th {
        background: #102d50;
        color: #fff;
        white-space: nowrap;
    }

    tr:hover td {
        background: #fafbfd;
    }

    .status {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
    }

    .status-paid {
        background: #e6f6ec;
        color: #17713a;
    }

    .status-unpaid {
        background: #fff1db;
        color: #9a5b00;
    }

    .status-partial {
        background: #e7f0ff;
        color: #2458a6;
    }

    .status-overdue {
        background: #fde8e8;
        color: #a32929;
    }

    .small-text {
        color: #687789;
        font-size: 12px;
        margin-top: 4px;
    }

    .empty-state {
        text-align: center;
        padding: 35px;
        color: #687789;
    }

    .reset-link {
        display: inline-block;
        margin-left: 10px;
        color: #102d50;
        text-decoration: none;
        font-weight: bold;
    }

    @media (max-width: 1000px) {

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .form-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .filter-grid {
            grid-template-columns: repeat(2, 1fr);
        }

    }

    @media (max-width: 600px) {

        .page-container {
            width: 100%;
            padding: 12px;
            margin: 10px auto;
        }

        .stats-grid,
        .form-grid,
        .filter-grid {
            grid-template-columns: 1fr;
        }

        .section-card {
            padding: 18px;
        }

        .page-header {
            padding: 20px;
        }

    }
    </style>

</head>

<body>

    <div class="page-container">

        <div class="page-header">

            <h1>Invoice Management</h1>

            <p>
                Generate semester-wise student invoices and monitor payment status.
            </p>

            <a href="<?= BASE_URL ?>/accounts/dashboard.php" class="back-link">
                ← Back to Accounts Dashboard
            </a>

        </div>

        <?php if ($message): ?>

        <div class="alert alert-success">
            <?= invoice_e($message) ?>
        </div>

        <?php endif; ?>

        <?php if ($error): ?>

        <div class="alert alert-error">
            <?= invoice_e($error) ?>
        </div>

        <?php endif; ?>

        <!-- Summary -->

        <div class="stats-grid">

            <div class="stat-card">

                <h3>Total Invoices</h3>

                <div class="value">
                    <?= number_format((int)$summary['total_invoices']) ?>
                </div>

            </div>

            <div class="stat-card">

                <h3>Total Invoiced</h3>

                <div class="value">
                    <?= invoice_money($summary['total_amount']) ?>
                </div>

            </div>

            <div class="stat-card">

                <h3>Verified Collection</h3>

                <div class="value">
                    <?= invoice_money($summary['verified_amount']) ?>
                </div>

            </div>

            <div class="stat-card">

                <h3>Outstanding Dues</h3>

                <div class="value">
                    <?= invoice_money($summary['outstanding']) ?>
                </div>

            </div>

        </div>

        <!-- Generate Invoice -->

        <div class="section-card">

            <h2>Generate New Invoices</h2>

            <form method="POST">

                <input type="hidden" name="csrf_token" value="<?= invoice_e($csrfToken) ?>">

                <input type="hidden" name="action" value="generate">

                <div class="form-grid">

                    <div class="form-group">

                        <label>Academic Semester</label>

                        <select name="semester_id" id="semester_id" required>

                            <option value="">Select Semester</option>

                            <?php foreach ($semesters as $semester): ?>

                            <option value="<?= (int)$semester['id'] ?>"
                                data-fee="<?= invoice_e($feePreview[$semester['id']]['total_fee'] ?? 0) ?>">

                                <?= invoice_e($semester['semester_name']) ?>
                                -
                                <?= invoice_e($semester['session_name']) ?>

                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Invoice Target</label>

                        <select name="target_type" id="target_type" required>

                            <option value="batch">Entire Batch</option>
                            <option value="individual">Individual Student</option>
                            <option value="all">All Active Students</option>

                        </select>

                    </div>

                    <div class="form-group" id="batch_group">

                        <label>Select Batch</label>

                        <select name="batch_id">

                            <option value="">Select Batch</option>

                            <?php foreach ($batches as $batch): ?>

                            <option value="<?= (int)$batch['id'] ?>">

                                <?= invoice_e($batch['batch_name']) ?>

                                (<?= invoice_e($batch['admission_year']) ?>)

                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="form-group" id="student_group" style="display:none;">

                        <label>Select Student</label>

                        <select name="student_id">

                            <option value="">Select Student</option>

                            <?php foreach ($students as $student): ?>

                            <option value="<?= (int)$student['id'] ?>">

                                <?= invoice_e($student['student_id']) ?>
                                -
                                <?= invoice_e($student['full_name']) ?>

                                <?php if (!empty($student['batch_name'])): ?>

                                (<?= invoice_e($student['batch_name']) ?>)

                                <?php endif; ?>

                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Invoice Due Date</label>

                        <input type="date" name="due_date" required>

                    </div>

                    <div class="form-group">

                        <label>Fee Amount Source</label>

                        <select name="amount_mode" id="amount_mode" required>

                            <option value="fee_structure">
                                Use Semester Fee Structure
                            </option>

                            <option value="custom">
                                Custom Amount
                            </option>

                        </select>

                    </div>

                    <div class="form-group" id="custom_amount_group" style="display:none;">

                        <label>Custom Invoice Amount (৳)</label>

                        <input type="number" name="custom_amount" min="0.01" step="0.01" placeholder="Enter amount">

                    </div>

                </div>

                <div class="amount-preview" id="fee_preview">

                    Select a semester to preview its fee structure.

                </div>

                <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Are you sure you want to generate invoices? Existing invoices for the same student and semester will be skipped.')">

                    Generate Invoices

                </button>

            </form>

        </div>

        <!-- Filters -->

        <div class="section-card">

            <h2>Invoice Records</h2>

            <form method="GET">

                <div class="filter-grid">

                    <div class="form-group">

                        <label>Search</label>

                        <input type="text" name="search" value="<?= invoice_e($search) ?>"
                            placeholder="Student ID, name, username, invoice number">

                    </div>

                    <div class="form-group">

                        <label>Semester</label>

                        <select name="semester_id">

                            <option value="">All Semesters</option>

                            <?php foreach ($semesters as $semester): ?>

                            <option value="<?= (int)$semester['id'] ?>"
                                <?= $filterSemester === (int)$semester['id'] ? 'selected' : '' ?>>

                                <?= invoice_e($semester['semester_name']) ?>
                                -
                                <?= invoice_e($semester['session_name']) ?>

                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Invoice Status</label>

                        <select name="status">

                            <option value="">All Statuses</option>

                            <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>
                                Unpaid
                            </option>

                            <option value="partial" <?= $filterStatus === 'partial' ? 'selected' : '' ?>>
                                Partial
                            </option>

                            <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>
                                Paid
                            </option>

                            <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>
                                Overdue
                            </option>

                        </select>

                    </div>

                    <div>

                        <button type="submit" class="btn btn-primary">

                            Search

                        </button>

                        <a href="<?= BASE_URL ?>/accounts/invoices.php" class="reset-link">

                            Reset

                        </a>

                    </div>

                </div>

            </form>

        </div>

        <!-- Invoice Table -->

        <div class="section-card">

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>Invoice Number</th>
                            <th>Student</th>
                            <th>Batch</th>
                            <th>Semester</th>
                            <th>Invoice Amount</th>
                            <th>Verified Paid</th>
                            <th>Balance</th>
                            <th>Due Date</th>
                            <th>Status</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($invoiceRows)): ?>

                        <tr>

                            <td colspan="9" class="empty-state">

                                No invoice records found.

                            </td>

                        </tr>

                        <?php else: ?>

                        <?php foreach ($invoiceRows as $invoice): ?>

                        <?php

                        $balance =
                            (float)$invoice['total_amount'] -
                            (float)$invoice['verified_paid'];

                        if ($balance < 0) {
                            $balance = 0;
                        }

                        ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= invoice_e($invoice['invoice_number']) ?>
                                </strong>

                                <div class="small-text">

                                    Created:
                                    <?= invoice_e(date('d M Y', strtotime($invoice['created_at']))) ?>

                                </div>

                            </td>

                            <td>

                                <strong>
                                    <?= invoice_e($invoice['full_name']) ?>
                                </strong>

                                <div class="small-text">

                                    ID:
                                    <?= invoice_e($invoice['student_code']) ?>

                                </div>

                                <div class="small-text">

                                    <?= invoice_e($invoice['username']) ?>

                                </div>

                            </td>

                            <td>

                                <?= invoice_e($invoice['batch_name'] ?? 'N/A') ?>

                            </td>

                            <td>

                                <?= invoice_e($invoice['semester_name']) ?>

                                <div class="small-text">

                                    <?= invoice_e($invoice['session_name'] ?? '') ?>

                                </div>

                            </td>

                            <td>

                                <strong>
                                    <?= invoice_money($invoice['total_amount']) ?>
                                </strong>

                            </td>

                            <td>

                                <?= invoice_money($invoice['verified_paid']) ?>

                            </td>

                            <td>

                                <strong>
                                    <?= invoice_money($balance) ?>
                                </strong>

                            </td>

                            <td>

                                <?= $invoice['due_date']
                                    ? invoice_e(date('d M Y', strtotime($invoice['due_date'])))
                                    : 'N/A' ?>

                            </td>

                            <td>

                                <span class="status status-<?= invoice_e($invoice['status']) ?>">

                                    <?= invoice_e($invoice['status']) ?>

                                </span>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <p class="small-text">

                Showing up to 500 invoice records.

            </p>

        </div>

    </div>

    <script>
    const targetType = document.getElementById('target_type');

    const batchGroup = document.getElementById('batch_group');

    const studentGroup = document.getElementById('student_group');

    const amountMode = document.getElementById('amount_mode');

    const customAmountGroup = document.getElementById('custom_amount_group');

    const semesterSelect = document.getElementById('semester_id');

    const feePreview = document.getElementById('fee_preview');

    function updateTargetFields() {

        if (targetType.value === 'batch') {

            batchGroup.style.display = 'block';
            studentGroup.style.display = 'none';

        } else if (targetType.value === 'individual') {

            batchGroup.style.display = 'none';
            studentGroup.style.display = 'block';

        } else {

            batchGroup.style.display = 'none';
            studentGroup.style.display = 'none';

        }

    }

    function updateAmountFields() {

        if (amountMode.value === 'custom') {

            customAmountGroup.style.display = 'block';

        } else {

            customAmountGroup.style.display = 'none';

        }

        updateFeePreview();

    }

    function updateFeePreview() {

        const selectedOption =
            semesterSelect.options[semesterSelect.selectedIndex];

        if (!selectedOption || !selectedOption.value) {

            feePreview.textContent =
                'Select a semester to preview its fee structure.';

            return;

        }

        const feeAmount =
            parseFloat(selectedOption.getAttribute('data-fee')) || 0;

        if (amountMode.value === 'custom') {

            feePreview.textContent =
                'Custom amount will be used for invoice generation.';

        } else if (feeAmount > 0) {

            feePreview.textContent =
                'Semester Fee Structure Total: ৳ ' +
                feeAmount.toLocaleString('en-BD', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

        } else {

            feePreview.textContent =
                'No fee structure is configured for this semester. Choose Custom Amount or create the fee structure first.';

        }

    }

    targetType.addEventListener('change', updateTargetFields);

    amountMode.addEventListener('change', updateAmountFields);

    semesterSelect.addEventListener('change', updateFeePreview);

    updateTargetFields();

    updateAmountFields();
    </script>

</body>

</html>