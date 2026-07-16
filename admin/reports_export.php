<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

// --- FILTER LOGIC ---
$filter_mode = $_GET['filter_mode'] ?? 'month';
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$selected_month = $_GET['filter_month'] ?? date('Y-m');
$filter_year = $_GET['filter_year'] ?? date('Y');

switch ($filter_mode) {
    case 'year':
        $start_date = $filter_year . '-01-01';
        $end_date = $filter_year . '-12-31';
        $display_period = $filter_year;
        break;
    case 'day':
        $start_date = $filter_date;
        $end_date = $filter_date;
        $display_period = date('d F Y', strtotime($filter_date));
        break;
    case 'month':
    default:
        $start_date = $selected_month . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
        $display_period = date('F Y', strtotime($start_date));
        break;
}

// --- 1. FETCH DEPARTMENT DATA ---
$dept_stmt = $pdo->query("
    SELECT d.department_name, COUNT(e.employee_id) as emp_count 
    FROM departments d 
    LEFT JOIN employees e ON d.department_id = e.department_id AND e.employment_status IN ('Active', 'On Probation')
    GROUP BY d.department_id
");
$dept_data = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
$dept_labels = []; $dept_counts = [];
foreach ($dept_data as $row) {
    $dept_labels[] = $row['department_name'];
    $dept_counts[] = $row['emp_count'];
}

// --- 2. FETCH ATTENDANCE DATA ---
$att_stmt = $pdo->prepare("
    SELECT status, COUNT(*) as status_count 
    FROM attendance 
    WHERE attendance_date BETWEEN ? AND ? 
    GROUP BY status
");
$att_stmt->execute([$start_date, $end_date]);
$att_data = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
$att_labels = []; $att_counts = [];
foreach ($att_data as $row) {
    $label = strpos($row['status'], 'Approved') !== false ? 'HR Adjusted' : $row['status'];
    $att_labels[] = $label;
    $att_counts[] = $row['status_count'];
}

// --- 3. FETCH LEAVE TYPES USAGE ---
// Overlap check instead of BETWEEN, so long leaves (e.g. Haji Leave) that span a
// whole selected period without starting/ending inside it still get counted.
$leave_type_stmt = $pdo->prepare("
    SELECT t.leave_name, COUNT(a.leave_id) as usage_count 
    FROM leave_applications a 
    JOIN leave_types t ON a.leave_type_id = t.leave_type_id 
    WHERE a.status = 'Approved' 
    AND a.start_date <= ? AND a.end_date >= ?
    GROUP BY t.leave_type_id
");
$leave_type_stmt->execute([$end_date, $start_date]);
$leave_type_data = $leave_type_stmt->fetchAll(PDO::FETCH_ASSOC);
$lt_labels = []; $lt_counts = [];
foreach ($leave_type_data as $row) {
    $lt_labels[] = $row['leave_name'];
    $lt_counts[] = $row['usage_count'];
}

// --- 4. FETCH LEAVE STATUS DISTRIBUTION ---
$leave_stat_stmt = $pdo->prepare("
    SELECT status, COUNT(*) as count 
    FROM leave_applications 
    WHERE start_date <= ? AND end_date >= ?
    GROUP BY status
");
$leave_stat_stmt->execute([$end_date, $start_date]);
$leave_stat_data = $leave_stat_stmt->fetchAll(PDO::FETCH_ASSOC);
$ls_labels = []; $ls_counts = [];
foreach ($leave_stat_data as $row) {
    $ls_labels[] = $row['status'];
    $ls_counts[] = $row['count'];
}

$report_title = 'System Analytics Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($report_title) ?></title>
    <!-- Include Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; padding: 30px; }
        
        /* Shared brand header styling */
        .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #10b981; padding-bottom: 15px; margin-bottom: 20px; }
        .brand { display: flex; align-items: center; gap: 10px; }
        .brand img { height: 38px; }
        .brand-name { font-weight: 700; letter-spacing: 1px; color: #10b981; font-size: 1.1rem; }
        
        h2 { margin: 0 0 4px; }
        .subtitle { color: #555; margin-bottom: 25px; font-size: 0.9rem; }
        
        .section-title { color: #10b981; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-top: 30px; margin-bottom: 10px;}
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 20px;}
        th, td { border: 1px solid #ccc; padding: 10px; font-size: 0.9rem; text-align: left; }
        th { background: #f8fafc; color: #333; font-weight: bold; }
        
        .no-print { margin-bottom: 20px; }
        .btn-success { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;}
        
        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        /* Chart specific styling */
        .chart-box {
            border: 1px solid #eaeaea;
            padding: 15px;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            page-break-inside: avoid;
        }
        .chart-box h4 { margin-top: 0; margin-bottom: 15px; color: #333; font-size: 1rem; text-align: center;}
        
        /* THE FIX: Lock the canvas size so it doesn't expand infinitely */
        .canvas-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .chart-box { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button class="btn-success" onclick="window.print()">Print / Save as PDF</button>
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
                Generated by: System Administrator
            </div>
        </div>
    </div>

    <div class="subtitle">
        <strong>Reporting Period:</strong> <?= htmlspecialchars($display_period) ?>
        &nbsp;|&nbsp; <strong>Generated On:</strong> <?= date('d M Y, h:i A') ?>
    </div>

    <!-- 1. GRAPHS SECTION -->
    <div class="grid-container" style="margin-bottom: 30px;">
        <div class="chart-box">
            <h4>Headcount by Department</h4>
            <div class="canvas-container">
                <canvas id="deptChart"></canvas>
            </div>
        </div>
        <div class="chart-box">
            <h4>Leave Applications Status</h4>
            <div class="canvas-container">
                <canvas id="leaveStatusChart"></canvas>
            </div>
        </div>
        <div class="chart-box">
            <h4>Attendance Overview</h4>
            <div class="canvas-container">
                <canvas id="attendanceChart"></canvas>
            </div>
        </div>
        <div class="chart-box">
            <h4>Approved Leaves by Type</h4>
            <div class="canvas-container">
                <canvas id="leaveTypeChart"></canvas>
            </div>
        </div>
    </div>

    <hr style="border: 0; border-top: 1px dashed #ccc; margin: 40px 0;">

    <!-- 2. DATA TABLES SECTION -->
    <div class="grid-container">
        <!-- Attendance Stats -->
        <div>
            <h3 class="section-title" style="margin-top: 0;">Attendance Overview Data</h3>
            <?php if(empty($att_data)): ?>
                <p style="color: #777; font-style: italic;">No attendance records found for this period.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 70%;">Status</th>
                        <th style="width: 30%;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($att_data as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['status']) ?></td>
                        <td><strong><?= $row['status_count'] ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Leave Requests by Status -->
        <div>
            <h3 class="section-title" style="margin-top: 0;">Leave Applications Status Data</h3>
            <?php if(empty($leave_stat_data)): ?>
                <p style="color: #777; font-style: italic;">No leave applications found for this period.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 70%;">Application Status</th>
                        <th style="width: 30%;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($leave_stat_data as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['status']) ?></td>
                        <td><strong><?= $row['count'] ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Approved Leaves by Type -->
    <h3 class="section-title">Approved Leaves Usage by Type Data</h3>
    <?php if(empty($leave_type_data)): ?>
        <p style="color: #777; font-style: italic;">No approved leaves recorded in this period.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th style="width: 70%;">Leave Type</th>
                <th style="width: 30%;">Total Approved Applications</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($leave_type_data as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['leave_name']) ?></td>
                <td><strong><?= $row['usage_count'] ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Headcount Data (Static relative to period) -->
    <h3 class="section-title">Current Department Headcount Data (Active Staff)</h3>
    <table>
        <thead>
            <tr>
                <th style="width: 70%;">Department</th>
                <th style="width: 30%;">Employee Count</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($dept_data as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['department_name']) ?></td>
                <td><strong><?= $row['emp_count'] ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Initialize Charts -->
    <script>
        // Global styling adapted for light background printing
        Chart.defaults.color = '#333';
        Chart.defaults.font.family = "Arial, Helvetica, sans-serif";

        const barColors = 'rgba(16, 185, 129, 0.8)';
        const pieColors = ['#10b981', '#f59e0b', '#ef4444', '#64748b'];

        // 1. Department Chart (Bar)
        const deptCtx = document.getElementById('deptChart')?.getContext('2d');
        if(deptCtx && <?= !empty($dept_labels) ? 'true' : 'false' ?>) {
            new Chart(deptCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($dept_labels) ?>,
                    datasets: [{
                        label: 'Employees',
                        data: <?= json_encode($dept_counts) ?>,
                        backgroundColor: barColors,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }

        // 2. Leave Status Chart (Doughnut)
        const lsCtx = document.getElementById('leaveStatusChart')?.getContext('2d');
        if(lsCtx && <?= !empty($ls_labels) ? 'true' : 'false' ?>) {
            new Chart(lsCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($ls_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($ls_counts) ?>,
                        backgroundColor: pieColors
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        // 3. Attendance Chart (Pie)
        const attCtx = document.getElementById('attendanceChart')?.getContext('2d');
        if(attCtx && <?= !empty($att_labels) ? 'true' : 'false' ?>) {
            new Chart(attCtx, {
                type: 'pie',
                data: {
                    labels: <?= json_encode($att_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($att_counts) ?>,
                        backgroundColor: ['#10b981', '#ef4444', '#3b82f6', '#f59e0b']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        // 4. Leave Types Usage (Horizontal Bar)
        const ltCtx = document.getElementById('leaveTypeChart')?.getContext('2d');
        if(ltCtx && <?= !empty($lt_labels) ? 'true' : 'false' ?>) {
            new Chart(ltCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($lt_labels) ?>,
                    datasets: [{
                        label: 'Approved Applications',
                        data: <?= json_encode($lt_counts) ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.8)'
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }
    </script>
</body>
</html>