<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

$emp_id = $_SESSION['user_id'];

// --- FILTER: MONTH ONLY (employees export their own history by month) ---
$selected_month = $_GET['filter_month'] ?? date('Y-m');
$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));
$display_range = date('F Y', strtotime($start_date));

// Employee info for the report header
$emp_stmt = $pdo->prepare("
    SELECT e.full_name, e.employee_no, d.department_name AS department 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.department_id 
    WHERE e.employee_id = ?
");
$emp_stmt->execute([$emp_id]);
$employee_info = $emp_stmt->fetch();

// OT eligibility (same rule used everywhere else)
$can_ot = false;
if ($employee_info) {
    $ot_stmt = $pdo->prepare("
        SELECT d.overtime_eligible FROM employees e 
        JOIN departments d ON e.department_id = d.department_id 
        WHERE e.employee_id = ?
    ");
    $ot_stmt->execute([$emp_id]);
    $can_ot = (bool) $ot_stmt->fetchColumn();
}

$stmt = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ORDER BY attendance_date ASC
");
$stmt->execute([$emp_id, $start_date, $end_date]);
$records = $stmt->fetchAll();

$report_title = 'My Attendance Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($report_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; padding: 30px; }
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

    <div class="report-header">
        <div class="brand">
            <img src="../finora_logo.png" alt="Finora">
            <span class="brand-name">FINORA</span>
        </div>
        <div style="text-align: right;">
            <h2><?= htmlspecialchars($report_title) ?></h2>
            <div class="subtitle" style="margin-bottom:0;">
                <?= htmlspecialchars($employee_info['full_name'] ?? '') ?>
                (#<?= htmlspecialchars($employee_info['employee_no'] ?? '') ?>)
                &nbsp;|&nbsp; <?= htmlspecialchars($employee_info['department'] ?? 'N/A') ?>
            </div>
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
                <th>Clock In</th>
                <th>Clock Out</th>
                <th>Total Hours</th>
                <?php if ($can_ot): ?><th>Overtime</th><?php endif; ?>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($records) > 0): ?>
                <?php foreach ($records as $rec): ?>
                    <?php
                        $hours_val = $can_ot ? ($rec['total_hours'] ?? null) : ($rec['working_hours'] ?? null);
                        $total_hours_display = '--';
                        if ($hours_val !== null) {
                            $h = floor((float) $hours_val);
                            $m = round(((float) $hours_val - $h) * 60);
                            $total_hours_display = "{$h}h {$m}m";
                        }

                        $ot_display = '-';
                        if ($can_ot && isset($rec['overtime_hours']) && $rec['overtime_hours'] > 0) {
                            $ot = (float) $rec['overtime_hours'];
                            $ot_h = floor($ot);
                            $ot_m = round(($ot - $ot_h) * 60);
                            $ot_display = "{$ot_h}h {$ot_m}m";
                        }

                        $status_class = $rec['status'] === 'Late' ? 'badge-late' : ($rec['status'] === 'On Time' ? 'badge-ontime' : '');
                    ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($rec['attendance_date'])) ?></td>
                        <td><?= $rec['clock_in'] ? date('h:i A', strtotime($rec['clock_in'])) : '--:--' ?></td>
                        <td><?= $rec['clock_out'] ? date('h:i A', strtotime($rec['clock_out'])) : '--:--' ?></td>
                        <td><?= $total_hours_display ?></td>
                        <?php if ($can_ot): ?><td><?= $ot_display ?></td><?php endif; ?>
                        <td class="<?= $status_class ?>"><?= htmlspecialchars($rec['status'] ?? 'Unknown') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?= $can_ot ? 6 : 5 ?>" style="text-align:center; padding: 20px; color: #777;">
                        No attendance records found for this period.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>