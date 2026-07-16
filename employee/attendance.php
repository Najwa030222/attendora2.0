<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// Check if user is logged in and is an Employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

$emp_id = $_SESSION['user_id'];

// --- OVERTIME ELIGIBILITY (based on department) ---
$ot_elig_stmt = $pdo->prepare("
    SELECT d.overtime_eligible 
    FROM employees e 
    JOIN departments d ON e.department_id = d.department_id 
    WHERE e.employee_id = ?
");
$ot_elig_stmt->execute([$emp_id]);
$is_overtime_eligible = (bool) $ot_elig_stmt->fetchColumn();

// --- FILTER LOGIC ---
// Default to the current month if no filter is specified
$selected_month = $_GET['filter_month'] ?? date('Y-m'); 

$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));
$display_month = date('F Y', strtotime($start_date));

// --- FETCH ATTENDANCE DATA ---
$att_stmt = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
    ORDER BY attendance_date DESC
");
$att_stmt->execute([$emp_id, $start_date, $end_date]);
$attendances = $att_stmt->fetchAll();

// --- MONTHLY OVERTIME TOTAL (summed in SQL, not PHP) ---
$ot_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(overtime_hours), 0) AS total_overtime_hours
    FROM attendance
    WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
");
$ot_stmt->execute([$emp_id, $start_date, $end_date]);
$total_overtime_hours = (float) $ot_stmt->fetchColumn();

$ot_h = floor($total_overtime_hours);
$ot_m = round(($total_overtime_hours - $ot_h) * 60);
$overtime_display = $ot_h . 'h ' . $ot_m . 'm';

// --- MONTHLY WORKING HOURS TOTAL (shown instead of OT for non-eligible departments) ---
$wh_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(working_hours), 0) AS total_working_hours
    FROM attendance
    WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
");
$wh_stmt->execute([$emp_id, $start_date, $end_date]);
$total_working_hours = (float) $wh_stmt->fetchColumn();

$wh_h = floor($total_working_hours);
$wh_m = round(($total_working_hours - $wh_h) * 60);
$working_hours_display = $wh_h . 'h ' . $wh_m . 'm';

// --- CALCULATE MONTHLY STATS (status tallies only; hours come from SQL above) ---
$stats = [
    'present' => 0,
    'on_time' => 0,
    'late' => 0
];

foreach ($attendances as $att) {
    // Treat 'Approved' / 'Manual Adjustment Approved' as present as well
    if (in_array($att['status'], ['On Time', 'Late', 'Half Day', 'Early Leave', 'Overtime']) || strpos($att['status'], 'Approved') !== false) {
        $stats['present']++;
    }
    if ($att['status'] === 'On Time') {
        $stats['on_time']++;
    }
    if ($att['status'] === 'Late') {
        $stats['late']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - Finora Portal</title>
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
            --danger-neon: #ef4444;
            --warning-neon: #f59e0b;
        }

        body { 
            background-color: var(--bg-main); 
            color: var(--text-light);
            font-family: 'Poppins', sans-serif;
            background-image: radial-gradient(circle at top left, rgba(16, 185, 129, 0.05), transparent 40%),
                              radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.05), transparent 40%);
            min-height: 100vh;
        }

        .text-emerald { color: var(--emerald-neon) !important; }
        .text-white { color: #ffffff !important; }
        
        .main-content { margin-left: 280px; flex-grow: 1; min-height: 100vh; overflow-x: hidden; }
        
        .panel-card { background: var(--bg-card); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 24px; overflow: hidden; }
        .stat-card { background: var(--bg-panel); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        
        /* Dropdowns and Inputs */
        .form-control, .form-select { 
            background-color: var(--bg-main); 
            border: 1px solid rgba(255,255,255,0.15); 
            color: #ffffff; 
            border-radius: 12px;
            padding: 10px 15px;
        }
        .form-control:focus, .form-select:focus { 
            background-color: var(--bg-main); 
            color: white; 
            border-color: var(--emerald-neon); 
            box-shadow: 0 0 10px var(--emerald-glow); 
        }
        
        /* Custom Input Placeholders */
        ::placeholder { color: #ffffff !important; opacity: 0.7 !important; }
        
        /* Table Styles */
        .table-dark-custom { color: #ffffff; vertical-align: middle; margin-bottom: 0; }
        .table-dark-custom thead th { 
            background-color: rgba(0,0,0,0.2); 
            color: #a1a1aa; 
            font-weight: 600; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.1); 
            text-transform: uppercase; 
            font-size: 0.8rem; 
            letter-spacing: 1px;
            padding: 18px 15px; 
        }
        .table-dark-custom tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .table-dark-custom tbody td { padding: 18px 15px; background: transparent; color: #ffffff; font-size: 0.9rem;}
        
        /* Badges */
        .badge-ontime { background: rgba(16, 185, 129, 0.15); color: var(--emerald-neon); border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-late { background: rgba(239, 68, 68, 0.15); color: var(--danger-neon); border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-other { background: rgba(245, 158, 11, 0.15); color: var(--warning-neon); border: 1px solid rgba(245, 158, 11, 0.3); }
        
        ::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; opacity: 0.8; }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php require 'sidebar.php'; ?>

        <div class="main-content p-4 p-lg-5">
            
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-5 p-4 shadow-sm panel-card" style="border-radius: 16px;">
                <div class="mb-3 mb-md-0">
                    <h4 class="mb-1 fw-bold text-white">My Attendance</h4>
                    <p class="text-white mb-0 fs-6">Review your clock-in history and monthly statistics.</p>
                </div>
                
                <div class="d-flex align-items-center gap-2">
                    <form method="GET" class="d-flex align-items-center">
                        <div class="input-group">
                            <span class="input-group-text bg-transparent border-end-0" style="border-color: rgba(255,255,255,0.15); color: #a1a1aa;">
                                <i class="bi bi-calendar3"></i>
                            </span>
                            <input type="month" name="filter_month" class="form-control border-start-0 ps-0" 
                                   value="<?= htmlspecialchars($selected_month) ?>" 
                                   onchange="this.form.submit()" 
                                   style="cursor: pointer;">
                        </div>
                    </form>
                    <a href="attendance_export.php?filter_month=<?= htmlspecialchars($selected_month) ?>" target="_blank" class="btn text-dark fw-bold rounded-pill px-4" style="background: var(--emerald-neon);">
                        <i class="bi bi-file-earmark-pdf me-1"></i> Export
                    </a>
                </div>
            </div>

            <!-- Month Statistics -->
            <h5 class="fw-bold mb-3 px-1 text-white">Summary for <?= $display_month ?></h5>
            <div class="row g-4 mb-5">
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card p-4 h-100 d-flex align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                            <i class="bi bi-calendar-check fs-4"></i>
                        </div>
                        <div>
                            <div class="text-white small fw-medium text-uppercase tracking-wide">Days Present</div>
                            <h3 class="fw-bold text-white mb-0"><?= $stats['present'] ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card p-4 h-100 d-flex align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; background: rgba(16, 185, 129, 0.1); color: var(--emerald-neon);">
                            <i class="bi bi-clock-history fs-4"></i>
                        </div>
                        <div>
                            <div class="text-white small fw-medium text-uppercase tracking-wide">On Time</div>
                            <h3 class="fw-bold text-white mb-0"><?= $stats['on_time'] ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card p-4 h-100 d-flex align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; background: rgba(239, 68, 68, 0.1); color: var(--danger-neon);">
                            <i class="bi bi-exclamation-triangle fs-4"></i>
                        </div>
                        <div>
                            <div class="text-white small fw-medium text-uppercase tracking-wide">Late Arrivals</div>
                            <h3 class="fw-bold text-white mb-0"><?= $stats['late'] ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card p-4 h-100 d-flex align-items-center">
                        <?php if ($is_overtime_eligible): ?>
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; background: rgba(168, 85, 247, 0.1); color: #a855f7;">
                                <i class="bi bi-lightning-charge fs-4"></i>
                            </div>
                            <div>
                                <div class="text-white small fw-medium text-uppercase tracking-wide">Overtime Logged</div>
                                <h3 class="fw-bold text-white mb-0"><?= $overtime_display ?></h3>
                            </div>
                        <?php else: ?>
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; background: rgba(16, 185, 129, 0.1); color: var(--emerald-neon);">
                                <i class="bi bi-hourglass-split fs-4"></i>
                            </div>
                            <div>
                                <div class="text-white small fw-medium text-uppercase tracking-wide">Hours Worked</div>
                                <h3 class="fw-bold text-white mb-0"><?= $working_hours_display ?></h3>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Attendance History Table -->
            <div class="panel-card shadow-lg">
                <div class="table-responsive">
                    <table class="table table-dark-custom w-100">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Total Hours</th>
                                <?php if ($is_overtime_eligible): ?>
                                <th>Overtime</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th>Location Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($attendances) > 0): foreach($attendances as $att): ?>
                            <?php
                                // For OT-eligible departments: total_hours (working_hours + overtime_hours)
                                // from the DB generated column. For everyone else: working_hours only,
                                // since they don't see overtime at all.
                                $total_hours_display = '--';
                                $hours_field = $is_overtime_eligible ? 'total_hours' : 'working_hours';
                                if (isset($att[$hours_field]) && $att[$hours_field] !== null) {
                                    $t = (float) $att[$hours_field];
                                    $h = floor($t);
                                    $m = round(($t - $h) * 60);
                                    $total_hours_display = "{$h}h {$m}m";
                                }
                            ?>
                            <tr>
                                <td class="fw-medium text-white">
                                    <div class="d-flex flex-column">
                                        <span><?= date('D, d M Y', strtotime($att['attendance_date'])) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if($att['clock_in']): ?>
                                        <span class="text-white fw-bold"><?= date('h:i A', strtotime($att['clock_in'])) ?></span>
                                    <?php else: ?>
                                        <span class="text-white fw-bold">--:--</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($att['clock_out']): ?>
                                        <span class="text-white fw-bold"><?= date('h:i A', strtotime($att['clock_out'])) ?></span>
                                    <?php else: ?>
                                        <span class="text-white">--:--</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-white fw-bold"><?= $total_hours_display ?></span>
                                </td>
                                <?php if ($is_overtime_eligible): ?>
                                <td>
                                    <?php if(isset($att['overtime_hours']) && $att['overtime_hours'] > 0): ?>
                                        <?php
                                            $ot = (float) $att['overtime_hours'];
                                            $ot_h = floor($ot);
                                            $ot_m = round(($ot - $ot_h) * 60);
                                        ?>
                                        <span class="text-info fw-bold"><?= $ot_h ?>h <?= $ot_m ?>m</span>
                                    <?php else: ?>
                                        <span class="text-white">-</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php 
                                        $status = htmlspecialchars($att['status'] ?? 'Unknown');
                                        if($status === 'On Time') {
                                            echo "<span class='badge badge-ontime rounded-pill px-3 py-2'>On Time</span>";
                                        } elseif($status === 'Late') {
                                            echo "<span class='badge badge-late rounded-pill px-3 py-2'>Late</span>";
                                        } elseif (strpos($status, 'Approved') !== false) {
                                            echo "<span class='badge rounded-pill px-3 py-2 fw-medium' style='background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3);'>HR Verified</span>";
                                        } else {
                                            echo "<span class='badge badge-other rounded-pill px-3 py-2'>$status</span>";
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                        // Handle Location In
                                        $loc_in = $att['location_in'] ?? 'N/A';
                                        echo '<div class="d-flex align-items-center mb-1">';
                                        echo '<small class="text-white d-inline-block" style="width: 32px;">In:</small>';
                                        if (strpos($loc_in, 'Outside|') === 0) {
                                            $coords = str_replace('Outside|', '', $loc_in);
                                            echo '<a href="https://www.google.com/maps/search/?api=1&query=' . urlencode($coords) . '" target="_blank" class="text-danger text-decoration-none fw-semibold"><i class="bi bi-geo-alt-fill"></i> View Map</a>';
                                        } else {
                                            echo '<small class="text-white">' . htmlspecialchars($loc_in) . '</small>';
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
                                            echo '<small class="text-white d-inline-block" style="width: 32px;">Out:</small>';
                                            if (strpos($loc_out, 'Outside|') === 0) {
                                                $coords = str_replace('Outside|', '', $loc_out);
                                                echo '<a href="https://www.google.com/maps/search/?api=1&query=' . urlencode($coords) . '" target="_blank" class="text-danger text-decoration-none fw-semibold"><i class="bi bi-geo-alt-fill"></i> View Map</a>';
                                            } else {
                                                echo '<small class="text-white">' . htmlspecialchars($loc_out) . '</small>';
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
                                                        <h6 class="text-white text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 1px;">My Remarks</h6>
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
                                                        <h6 class="text-muted text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 1px;">My Remarks</h6>
                                                        <p class="text-light mb-0 fs-6"><?= htmlspecialchars($att['remarks'] ?? 'No remarks provided.') ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td colspan="<?= $is_overtime_eligible ? 7 : 6 ?>" class="text-center p-5">
                                    <div class="text-muted">
                                        <i class="bi bi-calendar-x fs-1 d-block mb-3" style="opacity: 0.2"></i>
                                        No attendance records found for <?= $display_month ?>.
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>