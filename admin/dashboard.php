<?php
session_start();

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Use ../ to go up one directory to find config.php in the root folder
require '../config.php';

// Quick stats queries mapping to your database tables
// "Total Employees" card = active workforce only, so Resigned/Terminated don't inflate it
$totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status NOT IN ('Resigned', 'Terminated')")->fetchColumn();
$pendingLeaves = $pdo->query("SELECT COUNT(*) FROM leave_applications WHERE status = 'Pending'")->fetchColumn();
$totalDepartments = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();

// Fetch today's attendance count
$today = date('Y-m-d');
$presentToday = $pdo->prepare("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE attendance_date = ?");
$presentToday->execute([$today]);
$presentCount = $presentToday->fetchColumn();

// --- RECENT LEAVE APPLICATIONS (latest 6, any status) ---
$recent_leaves = $pdo->query("
    SELECT la.*, e.full_name, e.employee_no, t.leave_name 
    FROM leave_applications la 
    JOIN employees e ON la.employee_id = e.employee_id 
    JOIN leave_types t ON la.leave_type_id = t.leave_type_id 
    ORDER BY la.created_at DESC 
    LIMIT 6
")->fetchAll();

// --- RECENT LATE ARRIVALS (latest 6) ---
$recent_late = $pdo->query("
    SELECT a.*, e.full_name, e.employee_no 
    FROM attendance a 
    JOIN employees e ON a.employee_id = e.employee_id 
    WHERE a.status = 'Late' 
    ORDER BY a.attendance_date DESC, a.clock_in DESC 
    LIMIT 6
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Attendora</title>
    <!-- Google Fonts for Modern Typography -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            /* Deep dark backgrounds inspired by the image */
            --bg-main: #0b0d14; 
            --bg-panel: #131620;
            --bg-card: #1a1e2b;
            
            /* Emerald Neon Accents */
            --emerald-neon: #10b981;
            --emerald-glow: rgba(16, 185, 129, 0.4);
            --emerald-glow-strong: rgba(16, 185, 129, 0.6);
            
            /* Text Colors */
            --text-light: #f8fafc;
            --text-muted: #94a3b8; /* Lighter slate gray for dark mode */
        }

        body { 
            background-color: var(--bg-main); 
            color: var(--text-light);
            font-family: 'Poppins', sans-serif;
            background-image: radial-gradient(circle at top left, rgba(16, 185, 129, 0.08), transparent 40%),
                              radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.05), transparent 40%);
            min-height: 100vh;
        }

        .text-muted {
            color: var(--text-muted) !important;
        }

        /* Sidebar CSS layout rules removed - controlled cleanly in administrative side file */
        
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

        /* Active Sidebar Link with Glowing Emerald Effect */
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
            transition: all 0.3s;
        }
        .btn-logout:hover {
            background-color: var(--emerald-neon);
            color: #000 !important;
            box-shadow: 0 0 15px var(--emerald-glow-strong);
        }

        /* Solid Emerald Button for Dashboard Actions */
        .btn-emerald-solid {
            background-color: var(--emerald-neon);
            color: #0b0d14 !important; /* Dark text for high contrast */
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 0 15px var(--emerald-glow);
        }
        .btn-emerald-solid:hover {
            background-color: #059669;
            color: #fff !important;
            box-shadow: 0 0 25px var(--emerald-glow-strong);
            transform: translateY(-2px);
        }

        /* Header Styling */
        .top-header {
            background: var(--bg-panel);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
        }

        /* Dashboard Stat Cards */
        .stat-card { 
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.5), 0 0 15px var(--emerald-glow);
            border-color: rgba(16, 185, 129, 0.3);
        }

        /* Top Accent Line for Cards */
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--emerald-neon);
            box-shadow: 0 0 10px var(--emerald-neon);
            opacity: 0.5;
        }

        .stat-card h6 {
            font-size: 0.85rem;
            letter-spacing: 1px;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-light);
            text-shadow: 0 2px 10px rgba(0,0,0,0.5);
        }

        /* Specific card icon colors */
        .icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: rgba(16, 185, 129, 0.1);
            color: var(--emerald-neon);
        }

        /* General Panel styles */
        .panel-card {
            background: var(--bg-panel);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
        }
        
        .text-emerald { color: var(--emerald-neon) !important; }
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
                    <h4 class="mb-1 fw-bold">Welcome back, <span class="text-emerald"><?= htmlspecialchars($_SESSION['name']) ?></span></h4>
                    <p class="text-muted mb-0 fs-6">Here is what's happening across Finora today.</p>
                </div>
                <div class="text-emerald fw-semibold px-4 py-2 rounded-pill" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2);">
                    <i class="bi bi-calendar-event me-2"></i> <?= date('l, d M Y') ?>
                </div>
            </div>

            <!-- Dashboard Stats -->
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="card stat-card h-100 p-2">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="text-uppercase text-muted fw-semibold">Total Employees</h6>
                                    <div class="stat-value"><?= $totalEmployees ?></div>
                                </div>
                                <div class="icon-wrapper">
                                    <i class="bi bi-people"></i>
                                </div>
                            </div>
                            <small class="text-emerald"><i class="bi bi-arrow-up-short"></i> Active workforce</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card h-100 p-2">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="text-uppercase text-muted fw-semibold">Present Today</h6>
                                    <div class="stat-value"><?= $presentCount ?></div>
                                </div>
                                <div class="icon-wrapper">
                                    <i class="bi bi-person-check"></i>
                                </div>
                            </div>
                            <small class="text-emerald"><i class="bi bi-clock"></i> Clocked in today</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card h-100 p-2">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="text-uppercase text-muted fw-semibold">Pending Leaves</h6>
                                    <div class="stat-value"><?= $pendingLeaves ?></div>
                                </div>
                                <div class="icon-wrapper" style="color: #f59e0b; background: rgba(245, 158, 11, 0.1);">
                                    <i class="bi bi-envelope-paper"></i>
                                </div>
                            </div>
                            <small class="text-warning"><i class="bi bi-exclamation-circle"></i> Requires approval</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card stat-card h-100 p-2">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="text-uppercase text-muted fw-semibold">Departments</h6>
                                    <div class="stat-value"><?= $totalDepartments ?></div>
                                </div>
                                <div class="icon-wrapper" style="color: #3b82f6; background: rgba(59, 130, 246, 0.1);">
                                    <i class="bi bi-building"></i>
                                </div>
                            </div>
                            <small class="text-primary"><i class="bi bi-diagram-3"></i> Company structure</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4 mt-1">
                <!-- Recent Leave Applications -->
                <div class="col-lg-6">
                    <div class="card panel-card shadow-lg h-100">
                        <div class="card-header border-bottom-0 pt-4 pb-3 px-4 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(255,255,255,0.05) !important;">
                            <h5 class="card-title mb-0 fw-bold text-light">Recent Leave Applications</h5>
                            <a href="leave.php" class="text-emerald small text-decoration-none">View All <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <div class="card-body px-4 pb-4 pt-0">
                            <?php if (count($recent_leaves) > 0): ?>
                                <?php foreach ($recent_leaves as $lv): ?>
                                    <?php
                                        $badge_bg = match($lv['status']) {
                                            'Approved' => 'rgba(16,185,129,0.15); color: var(--emerald-neon)',
                                            'Rejected' => 'rgba(239,68,68,0.15); color: #ef4444',
                                            'Cancelled' => 'rgba(107,114,128,0.15); color: #9ca3af',
                                            default => 'rgba(245,158,11,0.15); color: #f59e0b',
                                        };
                                    ?>
                                    <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <div>
                                            <div class="fw-semibold text-white small"><?= htmlspecialchars($lv['full_name']) ?> <span class="text-muted">#<?= htmlspecialchars($lv['employee_no']) ?></span></div>
                                            <div class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars($lv['leave_name']) ?> - <?= date('d M', strtotime($lv['start_date'])) ?> to <?= date('d M Y', strtotime($lv['end_date'])) ?></div>
                                        </div>
                                        <span class="badge rounded-pill px-3 py-2" style="background: <?= $badge_bg ?>;"><?= htmlspecialchars($lv['status']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center py-4 mb-0">No leave applications yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Late Arrivals -->
                <div class="col-lg-6">
                    <div class="card panel-card shadow-lg h-100">
                        <div class="card-header border-bottom-0 pt-4 pb-3 px-4 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(255,255,255,0.05) !important;">
                            <h5 class="card-title mb-0 fw-bold text-light">Recent Late Arrivals</h5>
                            <a href="attendance.php" class="text-emerald small text-decoration-none">View All <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <div class="card-body px-4 pb-4 pt-0">
                            <?php if (count($recent_late) > 0): ?>
                                <?php foreach ($recent_late as $lt): ?>
                                    <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <div>
                                            <div class="fw-semibold text-white small"><?= htmlspecialchars($lt['full_name']) ?> <span class="text-muted">#<?= htmlspecialchars($lt['employee_no']) ?></span></div>
                                            <div class="text-muted" style="font-size: 0.8rem;"><?= date('d M Y', strtotime($lt['attendance_date'])) ?></div>
                                        </div>
                                        <span class="badge rounded-pill px-3 py-2" style="background: rgba(239,68,68,0.15); color: #ef4444;">
                                            <?= $lt['clock_in'] ? date('h:i A', strtotime($lt['clock_in'])) : '--:--' ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center py-4 mb-0">No late arrivals recorded.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card panel-card mt-4 shadow-lg">
                <div class="card-body px-4 py-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-1 fw-bold text-light">View the full reports</h5>
                        <p class="text-muted mb-0">Charts and trends for attendance, leave and department breakdowns.</p>
                    </div>
                    <a href="reports.php" class="btn btn-emerald-solid border-0 px-4 py-2 text-decoration-none">
                        <i class="bi bi-file-earmark-bar-graph me-2"></i> View Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>