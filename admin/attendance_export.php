<?php
session_start();

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

// --- FILTER LOGIC (mirrors attendance.php so the export matches whatever was being viewed) ---
$filter_mode = $_GET['filter_mode'] ?? 'day';
if (!in_array($filter_mode, ['day', 'month', 'year'])) {
    $filter_mode = 'day';
}

$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$selected_month = $_GET['filter_month'] ?? date('Y-m');
$filter_year = $_GET['filter_year'] ?? date('Y');
$search_name = trim($_GET['search_name'] ?? '');
$filter_department = $_GET['filter_department'] ?? '';
$employee_id = $_GET['employee_id'] ?? '';

switch ($filter_mode) {
    case 'month':
        $start_date = $selected_month . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
        $display_range = date('F Y', strtotime($start_date));
        break;
    case 'year':
        $start_date = $filter_year . '-01-01';
        $end_date = $filter_year . '-12-31';
        $display_range = $filter_year;
        break;
    case 'day':
    default:
        $start_date = $filter_date;
        $end_date = $filter_date;
        $display_range = date('d F Y', strtotime($filter_date));
        break;
}

$where = "WHERE a.attendance_date BETWEEN ? AND ?";
$params = [$start_date, $end_date];

if ($employee_id !== '') {
    $where .= " AND a.employee_id = ?";
    $params[] = $employee_id;
} else {
    // Only apply search/department filters on the "all employees" HR report
    if ($search_name !== '') {
        $where .= " AND (e.full_name LIKE ? OR e.employee_no LIKE ?)";
        $params[] = '%' . $search_name . '%';
        $params[] = '%' . $search_name . '%';
    }
    if ($filter_department !== '') {
        $where .= " AND e.department_id = ?";
        $params[] = $filter_department;
    }
}

$stmt = $pdo->prepare("
    SELECT a.*, e.full_name, e.employee_no, d.department_name AS department 
    FROM attendance a 
    JOIN employees e ON a.employee_id = e.employee_id 
    LEFT JOIN departments d ON e.department_id = d.department_id
    $where
    ORDER BY e.full_name ASC, a.attendance_date ASC
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// If a single employee was requested, grab their info for the report header
$employee_info = null;
if ($employee_id !== '') {
    $emp_stmt = $pdo->prepare("
        SELECT e.full_name, e.employee_no, d.department_name AS department 
        FROM employees e 
        LEFT JOIN departments d ON e.department_id = d.department_id 
        WHERE e.employee_id = ?
    ");
    $emp_stmt->execute([$employee_id]);
    $employee_info = $emp_stmt->fetch();
}

$report_title = $employee_info
    ? 'Attendance Report - ' . $employee_info['full_name']
    : 'Attendance Report - All Employees';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($report_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; padding: 30px; }
        
        /* Added brand header styling from reference */
        .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #10b981; padding-bottom: 15px; margin-bottom: 20px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { height: 38px; }
        .brand-name { font-weight: 700; letter-spacing: 1px; color: #10b981; font-size: 1.1rem; }
        
        h2 { margin: 0 0 4px; }
        .subtitle { color: #555; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ccc; padding: 8px 10px; font-size: 0.85rem; text-align: left; }
        th { background: #f0f0f0; }
        .badge-late { color: #b91c1c; font-weight: bold; }
        .badge-ontime { color: #047857; font-weight: bold; }
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
            <?php if ($employee_info): ?>
                <div class="subtitle" style="margin-bottom:0;">
                    Employee #<?= htmlspecialchars($employee_info['employee_no']) ?>
                    &nbsp;|&nbsp; Department: <?= htmlspecialchars($employee_info['department'] ?? 'N/A') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="subtitle">
        Period: <?= htmlspecialchars($display_range) ?>
        &nbsp;|&nbsp; Generated: <?= date('d M Y, h:i A') ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <?php if (!$employee_info): ?>
                    <th>Employee</th>
                    <th>Department</th>
                <?php endif; ?>
                <th>Clock In</th>
                <th>Clock Out</th>
                <th>Total Hours</th>
                <th>Overtime</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($records) > 0): ?>
                <?php foreach ($records as $rec): ?>
                    <?php
                        $total_hours_display = '--';
                        if (isset($rec['total_hours']) && $rec['total_hours'] !== null) {
                            $t = (float) $rec['total_hours'];
                            $h = floor($t);
                            $m = round(($t - $h) * 60);
                            $total_hours_display = "{$h}h {$m}m";
                        }

                        $ot_display = '-';
                        if (isset($rec['overtime_hours']) && $rec['overtime_hours'] > 0) {
                            $ot = (float) $rec['overtime_hours'];
                            $ot_h = floor($ot);
                            $ot_m = round(($ot - $ot_h) * 60);
                            $ot_display = "{$ot_h}h {$ot_m}m";
                        }

                        $status_class = $rec['status'] === 'Late' ? 'badge-late' : ($rec['status'] === 'On Time' ? 'badge-ontime' : '');
                    ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($rec['attendance_date'])) ?></td>
                        <?php if (!$employee_info): ?>
                            <td><?= htmlspecialchars($rec['full_name']) ?> (#<?= htmlspecialchars($rec['employee_no']) ?>)</td>
                            <td><?= htmlspecialchars($rec['department'] ?? 'N/A') ?></td>
                        <?php endif; ?>
                        <td><?= $rec['clock_in'] ? date('h:i A', strtotime($rec['clock_in'])) : '--:--' ?></td>
                        <td><?= $rec['clock_out'] ? date('h:i A', strtotime($rec['clock_out'])) : '--:--' ?></td>
                        <td><?= $total_hours_display ?></td>
                        <td><?= $ot_display ?></td>
                        <td class="<?= $status_class ?>"><?= htmlspecialchars($rec['status'] ?? 'Unknown') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?= $employee_info ? 6 : 8 ?>" style="text-align:center; padding: 20px; color: #777;">
                        No attendance records found for this period.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>