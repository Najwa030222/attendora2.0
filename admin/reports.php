<?php
session_start();

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

// --- FILTER LOGIC ---
$filter_mode = $_GET['filter_mode'] ?? 'month'; // Default to month
if (!in_array($filter_mode, ['day', 'month', 'year'])) {
    $filter_mode = 'month';
}

$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$selected_month = $_GET['filter_month'] ?? date('Y-m');
$filter_year = $_GET['filter_year'] ?? date('Y');

// Determine date ranges based on filter mode
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

// --- 1. FETCH DEPARTMENT HEADCOUNT DATA ---
// This generally stays static regardless of date, as it's the current active headcount
$dept_stmt = $pdo->query("
    SELECT d.department_name, COUNT(e.employee_id) as emp_count 
    FROM departments d 
    LEFT JOIN employees e ON d.department_id = e.department_id AND e.employment_status IN ('Active', 'On Probation')
    GROUP BY d.department_id
");
$dept_data = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
$dept_labels = [];
$dept_counts = [];
foreach ($dept_data as $row) {
    $dept_labels[] = $row['department_name'];
    $dept_counts[] = $row['emp_count'];
}

// --- 2. FETCH ATTENDANCE STATUS DATA (Filtered) ---
$att_stmt = $pdo->prepare("
    SELECT status, COUNT(*) as status_count 
    FROM attendance 
    WHERE attendance_date BETWEEN ? AND ? 
    GROUP BY status
");
$att_stmt->execute([$start_date, $end_date]);
$att_data = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
$att_labels = [];
$att_counts = [];
foreach ($att_data as $row) {
    $label = strpos($row['status'], 'Approved') !== false ? 'HR Adjusted' : $row['status'];
    $att_labels[] = $label;
    $att_counts[] = $row['status_count'];
}

// --- 3. FETCH LEAVE TYPES USAGE (Filtered) ---
// Overlap check: a leave counts if it overlaps the period at all, not just if its
// start/end date happens to fall inside it (fixes long leaves like Haji Leave
// silently disappearing from months they span but don't start/end in).
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
$lt_labels = [];
$lt_counts = [];
foreach ($leave_type_data as $row) {
    $lt_labels[] = $row['leave_name'];
    $lt_counts[] = $row['usage_count'];
}

// --- 4. FETCH LEAVE STATUS DISTRIBUTION (Filtered) ---
$leave_stat_stmt = $pdo->prepare("
    SELECT status, COUNT(*) as count 
    FROM leave_applications 
    WHERE start_date <= ? AND end_date >= ?
    GROUP BY status
");
$leave_stat_stmt->execute([$end_date, $start_date]);
$leave_stat_data = $leave_stat_stmt->fetchAll(PDO::FETCH_ASSOC);
$ls_labels = [];
$ls_counts = [];
foreach ($leave_stat_data as $row) {
    $ls_labels[] = $row['status'];
    $ls_counts[] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics & Reports - Attendora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <!-- Chart.js for Graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --bg-main: #0b0d14; 
            --bg-panel: #131620;
            --bg-card: #1a1e2b;
            --emerald-neon: #10b981;
            --emerald-glow: rgba(16, 185, 129, 0.4);
            --text-light: #f8fafc;
            --text-muted: #94a3b8;
        }

        body { 
            background-color: var(--bg-main); 
            color: var(--text-light);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        .text-muted { color: var(--text-muted) !important; }
        .text-emerald { color: var(--emerald-neon) !important; }

        .main-content {
            margin-left: 280px;
            flex-grow: 1;
            width: calc(100% - 280px);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Top Header */
        .top-header {
            background: var(--bg-panel);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
        }

        /* Chart Panels */
        .chart-card {
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            height: 100%;
        }

        .chart-title {
            color: var(--text-light);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Form & Button Styles */
        .btn-emerald-solid {
            background-color: var(--emerald-neon);
            color: #0b0d14 !important;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 0 15px var(--emerald-glow);
            padding: 8px 20px;
            border: 1px solid var(--emerald-neon);
        }
        .btn-emerald-solid:hover {
            background-color: #059669;
            color: #fff !important;
            transform: translateY(-2px);
        }
        
        .btn-outline-emerald {
            border: 1px solid var(--emerald-neon);
            color: var(--emerald-neon);
            background: transparent;
            font-weight: 500;
        }
        .btn-outline-emerald:hover {
            background: rgba(16, 185, 129, 0.1);
            color: var(--emerald-neon);
        }

        .form-control-dark {
            background-color: var(--bg-panel);
            border: 1px solid rgba(255,255,255,0.2);
            color: var(--text-light);
            border-radius: 8px;
            padding: 6px 12px;
        }
        .form-control-dark:focus {
            background-color: var(--bg-panel);
            color: white;
            border-color: var(--emerald-neon);
            box-shadow: 0 0 10px var(--emerald-glow);
        }
        ::-webkit-calendar-picker-indicator { filter: invert(1); opacity: 0.7; cursor: pointer; }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar Navigation -->
        <?php require 'sidebar.php'; ?>

        <!-- Main Content Area -->
        <div class="main-content p-5">
            
            <!-- Header with Filters -->
            <div class="top-header d-flex flex-wrap justify-content-between align-items-center mb-4 p-4 shadow-sm gap-3">
                <div>
                    <h4 class="mb-1 fw-bold">Analytics & Reports</h4>
                    <p class="text-muted mb-0 fs-6">Visual insights for attendance, leaves, and workforce distribution.</p>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <!-- Filter Form -->
                    <form method="GET" action="reports.php" class="d-flex align-items-center gap-2" id="filterForm">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn <?= $filter_mode === 'day' ? 'btn-emerald-solid' : 'btn-outline-emerald' ?>" onclick="setFilterMode('day')">Day</button>
                            <button type="button" class="btn <?= $filter_mode === 'month' ? 'btn-emerald-solid' : 'btn-outline-emerald' ?>" onclick="setFilterMode('month')">Month</button>
                            <button type="button" class="btn <?= $filter_mode === 'year' ? 'btn-emerald-solid' : 'btn-outline-emerald' ?>" onclick="setFilterMode('year')">Year</button>
                        </div>

                        <input type="hidden" name="filter_mode" id="filterModeInput" value="<?= htmlspecialchars($filter_mode) ?>">

                        <input type="date" name="filter_date" id="dateInput" class="form-control form-control-dark <?= $filter_mode === 'day' ? '' : 'd-none' ?>"
                               value="<?= htmlspecialchars($filter_date) ?>" onchange="this.form.submit()" title="Select Date">

                        <input type="month" name="filter_month" id="monthInput" class="form-control form-control-dark <?= $filter_mode === 'month' ? '' : 'd-none' ?>"
                               value="<?= htmlspecialchars($selected_month) ?>" onchange="this.form.submit()" title="Select Month and Year">

                        <select name="filter_year" id="yearInput" class="form-select form-control-dark <?= $filter_mode === 'year' ? '' : 'd-none' ?>" onchange="this.form.submit()" title="Select Year">
                            <?php $current_yr = (int) date('Y'); for ($y = $current_yr; $y >= $current_yr - 5; $y--): ?>
                                <option value="<?= $y ?>" <?= (string) $filter_year === (string) $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>

                    <!-- Export Form redirecting to reports_export.php -->
                    <form method="GET" action="reports_export.php" target="_blank" class="m-0">
                        <input type="hidden" name="filter_mode" value="<?= htmlspecialchars($filter_mode) ?>">
                        <input type="hidden" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
                        <input type="hidden" name="filter_month" value="<?= htmlspecialchars($selected_month) ?>">
                        <input type="hidden" name="filter_year" value="<?= htmlspecialchars($filter_year) ?>">
                        
                        <button type="submit" class="btn btn-emerald-solid">
                            <i class="bi bi-file-earmark-pdf-fill me-2"></i> Export Report
                        </button>
                    </form>
                </div>
            </div>

            <!-- Report Container -->
            <div id="reportContainer" class="p-2">
                <div class="row g-4 mb-4">
                    
                    <!-- Headcount by Department (Bar Chart) -->
                    <div class="col-lg-8">
                        <div class="chart-card">
                            <h5 class="chart-title"><span class="text-white"><i class="bi bi-building me-2 text-emerald"></i> Headcount by Department</span></h5>
                            <canvas id="deptChart" height="100"></canvas>
                        </div>
                    </div>

                    <!-- Leave Status Distribution (Doughnut Chart) -->
                    <div class="col-lg-4">
                        <div class="chart-card">
                            <h5 class="chart-title">
                                <span class="text-white"><i class="bi bi-envelope-paper me-2 text-warning"></i> Leave Request Status</span>
                                <small class="text-muted" style="font-size:0.7rem; font-weight:normal;"><?= $display_period ?></small>
                            </h5>
                            <?php if(empty($ls_labels)): ?>
                                <div class="h-100 d-flex align-items-center justify-content-center text-muted">No data for this period</div>
                            <?php else: ?>
                                <canvas id="leaveStatusChart" height="200"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Attendance Status (Pie Chart) -->
                    <div class="col-lg-4">
                        <div class="chart-card">
                            <h5 class="chart-title">
                                <span class="text-white"><i class="bi bi-clock-history me-2 text-info"></i> Attendance Status</span>
                                <small class="text-muted" style="font-size:0.7rem; font-weight:normal;"><?= $display_period ?></small>
                            </h5>
                            <?php if(empty($att_labels)): ?>
                                <div class="h-100 d-flex align-items-center justify-content-center text-muted">No data for this period</div>
                            <?php else: ?>
                                <canvas id="attendanceChart" height="200"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Most Used Leave Types (Bar Chart Horizontal) -->
                    <div class="col-lg-8">
                        <div class="chart-card">
                            <h5 class="chart-title">
                                <span class="text-white"><i class="bi bi-bar-chart-fill me-2 text-emerald"></i> Approved Leaves by Type</span>
                                <small class="text-muted" style="font-size:0.7rem; font-weight:normal;"><?= $display_period ?></small>
                            </h5>
                            <?php if(empty($lt_labels)): ?>
                                <div class="h-100 d-flex align-items-center justify-content-center text-muted">No data for this period</div>
                            <?php else: ?>
                                <canvas id="leaveTypeChart" height="100"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>

    <!-- Initialize Charts -->
    <script>
        // Global Chart Defaults for Dark Theme
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.05)';
        Chart.defaults.font.family = "'Poppins', sans-serif";

        // Filter Mode Switcher
        function setFilterMode(mode) {
            document.getElementById('filterModeInput').value = mode;
            document.getElementById('dateInput').classList.toggle('d-none', mode !== 'day');
            document.getElementById('monthInput').classList.toggle('d-none', mode !== 'month');
            document.getElementById('yearInput').classList.toggle('d-none', mode !== 'year');
            document.getElementById('filterForm').submit();
        }

        // 1. Department Chart (Vertical Bar)
        const deptCtx = document.getElementById('deptChart')?.getContext('2d');
        if(deptCtx) {
            new Chart(deptCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($dept_labels) ?>,
                    datasets: [{
                        label: 'Number of Employees',
                        data: <?= json_encode($dept_counts) ?>,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }

        // 2. Leave Status Chart (Doughnut)
        const lsCtx = document.getElementById('leaveStatusChart')?.getContext('2d');
        if(lsCtx) {
            new Chart(lsCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($ls_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($ls_counts) ?>,
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#64748b'], 
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    cutout: '70%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } }
                    }
                }
            });
        }

        // 3. Attendance Chart (Pie)
        const attCtx = document.getElementById('attendanceChart')?.getContext('2d');
        if(attCtx) {
            new Chart(attCtx, {
                type: 'pie',
                data: {
                    labels: <?= json_encode($att_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($att_counts) ?>,
                        backgroundColor: ['#10b981', '#ef4444', '#3b82f6', '#f59e0b'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } }
                    }
                }
            });
        }

        // 4. Leave Types Usage (Horizontal Bar)
        const ltCtx = document.getElementById('leaveTypeChart')?.getContext('2d');
        if(ltCtx) {
            new Chart(ltCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($lt_labels) ?>,
                    datasets: [{
                        label: 'Days / Applications Approved',
                        data: <?= json_encode($lt_counts) ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y', // Makes it horizontal
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }
    </script>
</body>
</html>