<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

$emp_id = $_SESSION['user_id'];
$current_year = date('Y');
$message = '';
$messageType = '';

// --- 1. FETCH EMPLOYEE DETAILS ---
$emp_stmt = $pdo->prepare("SELECT gender, religion, marital_status, employment_status FROM employees WHERE employee_id = ?");
$emp_stmt->execute([$emp_id]);
$employee = $emp_stmt->fetch();

// --- 2. DETERMINE ALLOWED LEAVE TYPES FIRST ---
$types_stmt = $pdo->query("SELECT * FROM leave_types");
$all_leave_types = $types_stmt->fetchAll();

$allowed_leave_type_ids = [];
foreach ($all_leave_types as $type) {
    // Probation check
    if ($employee['employment_status'] === 'On Probation' && $type['leave_name'] === 'Unpaid Leave') continue;
    // Gender check
    if ($type['eligible_gender'] != 'All' && $type['eligible_gender'] != $employee['gender']) continue;
    // Religion check (e.g. Haji Leave is Islam-only)
    if ($type['eligible_religion'] != 'All' && $type['eligible_religion'] != $employee['religion']) continue;
    // Marital Status check
    if (($type['leave_name'] == 'Maternity Leave' || $type['leave_name'] == 'Paternity Leave') && $employee['marital_status'] != 'Married') continue;

    $allowed_leave_type_ids[] = $type['leave_type_id'];
}

// --- 2b. FIND CONSECUTIVE-TYPE LEAVES THAT ARE ALREADY LOCKED ---
// Marital/Maternity/Paternity must be taken as ONE continuous block - once an
// application exists (Pending or Approved), block further applications of that
// same type until it's Rejected/Cancelled. Regular Consecutive types are locked
// per calendar year; one_time_only types (e.g. Haji Leave) are locked for life.
$locked_stmt = $pdo->prepare("
    SELECT DISTINCT la.leave_type_id 
    FROM leave_applications la 
    JOIN leave_types t ON la.leave_type_id = t.leave_type_id 
    WHERE la.employee_id = ? 
      AND t.leave_category = 'Consecutive' 
      AND la.status IN ('Pending', 'Approved') 
      AND (t.one_time_only = 1 OR YEAR(la.start_date) = ?)
");
$locked_stmt->execute([$emp_id, $current_year]);
$locked_type_ids = $locked_stmt->fetchAll(PDO::FETCH_COLUMN);

// --- 3. INITIALIZE BALANCES ---
// Regular types get a fresh balance every calendar year. one_time_only types
// (e.g. Haji Leave) are granted exactly once and live under leave_year = 0
// ("lifetime" sentinel) so they're never reset or re-granted.
foreach ($all_leave_types as $type) {
    $entitlement = $type['total_days'];
    if ($employee['employment_status'] === 'On Probation' && $type['leave_name'] === 'Annual Leave') {
        $entitlement = floor($entitlement / 2);
    }

    $balance_year = $type['one_time_only'] ? '0000' : $current_year;

    $check = $pdo->prepare("SELECT balance_id FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?");
    $check->execute([$emp_id, $type['leave_type_id'], $balance_year]);

    if ($check->rowCount() == 0) {
        $insert = $pdo->prepare("INSERT INTO leave_balances (employee_id, leave_type_id, leave_year, entitlement, remaining_balance) VALUES (?, ?, ?, ?, ?)");
        $insert->execute([$emp_id, $type['leave_type_id'], $balance_year, $entitlement, $entitlement]);
    }
}

// --- 4. HANDLE FORM SUBMISSION ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {

    if ($_POST['action'] == 'apply_leave') {
        $leave_type_id = $_POST['leave_type_id'];
        $date_range = $_POST['date_range'] ?? '';
        $reason = $_POST['reason'];

        // Check if this is a Consecutive type that's already locked
        $type_check = $pdo->prepare("SELECT leave_name, leave_category, attachment_required, one_time_only FROM leave_types WHERE leave_type_id = ?");
        $type_check->execute([$leave_type_id]);
        $selected_type_info = $type_check->fetch();
        $balance_year = ($selected_type_info && $selected_type_info['one_time_only']) ? '0000' : $current_year;

        if ($selected_type_info && $selected_type_info['leave_category'] === 'Consecutive' && in_array($leave_type_id, $locked_type_ids)) {
            $lock_msg = $selected_type_info['one_time_only']
                ? " can only be applied for once. You already have a pending or approved application for it - cancel that one first if you need to change the dates."
                : " must be taken as a single continuous period. You already have a pending or approved application for it this year - cancel that one first if you need to change the dates.";
            $message = htmlspecialchars($selected_type_info['leave_name']) . $lock_msg;
            $messageType = "danger";
        } elseif ($selected_type_info && $selected_type_info['attachment_required'] == 1 && (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] != 0 || empty($_FILES['attachment']['name']))) {
            $message = htmlspecialchars($selected_type_info['leave_name']) . " requires a supporting document attachment (e.g. medical certificate).";
            $messageType = "danger";
        } elseif (empty($date_range)) {
            $message = "Error: Please select a valid date range from the calendar.";
            $messageType = "danger";
        } else {
            // Extract dates from flatpickr range "YYYY-MM-DD to YYYY-MM-DD"
            $dates = explode(' to ', $date_range);
            $start_date = trim($dates[0]);
            $end_date = isset($dates[1]) ? trim($dates[1]) : $start_date;

            if (!empty($start_date) && !empty($end_date)) {
                $total_days = round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1;

                $bal_stmt = $pdo->prepare("SELECT remaining_balance FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?");
                $bal_stmt->execute([$emp_id, $leave_type_id, $balance_year]);
                $balance = $bal_stmt->fetchColumn();

                if ($balance >= $total_days || $leave_type_id == 11) { // 11 is Unpaid Leave (if you don't limit unpaid)

                    // Handle File Upload
                    $attachmentPath = null;
                    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
                        $uploadDir = '../uploads/leaves/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                        $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "_", basename($_FILES['attachment']['name']));
                        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $fileName)) {
                            $attachmentPath = $fileName;
                        }
                    }

                    try {
                        $insert_leave = $pdo->prepare("INSERT INTO leave_applications (employee_id, leave_type_id, start_date, end_date, total_days, reason, attachment, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')");
                        $insert_leave->execute([$emp_id, $leave_type_id, $start_date, $end_date, $total_days, $reason, $attachmentPath]);

                        $update_bal = $pdo->prepare("UPDATE leave_balances SET pending_leave = pending_leave + ?, remaining_balance = remaining_balance - ? WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?");
                        $update_bal->execute([$total_days, $total_days, $emp_id, $leave_type_id, $balance_year]);

                        $message = "Leave application submitted successfully! Pending HR approval.";
                        $messageType = "success";
                    } catch (PDOException $e) {
                        $message = "Database Error: " . $e->getMessage();
                        $messageType = "danger";
                    }
                } else {
                    $message = "Insufficient balance. You applied for $total_days days but only have $balance remaining.";
                    $messageType = "warning";
                }
            } else {
                $message = "Invalid dates provided.";
                $messageType = "danger";
            }
        }
    } elseif ($_POST['action'] == 'cancel_leave') {
        $cancel_id = $_POST['leave_id'];
        $chk_stmt = $pdo->prepare("
            SELECT la.*, t.one_time_only 
            FROM leave_applications la 
            JOIN leave_types t ON la.leave_type_id = t.leave_type_id 
            WHERE la.leave_id = ? AND la.employee_id = ? AND la.status = 'Pending'
        ");
        $chk_stmt->execute([$cancel_id, $emp_id]);
        $cancel_req = $chk_stmt->fetch();

        if ($cancel_req) {
            $refund_year = $cancel_req['one_time_only'] ? '0000' : $current_year;
            $pdo->prepare("UPDATE leave_applications SET status = 'Cancelled' WHERE leave_id = ?")->execute([$cancel_id]);
            $refund_stmt = $pdo->prepare("UPDATE leave_balances SET pending_leave = pending_leave - ?, remaining_balance = remaining_balance + ? WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?");
            $refund_stmt->execute([$cancel_req['total_days'], $cancel_req['total_days'], $emp_id, $cancel_req['leave_type_id'], $refund_year]);
            $message = "Leave application cancelled and balance restored.";
            $messageType = "info";
        }
    }
}

// --- 5. FETCH FILTERED DATA ---
// Only fetch balances that the user is actually allowed to see.
// one_time_only types live under leave_year = 0 (lifetime), so they need an OR
// condition rather than the plain current-year match regular types use.
$my_balances = $pdo->prepare("
    SELECT b.*, t.leave_name, t.attachment_required, t.one_time_only 
    FROM leave_balances b 
    JOIN leave_types t ON b.leave_type_id = t.leave_type_id 
    WHERE b.employee_id = ? 
      AND (b.leave_year = ? OR (t.one_time_only = 1 AND b.leave_year = '0000'))
");
$my_balances->execute([$emp_id, $current_year]);
$raw_balances = $my_balances->fetchAll();

// Purge any un-allowed leave balances from the interface
$balances = [];
foreach ($raw_balances as $b) {
    if (in_array($b['leave_type_id'], $allowed_leave_type_ids)) {
        $balances[] = $b;
    }
}

// --- GET ALL HISTORY FOR TOP STATS CARDS ---
$stat_stmt = $pdo->prepare("SELECT status FROM leave_applications WHERE employee_id = ?");
$stat_stmt->execute([$emp_id]);
$all_history = $stat_stmt->fetchAll();

$stats = ['total' => count($all_history), 'approved' => 0, 'pending' => 0, 'rejected' => 0];
foreach ($all_history as $h) {
    if ($h['status'] == 'Approved') $stats['approved']++;
    if ($h['status'] == 'Pending') $stats['pending']++;
    if ($h['status'] == 'Rejected' || $h['status'] == 'Cancelled') $stats['rejected']++;
}

// --- DYNAMIC TABLE FILTERS ---
$filter_year = $_GET['filter_year'] ?? $current_year;
$filter_status = $_GET['filter_status'] ?? 'All';
$filter_leave_type = $_GET['filter_leave_type'] ?? 'All'; // NEW: Filter for specific leave type in table

$query = "SELECT a.*, t.leave_name FROM leave_applications a JOIN leave_types t ON a.leave_type_id = t.leave_type_id WHERE a.employee_id = ?";
$params = [$emp_id];

if ($filter_year !== 'All') {
    $query .= " AND YEAR(a.start_date) = ?";
    $params[] = $filter_year;
}

if ($filter_status !== 'All') {
    if ($filter_status === 'Rejected') {
        $query .= " AND (a.status = 'Rejected' OR a.status = 'Cancelled')";
    } else {
        $query .= " AND a.status = ?";
        $params[] = $filter_status;
    }
}

// NEW: Add leave type condition to query
if ($filter_leave_type !== 'All') {
    $query .= " AND a.leave_type_id = ?";
    $params[] = $filter_leave_type;
}

$query .= " ORDER BY a.created_at DESC";
$my_history = $pdo->prepare($query);
$my_history->execute($params);
$history = $my_history->fetchAll();

// --- DONUT CHART SELECTION ---
// Default view is 'all' - a summed total across every allowed leave type.
// Selecting a specific type from the dropdown switches to that type's own numbers.
$selected_type_id = $_GET['view_type'] ?? 'all';

if ($selected_type_id === 'all') {
    $display_label = 'All Leave Types';
    $display_entitlement = array_sum(array_column($balances, 'entitlement'));
    $display_remaining = array_sum(array_column($balances, 'remaining_balance'));
    $display_taken = array_sum(array_column($balances, 'leave_taken'));
} else {
    $active_balance = null;
    foreach ($balances as $b) {
        if ($b['leave_type_id'] == $selected_type_id) {
            $active_balance = $b;
            break;
        }
    }
    // Fall back to 'all' if the requested type isn't valid/allowed for this employee
    if ($active_balance === null) {
        $selected_type_id = 'all';
        $display_label = 'All Leave Types';
        $display_entitlement = array_sum(array_column($balances, 'entitlement'));
        $display_remaining = array_sum(array_column($balances, 'remaining_balance'));
        $display_taken = array_sum(array_column($balances, 'leave_taken'));
    } else {
        $display_label = $active_balance['leave_name'];
        $display_entitlement = $active_balance['entitlement'];
        $display_remaining = $active_balance['remaining_balance'];
        $display_taken = $active_balance['leave_taken'];
    }
}

$total_entitlement = max(1, $display_entitlement);
$rem_pct = ($display_remaining / $total_entitlement) * 100;
$used_pct = ($display_taken / $total_entitlement) * 100;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Leave Requests - Finora Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <!-- Flatpickr Calendar CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" type="text/css" href="https://npmcdn.com/flatpickr/dist/themes/dark.css">

    <style>
        :root {
            --bg-main: #0b0d14;
            --bg-panel: #131620;
            --bg-card: #1a1e2b;
            --emerald-neon: #10b981;
            --emerald-glow: rgba(16, 185, 129, 0.4);
            --chart-rem: #4f46e5;
            --chart-used: #eab308;
            --danger-neon: #ef4444;
        }

        body {
            background-color: var(--bg-main);
            color: #ffffff;
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            flex-grow: 1;
            padding: 40px;
        }

        .panel-card {
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            overflow: hidden;
        }

        .stat-card {
            background: var(--bg-panel);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
        }

        /* Form Overrides for Perfect Visibility */
        .form-control,
        .form-select {
            background-color: var(--bg-main) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            color: #ffffff !important;
            border-radius: 8px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--emerald-neon) !important;
            box-shadow: 0 0 10px var(--emerald-glow) !important;
        }

        .form-select option {
            background-color: var(--bg-card);
            color: #ffffff;
        }

        ::placeholder {
            color: #ffffff !important;
            opacity: 0.6 !important;
        }

        /* Table Styles */
        .table-dark-custom {
            color: #ffffff;
            margin-bottom: 0;
        }

        .table-dark-custom thead th {
            background-color: rgba(0, 0, 0, 0.2);
            color: #a1a1aa;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            font-weight: 500;
            font-size: 0.85rem;
            padding: 15px;
        }

        .table-dark-custom tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .table-dark-custom tbody td {
            padding: 15px;
            background: transparent;
            color: #ffffff;
        }

        /* Charts */
        .circular-chart {
            position: relative;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
        }

        .circular-chart::before {
            content: "";
            position: absolute;
            width: 70px;
            height: 70px;
            background-color: var(--bg-card);
            border-radius: 50%;
        }

        .circular-chart-value {
            position: relative;
            font-size: 1.2rem;
            font-weight: 700;
            color: #ffffff;
            text-align: center;
            line-height: 1.2;
        }

        .donut-rem {
            background: conic-gradient(var(--chart-rem) calc(var(--percentage) * 1%), #1f2937 0);
        }

        .donut-used {
            background: conic-gradient(var(--chart-used) calc(var(--percentage) * 1%), #1f2937 0);
        }

        /* Modal specific overrides */
        .modal-content {
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background-color: var(--bg-card);
            overflow: hidden;
        }

        .flatpickr-calendar.inline {
            background: transparent;
            border: none;
            box-shadow: none;
            width: 100%;
        }

        .flatpickr-innerContainer {
            justify-content: center;
        }
    </style>
</head>

<body>
    <div class="d-flex">
        <?php require 'sidebar.php'; ?>

        <div class="main-content">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold text-white mb-0">Leave Requests</h3>
                <button class="btn text-white fw-medium px-4 py-2 rounded-pill" style="background: var(--chart-rem);" data-bs-toggle="modal" data-bs-target="#applyModal">Apply Leave</button>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> bg-transparent border-<?= $messageType ?> text-white rounded-3">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <!-- Dashboard Analytics -->
            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="panel-card p-4 d-flex justify-content-between align-items-center h-100">
                        <div>
                            <div class="text-white fw-bold mb-1">Leave Allowance</div>
                            <h2 class="fw-bold text-white mb-4"><?= $display_entitlement ?> <span class="fs-6 text-white fw-normal">Days</span></h2>
                            <form method="GET">
                                <!-- Keep status filter intact when switching types -->
                                <input type="hidden" name="filter_year" value="<?= htmlspecialchars($filter_year) ?>">
                                <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filter_status) ?>">
                                <!-- Keep the new type filter intact as well -->
                                <input type="hidden" name="filter_leave_type" value="<?= htmlspecialchars($filter_leave_type) ?>">

                                <select class="form-select w-auto" name="view_type" onchange="this.form.submit()">
                                    <option value="all" <?= $selected_type_id === 'all' ? 'selected' : '' ?>>All Leave Types</option>
                                    <?php foreach ($balances as $b): ?>
                                        <option value="<?= $b['leave_type_id'] ?>" <?= (string)$selected_type_id === (string)$b['leave_type_id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['leave_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div class="d-flex gap-4 text-center">
                            <div>
                                <div class="text-white fw-semibold mb-2">Remaining</div>
                                <div class="circular-chart donut-rem" style="--percentage: <?= $rem_pct ?>;">
                                    <div class="circular-chart-value"><?= $display_remaining ?><br><span style="font-size: 0.6rem; color:#a1a1aa;">Days</span></div>
                                </div>
                            </div>
                            <div>
                                <div class="text-white fw-semibold mb-2">Used</div>
                                <div class="circular-chart donut-used" style="--percentage: <?= $used_pct ?>;">
                                    <div class="circular-chart-value"><?= $display_taken ?><br><span style="font-size: 0.6rem; color:#a1a1aa;">Days</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="row row-cols-2 g-3 h-100">
                        <div class="col">
                            <div class="stat-card p-3 text-center h-100"><i class="bi bi-check-circle text-success fs-3"></i>
                                <h4 class="text-white mt-2 mb-0"><?= $stats['approved'] ?></h4><small class="text-white">Approved</small>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-card p-3 text-center h-100"><i class="bi bi-hourglass-split text-warning fs-3"></i>
                                <h4 class="text-white mt-2 mb-0"><?= $stats['pending'] ?></h4><small class="text-white">Pending</small>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-card p-3 text-center h-100"><i class="bi bi-x-circle text-danger fs-3"></i>
                                <h4 class="text-white mt-2 mb-0"><?= $stats['rejected'] ?></h4><small class="text-white">Declined/Cancelled</small>
                            </div>
                        </div>
                        <div class="col">
                            <div class="stat-card p-3 text-center h-100"><i class="bi bi-folder text-primary fs-3"></i>
                                <h4 class="text-white mt-2 mb-0"><?= $stats['total'] ?></h4><small class="text-white">Total Requests</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- History Table -->
            <div class="panel-card">
                <div class="d-flex flex-wrap justify-content-between align-items-center p-3 border-bottom border-secondary" style="border-color: rgba(255,255,255,0.05) !important;">
                    <form method="GET" class="d-flex flex-wrap align-items-center gap-3 m-0">
                        <!-- Keep the active donut chart view state -->
                        <input type="hidden" name="view_type" value="<?= htmlspecialchars($selected_type_id) ?>">

                        <select name="filter_year" class="form-select form-select-sm w-auto rounded-pill px-3 py-2" style="background: rgba(255,255,255,0.05) !important; border-color: rgba(255,255,255,0.2) !important; color: #fff !important; cursor: pointer;" onchange="this.form.submit()">
                            <option value="All" class="bg-dark" <?= $filter_year === 'All' ? 'selected' : '' ?>>All Years</option>
                            <option value="<?= $current_year ?>" class="bg-dark" <?= (string)$filter_year === (string)$current_year ? 'selected' : '' ?>><?= $current_year ?></option>
                            <option value="<?= $current_year - 1 ?>" class="bg-dark" <?= (string)$filter_year === (string)($current_year - 1) ? 'selected' : '' ?>><?= $current_year - 1 ?></option>
                        </select>

                        <!-- NEW: Leave Type Filter for the Table -->
                        <select name="filter_leave_type" class="form-select form-select-sm w-auto rounded-pill px-3 py-2" style="background: rgba(255,255,255,0.05) !important; border-color: rgba(255,255,255,0.2) !important; color: #fff !important; cursor: pointer;" onchange="this.form.submit()">
                            <option value="All" class="bg-dark" <?= $filter_leave_type === 'All' ? 'selected' : '' ?>>All Leave Types</option>
                            <?php foreach ($balances as $b): ?>
                                <option value="<?= $b['leave_type_id'] ?>" class="bg-dark" <?= (string)$filter_leave_type === (string)$b['leave_type_id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['leave_name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="filter_status" class="form-select form-select-sm w-auto rounded-pill px-3 py-2" style="background: rgba(255,255,255,0.05) !important; border-color: rgba(255,255,255,0.2) !important; color: #fff !important; cursor: pointer;" onchange="this.form.submit()">
                            <option value="All" class="bg-dark" <?= $filter_status === 'All' ? 'selected' : '' ?>>All Statuses (<?= $stats['total'] ?>)</option>
                            <option value="Approved" class="bg-dark" <?= $filter_status === 'Approved' ? 'selected' : '' ?>>Approved (<?= $stats['approved'] ?>)</option>
                            <option value="Pending" class="bg-dark" <?= $filter_status === 'Pending' ? 'selected' : '' ?>>Pending (<?= $stats['pending'] ?>)</option>
                            <option value="Rejected" class="bg-dark" <?= $filter_status === 'Rejected' ? 'selected' : '' ?>>Declined (<?= $stats['rejected'] ?>)</option>
                        </select>
                    </form>

                    <form method="GET" action="leave_export.php" target="_blank" class="m-0 mt-3 mt-md-0">
                        <input type="hidden" name="filter_year" value="<?= htmlspecialchars($filter_year) ?>">
                        <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filter_status) ?>">
                        <!-- Include the new type filter in the export too -->
                        <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_leave_type) ?>">
                        <button type="submit" class="btn text-white rounded-pill px-4 py-2" style="background: #4f46e5; border: none; font-size: 0.85rem; font-weight: 500;">
                            <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                        </button>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-dark-custom">
                        <thead>
                            <tr>
                                <th>Leave Type</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($history) > 0): foreach ($history as $h): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($h['leave_name']) ?></td>
                                        <td><?= !empty($h['start_date']) ? date('d/m/Y', strtotime($h['start_date'])) : 'Invalid' ?></td>
                                        <td><?= !empty($h['end_date']) ? date('d/m/Y', strtotime($h['end_date'])) : 'Invalid' ?></td>
                                        <td><?= $h['total_days'] ?> Days</td>
                                        <td>
                                            <?php if ($h['status'] == 'Approved'): ?> <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i> Approved</span>
                                            <?php elseif ($h['status'] == 'Pending'): ?> <span class="text-warning"><i class="bi bi-circle-fill me-1"></i> Pending</span>
                                            <?php else: ?> <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i> <?= $h['status'] ?></span> <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($h['status'] == 'Pending'): ?>
                                                <form method="POST" onsubmit="return confirm('Cancel this request?');">
                                                    <input type="hidden" name="action" value="cancel_leave">
                                                    <input type="hidden" name="leave_id" value="<?= $h['leave_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <i class="bi bi-lock text-white"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="6" class="text-center p-4 text-white">No leave applications found matching this filter.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Redesigned Apply Leave Modal -->
    <div class="modal fade" id="applyModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-bottom border-secondary px-4 py-3">
                    <h5 class="modal-title fw-bold text-white">Apply for Leave</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="leaveApplicationForm">
                    <div class="modal-body p-0">
                        <input type="hidden" name="action" value="apply_leave">
                        <!-- Hidden date range captured from flatpickr -->
                        <input type="hidden" name="date_range" id="hiddenDateRange">

                        <div class="row g-0">
                            <!-- Left Column: Form Inputs -->
                            <div class="col-md-6 p-4 border-end border-secondary" style="border-color: rgba(255,255,255,0.05) !important;">

                                <div class="mb-4">
                                    <label class="form-label text-white small text-uppercase">Leave Type</label>
                                    <select class="form-select" name="leave_type_id" id="leaveTypeSelect" required>
                                        <option value="" data-remaining="0">Select type...</option>
                                        <?php foreach ($balances as $b): ?>
                                            <?php $is_locked = in_array($b['leave_type_id'], $locked_type_ids); ?>
                                            <option value="<?= $b['leave_type_id'] ?>" data-remaining="<?= $b['remaining_balance'] ?>" data-type="<?= $b['leave_type_id'] ?>" data-attachment-required="<?= $b['attachment_required'] ?>" <?= $is_locked ? 'disabled' : '' ?>>
                                                <?= htmlspecialchars($b['leave_name']) ?> (Remaining: <?= $b['remaining_balance'] ?>/<?= $b['entitlement'] ?>)<?php
                                                                                                                                                                if ($is_locked) {
                                                                                                                                                                    echo $b['one_time_only'] ? ' - Already applied (one-time only)' : ' - Already applied this year';
                                                                                                                                                                }
                                                                                                                                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label text-white small text-uppercase">Reason for leave</label>
                                    <textarea name="reason" class="form-control" rows="5" placeholder="Type your reason..." required></textarea>
                                </div>

                                <div class="mb-3 d-none" id="attachmentSection">
                                    <label class="form-label text-white small text-uppercase">Attachment Upload <span class="text-danger">(Required)</span></label>
                                    <input type="file" name="attachment" id="attachmentInput" class="form-control text-muted">
                                    <small class="text-white d-block mt-1" style="font-size: 0.75rem;">This leave type requires a supporting document (e.g. medical certificate).</small>
                                </div>

                            </div>

                            <!-- Right Column: Interactive Inline Calendar & Data -->
                            <div class="col-md-6 p-4" style="background: rgba(0,0,0,0.2);">

                                <div class="d-flex justify-content-around mb-4 border-bottom border-secondary pb-4" style="border-color: rgba(255,255,255,0.05) !important;">
                                    <div class="text-center">
                                        <div class="text-white small text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 1px;">Leaves Remaining</div>
                                        <div class="fs-1 fw-bold text-emerald" id="uiRemaining">--</div>
                                    </div>
                                    <div style="width: 1px; background: rgba(255,255,255,0.1);"></div>
                                    <div class="text-center">
                                        <div class="text-white small text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 1px;">Selected Days</div>
                                        <div class="fs-1 fw-bold text-white" id="uiSelectedDays">0</div>
                                    </div>
                                </div>

                                <!-- Inline Flatpickr Container -->
                                <div id="inlineCalendarContainer" class="d-flex justify-content-center"></div>

                                <!-- Error Alert (Shows if selected days > balance) -->
                                <div id="leaveError" class="alert alert-danger d-none py-2 px-3 mt-4 mb-0 text-center rounded-3" style="font-size: 0.85rem; border: 1px solid var(--danger-neon);"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top border-secondary px-4 py-3" style="border-color: rgba(255,255,255,0.05) !important; background: rgba(0,0,0,0.1);">
                        <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn text-white rounded-pill px-4 fw-medium" style="background: var(--chart-rem);" id="submitLeaveBtn" disabled>Send Request <i class="bi bi-send-fill ms-1"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // DOM Elements
        const leaveTypeSelect = document.getElementById('leaveTypeSelect');
        const uiRemaining = document.getElementById('uiRemaining');
        const uiSelectedDays = document.getElementById('uiSelectedDays');
        const hiddenDateRange = document.getElementById('hiddenDateRange');
        const submitBtn = document.getElementById('submitLeaveBtn');
        const leaveError = document.getElementById('leaveError');
        const attachmentSection = document.getElementById('attachmentSection');
        const attachmentInput = document.getElementById('attachmentInput');

        let selectedDaysCount = 0;

        // Validation Function
        function validateLeave() {
            const option = leaveTypeSelect.options[leaveTypeSelect.selectedIndex];
            const remaining = option.value ? parseInt(option.getAttribute('data-remaining')) : 0;
            const typeId = option.getAttribute('data-type');
            const attachmentRequired = option.value && option.getAttribute('data-attachment-required') === '1';

            // Check if user has selected a valid date and a type
            if (!option.value || selectedDaysCount === 0) {
                submitBtn.disabled = true;
                leaveError.classList.add('d-none');
                return;
            }

            // Unpaid leave (Type 11) usually does not strictly block, but let's enforce logic
            if (selectedDaysCount > remaining && typeId !== "11") {
                submitBtn.disabled = true;
                leaveError.textContent = `Insufficient balance. You selected ${selectedDaysCount} days but only have ${remaining} days remaining.`;
                leaveError.classList.remove('d-none');
                return;
            }

            // Block submit until a file is attached, for types that require one
            if (attachmentRequired && (!attachmentInput.files || attachmentInput.files.length === 0)) {
                submitBtn.disabled = true;
                leaveError.textContent = `This leave type requires a supporting document. Please upload an attachment.`;
                leaveError.classList.remove('d-none');
                return;
            }

            submitBtn.disabled = false;
            leaveError.classList.add('d-none');
        }

        // Leave Type Dropdown Listener
        leaveTypeSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const remaining = option.value ? option.getAttribute('data-remaining') : '--';
            uiRemaining.textContent = remaining;

            // Show/hide + toggle required on the attachment field based on the selected type
            const attachmentRequired = option.value && option.getAttribute('data-attachment-required') === '1';
            if (attachmentRequired) {
                attachmentSection.classList.remove('d-none');
                attachmentInput.setAttribute('required', 'required');
            } else {
                attachmentSection.classList.add('d-none');
                attachmentInput.removeAttribute('required');
                attachmentInput.value = ''; // clear any previously selected file for a now-non-required type
            }

            validateLeave();
        });

        // Re-validate as soon as a file is chosen (so the button unlocks immediately)
        attachmentInput.addEventListener('change', validateLeave);

        // Initialize inline Flatpickr
        flatpickr("#inlineCalendarContainer", {
            mode: "range",
            inline: true,
            showMonths: 1,
            minDate: "today",
            onChange: function(selectedDates, dateStr, instance) {
                hiddenDateRange.value = dateStr;

                if (selectedDates.length === 2) {
                    const diffTime = Math.abs(selectedDates[1] - selectedDates[0]);
                    selectedDaysCount = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                } else if (selectedDates.length === 1) {
                    selectedDaysCount = 1;
                } else {
                    selectedDaysCount = 0;
                }

                uiSelectedDays.textContent = selectedDaysCount;
                validateLeave();
            }
        });
    </script>
</body>

</html>