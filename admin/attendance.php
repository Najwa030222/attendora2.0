<?php
session_start();

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

$message = '';
$messageType = '';

// --- 1. PROCESS ADMIN ACTIONS (APPROVE / REJECT REQUESTS) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $req_id = $_POST['request_id'];
    
    if ($_POST['action'] == 'approve_request') {
        try {
            // Fetch request details
            $stmt = $pdo->prepare("SELECT * FROM attendance_requests WHERE id = ?");
            $stmt->execute([$req_id]);
            $req = $stmt->fetch();

            if ($req) {
                $emp_id = $req['employee_id'];
                $date = $req['request_date'];
                $est_time = $req['estimated_time'];

                // Determine if late based on estimated time (after 08:30 AM)
                $status = (strtotime($est_time) > strtotime('08:30:00')) ? 'Late' : 'On Time';

                // Use the location/photo the employee actually submitted with the request
                // (falls back to 'Inside Office' for older requests made before this column existed)
                $loc_in = $req['location_in'] ?? 'Inside Office';
                $photo_in = $req['photo_in'] ?? null;

                // Check if a record already exists for this day (e.g. they clocked out first)
                $check = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
                $check->execute([$emp_id, $date]);
                $existing = $check->fetch();

                if ($existing) {
                    // Update existing record with the approved clock in details
                    $update = $pdo->prepare("UPDATE attendance SET clock_in = ?, status = ?, location_in = ?, photo_in = ? WHERE attendance_id = ?");
                    $update->execute([$est_time, $status, $loc_in, $photo_in, $existing['attendance_id']]);
                } else {
                    // Create new complete attendance record
                    $insert = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, clock_in, status, location_in, photo_in) VALUES (?, ?, ?, ?, ?, ?)");
                    $insert->execute([$emp_id, $date, $est_time, $status, $loc_in, $photo_in]);
                }

                // Update original request status to Approved
                $pdo->prepare("UPDATE attendance_requests SET status = 'Approved' WHERE id = ?")->execute([$req_id]);
                
                $message = "Request approved successfully. Attendance records updated.";
                $messageType = "success";
            }
        } catch (PDOException $e) {
            $message = "Error processing approval: " . $e->getMessage();
            $messageType = "danger";
        }
    } elseif ($_POST['action'] == 'reject_request') {
        try {
            $pdo->prepare("UPDATE attendance_requests SET status = 'Rejected' WHERE id = ?")->execute([$req_id]);
            $message = "Adjustment request rejected.";
            $messageType = "info";
        } catch (PDOException $e) {
            $message = "Error rejecting request: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// --- 2. FETCH PENDING REQUESTS ---
$pending_stmt = $pdo->query("
    SELECT r.*, e.full_name, e.employee_no 
    FROM attendance_requests r 
    JOIN employees e ON r.employee_id = e.employee_id 
    WHERE r.status = 'Pending'
    ORDER BY r.request_date ASC
");
$pending_requests = $pending_stmt->fetchAll();

// --- 3. FILTER LOGIC & FETCH ATTENDANCE DATA ---
// Default view is TODAY. Admin can switch to Month or Year mode instead.
$filter_mode = $_GET['filter_mode'] ?? 'day';
if (!in_array($filter_mode, ['day', 'month', 'year'])) {
    $filter_mode = 'day'; // guard against a tampered/invalid value
}

$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$selected_month = $_GET['filter_month'] ?? date('Y-m');
$filter_year = $_GET['filter_year'] ?? date('Y');

// --- Search / Department filter (applies on top of the day/month/year range above) ---
$search_name = trim($_GET['search_name'] ?? '');
$filter_department = $_GET['filter_department'] ?? '';

// List of departments for the filter dropdown
$dept_stmt = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
$departments = $dept_stmt->fetchAll();

// List of employees for the PDF export dropdown
$emp_list_stmt = $pdo->query("SELECT employee_id, full_name, employee_no FROM employees ORDER BY full_name ASC");
$all_employees = $emp_list_stmt->fetchAll();

switch ($filter_mode) {
    case 'month':
        $start_date = $selected_month . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
        $display_month = date('F Y', strtotime($start_date));
        break;
    case 'year':
        $start_date = $filter_year . '-01-01';
        $end_date = $filter_year . '-12-31';
        $display_month = $filter_year;
        break;
    case 'day':
    default:
        $start_date = $filter_date;
        $end_date = $filter_date;
        $display_month = date('d F Y', strtotime($filter_date));
        break;
}

$att_where = "WHERE a.attendance_date BETWEEN ? AND ?";
$att_params = [$start_date, $end_date];

if ($search_name !== '') {
    $att_where .= " AND (e.full_name LIKE ? OR e.employee_no LIKE ?)";
    $att_params[] = '%' . $search_name . '%';
    $att_params[] = '%' . $search_name . '%';
}

if ($filter_department !== '') {
    $att_where .= " AND e.department_id = ?";
    $att_params[] = $filter_department;
}

$att_stmt = $pdo->prepare("
    SELECT a.*, e.full_name, e.employee_no, e.department_id, d.overtime_eligible 
    FROM attendance a 
    JOIN employees e ON a.employee_id = e.employee_id 
    LEFT JOIN departments d ON e.department_id = d.department_id 
    $att_where
    ORDER BY a.attendance_date DESC, e.full_name ASC
");
$att_stmt->execute($att_params);
$attendances = $att_stmt->fetchAll();

// --- Late-count summary for employees currently in view (5+ = yellow, 10+ = red) ---
$late_counts = [];
foreach ($attendances as $att_row) {
    if ($att_row['status'] === 'Late') {
        $emp_key = $att_row['employee_id'];
        if (!isset($late_counts[$emp_key])) {
            $late_counts[$emp_key] = [
                'full_name'   => $att_row['full_name'],
                'employee_no' => $att_row['employee_no'],
                'count'       => 0,
            ];
        }
        $late_counts[$emp_key]['count']++;
    }
}
// Only employees with 5 or more lates are worth flagging
$flagged_late_employees = array_filter($late_counts, function ($row) {
    return $row['count'] >= 5;
});
// Highest count first
uasort($flagged_late_employees, function ($a, $b) {
    return $b['count'] <=> $a['count'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Records - Attendora</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <style>
        :root {
            --bg-main: #0b0d14; 
            --bg-panel: #131620;
            --bg-card: #1a1e2b;
            --emerald-neon: #10b981;
            --emerald-glow: rgba(16, 185, 129, 0.4);
            --text-light: #f8fafc;
            --text-muted: #94a3b8;
            --warning-neon: #f59e0b;
        }

        body { 
            background-color: var(--bg-main); 
            color: var(--text-light);
            font-family: 'Poppins', sans-serif;
            background-image: radial-gradient(circle at top left, rgba(16, 185, 129, 0.08), transparent 40%),
                              radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.05), transparent 40%);
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
        
        .sidebar-brand { letter-spacing: 2px; text-transform: uppercase; }

        .sidebar a { 
            color: var(--text-muted); text-decoration: none; padding: 14px 24px; 
            display: block; transition: all 0.3s ease; font-weight: 500;
            font-size: 0.95rem; border-left: 4px solid transparent;
        }
        
        .sidebar a:hover { color: var(--text-light); background: rgba(255, 255, 255, 0.03); }

        .sidebar a.active { 
            background: linear-gradient(90deg, rgba(16,185,129,0.15) 0%, transparent 100%);
            color: var(--emerald-neon); border-left: 4px solid var(--emerald-neon); 
            text-shadow: 0 0 10px var(--emerald-glow);
        }

        .btn-logout {
            background-color: transparent; border: 1px solid var(--emerald-neon);
            color: var(--emerald-neon) !important; border-radius: 50px; margin: 0 20px;
            text-align: center; padding: 10px 20px !important; text-decoration: none; transition: all 0.3s;
        }
        .btn-logout:hover {
            background-color: var(--emerald-neon); color: #000 !important;
            box-shadow: 0 0 15px var(--emerald-glow);
        }

        /* Panels & Tables */
        .top-header { background: var(--bg-panel); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; }
        .panel-card { background: var(--bg-card); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; overflow: hidden; }
        
        .table-dark-custom { color: var(--text-light); vertical-align: middle; margin-bottom: 0; }
        .table-dark-custom thead th {
            background-color: rgba(0,0,0,0.2); color: var(--text-muted); font-weight: 600;
            border-bottom: 2px solid rgba(16, 185, 129, 0.3); text-transform: uppercase;
            font-size: 0.8rem; letter-spacing: 1px; padding: 15px;
        }
        .table-dark-custom tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); transition: background-color 0.2s; }
        .table-dark-custom tbody tr:hover { background-color: rgba(255, 255, 255, 0.02); }
        .table-dark-custom tbody td { padding: 15px; background: transparent; color: var(--text-light); border-bottom: none; }
        
        /* Badges */
        .badge-ontime { background: rgba(16, 185, 129, 0.15); color: var(--emerald-neon); border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-late { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-pending { background: rgba(245, 158, 11, 0.15); color: var(--warning-neon); border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-flag-yellow { background: rgba(245, 158, 11, 0.15); color: var(--warning-neon); border: 1px solid rgba(245, 158, 11, 0.3); }

        /* Custom Inputs */
        .form-control-dark {
            background-color: var(--bg-panel);
            border: 1px solid rgba(255,255,255,0.2);
            color: var(--text-light);
            border-radius: 8px;
            padding: 8px 15px;
        }

        .form-control-dark::placeholder {
            color: white;
            opacity: 1;
        }

        .form-control-dark:focus {
            background-color: var(--bg-panel);
            color: white;
            border-color: var(--emerald-neon);
            box-shadow: 0 0 10px var(--emerald-glow);
        }

        /* Filter mode buttons */
        .btn-emerald-solid {
            background-color: var(--emerald-neon);
            color: #0b0d14 !important;
            border: 1px solid var(--emerald-neon);
            font-weight: 600;
        }
        .btn-outline-light {
            border: 1px solid rgba(255,255,255,0.2);
            color: var(--text-light);
        }
        .btn-outline-light:hover {
            background-color: rgba(255,255,255,0.05);
            color: var(--text-light);
        }
        .btn-outline-emerald {
            border: 1px solid var(--emerald-neon);
            color: var(--emerald-neon);
            background: transparent;
        }
        .btn-outline-emerald:hover {
            background: rgba(16, 185, 129, 0.1);
            color: var(--emerald-neon);
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
            
            <!-- Header -->
            <div class="top-header d-flex justify-content-between align-items-center mb-5 p-4 shadow-sm">
                <div>
                    <h4 class="mb-1 fw-bold">Attendance Records</h4>
                    <p class="text-muted mb-0 fs-6">Monitor clock-ins, clock-outs, and manage requests.</p>
                </div>
                
                <!-- Dynamic Day/Month/Year Filter Form -->
                <form method="GET" action="attendance.php" class="d-flex align-items-center gap-2" id="filterForm">
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn <?= $filter_mode === 'day' ? 'btn-emerald-solid' : 'btn-outline-light' ?>" onclick="setFilterMode('day')">Day</button>
                        <button type="button" class="btn <?= $filter_mode === 'month' ? 'btn-emerald-solid' : 'btn-outline-light' ?>" onclick="setFilterMode('month')">Month</button>
                        <button type="button" class="btn <?= $filter_mode === 'year' ? 'btn-emerald-solid' : 'btn-outline-light' ?>" onclick="setFilterMode('year')">Year</button>
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

                    <?php if ($filter_mode !== 'day' || $filter_date !== date('Y-m-d')): ?>
                        <a href="attendance.php" class="btn btn-sm btn-outline-emerald rounded-pill px-3">Today</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Search / Department Filter + PDF Export Row -->
            <div class="top-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-5 p-4 shadow-sm">
                <form method="GET" action="attendance.php" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="filter_mode" value="<?= htmlspecialchars($filter_mode) ?>">
                    <input type="hidden" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
                    <input type="hidden" name="filter_month" value="<?= htmlspecialchars($selected_month) ?>">
                    <input type="hidden" name="filter_year" value="<?= htmlspecialchars($filter_year) ?>">

                    <input type="text" name="search_name" class="form-control form-control-dark" style="width: 250px;"
                           placeholder="Search Employee Name" value="<?= htmlspecialchars($search_name) ?>">

                    <select name="filter_department" class="form-select form-control-dark" style="width: 180px;">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>" <?= (string) $filter_department === (string) $dept['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-sm btn-emerald-solid rounded-pill px-3"><i class="bi bi-search"></i> Filter</button>
                    <?php if ($search_name !== '' || $filter_department !== ''): ?>
                        <a href="attendance.php?filter_mode=<?= urlencode($filter_mode) ?>&filter_date=<?= urlencode($filter_date) ?>&filter_month=<?= urlencode($selected_month) ?>&filter_year=<?= urlencode($filter_year) ?>" class="btn btn-sm btn-outline-light rounded-pill px-3">Clear</a>
                    <?php endif; ?>
                </form>

                <form method="GET" action="attendance_export.php" target="_blank" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="filter_mode" value="<?= htmlspecialchars($filter_mode) ?>">
                    <input type="hidden" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
                    <input type="hidden" name="filter_month" value="<?= htmlspecialchars($selected_month) ?>">
                    <input type="hidden" name="filter_year" value="<?= htmlspecialchars($filter_year) ?>">
                    <input type="hidden" name="filter_department" value="<?= htmlspecialchars($filter_department) ?>">
                    <input type="hidden" name="search_name" value="<?= htmlspecialchars($search_name) ?>">

                    <select name="employee_id" class="form-select form-control-dark" style="width: 200px;">
                        <option value="">All Employees (HR Report)</option>
                        <?php foreach ($all_employees as $emp_opt): ?>
                            <option value="<?= $emp_opt['employee_id'] ?>">#<?= htmlspecialchars($emp_opt['employee_no']) ?> - <?= htmlspecialchars($emp_opt['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-sm btn-outline-emerald rounded-pill px-3"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
                </form>
            </div>

            <!-- Late-Count Flag Table (5+ Late = Yellow, 10+ Late = Red) -->
            <?php if (count($flagged_late_employees) > 0): ?>
            <div class="card panel-card shadow-lg mb-5">
                <div class="card-header border-0 bg-transparent pt-4 pb-2 px-4">
                    <h5 class="fw-bold mb-0 text-white"><i class="bi bi-flag-fill me-2"></i> Late Attendance Flags <span class="text-muted fw-normal fs-6">(<?= $display_month ?>)</span></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark-custom">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Times Late</th>
                                    <th>Flag</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($flagged_late_employees as $flag_row): ?>
                                    <?php
                                        $flag_class = $flag_row['count'] >= 10 ? 'badge-late' : 'badge-flag-yellow';
                                        $flag_label = $flag_row['count'] >= 10 ? 'Red - Frequent' : 'Yellow - Warning';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($flag_row['full_name']) ?></div>
                                            <div class="text-muted small">#<?= htmlspecialchars($flag_row['employee_no']) ?></div>
                                        </td>
                                        <td class="fw-bold"><?= $flag_row['count'] ?></td>
                                        <td><span class="badge <?= $flag_class ?> rounded-pill px-3 py-2"><?= $flag_label ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($message): ?>
                <div class="alert alert-<?= $messageType ?> bg-transparent border-<?= $messageType ?> border-opacity-50 text-light mb-4" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <!-- Pending Adjustment Requests Block -->
            <?php if (count($pending_requests) > 0): ?>
            <div class="card panel-card shadow-lg mb-5" style="border-left: 4px solid var(--warning-neon);">
                <div class="card-header border-0 bg-transparent pt-4 pb-2 px-4">
                    <h5 class="fw-bold text-warning mb-0"><i class="bi bi-exclamation-circle-fill me-2"></i> Pending Clock-In Requests (<?= count($pending_requests) ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark-custom">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Date</th>
                                    <th>Est. Arrival</th>
                                    <th>Reason Provided</th>
                                    <th>Location</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($pending_requests as $req): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($req['full_name']) ?></div>
                                        <div class="text-muted small">#<?= htmlspecialchars($req['employee_no']) ?></div>
                                    </td>
                                    <td><?= date('d M Y', strtotime($req['request_date'])) ?></td>
                                    <td><span class="badge badge-pending rounded-pill px-3 py-2"><?= date('h:i A', strtotime($req['estimated_time'])) ?></span></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($req['reason']) ?></small></td>
                                    <td>
                                        <?php
                                            $req_loc = $req['location_in'] ?? null;
                                            if ($req_loc === null) {
                                                echo '<span class="text-muted small">Not recorded</span>';
                                            } elseif (strpos($req_loc, 'Outside') === 0) {
                                                $coords = str_replace('Outside|', '', $req_loc);
                                                echo '<a href="https://www.google.com/maps/search/?api=1&query=' . urlencode($coords) . '" target="_blank" class="text-danger text-decoration-none fw-semibold d-block mb-1"><i class="bi bi-geo-alt-fill"></i> Outside - View Map</a>';
                                            } else {
                                                echo '<span class="text-emerald small"><i class="bi bi-geo-alt-fill"></i> ' . htmlspecialchars($req_loc) . '</span>';
                                            }
                                            if (!empty($req['photo_in'])) {
                                                echo '<button type="button" class="btn btn-sm btn-link text-info p-0 ms-1" data-bs-toggle="modal" data-bs-target="#reqSelfieModal' . $req['id'] . '" title="View Selfie"><i class="bi bi-camera-fill"></i></button>';
                                            }
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                            <button type="submit" name="action" value="approve_request" class="btn btn-sm btn-success rounded-pill px-3 me-2 shadow-sm"><i class="bi bi-check-lg"></i> Approve</button>
                                            <button type="submit" name="action" value="reject_request" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-x-lg"></i> Reject</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php if (!empty($req['photo_in'])): ?>
                                <div class="modal fade" id="reqSelfieModal<?= $req['id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content shadow-lg border-info" style="background-color: var(--bg-card);">
                                            <div class="modal-header border-bottom border-secondary">
                                                <h5 class="modal-title fw-bold text-info"><i class="bi bi-camera me-2"></i> Clock-In Selfie</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body p-4 text-center">
                                                <img src="../uploads/attendance/<?= htmlspecialchars($req['photo_in']) ?>" alt="Selfie" class="img-fluid rounded-3 border border-secondary" style="max-height: 400px; object-fit: cover;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Historical Attendance Log -->
            <div class="card panel-card shadow-lg">
                <div class="card-header border-0 bg-transparent pt-4 pb-3 px-4">
                    <h5 class="fw-bold mb-0 text-white">Records for <span class="text-emerald"><?= $display_month ?></span></h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark-custom">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Employee</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Total Hours</th>
                                    <th>Overtime</th>
                                    <th>Status</th>
                                    <th>Location Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($attendances) > 0): ?>
                                    <?php foreach($attendances as $att): ?>
                                        <?php
                                            // --- ELIGIBILITY CHECK FOR OVERTIME (from departments.overtime_eligible) ---
                                            $can_ot = !empty($att['overtime_eligible']);

                                            // Default dashes
                                            $total_hours_display = '--';
                                            
                                            // 1. Determine which DB column to use for "Total Hours" based on OT eligibility
                                            $hours_column_to_use = $can_ot ? ($att['total_hours'] ?? null) : ($att['working_hours'] ?? null);

                                            if ($hours_column_to_use !== null) {
                                                $t = (float) $hours_column_to_use;
                                                $h = floor($t);
                                                $m = round(($t - $h) * 60);
                                                $total_hours_display = "{$h}h {$m}m";
                                            }
                                        ?>
                                        <tr>
                                            <td><?= date('d M Y', strtotime($att['attendance_date'])) ?></td>
                                            <td>
                                                <div class="fw-bold"><?= htmlspecialchars($att['full_name']) ?></div>
                                                <div class="text-muted small">#<?= htmlspecialchars($att['employee_no']) ?></div>
                                            </td>
                                            <td class="fw-semibold">
                                                <?= $att['clock_in'] ? date('h:i A', strtotime($att['clock_in'])) : '<span class="text-muted">--:--</span>' ?>
                                            </td>
                                            <td class="fw-semibold">
                                                <?= $att['clock_out'] ? date('h:i A', strtotime($att['clock_out'])) : '<span class="text-muted">--:--</span>' ?>
                                            </td>
                                            <td class="fw-bold">
                                                <?= $total_hours_display ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $ot = (float) ($att['overtime_hours'] ?? 0);
                                                    $ot_h = floor($ot);
                                                    $ot_m = round(($ot - $ot_h) * 60);
                                                    $ot_text = $ot > 0 ? "{$ot_h}h {$ot_m}m" : '-';
                                                ?>
                                                <?php if ($can_ot): ?>
                                                    <?php if ($ot > 0): ?>
                                                        <span class="text-info fw-bold"><?= $ot_text ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted" style="opacity: 0.5;" title="Department not eligible for overtime">
                                                        <?= $ot_text ?> <i class="bi bi-slash-circle ms-1" style="font-size: 0.7rem;"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($att['status'] === 'Late'): ?>
                                                    <span class="badge badge-late rounded-pill px-3 py-2">Late</span>
                                                <?php elseif($att['status'] === 'On Time'): ?>
                                                    <span class="badge badge-ontime rounded-pill px-3 py-2">On Time</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary rounded-pill px-3 py-2"><?= htmlspecialchars($att['status'] ?? 'Unknown') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    // Handle Location In
                                                    $loc_in = $att['location_in'] ?? 'N/A';
                                                    echo '<div class="d-flex align-items-center mb-1">';
                                                    echo '<small class="text-muted d-inline-block" style="width: 32px;">In:</small>';
                                                    if (strpos($loc_in, 'Outside|') === 0) {
                                                        $coords = str_replace('Outside|', '', $loc_in);
                                                        echo '<a href="https://www.google.com/maps/search/?api=1&query=' . urlencode($coords) . '" target="_blank" class="text-danger text-decoration-none fw-semibold"><i class="bi bi-geo-alt-fill"></i> View Map</a>';
                                                    } else {
                                                        echo '<small class="text-muted">' . htmlspecialchars($loc_in) . '</small>';
                                                    }
                                                    
                                                    // Selfie Trigger In (fixed-width slot so it always lines up whether or not it's present)
                                                    echo '<span class="d-inline-flex justify-content-center" style="width: 28px;">';
                                                    if (!empty($att['photo_in'])) {
                                                        echo '<button type="button" class="btn btn-sm btn-link text-info p-0 ms-2" data-bs-toggle="modal" data-bs-target="#selfieModalIn' . $att['attendance_id'] . '" title="View Check-In Selfie"><i class="bi bi-camera-fill fs-5"></i></button>';
                                                    }
                                                    echo '</span>';
                                                    echo '</div>';

                                                    // Handle Location Out
                                                    if ($att['location_out']) {
                                                        $loc_out = $att['location_out'];
                                                        echo '<div class="d-flex align-items-center">';
                                                        echo '<small class="text-muted d-inline-block" style="width: 32px;">Out:</small>';
                                                        if (strpos($loc_out, 'Outside|') === 0) {
                                                            $coords = str_replace('Outside|', '', $loc_out);
                                                            echo '<a href="https://www.google.com/maps/search/?api=1&query=' . urlencode($coords) . '" target="_blank" class="text-danger text-decoration-none fw-semibold"><i class="bi bi-geo-alt-fill"></i> View Map</a>';
                                                        } else {
                                                            echo '<small class="text-muted">' . htmlspecialchars($loc_out) . '</small>';
                                                        }
                                                        
                                                        // Selfie Trigger Out (same fixed-width slot as In row)
                                                        echo '<span class="d-inline-flex justify-content-center" style="width: 28px;">';
                                                        if (!empty($att['photo_out'])) {
                                                            echo '<button type="button" class="btn btn-sm btn-link text-info p-0 ms-2" data-bs-toggle="modal" data-bs-target="#selfieModalOut' . $att['attendance_id'] . '" title="View Check-Out Selfie"><i class="bi bi-camera-fill fs-5"></i></button>';
                                                        }
                                                        echo '</span>';
                                                        echo '</div>';
                                                    }
                                                ?>

                                                <!-- Dynamic Selfie Verification Modals -->
                                                <?php if (!empty($att['photo_in'])): ?>
                                                <div class="modal fade" id="selfieModalIn<?= $att['attendance_id'] ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content shadow-lg border-info" style="background-color: var(--bg-card);">
                                                            <div class="modal-header border-bottom border-secondary">
                                                                <h5 class="modal-title fw-bold text-info"><i class="bi bi-camera me-2"></i> Clock-In Verification</h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body p-4 text-center">
                                                                <img src="../uploads/attendance/<?= htmlspecialchars($att['photo_in']) ?>" alt="Clock In Selfie" class="img-fluid rounded-3 mb-3 border border-secondary" style="max-height: 400px; object-fit: cover;">
                                                                <div class="bg-dark p-3 rounded-3 text-start border border-secondary border-opacity-50">
                                                                    <h6 class="text-muted text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 1px;">Employee Remarks</h6>
                                                                    <p class="text-light mb-0 fs-6"><?= htmlspecialchars($att['remarks'] ?? 'No remarks provided.') ?></p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                                <?php if (!empty($att['photo_out'])): ?>
                                                <div class="modal fade" id="selfieModalOut<?= $att['attendance_id'] ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content shadow-lg border-info" style="background-color: var(--bg-card);">
                                                            <div class="modal-header border-bottom border-secondary">
                                                                <h5 class="modal-title fw-bold text-info"><i class="bi bi-camera me-2"></i> Clock-Out Verification</h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body p-4 text-center">
                                                                <img src="../uploads/attendance/<?= htmlspecialchars($att['photo_out']) ?>" alt="Clock Out Selfie" class="img-fluid rounded-3 mb-3 border border-secondary" style="max-height: 400px; object-fit: cover;">
                                                                <div class="bg-dark p-3 rounded-3 text-start border border-secondary border-opacity-50">
                                                                    <h6 class="text-muted text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 1px;">Employee Remarks</h6>
                                                                    <p class="text-light mb-0 fs-6"><?= htmlspecialchars($att['remarks'] ?? 'No remarks provided.') ?></p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center p-5 text-muted">
                                            <i class="bi bi-calendar-x fs-1 d-block mb-3" style="opacity: 0.2"></i>
                                            No attendance records found for <?= $display_month ?>.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // --- Day / Month / Year Filter Mode Switcher ---
        function setFilterMode(mode) {
            document.getElementById('filterModeInput').value = mode;
            document.getElementById('dateInput').classList.toggle('d-none', mode !== 'day');
            document.getElementById('monthInput').classList.toggle('d-none', mode !== 'month');
            document.getElementById('yearInput').classList.toggle('d-none', mode !== 'year');
            document.getElementById('filterForm').submit();
        }
    </script>
</body>
</html>