<?php
session_start();

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

// --- FILTER LOGIC (mirrors leave_management.php) ---
$filter_month = $_GET['filter_month'] ?? date('Y-m'); 
$filter_status = $_GET['filter_status'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';
$filter_name = $_GET['filter_name'] ?? '';

$start_date = $filter_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));
$display_month = date('F Y', strtotime($start_date));

// Construct the base query matching the history table view
$query = "
    SELECT a.*, e.full_name, e.employee_no, t.leave_name 
    FROM leave_applications a 
    JOIN employees e ON a.employee_id = e.employee_id 
    JOIN leave_types t ON a.leave_type_id = t.leave_type_id 
    WHERE (a.start_date BETWEEN ? AND ? OR a.end_date BETWEEN ? AND ?)
    AND a.status != 'Pending'
";
$params = [$start_date, $end_date, $start_date, $end_date];

// Apply additional filters
if (!empty($filter_status)) {
    $query .= " AND a.status = ?";
    $params[] = $filter_status;
}

if (!empty($filter_type)) {
    $query .= " AND a.leave_type_id = ?";
    $params[] = $filter_type;
}

if (!empty($filter_name)) {
    $query .= " AND e.full_name LIKE ?";
    $params[] = '%' . $filter_name . '%';
}

$query .= " ORDER BY a.approval_date DESC, a.start_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

$report_title = 'Leave Applications Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($report_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; padding: 30px; }
        
        /* Shared brand header styling from attendance_export */
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
            <img src="../finora_logo.png" alt="Finora">
            <span class="brand-name">FINORA</span>
        </div>
        <div style="text-align: right;">
            <h2><?= htmlspecialchars($report_title) ?></h2>
        </div>
    </div>

    <div class="subtitle">
        Period: <?= htmlspecialchars($display_month) ?>
        &nbsp;|&nbsp; Generated: <?= date('d M Y, h:i A') ?>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 20%;">Employee</th>
                <th style="width: 15%;">Leave Type</th>
                <th style="width: 20%;">Dates</th>
                <th style="width: 10%;">Days</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 25%;">Remarks</th>
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
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($rec['full_name']) ?></strong><br>
                            <span style="color: #666; font-size: 0.8rem;">#<?= htmlspecialchars($rec['employee_no']) ?></span>
                        </td>
                        <td><?= htmlspecialchars($rec['leave_name']) ?></td>
                        <td>
                            <?= !empty($rec['start_date']) ? date('d M Y', strtotime($rec['start_date'])) : 'N/A' ?> to <br>
                            <?= !empty($rec['end_date']) ? date('d M Y', strtotime($rec['end_date'])) : 'N/A' ?>
                        </td>
                        <td><?= $rec['total_days'] ?></td>
                        <td class="<?= $status_class ?>"><?= htmlspecialchars($rec['status']) ?></td>
                        <td style="font-size: 0.8rem; color: #444;">
                            <strong>Emp:</strong> <?= htmlspecialchars($rec['reason'] ?? '-') ?><br>
                            <?php if (!empty($rec['admin_remarks'])): ?>
                                <strong>Admin:</strong> <?= htmlspecialchars($rec['admin_remarks']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding: 20px; color: #777;">
                        No leave records found for this period with current filters.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>