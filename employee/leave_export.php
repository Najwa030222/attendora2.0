<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

$emp_id = $_SESSION['user_id'];
$current_year = date('Y');

// --- FILTER LOGIC (mirrors employee/leave.php) ---
$filter_year = $_GET['filter_year'] ?? $current_year;
$filter_status = $_GET['filter_status'] ?? 'All';
$filter_type = $_GET['filter_type'] ?? 'All';

$query = "SELECT a.*, t.leave_name FROM leave_applications a JOIN leave_types t ON a.leave_type_id = t.leave_type_id WHERE a.employee_id = ?";
$params = [$emp_id];

// Apply Year Filter
if ($filter_year !== 'All') {
    $query .= " AND YEAR(a.start_date) = ?";
    $params[] = $filter_year;
    $display_year = $filter_year;
} else {
    $display_year = "All Years";
}

// Apply Leave Type Filter
if ($filter_type !== 'All') {
    $query .= " AND a.leave_type_id = ?";
    $params[] = $filter_type;
}

// Apply Status Filter
if ($filter_status !== 'All') {
    if ($filter_status === 'Rejected') {
        $query .= " AND (a.status = 'Rejected' OR a.status = 'Cancelled')";
    } else {
        $query .= " AND a.status = ?";
        $params[] = $filter_status;
    }
    $display_status = $filter_status;
} else {
    $display_status = "All Statuses";
}

$query .= " ORDER BY a.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Fetch employee info for the report header
$emp_stmt = $pdo->prepare("
    SELECT e.full_name, e.employee_no, d.department_name AS department 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.department_id 
    WHERE e.employee_id = ?
");
$emp_stmt->execute([$emp_id]);
$employee_info = $emp_stmt->fetch();

$report_title = 'My Leave History';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($report_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; padding: 30px; }
        
        /* Shared brand header styling */
        .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #10b981; padding-bottom: 15px; margin-bottom: 20px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { height: 38px; }
        .brand-name { font-weight: 700; letter-spacing: 1px; color: #10b981; font-size: 1.1rem; }
        
        h2 { margin: 0 0 4px; }
        .subtitle { color: #555; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ccc; padding: 8px 10px; font-size: 0.85rem; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; }
        .badge-approved { color: #047857; font-weight: bold; }
        .badge-rejected { color: #b91c1c; font-weight: bold; }
        .badge-cancelled { color: #6b7280; font-style: italic; }
        .badge-pending { color: #b45309; font-weight: bold; }
        .no-print { margin-bottom: 20px; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button class="btn btn-success" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <!-- Integrated Finora Logo Header -->
    <div class="report-header">
        <div class="brand">
            <img src="../finora_logo.png" alt="Finora" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'38\' height=\'38\' viewBox=\'0 0 24 24\' fill=\'%2310b981\'%3E%3Cpath d=\'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5\'/%3E%3C/svg%3E';">
            <span class="brand-name">FINORA</span>
        </div>
        <div style="text-align: right;">
            <h2><?= htmlspecialchars($report_title) ?></h2>
            <div class="subtitle" style="margin-bottom:0;">
                <?= htmlspecialchars($employee_info['full_name'] ?? 'Unknown Employee') ?> (#<?= htmlspecialchars($employee_info['employee_no'] ?? 'N/A') ?>)
                <br>
                Department: <?= htmlspecialchars($employee_info['department'] ?? 'N/A') ?>
            </div>
        </div>
    </div>

    <div class="subtitle">
        Year: <?= htmlspecialchars($display_year) ?>
        &nbsp;|&nbsp; Status: <?= htmlspecialchars($display_status) ?>
        &nbsp;|&nbsp; Generated: <?= date('d M Y, h:i A') ?>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 15%;">Leave Type</th>
                <th style="width: 25%;">Dates</th>
                <th style="width: 10%;">Duration</th>
                <th style="width: 15%;">Status</th>
                <th style="width: 35%;">Reason / Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($records) > 0): ?>
                <?php foreach ($records as $rec): ?>
                    <?php
                        $status_class = '';
                        if ($rec['status'] === 'Approved') $status_class = 'badge-approved';
                        elseif ($rec['status'] === 'Rejected') $status_class = 'badge-rejected';
                        elseif ($rec['status'] === 'Cancelled') $status_class = 'badge-cancelled';
                        elseif ($rec['status'] === 'Pending') $status_class = 'badge-pending';
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($rec['leave_name']) ?></strong></td>
                        <td>
                            <?= !empty($rec['start_date']) ? date('d M Y', strtotime($rec['start_date'])) : 'N/A' ?> 
                            <?php if ($rec['start_date'] != $rec['end_date']) echo " to <br>" . (!empty($rec['end_date']) ? date('d M Y', strtotime($rec['end_date'])) : 'N/A'); ?>
                        </td>
                        <td><?= $rec['total_days'] ?> Days</td>
                        <td class="<?= $status_class ?>"><?= htmlspecialchars($rec['status']) ?></td>
                        <td style="font-size: 0.8rem; color: #444;">
                            <strong>Me:</strong> <?= htmlspecialchars($rec['reason'] ?? '-') ?><br>
                            <?php if (!empty($rec['admin_remarks'])): ?>
                                <strong style="color:#b91c1c;">Admin:</strong> <?= htmlspecialchars($rec['admin_remarks']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding: 20px; color: #777;">
                        No leave applications found matching your current filters.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>