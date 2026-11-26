<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

require_role('accounts');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['fee_csrf'])) {
    $_SESSION['fee_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['fee_csrf'];

$message = '';
$error = '';

function fee_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fee_money($amount): string
{
    return '৳ ' . number_format((float)$amount, 2);
}

/*
|--------------------------------------------------------------------------
| Semester List
|--------------------------------------------------------------------------
*/

$semesters = [];

$semesterResult = $conn->query("
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
");

if ($semesterResult) {
    while ($row = $semesterResult->fetch_assoc()) {
        $semesters[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Selected Semester
|--------------------------------------------------------------------------
*/

$selectedSemester = (int)($_GET['semester_id'] ?? 0);

if ($selectedSemester <= 0 && !empty($semesters)) {
    $selectedSemester = (int)$semesters[0]['id'];
}

/*
|--------------------------------------------------------------------------
| Edit Mode
|--------------------------------------------------------------------------
*/

$editId = (int)($_GET['edit'] ?? 0);

$editFee = null;

if ($editId > 0) {

    $editStmt = $conn->prepare("
        SELECT id, semester_id, fee_name, amount
        FROM fee_structures
        WHERE id = ?
        LIMIT 1
    ");

    $editStmt->bind_param("i", $editId);
    $editStmt->execute();

    $editFee = $editStmt->get_result()->fetch_assoc();

    $editStmt->close();

    if ($editFee) {
        $selectedSemester = (int)$editFee['semester_id'];
    } else {
        $editId = 0;
    }
}

/*
|--------------------------------------------------------------------------
| Handle POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {

        $error = 'Invalid security token. Please refresh and try again.';

    } else {

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | Add Fee
        |--------------------------------------------------------------------------
        */

        if ($action === 'add') {

            $semesterId = (int)($_POST['semester_id'] ?? 0);
            $feeName = trim($_POST['fee_name'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);

            if ($semesterId <= 0) {
                $error = 'Please select a semester.';
            } elseif ($feeName === '') {
                $error = 'Fee name is required.';
            } elseif (mb_strlen($feeName) > 120) {
                $error = 'Fee name cannot exceed 120 characters.';
            } elseif ($amount <= 0) {
                $error = 'Fee amount must be greater than zero.';
            } else {

                // Validate semester exists
                $checkSemester = $conn->prepare("
                    SELECT id
                    FROM semesters
                    WHERE id = ?
                    LIMIT 1
                ");

                $checkSemester->bind_param("i", $semesterId);
                $checkSemester->execute();

                $semesterExists =
                    $checkSemester->get_result()->num_rows > 0;

                $checkSemester->close();

                if (!$semesterExists) {

                    $error = 'Selected semester does not exist.';

                } else {

                    // Prevent duplicate fee name within same semester
                    $duplicateStmt = $conn->prepare("
                        SELECT id
                        FROM fee_structures
                        WHERE semester_id = ?
                          AND LOWER(fee_name) = LOWER(?)
                        LIMIT 1
                    ");

                    $duplicateStmt->bind_param(
                        "is",
                        $semesterId,
                        $feeName
                    );

                    $duplicateStmt->execute();

                    $duplicateExists =
                        $duplicateStmt->get_result()->num_rows > 0;

                    $duplicateStmt->close();

                    if ($duplicateExists) {

                        $error = 'This fee name already exists for the selected semester.';

                    } else {

                        $insertStmt = $conn->prepare("
                            INSERT INTO fee_structures
                                (semester_id, fee_name, amount)
                            VALUES (?, ?, ?)
                        ");

                        $insertStmt->bind_param(
                            "isd",
                            $semesterId,
                            $feeName,
                            $amount
                        );

                        if ($insertStmt->execute()) {

                            $insertStmt->close();

                            header(
                                "Location: " .
                                BASE_URL .
                                "/accounts/fee-structures.php?semester_id=" .
                                $semesterId .
                                "&success=added"
                            );

                            exit;

                        } else {

                            $error = 'Unable to add fee structure.';
                            $insertStmt->close();

                        }

                    }

                }

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Update Fee
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'update') {

            $feeId = (int)($_POST['fee_id'] ?? 0);
            $semesterId = (int)($_POST['semester_id'] ?? 0);
            $feeName = trim($_POST['fee_name'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);

            if ($feeId <= 0) {
                $error = 'Invalid fee structure.';
            } elseif ($semesterId <= 0) {
                $error = 'Please select a semester.';
            } elseif ($feeName === '') {
                $error = 'Fee name is required.';
            } elseif (mb_strlen($feeName) > 120) {
                $error = 'Fee name cannot exceed 120 characters.';
            } elseif ($amount <= 0) {
                $error = 'Fee amount must be greater than zero.';
            } else {

                // Prevent duplicate names excluding current record
                $duplicateStmt = $conn->prepare("
                    SELECT id
                    FROM fee_structures
                    WHERE semester_id = ?
                      AND LOWER(fee_name) = LOWER(?)
                      AND id <> ?
                    LIMIT 1
                ");

                $duplicateStmt->bind_param(
                    "isi",
                    $semesterId,
                    $feeName,
                    $feeId
                );

                $duplicateStmt->execute();

                $duplicateExists =
                    $duplicateStmt->get_result()->num_rows > 0;

                $duplicateStmt->close();

                if ($duplicateExists) {

                    $error = 'Another fee with this name already exists for this semester.';

                } else {

                    $updateStmt = $conn->prepare("
                        UPDATE fee_structures
                        SET semester_id = ?,
                            fee_name = ?,
                            amount = ?
                        WHERE id = ?
                    ");

                    $updateStmt->bind_param(
                        "isdi",
                        $semesterId,
                        $feeName,
                        $amount,
                        $feeId
                    );

                    if ($updateStmt->execute()) {

                        $updateStmt->close();

                        header(
                            "Location: " .
                            BASE_URL .
                            "/accounts/fee-structures.php?semester_id=" .
                            $semesterId .
                            "&success=updated"
                        );

                        exit;

                    } else {

                        $error = 'Unable to update fee structure.';
                        $updateStmt->close();

                    }

                }

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Delete Fee
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'delete') {

            $feeId = (int)($_POST['fee_id'] ?? 0);
            $semesterId = (int)($_POST['semester_id'] ?? 0);

            if ($feeId <= 0) {

                $error = 'Invalid fee structure selected.';

            } else {

                $deleteStmt = $conn->prepare("
                    DELETE FROM fee_structures
                    WHERE id = ?
                ");

                $deleteStmt->bind_param("i", $feeId);

                if ($deleteStmt->execute()) {

                    $deleteStmt->close();

                    header(
                        "Location: " .
                        BASE_URL .
                        "/accounts/fee-structures.php?semester_id=" .
                        $semesterId .
                        "&success=deleted"
                    );

                    exit;

                } else {

                    $error = 'Unable to delete fee structure.';
                    $deleteStmt->close();

                }

            }

        }

    }

}

/*
|--------------------------------------------------------------------------
| Success Message
|--------------------------------------------------------------------------
*/

$success = $_GET['success'] ?? '';

if ($success === 'added') {
    $message = 'Fee structure added successfully.';
} elseif ($success === 'updated') {
    $message = 'Fee structure updated successfully.';
} elseif ($success === 'deleted') {
    $message = 'Fee structure deleted successfully.';
}

/*
|--------------------------------------------------------------------------
| Selected Semester Information
|--------------------------------------------------------------------------
*/

$semesterInfo = null;

if ($selectedSemester > 0) {

    $semesterInfoStmt = $conn->prepare("
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
        WHERE s.id = ?
        LIMIT 1
    ");

    $semesterInfoStmt->bind_param("i", $selectedSemester);
    $semesterInfoStmt->execute();

    $semesterInfo =
        $semesterInfoStmt->get_result()->fetch_assoc();

    $semesterInfoStmt->close();

}

/*
|--------------------------------------------------------------------------
| Fee Structure List
|--------------------------------------------------------------------------
*/

$feeRows = [];
$totalFee = 0;

if ($selectedSemester > 0) {

    $feeListStmt = $conn->prepare("
        SELECT id, semester_id, fee_name, amount
        FROM fee_structures
        WHERE semester_id = ?
        ORDER BY fee_name ASC, id ASC
    ");

    $feeListStmt->bind_param("i", $selectedSemester);
    $feeListStmt->execute();

    $feeListResult = $feeListStmt->get_result();

    while ($row = $feeListResult->fetch_assoc()) {

        $feeRows[] = $row;
        $totalFee += (float)$row['amount'];

    }

    $feeListStmt->close();

}

/*
|--------------------------------------------------------------------------
| Overall Statistics
|--------------------------------------------------------------------------
*/

$overallStats = [
    'fee_items' => 0,
    'semester_count' => 0,
    'overall_total' => 0
];

$statsResult = $conn->query("
    SELECT
        COUNT(*) AS fee_items,
        COUNT(DISTINCT semester_id) AS semester_count,
        COALESCE(SUM(amount), 0) AS overall_total
    FROM fee_structures
");

if ($statsResult) {
    $overallStats = $statsResult->fetch_assoc();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Fee Structure Management | Accounts</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background: #f4efe5;
        color: #263548;
        font-family: Arial, Helvetica, sans-serif;
    }

    .container {
        width: 95%;
        max-width: 1250px;
        margin: 30px auto;
        padding: 20px;
    }

    .header {
        background: #102d50;
        color: #fff;
        padding: 25px 30px;
        border-radius: 12px;
        margin-bottom: 25px;
    }

    .header h1 {
        margin: 0 0 8px;
        font-size: 27px;
    }

    .header p {
        color: #dce6f0;
        margin: 0;
    }

    .back-link {
        display: inline-block;
        margin-top: 15px;
        color: #fff;
        text-decoration: none;
        font-weight: bold;
    }

    .stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 18px;
        margin-bottom: 25px;
    }

    .stat-card {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 22px;
    }

    .stat-card .label {
        font-size: 14px;
        color: #687789;
        margin-bottom: 10px;
    }

    .stat-card .value {
        font-size: 25px;
        font-weight: bold;
        color: #102d50;
    }

    .card {
        background: #fff;
        border: 1px solid #e1e5eb;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
    }

    .card h2 {
        color: #102d50;
        font-size: 21px;
        margin: 0 0 20px;
    }

    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .success {
        background: #e8f7ed;
        color: #176534;
        border: 1px solid #b7e3c5;
    }

    .error {
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
        margin-bottom: 8px;
        font-weight: 600;
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
    }

    .btn {
        border: 0;
        padding: 11px 17px;
        border-radius: 7px;
        cursor: pointer;
        font-weight: bold;
        text-decoration: none;
        display: inline-block;
        font-size: 13px;
    }

    .primary {
        background: #102d50;
        color: #fff;
    }

    .primary:hover {
        background: #1c456e;
    }

    .secondary {
        background: #e8edf3;
        color: #102d50;
    }

    .danger {
        background: #fde8e8;
        color: #a32929;
    }

    .semester-info {
        background: #f4efe5;
        border: 1px solid #e1e5eb;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }

    .semester-info strong {
        color: #102d50;
    }

    .table-wrapper {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 650px;
    }

    th,
    td {
        padding: 13px;
        border-bottom: 1px solid #e1e5eb;
        text-align: left;
        vertical-align: middle;
    }

    th {
        background: #102d50;
        color: #fff;
    }

    .row-actions {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .inline-form {
        display: inline;
        margin: 0;
    }

    .total-row {
        background: #f4efe5;
        font-weight: bold;
        color: #102d50;
    }

    .empty {
        text-align: center;
        padding: 35px;
        color: #687789;
    }

    .help-text {
        color: #687789;
        font-size: 13px;
        margin-top: 7px;
    }

    @media (max-width: 850px) {

        .stats {
            grid-template-columns: 1fr;
        }

        .form-grid {
            grid-template-columns: repeat(2, 1fr);
        }

    }

    @media (max-width: 600px) {

        .container {
            width: 100%;
            padding: 12px;
            margin: 10px auto;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }

        .card {
            padding: 18px;
        }

        .header {
            padding: 20px;
        }

    }
    </style>

</head>

<body>

    <div class="container">

        <div class="header">

            <h1>Fee Structure Management</h1>

            <p>
                Create and manage semester-wise tuition and other academic fees.
            </p>

            <a href="<?= BASE_URL ?>/accounts/dashboard.php" class="back-link">

                ← Back to Accounts Dashboard

            </a>

        </div>

        <?php if ($message): ?>

        <div class="alert success">
            <?= fee_e($message) ?>
        </div>

        <?php endif; ?>

        <?php if ($error): ?>

        <div class="alert error">
            <?= fee_e($error) ?>
        </div>

        <?php endif; ?>

        <!-- Statistics -->

        <div class="stats">

            <div class="stat-card">

                <div class="label">
                    Total Fee Items
                </div>

                <div class="value">
                    <?= number_format((int)$overallStats['fee_items']) ?>
                </div>

            </div>

            <div class="stat-card">

                <div class="label">
                    Semesters with Fee Structures
                </div>

                <div class="value">
                    <?= number_format((int)$overallStats['semester_count']) ?>
                </div>

            </div>

            <div class="stat-card">

                <div class="label">
                    Total Configured Fees
                </div>

                <div class="value">
                    <?= fee_money($overallStats['overall_total']) ?>
                </div>

            </div>

        </div>

        <!-- Semester Selection -->

        <div class="card">

            <h2>Select Academic Semester</h2>

            <form method="GET">

                <div class="form-grid">

                    <div class="form-group">

                        <label>Semester</label>

                        <select name="semester_id" onchange="this.form.submit()">

                            <?php foreach ($semesters as $semester): ?>

                            <option value="<?= (int)$semester['id'] ?>"
                                <?= $selectedSemester === (int)$semester['id'] ? 'selected' : '' ?>>

                                <?= fee_e($semester['semester_name']) ?>
                                -
                                <?= fee_e($semester['session_name']) ?>

                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

            </form>

            <?php if ($semesterInfo): ?>

            <div class="semester-info">

                <strong>
                    <?= fee_e($semesterInfo['semester_name']) ?>
                </strong>

                &nbsp; | &nbsp;

                <?= fee_e($semesterInfo['session_name'] ?? '') ?>

                &nbsp; | &nbsp;

                Status:
                <?= fee_e(ucfirst($semesterInfo['status'])) ?>

            </div>

            <?php endif; ?>

        </div>

        <!-- Add / Edit Fee -->

        <div class="card">

            <h2>
                <?= $editFee ? 'Edit Fee Structure' : 'Add New Fee' ?>
            </h2>

            <form method="POST">

                <input type="hidden" name="csrf_token" value="<?= fee_e($csrfToken) ?>">

                <input type="hidden" name="action" value="<?= $editFee ? 'update' : 'add' ?>">

                <?php if ($editFee): ?>

                <input type="hidden" name="fee_id" value="<?= (int)$editFee['id'] ?>">

                <?php endif; ?>

                <div class="form-grid">

                    <div class="form-group">

                        <label>Semester</label>

                        <select name="semester_id" required>

                            <?php foreach ($semesters as $semester): ?>

                            <option value="<?= (int)$semester['id'] ?>"
                                <?= (int)($editFee['semester_id'] ?? $selectedSemester) === (int)$semester['id'] ? 'selected' : '' ?>>

                                <?= fee_e($semester['semester_name']) ?>
                                -
                                <?= fee_e($semester['session_name']) ?>

                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Fee Name</label>

                        <input type="text" name="fee_name" maxlength="120" placeholder="e.g. Tuition Fee"
                            value="<?= fee_e($editFee['fee_name'] ?? '') ?>" required>

                    </div>

                    <div class="form-group">

                        <label>Amount (৳)</label>

                        <input type="number" name="amount" min="0.01" step="0.01" placeholder="Enter amount"
                            value="<?= fee_e($editFee['amount'] ?? '') ?>" required>

                    </div>

                </div>

                <button type="submit" class="btn primary">

                    <?= $editFee ? 'Update Fee' : 'Add Fee' ?>

                </button>

                <?php if ($editFee): ?>

                <a href="<?= BASE_URL ?>/accounts/fee-structures.php?semester_id=<?= $selectedSemester ?>"
                    class="btn secondary">

                    Cancel Editing

                </a>

                <?php endif; ?>

            </form>

            <p class="help-text">

                Fee names must be unique within the same semester.

            </p>

        </div>

        <!-- Fee List -->

        <div class="card">

            <h2>Semester Fee Breakdown</h2>

            <?php if (empty($feeRows)): ?>

            <div class="empty">

                No fee structures have been configured for this semester.

                <br><br>

                Add Tuition Fee, Registration Fee, Examination Fee, or other charges above.

            </div>

            <?php else: ?>

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>#</th>
                            <th>Fee Name</th>
                            <th>Amount</th>
                            <th>Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($feeRows as $index => $fee): ?>

                        <tr>

                            <td>
                                <?= $index + 1 ?>
                            </td>

                            <td>
                                <strong>
                                    <?= fee_e($fee['fee_name']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= fee_money($fee['amount']) ?>
                            </td>

                            <td>

                                <div class="row-actions">

                                    <a href="<?= BASE_URL ?>/accounts/fee-structures.php?semester_id=<?= $selectedSemester ?>&edit=<?= (int)$fee['id'] ?>"
                                        class="btn secondary">

                                        Edit

                                    </a>

                                    <form method="POST" class="inline-form"
                                        onsubmit="return confirm('Are you sure you want to delete this fee structure?')">

                                        <input type="hidden" name="csrf_token" value="<?= fee_e($csrfToken) ?>">

                                        <input type="hidden" name="action" value="delete">

                                        <input type="hidden" name="fee_id" value="<?= (int)$fee['id'] ?>">

                                        <input type="hidden" name="semester_id" value="<?= $selectedSemester ?>">

                                        <button type="submit" class="btn danger">

                                            Delete

                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <tr class="total-row">

                            <td colspan="2">

                                Total Semester Fee

                            </td>

                            <td colspan="2">

                                <?= fee_money($totalFee) ?>

                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

            <?php endif; ?>

        </div>

    </div>

</body>

</html>