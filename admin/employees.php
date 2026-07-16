<?php
session_start();

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

// --- ADDED: Fetch departments for the filter dropdown ---
$dept_stmt = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
$all_departments = $dept_stmt->fetchAll();

// --- ADDED: Handle filter parameters ---
$search_name = $_GET['search_name'] ?? '';
$filter_dept = $_GET['department_id'] ?? '';
$filter_status = $_GET['employment_status'] ?? ''; // Added Status Filter

// --- MODIFIED (Additive only): Dynamic query based on filters ---
// Main list only shows active workforce (Active / On Probation) - Resigned/Terminated live in the Archive below
$query = "
    SELECT e.employee_id, e.employee_no, e.full_name, e.position, e.employment_status, d.department_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE e.employment_status NOT IN ('Resigned', 'Terminated')
";
$params = [];

if (!empty($search_name)) {
    $query .= " AND e.full_name LIKE ?";
    $params[] = "%$search_name%";
}

if (!empty($filter_dept)) {
    $query .= " AND e.department_id = ?";
    $params[] = $filter_dept;
}

if (!empty($filter_status)) {
    $query .= " AND e.employment_status = ?";
    $params[] = $filter_status;
}

$query .= " ORDER BY e.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$employees = $stmt->fetchAll();

// --- ARCHIVE: Resigned / Terminated employees, same search/department filters applied ---
$archive_query = "
    SELECT e.employee_id, e.employee_no, e.full_name, e.position, e.employment_status, d.department_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE e.employment_status IN ('Resigned', 'Terminated')
";
$archive_params = [];

if (!empty($search_name)) {
    $archive_query .= " AND e.full_name LIKE ?";
    $archive_params[] = "%$search_name%";
}

if (!empty($filter_dept)) {
    $archive_query .= " AND e.department_id = ?";
    $archive_params[] = $filter_dept;
}

$archive_query .= " ORDER BY e.created_at DESC";

$archive_stmt = $pdo->prepare($archive_query);
$archive_stmt->execute($archive_params);
$archived_employees = $archive_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management - Attendora</title>
    <!-- Google Fonts for Modern Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
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
        }

        body { 
            background-color: var(--bg-main); 
            color: var(--text-light);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        .text-muted { color: var(--text-muted) !important; }
        .text-emerald { color: var(--emerald-neon) !important; }

        /* Sidebar styles removed - handled by standard require of admin/sidebar.php */
        
        /* Main Content Wrapper */
        .main-content {
            margin-left: 280px;
            flex-grow: 1;
            width: calc(100% - 280px);
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .sidebar-brand {
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .sidebar a { 
            color: var(--text-muted); 
            text-decoration: none; 
            padding: 14px 24px; 
            display: block; 
            transition: all 0.3s ease; 
            font-weight: 500;
            font-size: 0.95rem;
            border-left: 4px solid transparent;
        }
        
        .sidebar a:hover {
            color: var(--text-light);
            background: rgba(255, 255, 255, 0.03);
        }

        .sidebar a.active { 
            background: linear-gradient(90deg, rgba(16,185,129,0.15) 0%, transparent 100%);
            color: var(--emerald-neon); 
            border-left: 4px solid var(--emerald-neon); 
            text-shadow: 0 0 10px var(--emerald-glow);
        }

        /* Rounded pill button style for logout */
        .btn-logout {
            background-color: transparent;
            border: 1px solid var(--emerald-neon);
            color: var(--emerald-neon) !important;
            border-radius: 50px;
            margin: 0 20px;
            text-align: center;
            padding: 10px 20px !important;
            text-decoration: none;
            transition: all 0.3s;
        }
        .btn-logout:hover {
            background-color: var(--emerald-neon);
            color: #000 !important;
            box-shadow: 0 0 15px var(--emerald-glow-strong);
        }

        /* Top Header */
        .top-header {
            background: var(--bg-panel);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
        }

        /* Table Panel Styling */
        .panel-card {
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
        }
        
        /* Dark Theme Table overrides */
        .table-dark-custom {
            color: var(--text-light);
            vertical-align: middle;
        }
        .table-dark-custom thead th {
            background-color: var(--bg-panel);
            color: var(--text-muted);
            font-weight: 600;
            border-bottom: 2px solid rgba(16, 185, 129, 0.3);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
            padding: 15px;
        }
        .table-dark-custom tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: background-color 0.2s;
        }
        .table-dark-custom tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        .table-dark-custom tbody td {
            padding: 15px;
            background: transparent;
            color: var(--text-light);
            border-bottom: none;
        }

        /* Status Badges */
        .badge-active { background: rgba(16, 185, 129, 0.15); color: var(--emerald-neon); border: 1px solid var(--emerald-glow); }
        .badge-probation { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-inactive { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }

        /* Emerald Button */
        .btn-emerald-solid {
            background-color: var(--emerald-neon);
            color: #0b0d14 !important;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 0 15px var(--emerald-glow);
        }
        .btn-emerald-solid:hover {
            background-color: #059669;
            color: #fff !important;
            transform: translateY(-2px);
        }
        
        .btn-outline-emerald {
            border: 1px solid var(--emerald-neon);
            color: var(--emerald-neon);
            border-radius: 8px;
            transition: all 0.3s;
        }
        .btn-outline-emerald:hover {
            background: rgba(16, 185, 129, 0.1);
            color: var(--emerald-neon);
        }

        /* ADDED: Form styles for the filter inputs */
        .form-control, .form-select { 
            background-color: var(--bg-panel); 
            border: 1px solid rgba(255,255,255,0.1); 
            color: var(--text-light); 
            padding: 10px 15px; 
            border-radius: 8px;
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--bg-panel); 
            color: var(--text-light); 
            border-color: var(--emerald-neon); 
            box-shadow: 0 0 10px var(--emerald-glow);
        }
        select option { background-color: var(--bg-card); color: var(--text-light); }
        .form-control::placeholder { color: rgba(255,255,255,0.3); }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar Navigation -->
        <?php require 'sidebar.php'; ?>

        <!-- Main Content Area -->
        <div class="main-content p-5">
            
            <!-- Header -->
            <div class="top-header d-flex justify-content-between align-items-center mb-4 p-4 shadow-sm">
                <div>
                    <h4 class="mb-1 fw-bold">Employee Directory</h4>
                    <p class="text-muted mb-0 fs-6">Manage and view all registered employees.</p>
                </div>
                <a href="add_employee.php" class="btn btn-emerald-solid border-0 px-4 py-2 text-decoration-none">
                    <i class="bi bi-person-plus-fill me-2"></i> Add Employee
                </a>
            </div>

            <!-- ADDED: Filter Section -->
            <div class="card panel-card shadow-sm mb-4">
                <div class="card-body p-3">
                    <form method="GET" class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <input type="text" name="search_name" class="form-control" placeholder="Search by full name..." value="<?= htmlspecialchars($search_name) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="department_id" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach($all_departments as $dept): ?>
                                    <option value="<?= $dept['department_id'] ?>" <?= $filter_dept == $dept['department_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['department_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="employment_status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="Active" <?= $filter_status == 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="On Probation" <?= $filter_status == 'On Probation' ? 'selected' : '' ?>>On Probation</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-emerald-solid flex-grow-1"><i class="bi bi-funnel-fill me-1"></i> Filter</button>
                            <?php if(!empty($search_name) || !empty($filter_dept) || !empty($filter_status)): ?>
                                <a href="employees.php" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="border-color: rgba(255,255,255,0.2); color: var(--text-light);" title="Clear Filters">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Data Table Card -->
            <div class="card panel-card shadow-lg">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Emp ID</th>
                                    <th>Full Name</th>
                                    <th>Department</th>
                                    <th>Position</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($employees) > 0): ?>
                                    <?php foreach($employees as $emp): ?>
                                        <tr>
                                            <td class="text-muted fw-semibold">#<?= htmlspecialchars($emp['employee_no']) ?></td>
                                            <td class="fw-bold"><?= htmlspecialchars($emp['full_name']) ?></td>
                                            <td><?= htmlspecialchars($emp['department_name'] ?? 'Not Assigned') ?></td>
                                            <td><?= htmlspecialchars($emp['position']) ?></td>
                                            <td>
                                                <?php 
                                                    if($emp['employment_status'] === 'Active') {
                                                        echo '<span class="badge badge-active rounded-pill px-3 py-2">Active</span>';
                                                    } elseif($emp['employment_status'] === 'On Probation') {
                                                        echo '<span class="badge badge-probation rounded-pill px-3 py-2">Probation</span>';
                                                    } else {
                                                        echo '<span class="badge badge-inactive rounded-pill px-3 py-2">'.$emp['employment_status'].'</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td class="text-end">
                                                <!-- MODIFIED: Replaced two buttons with a single link to view_employee.php -->
                                                <a href="view_employee.php?id=<?= $emp['employee_id'] ?>" class="btn btn-sm btn-outline-emerald" title="View/Edit Profile">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center p-5 text-muted">
                                            <i class="bi bi-people fs-1 d-block mb-3" style="opacity: 0.2"></i>
                                            <?php if(!empty($search_name) || !empty($filter_dept) || !empty($filter_status)): ?>
                                                No employees found matching your filters.
                                            <?php else: ?>
                                                No employees found in the system.
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Archive: Resigned / Terminated Employees -->
            <div class="card panel-card shadow-lg mt-4">
                <div class="card-header border-0 bg-transparent py-3 px-4" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#archiveSection">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold text-muted">
                            <i class="bi bi-archive-fill me-2"></i> Archive - Resigned / Terminated (<?= count($archived_employees) ?>)
                        </h6>
                        <i class="bi bi-chevron-down text-muted"></i>
                    </div>
                </div>
                <div class="collapse" id="archiveSection">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Emp ID</th>
                                        <th>Full Name</th>
                                        <th>Department</th>
                                        <th>Position</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($archived_employees) > 0): ?>
                                        <?php foreach($archived_employees as $emp): ?>
                                            <tr style="opacity: 0.7;">
                                                <td class="text-muted fw-semibold">#<?= htmlspecialchars($emp['employee_no']) ?></td>
                                                <td class="fw-bold"><?= htmlspecialchars($emp['full_name']) ?></td>
                                                <td><?= htmlspecialchars($emp['department_name'] ?? 'Not Assigned') ?></td>
                                                <td><?= htmlspecialchars($emp['position']) ?></td>
                                                <td><span class="badge badge-inactive rounded-pill px-3 py-2"><?= htmlspecialchars($emp['employment_status']) ?></span></td>
                                                <td class="text-end">
                                                    <a href="view_employee.php?id=<?= $emp['employee_id'] ?>" class="btn btn-sm btn-outline-emerald" title="View Profile">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center p-4 text-muted">No archived employees.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>