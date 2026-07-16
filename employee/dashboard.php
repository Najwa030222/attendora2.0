<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

$emp_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$current_time = date('H:i:s');
$message = '';
$messageType = '';

// --- TIME BOUNDARY RULES ---
$time_start_window = '06:00:00'; // System wakes up
$time_late_cutoff  = '08:30:00'; // Marked late after this
$time_forgot_in    = '11:00:00'; // Forces the 'Forgot Clock In' modal
$time_ot_start     = '17:00:00'; // 5:00 PM, overtime begins
$time_forgot_out   = '21:00:00'; // 9:00 PM, locks down the system

$current_timestamp = strtotime($current_time);
$is_too_early      = $current_timestamp < strtotime($time_start_window);
$is_past_in_cutoff = $current_timestamp > strtotime($time_forgot_in);
$is_past_out_cutoff = $current_timestamp > strtotime($time_forgot_out);

// --- HELPER FUNCTION FOR FILE UPLOADS ---
function handleSelfieUpload($file, $emp_id, $type) {
    if (isset($file) && $file['error'] == 0) {
        $uploadDir = '../uploads/attendance/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        // Unique filename: timestamp_type_empid.jpg
        $fileName = time() . '_' . $type . '_' . $emp_id . '.jpg';
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            return $fileName;
        }
    }
    return null;
}

// --- HANDLE POST REQUESTS ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Forgot Clock In Request (Modal Submission)
    if (isset($_POST['action']) && $_POST['action'] == 'forgot_request') {
        $estimated_time = $_POST['estimated_time'];
        $reason = $_POST['reason'];
        $location_status = $_POST['location_status'] ?? 'Unknown';

        $photo_in = null;
        if (strpos($location_status, 'Outside') === 0) {
            // Outside the office radius: photo + remarks are required (enforced client-side + here)
            $photo_in = handleSelfieUpload($_FILES['selfie'] ?? null, $emp_id, 'in');
            if (!empty($_POST['outside_remarks'])) {
                $reason .= " | [Outside] " . $_POST['outside_remarks'];
            }
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO attendance_requests (employee_id, request_date, estimated_time, reason, status, location_in, photo_in) VALUES (?, ?, ?, ?, 'Pending', ?, ?)");
            $stmt->execute([$emp_id, $today, $estimated_time, $reason, $location_status, $photo_in]);
            $message = "Request sent to HR for approval.";
            $messageType = "success";
        } catch(PDOException $e) {
            $message = "Error submitting request. Please contact Admin.";
            $messageType = "danger";
        }
    }

    // 2. Missing Clock Out Resolution (The Blocker)
    if (isset($_POST['action']) && $_POST['action'] == 'forgot_clock_out_request') {
        $att_id = $_POST['attendance_id'];
        $missed_date = $_POST['missed_date'];
        $estimated_time = $_POST['estimated_time'];
        $reason = "[Forgot Clock Out] " . $_POST['reason']; 
        
        try {
            $stmt = $pdo->prepare("INSERT INTO attendance_requests (employee_id, request_date, estimated_time, reason, status) VALUES (?, ?, ?, ?, 'Pending')");
            $stmt->execute([$emp_id, $missed_date, $estimated_time, $reason]);
            
            // overtime_hours is now calculated automatically by the DB trigger when clock_out is set
            $update = $pdo->prepare("UPDATE attendance SET clock_out = ?, remarks = 'Clock Out Adjusted - Pending HR' WHERE attendance_id = ?");
            $update->execute([$estimated_time, $att_id]);
            
            $message = "Missing clock-out resolved. HR has been notified.";
            $messageType = "success";
        } catch(PDOException $e) {
            $message = "Error submitting adjustment: " . $e->getMessage();
            $messageType = "danger";
        }
    }

    // 3. Standard & Outside Clock In
    if (isset($_POST['action']) && $_POST['action'] == 'clock_in') {
        if ($is_too_early || $is_past_out_cutoff) {
            $message = "System is currently offline. Office hours are closed.";
            $messageType = "danger";
        } elseif ($is_past_in_cutoff) {
            $message = "Clock-in cutoff time has passed. Please submit an adjustment request.";
            $messageType = "warning";
        } else {
            $location_status = $_POST['location_status'];
            $status = ($current_timestamp > strtotime($time_late_cutoff)) ? 'Late' : 'On Time';
            
            // Check for Selfie and Remarks
            $photo_in = handleSelfieUpload($_FILES['selfie'] ?? null, $emp_id, 'in');
            $remarks = !empty($_POST['outside_remarks']) ? "[Outside In] " . $_POST['outside_remarks'] : null;

            try {
                $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, clock_in, status, location_in, photo_in, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$emp_id, $today, $current_time, $status, $location_status, $photo_in, $remarks]);
                $message = "Successfully Clocked In at " . date('h:i A');
                $messageType = "success";
            } catch(PDOException $e) {
                $message = "Error clocking in. You may have already clocked in today.";
                $messageType = "danger";
            }
        }
    }

    // 4. Standard & Outside Clock Out
    if (isset($_POST['action']) && $_POST['action'] == 'clock_out') {
        if ($is_past_out_cutoff) {
            $message = "Standard clock out closed at 9:00 PM. Please use the missing data form.";
            $messageType = "warning";
        } else {
            $location_status = $_POST['location_status'];

            // overtime_hours is now calculated automatically by the DB trigger when clock_out is set

            // Handle Selfie & Fetch existing remarks to append
            $photo_out = handleSelfieUpload($_FILES['selfie'] ?? null, $emp_id, 'out');
            
            $get_rem = $pdo->prepare("SELECT remarks FROM attendance WHERE employee_id = ? AND attendance_date = ?");
            $get_rem->execute([$emp_id, $today]);
            $existing_remarks = $get_rem->fetchColumn();
            
            $final_remarks = $existing_remarks;
            if (!empty($_POST['outside_remarks'])) {
                $new_rmk = "[Outside Out] " . $_POST['outside_remarks'];
                $final_remarks = $existing_remarks ? $existing_remarks . " | " . $new_rmk : $new_rmk;
            }

            try {
                $stmt = $pdo->prepare("UPDATE attendance SET clock_out = ?, location_out = ?, photo_out = ?, remarks = ? WHERE employee_id = ? AND attendance_date = ?");
                $stmt->execute([$current_time, $location_status, $photo_out, $final_remarks, $emp_id, $today]);
                
                if ($stmt->rowCount() == 0) {
                    $insert_stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, clock_out, location_out, photo_out, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                    $insert_stmt->execute([$emp_id, $today, $current_time, $location_status, $photo_out, $final_remarks]);
                }

                $message = "Successfully Clocked Out at " . date('h:i A');
                $messageType = "success";
            } catch(PDOException $e) {
                $message = "Error clocking out.";
                $messageType = "danger";
            }
        }
    }
    // 5. Mark Notifications as Read (called via fetch(), no page reload)
    if (isset($_POST['action']) && $_POST['action'] == 'mark_notifications_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE employee_id = ? AND is_read = 0")->execute([$emp_id]);
        exit; // no need to render the rest of the page for this AJAX call
    }
}

// --- CHECK FOR MISSING PREVIOUS CLOCK OUTS (THE BLOCKER) ---
$missing_out_stmt = $pdo->prepare("
    SELECT * FROM attendance 
    WHERE employee_id = ? 
    AND (attendance_date < ? OR (attendance_date = ? AND ? > ?))
    AND clock_in IS NOT NULL 
    AND clock_out IS NULL 
    ORDER BY attendance_date ASC LIMIT 1
");
$missing_out_stmt->execute([$emp_id, $today, $today, $current_time, $time_forgot_out]);
$missing_clock_out = $missing_out_stmt->fetch();

// --- FETCH TODAY'S ATTENDANCE STATUS ---
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
$stmt->execute([$emp_id, $today]);
$attendance_today = $stmt->fetch();

$has_clocked_in = $attendance_today && $attendance_today['clock_in'] ? true : false;
$has_clocked_out = $attendance_today && $attendance_today['clock_out'] ? true : false;

$req_stmt = $pdo->prepare("SELECT * FROM attendance_requests WHERE employee_id = ? AND request_date = ? ORDER BY id DESC LIMIT 1");
$req_stmt->execute([$emp_id, $today]);
$pending_request = $req_stmt->fetch();

$is_estimated = ($pending_request && $pending_request['status'] === 'Pending');

// --- DASHBOARD WIDGETS: DATA FETCH ---
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

// OT eligibility (based on department)
$ot_elig_stmt = $pdo->prepare("
    SELECT d.overtime_eligible 
    FROM employees e 
    JOIN departments d ON e.department_id = d.department_id 
    WHERE e.employee_id = ?
");
$ot_elig_stmt->execute([$emp_id]);
$is_overtime_eligible = (bool) $ot_elig_stmt->fetchColumn();

// This month's hours worked
$hours_col = $is_overtime_eligible ? 'total_hours' : 'working_hours';
$hours_stmt = $pdo->prepare("SELECT COALESCE(SUM($hours_col), 0) FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$hours_stmt->execute([$emp_id, $month_start, $month_end]);
$month_hours = (float) $hours_stmt->fetchColumn();
$mh_h = floor($month_hours);
$mh_m = round(($month_hours - $mh_h) * 60);
$month_hours_display = "{$mh_h}h {$mh_m}m";

// --- STRICT LEAVE BALANCE CALCULATION (Filtered exactly like leave.php) ---
// 1. Fetch employee demographics to determine eligibility
$emp_det_stmt = $pdo->prepare("SELECT gender, religion, marital_status, employment_status FROM employees WHERE employee_id = ?");
$emp_det_stmt->execute([$emp_id]);
$emp_details = $emp_det_stmt->fetch();

// 2. Fetch all leave types and build allowed IDs array
$types_stmt = $pdo->query("SELECT * FROM leave_types");
$all_leave_types = $types_stmt->fetchAll();

$allowed_leave_type_ids = [];
foreach ($all_leave_types as $type) {
    if ($emp_details['employment_status'] === 'On Probation' && $type['leave_name'] === 'Unpaid Leave') continue;
    if ($type['eligible_gender'] != 'All' && $type['eligible_gender'] != $emp_details['gender']) continue;
    if ($type['eligible_religion'] != 'All' && $type['eligible_religion'] != $emp_details['religion']) continue;
    if (($type['leave_name'] == 'Maternity Leave' || $type['leave_name'] == 'Paternity Leave') && $emp_details['marital_status'] != 'Married') continue;
    
    $allowed_leave_type_ids[] = $type['leave_type_id'];
}

// 3. Fetch all raw balances for the employee for this year (or lifetime one_time_only)
$leave_bal_stmt = $pdo->prepare("
    SELECT b.leave_type_id, b.remaining_balance 
    FROM leave_balances b 
    JOIN leave_types t ON b.leave_type_id = t.leave_type_id 
    WHERE b.employee_id = ? AND (b.leave_year = ? OR (t.one_time_only = 1 AND b.leave_year = '0000'))
");
$leave_bal_stmt->execute([$emp_id, date('Y')]);
$raw_balances = $leave_bal_stmt->fetchAll();

// 4. Sum up only the balances the employee is eligible for
$total_leave_remaining = 0;
foreach ($raw_balances as $b) {
    if (in_array($b['leave_type_id'], $allowed_leave_type_ids)) {
        $total_leave_remaining += $b['remaining_balance'];
    }
}
// --- END STRICT LEAVE BALANCE CALCULATION ---

// Most recent leave request
$recent_leave_stmt = $pdo->prepare("
    SELECT la.*, t.leave_name 
    FROM leave_applications la 
    JOIN leave_types t ON la.leave_type_id = t.leave_type_id 
    WHERE la.employee_id = ? 
    ORDER BY la.created_at DESC LIMIT 1
");
$recent_leave_stmt->execute([$emp_id]);
$recent_leave = $recent_leave_stmt->fetch();

// Late-count flag this month
$late_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND status = 'Late' AND attendance_date BETWEEN ? AND ?");
$late_count_stmt->execute([$emp_id, $month_start, $month_end]);
$late_count_this_month = (int) $late_count_stmt->fetchColumn();
if ($late_count_this_month >= 10) {
    $late_flag_color = '#ef4444'; $late_flag_bg = 'rgba(239, 68, 68, 0.1)'; $late_flag_label = 'Frequent Lateness';
} elseif ($late_count_this_month >= 5) {
    $late_flag_color = '#f59e0b'; $late_flag_bg = 'rgba(245, 158, 11, 0.1)'; $late_flag_label = 'Warning';
} else {
    $late_flag_color = 'var(--emerald-neon)'; $late_flag_bg = 'rgba(16, 185, 129, 0.1)'; $late_flag_label = 'Good Standing';
}

// --- NOTIFICATIONS ---
$notif_stmt = $pdo->prepare("SELECT * FROM notifications WHERE employee_id = ? ORDER BY created_at DESC LIMIT 15");
$notif_stmt->execute([$emp_id]);
$notifications = $notif_stmt->fetchAll();
$unread_count = 0;
foreach ($notifications as $n) { if (!$n['is_read']) $unread_count++; }

// --- FIRST LOGIN CHECK ---
$fl_stmt = $pdo->prepare("SELECT is_first_login FROM employees WHERE employee_id = ?");
$fl_stmt->execute([$emp_id]);
$is_first_login = (bool) $fl_stmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Clock - Finora Portal</title>
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
            --emerald-glow-strong: rgba(16, 185, 129, 0.6);
            --text-light: #ffffff;
            --text-muted: #94a3b8;
            --danger-neon: #ef4444;
            --danger-glow: rgba(239, 68, 68, 0.4);
            --radius-lg: 24px;
            --radius-md: 16px;
        }

        body { 
            background-color: var(--bg-main); 
            color: var(--text-light);
            font-family: 'Poppins', sans-serif;
            background-image: radial-gradient(circle at top left, rgba(16, 185, 129, 0.05), transparent 40%),
                              radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.05), transparent 40%);
            min-height: 100vh;
        }

        .text-muted { color: var(--text-muted) !important; }
        .text-emerald { color: var(--emerald-neon) !important; }
        .text-white { color: var(--text-light) !important; }

        .main-content { margin-left: 280px; flex-grow: 1; min-height: 100vh; overflow-x: hidden; }

        .clock-container {
            background: var(--bg-card);
            border: 1px solid rgba(16, 185, 129, 0.1);
            border-radius: var(--radius-lg);
            padding: 40px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5), inset 0 0 20px rgba(16, 185, 129, 0.03);
            position: relative;
            overflow: hidden;
        }
        
        .clock-container::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px;
            background: var(--emerald-neon); box-shadow: 0 0 15px var(--emerald-neon);
        }
        
        .clock-container.blocked::before { background: var(--danger-neon); box-shadow: 0 0 15px var(--danger-neon); }

        .digital-clock {
            font-size: 4.5rem;
            font-weight: 700;
            color: var(--text-light);
            text-shadow: 0 0 20px rgba(255,255,255,0.1);
            letter-spacing: 2px;
            font-variant-numeric: tabular-nums;
        }

        .btn-clock {
            width: 220px;
            height: 60px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            border: none;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-clock-in { background-color: var(--emerald-neon); color: #000; box-shadow: 0 0 20px var(--emerald-glow); }
        .btn-clock-in:hover { background-color: #059669; color: #fff; transform: translateY(-2px); box-shadow: 0 5px 25px var(--emerald-glow-strong); }

        .btn-clock-out { background-color: var(--danger-neon); color: #fff; box-shadow: 0 0 20px var(--danger-glow); }
        .btn-clock-out:hover { background-color: #dc2626; transform: translateY(-2px); box-shadow: 0 5px 25px rgba(239, 68, 68, 0.6); }
        
        .btn-clock-disabled { background-color: var(--bg-panel); color: #475569; border: 1px solid rgba(255,255,255,0.05); cursor: not-allowed; box-shadow: none; }

        .panel-card { background: var(--bg-card); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: var(--radius-md); }

        .modal-content { background-color: var(--bg-card); border: 1px solid rgba(255,255,255,0.1); border-radius: var(--radius-md); }
        .modal-header { border-bottom: 1px solid rgba(255,255,255,0.05); }
        .modal-footer { border-top: 1px solid rgba(255,255,255,0.05); }
        .form-control { background-color: var(--bg-panel); border: 1px solid rgba(255,255,255,0.1); color: var(--text-light); border-radius: 12px; padding: 12px 15px;}
        .form-control:focus { background-color: var(--bg-panel); color: white; border-color: var(--emerald-neon); box-shadow: 0 0 10px var(--emerald-glow); }
        ::placeholder { color: #ffffff !important; opacity: 0.6 !important; }
        
        #location-status { font-weight: 600; transition: color 0.3s; }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php require 'sidebar.php'; ?>

        <div class="main-content p-4 p-lg-5">
            <div class="d-flex justify-content-between align-items-center mb-4 p-4 shadow-sm panel-card">
                <div>
                    <h4 class="mb-1 fw-bold text-white">Welcome, <span class="text-emerald"><?= htmlspecialchars($_SESSION['name']) ?></span></h4>
                    <p class="text-muted mb-0 fs-6">Office Hours: 08:00 AM - 05:00 PM</p>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <!-- Notification Bell -->
                    <div class="dropdown">
                        <button class="btn position-relative p-2" type="button" id="notifBell" data-bs-toggle="dropdown" aria-expanded="false" style="background: rgba(255,255,255,0.05); border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                            <i class="bi bi-bell-fill text-white fs-5"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem;"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-0" style="width: 340px; max-height: 420px; overflow-y: auto; background: var(--bg-card); border: 1px solid rgba(255,255,255,0.1);">
                            <div class="p-3 border-bottom" style="border-color: rgba(255,255,255,0.05) !important;">
                                <h6 class="mb-0 fw-bold text-white">Notifications</h6>
                            </div>
                            <?php if (count($notifications) > 0): ?>
                                <?php foreach ($notifications as $n): ?>
                                    <?php
                                        $sev_color = ['info' => '#3b82f6', 'success' => '#10b981', 'warning' => '#f59e0b', 'danger' => '#ef4444'][$n['severity']] ?? '#94a3b8';
                                    ?>
                                    <a href="<?= !empty($n['link']) ? htmlspecialchars($n['link']) : '#' ?>" class="d-block px-3 py-2 text-decoration-none" style="border-left: 3px solid <?= $sev_color ?>; <?= $n['is_read'] ? 'opacity: 0.55;' : 'background: rgba(255,255,255,0.02);' ?>">
                                        <div class="fw-semibold text-white small"><?= htmlspecialchars($n['title']) ?></div>
                                        <div class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars($n['message']) ?></div>
                                        <div class="text-muted" style="font-size: 0.7rem;"><?= date('d M, h:i A', strtotime($n['created_at'])) ?></div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted p-4 small">No notifications yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-emerald fw-semibold px-4 py-2 rounded-pill" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2);">
                        <i class="bi bi-calendar-event me-2"></i> <?= date('l, d M Y') ?>
                    </div>
                </div>
            </div>

            <!-- Dashboard Summary Widgets -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="panel-card p-3 h-100">
                        <div class="text-muted small text-uppercase mb-1" style="letter-spacing: 1px; font-size: 0.75rem;">Hours Worked This Month</div>
                        <div class="fs-4 fw-bold text-white"><?= $month_hours_display ?></div>
                        <?php if (!$is_overtime_eligible): ?>
                            <div class="text-muted" style="font-size: 0.7rem;">Regular hours only</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="panel-card p-3 h-100">
                        <div class="text-muted small text-uppercase mb-1" style="letter-spacing: 1px; font-size: 0.75rem;">Leave Balance</div>
                        <div class="fs-4 fw-bold text-white"><?= $total_leave_remaining ?> <span class="fs-6 text-muted fw-normal">days left</span></div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="panel-card p-3 h-100">
                        <div class="text-muted small text-uppercase mb-1" style="letter-spacing: 1px; font-size: 0.75rem;">Latest Leave Request</div>
                        <?php if ($recent_leave): ?>
                            <div class="fw-bold text-white small"><?= htmlspecialchars($recent_leave['leave_name']) ?></div>
                            <span class="badge rounded-pill px-2 py-1 mt-1" style="font-size: 0.7rem; background: <?= $recent_leave['status'] === 'Approved' ? 'rgba(16,185,129,0.15); color: var(--emerald-neon)' : ($recent_leave['status'] === 'Rejected' ? 'rgba(239,68,68,0.15); color: #ef4444' : 'rgba(245,158,11,0.15); color: #f59e0b') ?>;"><?= htmlspecialchars($recent_leave['status']) ?></span>
                        <?php else: ?>
                            <div class="text-muted small">No requests yet</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="panel-card p-3 h-100" style="border-color: <?= $late_flag_color ?>33 !important;">
                        <div class="text-muted small text-uppercase mb-1" style="letter-spacing: 1px; font-size: 0.75rem;">This Month's Standing</div>
                        <div class="fw-bold small" style="color: <?= $late_flag_color ?>;"><?= $late_flag_label ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Late <?= $late_count_this_month ?> time<?= $late_count_this_month == 1 ? '' : 's' ?> this month</div>
                    </div>
                </div>
            </div>

            <?php if($message): ?>
                <div class="alert alert-<?= $messageType ?> bg-transparent border-<?= $messageType ?> border-opacity-50 text-white mb-4 rounded-4" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="row justify-content-center mt-5">
                <div class="col-md-8 col-lg-7 col-xl-6">
                    <div class="clock-container <?= $missing_clock_out ? 'blocked' : '' ?>">
                        <div class="text-muted mb-2 text-uppercase" style="letter-spacing: 2px; font-size: 0.9rem;">Current Time</div>
                        <div class="digital-clock mb-2" id="live-clock">00:00:00</div>
                        <div class="text-muted mb-5" id="live-date">Loading date...</div>

                        <div class="mb-4 p-3 rounded-4" style="background: rgba(0,0,0,0.2);">
                            <i class="bi bi-geo-alt-fill me-2"></i> Location: 
                            <span id="location-status" class="text-warning">Detecting GPS...</span>
                            <small class="d-block text-muted mt-1" style="font-size: 0.75rem;">Radius limit: 1km from HQ</small>
                        </div>

                        <!-- Main Attendance Form (Inside) -->
                        <form method="POST" id="attendance-form">
                            <input type="hidden" name="location_status" id="form_location_status" value="Unknown">
                            <input type="hidden" name="action" id="main_action" value="">
                        </form>
                        
                        <!-- Actions Interface -->
                        <?php if ($missing_clock_out): ?>
                            <div class="alert alert-danger bg-transparent border-danger text-danger border-opacity-50 py-3 mb-4 fs-6 text-center rounded-3">
                                <i class="bi bi-exclamation-triangle-fill fs-5 d-block mb-2"></i> 
                                <strong>Action Required:</strong> You forgot to clock out on <br><?= date('l, d M Y', strtotime($missing_clock_out['attendance_date'])) ?>.
                            </div>
                            <button type="button" class="btn btn-clock btn-clock-out w-100" data-bs-toggle="modal" data-bs-target="#missingClockOutModal">Clock Out</button>

                        <?php elseif (!$has_clocked_in && !$is_estimated): ?>
                            
                            <?php if ($is_too_early || $is_past_out_cutoff): ?>
                                <button type="button" class="btn btn-clock btn-clock-disabled w-100" disabled>System Offline</button>
                                <div class="mt-3 text-muted small"><i class="bi bi-info-circle"></i> Clock In available from 06:00 AM to 11:00 AM.</div>
                                
                            <?php elseif ($is_past_in_cutoff): ?>
                                <button type="button" class="btn btn-clock btn-clock-in w-100" id="main-clock-btn" data-bs-toggle="modal" data-bs-target="#forgotClockInModal" disabled>Clock In</button>
                                
                            <?php else: ?>
                                <button type="button" class="btn btn-clock btn-clock-in w-100" id="main-clock-btn" onclick="processAttendance('clock_in')" disabled>Clock In</button>
                            <?php endif; ?>
                            
                        <?php elseif (($has_clocked_in || $is_estimated) && !$has_clocked_out): ?>
                            <button type="button" class="btn btn-clock btn-clock-out w-100" id="main-clock-btn" onclick="processAttendance('clock_out')" disabled>Clock Out</button>
                            
                        <?php else: ?>
                            <button type="button" class="btn btn-clock btn-clock-disabled w-100" disabled>Shift Completed</button>
                        <?php endif; ?>
                        
                    </div>
                </div>
            </div>

            <!-- Today's Record Summary -->
            <?php if ($has_clocked_in || $is_estimated || $has_clocked_out): ?>
            <div class="row justify-content-center mt-4">
                <div class="col-md-8 col-lg-7 col-xl-6">
                    <div class="card panel-card shadow-lg p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2 text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Time In</h6>
                                <?php if ($is_estimated && !$has_clocked_in): ?>
                                    <div class="fs-5 fw-bold text-warning" style="text-shadow: 0 0 10px rgba(245, 158, 11, 0.4);">
                                        <?= date('h:i A', strtotime($pending_request['estimated_time'])) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="fs-5 fw-bold text-white"><?= date('h:i A', strtotime($attendance_today['clock_in'])) ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="text-center">
                                <h6 class="text-muted mb-2 text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Status</h6>
                                <?php if ($is_estimated && !$has_clocked_in): ?>
                                    <span class="badge rounded-pill px-3 py-2 fw-medium" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);">PENDING HR</span>
                                <?php elseif (isset($attendance_today['status']) && $attendance_today['status'] === 'Late'): ?>
                                    <span class="badge rounded-pill px-3 py-2 fw-medium" style="background: rgba(239, 68, 68, 0.15); color: var(--danger-neon); border: 1px solid rgba(239, 68, 68, 0.3);">LATE</span>
                                <?php elseif (isset($attendance_today['status']) && strpos($attendance_today['status'], 'Approved') !== false): ?>
                                    <span class="badge rounded-pill px-3 py-2 fw-medium" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3);">HR VERIFIED</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill px-3 py-2 fw-medium" style="background: rgba(16, 185, 129, 0.15); color: var(--emerald-neon); border: 1px solid rgba(16, 185, 129, 0.3);">ON TIME</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="text-end">
                                <h6 class="text-muted mb-2 text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Time Out</h6>
                                <div class="fs-5 fw-bold text-white">
                                    <?= ($attendance_today && $attendance_today['clock_out']) ? date('h:i A', strtotime($attendance_today['clock_out'])) : '--:--' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- 0. First Login - Forced Password Change Modal -->
    <?php if ($is_first_login): ?>
    <div class="modal fade" id="firstLoginModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-warning">
                <div class="modal-header border-bottom border-secondary">
                    <h5 class="modal-title fw-bold text-warning"><i class="bi bi-shield-exclamation me-2"></i> Password Change Required</h5>
                </div>
                <div class="modal-body p-4 text-white">
                    <p class="mb-0">For your account's security, you must set your own password before continuing. This only takes a moment.</p>
                </div>
                <div class="modal-footer border-top border-secondary">
                    <a href="profile.php#security" class="btn text-dark fw-bold rounded-pill px-4 w-100" style="background: var(--emerald-neon);">
                        <i class="bi bi-key-fill me-2"></i> Change Password Now
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 1. Missing Clock Out Modal -->
    <?php if ($missing_clock_out): ?>
    <div class="modal fade" id="missingClockOutModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-danger">
                <div class="modal-header border-bottom border-secondary">
                    <h5 class="modal-title fw-bold text-danger"><i class="bi bi-shield-lock-fill me-2"></i> Missed Clock-Out</h5>
                </div>
                <form method="POST">
                    <div class="modal-body p-4 text-white">
                        <input type="hidden" name="action" value="forgot_clock_out_request">
                        <input type="hidden" name="attendance_id" value="<?= $missing_clock_out['attendance_id'] ?>">
                        <input type="hidden" name="missed_date" value="<?= $missing_clock_out['attendance_date'] ?>">
                        
                        <p class="text-muted mb-4 fs-6">You cannot clock in until you resolve your missing clock-out from <strong><?= date('l, d M Y', strtotime($missing_clock_out['attendance_date'])) ?></strong>.</p>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted text-uppercase" style="font-size: 0.8rem;">Estimated Departure Time</label>
                            <input type="time" class="form-control" name="estimated_time" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted text-uppercase" style="font-size: 0.8rem;">Reason / Remarks</label>
                            <textarea class="form-control" name="reason" rows="3" required placeholder="e.g. Rushed home for an emergency, forgot to check the portal..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top border-secondary">
                        <button type="submit" class="btn btn-danger rounded-pill px-4 border-0"><i class="bi bi-unlock-fill me-2"></i> Submit & Unlock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2. Late Clock-In Adjustment Modal -->
    <div class="modal fade" id="forgotClockInModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-bottom border-secondary">
                    <h5 class="modal-title fw-bold text-emerald"><i class="bi bi-exclamation-circle me-2"></i> Forgot/Late Clock-In Adjustment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="forgotClockInForm">
                    <div class="modal-body p-4 text-white">
                        <input type="hidden" name="action" value="forgot_request">
                        <input type="hidden" name="location_status" id="forgot_location_status" value="Unknown">
                        <div class="alert alert-warning bg-transparent border-warning text-warning border-opacity-50 py-2 mb-4 fs-6 rounded-3">
                            <i class="bi bi-info-circle-fill me-1"></i> You are attempting to clock in past the 11:00 AM cutoff.
                        </div>
                        <p class="text-muted mb-4 fs-6">Please submit your estimated arrival time and reason. HR will review this request.</p>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted text-uppercase" style="font-size: 0.8rem;">Estimated Arrival Time</label>
                            <input type="time" class="form-control" name="estimated_time" id="forgot_estimated_time" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted text-uppercase" style="font-size: 0.8rem;">Reason</label>
                            <textarea class="form-control" name="reason" id="forgot_reason" rows="3" required placeholder="e.g. Traffic accident, system was down, forgot my phone..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top border-secondary">
                        <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn text-dark fw-bold rounded-pill px-4" style="background: var(--emerald-neon);" onclick="handleForgotClockInSubmit()">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 3. Outside Location Selfie Verification Modal -->
    <div class="modal fade" id="outsideLocationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-warning">
                <div class="modal-header border-bottom border-secondary">
                    <h5 class="modal-title fw-bold text-warning" id="outsideModalTitle"><i class="bi bi-camera me-2"></i> Outside Verification</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <!-- Multipart Form is required for file uploads -->
                <form method="POST" enctype="multipart/form-data" id="outsideVerificationForm">
                    <div class="modal-body p-4 text-white">
                        <input type="hidden" name="action" id="outside_action" value="">
                        <input type="hidden" name="location_status" id="outside_location_status" value="">
                        <input type="hidden" name="estimated_time" id="outside_estimated_time" value="">
                        <input type="hidden" name="reason" id="outside_reason" value="">
                        
                        <div class="alert alert-warning bg-transparent border-warning text-warning border-opacity-50 py-2 mb-4 fs-6 rounded-3">
                            <i class="bi bi-geo-alt-fill me-1"></i> You are currently outside the 1km office radius.
                        </div>
                        <p class="text-muted mb-4 fs-6">To proceed, please capture a live photo and provide a brief explanation for working remotely.</p>
                        
                        <div class="mb-4">
                            <label class="form-label text-muted text-uppercase" style="font-size: 0.8rem;">Live Photo Verification *</label>
                            
                            <div id="cameraContainer" class="rounded-3 overflow-hidden position-relative" style="background: #000; aspect-ratio: 4/3;">
                                <video id="cameraVideo" autoplay playsinline class="w-100 h-100" style="object-fit: cover;"></video>
                                <canvas id="cameraCanvas" class="w-100 h-100 d-none" style="object-fit: cover;"></canvas>
                                <div id="cameraError" class="d-none position-absolute top-50 start-50 translate-middle text-center text-danger px-3">
                                    <i class="bi bi-camera-video-off fs-2 d-block mb-2"></i>
                                    <small>Camera access denied or unavailable. Please allow camera permission and reopen this form.</small>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-2">
                                <button type="button" id="captureBtn" class="btn btn-outline-light flex-grow-1 rounded-pill" onclick="capturePhoto()">
                                    <i class="bi bi-camera-fill me-1"></i> Capture Photo
                                </button>
                                <button type="button" id="retakeBtn" class="btn btn-outline-warning flex-grow-1 rounded-pill d-none" onclick="retakePhoto()">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Retake
                                </button>
                            </div>

                            <!-- The actual file sent to the server - populated from the canvas snapshot -->
                            <input type="file" name="selfie" id="selfieFileInput" class="d-none">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted text-uppercase" style="font-size: 0.8rem;">Remote Remarks *</label>
                            <textarea class="form-control" name="outside_remarks" rows="3" required placeholder="e.g. Client meeting at Site A, Approved Work From Home..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-top border-secondary">
                        <button type="button" class="btn btn-outline-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-outline-light text-green fw-bold rounded-pill px-4" style="background: var(--warning-neon);">Submit Verification</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // --- 1. LIVE CLOCK LOGIC ---
        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            let ampm = hours >= 12 ? 'PM' : 'AM';
            
            hours = hours % 12;
            hours = hours ? hours : 12; 
            
            hours = hours < 10 ? '0' + hours : hours;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            
            document.getElementById('live-clock').textContent = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
            
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('live-date').textContent = now.toLocaleDateString('en-US', options);
        }
        
        setInterval(updateClock, 1000);
        updateClock(); 

        // --- 2. SMART ATTENDANCE PROCESSOR (Handles Modals vs Direct Submit) ---
        function processAttendance(actionType) {
            const locStatus = document.getElementById('form_location_status').value;
            
            if (locStatus.startsWith('Outside')) {
                // If Outside, trigger the Photo Verification Modal instead of submitting
                document.getElementById('outside_location_status').value = locStatus;
                document.getElementById('outside_action').value = actionType;
                
                document.getElementById('outsideModalTitle').innerHTML = actionType === 'clock_in' ? 
                    '<i class="bi bi-camera me-2"></i> Outside Clock-In Verification' : 
                    '<i class="bi bi-camera me-2"></i> Outside Clock-Out Verification';
                    
                const outsideModal = new bootstrap.Modal(document.getElementById('outsideLocationModal'));
                outsideModal.show();
            } else {
                // If Inside, process immediately
                document.getElementById('main_action').value = actionType;
                document.getElementById('attendance-form').submit();
            }
        }

        // --- 2b. LATE CLOCK-IN REQUEST PROCESSOR ---
        // Same idea as processAttendance(), but for the "forgot to clock in" request form.
        // If the employee is outside the office radius, route through the same photo
        // verification modal (carrying the estimated_time/reason along with it) instead
        // of silently submitting without any location proof.
        function handleForgotClockInSubmit() {
            const form = document.getElementById('forgotClockInForm');
            if (!form.reportValidity()) return; // respect required fields (estimated_time, reason)

            const locStatus = document.getElementById('forgot_location_status').value;

            if (locStatus.startsWith('Outside')) {
                // Carry the late clock-in fields over to the outside-verification modal
                document.getElementById('outside_estimated_time').value = document.getElementById('forgot_estimated_time').value;
                document.getElementById('outside_reason').value = document.getElementById('forgot_reason').value;
                document.getElementById('outside_location_status').value = locStatus;
                document.getElementById('outside_action').value = 'forgot_request';

                document.getElementById('outsideModalTitle').innerHTML =
                    '<i class="bi bi-camera me-2"></i> Outside Late Clock-In Verification';

                const forgotModalEl = document.getElementById('forgotClockInModal');
                const forgotModal = bootstrap.Modal.getInstance(forgotModalEl) || new bootstrap.Modal(forgotModalEl);
                forgotModal.hide();

                const outsideModal = new bootstrap.Modal(document.getElementById('outsideLocationModal'));
                outsideModal.show();
            } else {
                // Inside office (or location denied) - submit directly, location_status already set
                form.submit();
            }
        }

        // --- 3. GEOLOCATION LOGIC ---
        const hqLat = 3.133906;
        const hqLon = 101.493705;
        const radiusKm = 1.0; 

        function getDistanceFromLatLonInKm(lat1, lon1, lat2, lon2) {
            const R = 6371; 
            const dLat = (lat2-lat1) * (Math.PI/180);
            const dLon = (lon2-lon1) * (Math.PI/180); 
            const a = 
                Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * (Math.PI/180)) * Math.cos(lat2 * (Math.PI/180)) * Math.sin(dLon/2) * Math.sin(dLon/2); 
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)); 
            return R * c; 
        }

        function checkLocation() {
            const statusEl = document.getElementById('location-status');
            const hiddenInput = document.getElementById('form_location_status');
            const forgotHiddenInput = document.getElementById('forgot_location_status');
            const clockBtn = document.getElementById('main-clock-btn');

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const userLat = position.coords.latitude;
                        const userLon = position.coords.longitude;
                        
                        const distance = getDistanceFromLatLonInKm(hqLat, hqLon, userLat, userLon);
                        
                        if (distance <= radiusKm) {
                            statusEl.textContent = 'Inside Office (' + distance.toFixed(2) + 'km)';
                            statusEl.className = 'text-emerald';
                            hiddenInput.value = 'Inside Office';
                            if (forgotHiddenInput) forgotHiddenInput.value = 'Inside Office';
                        } else {
                            statusEl.textContent = 'Outside Office (' + distance.toFixed(2) + 'km)';
                            statusEl.className = 'text-warning';
                            hiddenInput.value = 'Outside|' + userLat + ',' + userLon;
                            if (forgotHiddenInput) forgotHiddenInput.value = 'Outside|' + userLat + ',' + userLon;
                        }
                        
                        if(clockBtn && !clockBtn.classList.contains('btn-clock-disabled')) {
                            clockBtn.disabled = false;
                        }
                    },
                    (error) => {
                        statusEl.textContent = 'Location access denied.';
                        statusEl.className = 'text-danger';
                        hiddenInput.value = 'Location Denied';
                        if (forgotHiddenInput) forgotHiddenInput.value = 'Location Denied';
                        
                        if(clockBtn && !clockBtn.classList.contains('btn-clock-disabled')) {
                            clockBtn.disabled = false;
                        }
                    },
                    { enableHighAccuracy: true } 
                );
            } else {
                statusEl.textContent = 'Geolocation not supported.';
                statusEl.className = 'text-danger';
            }
        }

        document.addEventListener('DOMContentLoaded', checkLocation);

        // --- 5. FIRST LOGIN - FORCE PASSWORD CHANGE MODAL ---
        <?php if ($is_first_login): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new bootstrap.Modal(document.getElementById('firstLoginModal')).show();
        });
        <?php endif; ?>

        // --- 6. MARK NOTIFICATIONS AS READ WHEN BELL IS OPENED ---
        <?php if ($unread_count > 0): ?>
        document.getElementById('notifBell').addEventListener('click', function() {
            fetch('dashboard.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_notifications_read'
            }).then(() => {
                // Remove the unread badge locally - no need to reload the page
                const badge = this.querySelector('.badge');
                if (badge) badge.remove();
            });
        }, { once: true });
        <?php endif; ?>

        // --- 4. LIVE CAMERA CAPTURE (Outside Verification) ---
        let cameraStream = null;

        function startCamera() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('cameraCanvas');
            const errorBox = document.getElementById('cameraError');
            const captureBtn = document.getElementById('captureBtn');

            // Reset to live-preview state each time the modal opens
            video.classList.remove('d-none');
            canvas.classList.add('d-none');
            errorBox.classList.add('d-none');
            captureBtn.classList.remove('d-none');
            document.getElementById('retakeBtn').classList.add('d-none');
            document.getElementById('selfieFileInput').value = '';

            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
                .then((stream) => {
                    cameraStream = stream;
                    video.srcObject = stream;
                })
                .catch(() => {
                    errorBox.classList.remove('d-none');
                    captureBtn.classList.add('d-none');
                });
        }

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
        }

        function capturePhoto() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('cameraCanvas');
            const ctx = canvas.getContext('2d');

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            canvas.toBlob((blob) => {
                const file = new File([blob], 'selfie_' + Date.now() + '.jpg', { type: 'image/jpeg' });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('selfieFileInput').files = dataTransfer.files;
            }, 'image/jpeg', 0.9);

            video.classList.add('d-none');
            canvas.classList.remove('d-none');
            document.getElementById('captureBtn').classList.add('d-none');
            document.getElementById('retakeBtn').classList.remove('d-none');
        }

        function retakePhoto() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('cameraCanvas');

            document.getElementById('selfieFileInput').value = '';
            canvas.classList.add('d-none');
            video.classList.remove('d-none');
            document.getElementById('captureBtn').classList.remove('d-none');
            document.getElementById('retakeBtn').classList.add('d-none');
        }

        const outsideModalEl = document.getElementById('outsideLocationModal');
        outsideModalEl.addEventListener('shown.bs.modal', startCamera);
        outsideModalEl.addEventListener('hidden.bs.modal', stopCamera);

        document.getElementById('outsideVerificationForm').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('selfieFileInput');
            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault();
                alert('Please capture a photo before submitting.');
            }
        });
    </script>
</body>
</html>