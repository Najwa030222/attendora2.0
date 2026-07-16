<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

$admin_id = $_SESSION['user_id'];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $leave_id = $_POST['leave_id'];
    $admin_remarks = $_POST['admin_remarks'];

    $stmt = $pdo->prepare("
        SELECT la.*, t.one_time_only, t.leave_name 
        FROM leave_applications la 
        JOIN leave_types t ON la.leave_type_id = t.leave_type_id 
        WHERE la.leave_id = ? AND la.status = 'Pending'
    ");
    $stmt->execute([$leave_id]);
    $leave = $stmt->fetch();

    if ($leave) {
        $emp_id = $leave['employee_id'];
        $type_id = $leave['leave_type_id'];
        $days = $leave['total_days'];
        // one_time_only types (e.g. Haji Leave) live under leave_year = 0 (lifetime),
        // regardless of which calendar year the leave dates actually fall in.
        $year = $leave['one_time_only'] ? '0000' : (!empty($leave['start_date']) ? date('Y', strtotime($leave['start_date'])) : date('Y'));

        if ($_POST['action'] == 'approve_leave') {
            try {
                $update = $pdo->prepare("UPDATE leave_applications SET status = 'Approved', admin_remarks = ?, approved_by = ?, approval_date = NOW() WHERE leave_id = ?");
                $update->execute([$admin_remarks, $admin_id, $leave_id]);

                $bal = $pdo->prepare("UPDATE leave_balances SET pending_leave = pending_leave - ?, leave_taken = leave_taken + ? WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?");
                $bal->execute([$days, $days, $emp_id, $type_id, $year]);

                $notif = $pdo->prepare("INSERT INTO notifications (employee_id, type, severity, title, message, link) VALUES (?, 'leave_approved', 'success', ?, ?, 'leave.php')");
                $notif->execute([
                    $emp_id,
                    'Leave Request Approved',
                    htmlspecialchars($leave['leave_name']) . ' (' . date('d M', strtotime($leave['start_date'])) . ' - ' . date('d M Y', strtotime($leave['end_date'])) . ') has been approved.'
                ]);

                $message = "Leave application approved successfully.";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = "danger";
            }
        } elseif ($_POST['action'] == 'reject_leave') {
            try {
                $update = $pdo->prepare("UPDATE leave_applications SET status = 'Rejected', admin_remarks = ?, approved_by = ?, approval_date = NOW() WHERE leave_id = ?");
                $update->execute([$admin_remarks, $admin_id, $leave_id]);

                $bal = $pdo->prepare("UPDATE leave_balances SET pending_leave = pending_leave - ?, remaining_balance = remaining_balance + ? WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?");
                $bal->execute([$days, $days, $emp_id, $type_id, $year]);

                $notif = $pdo->prepare("INSERT INTO notifications (employee_id, type, severity, title, message, link) VALUES (?, 'leave_rejected', 'danger', ?, ?, 'leave.php')");
                $notif->execute([
                    $emp_id,
                    'Leave Request Rejected',
                    htmlspecialchars($leave['leave_name']) . ' (' . date('d M', strtotime($leave['start_date'])) . ' - ' . date('d M Y', strtotime($leave['end_date'])) . ') was rejected.' . (!empty($admin_remarks) ? ' Reason: ' . htmlspecialchars($admin_remarks) : '')
                ]);

                $message = "Leave application rejected and balance refunded.";
                $messageType = "info";
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

$pending_stmt = $pdo->query("
    SELECT a.*, e.full_name, e.employee_no, t.leave_name, t.one_time_only, b.remaining_balance 
    FROM leave_applications a 
    JOIN employees e ON a.employee_id = e.employee_id 
    JOIN leave_types t ON a.leave_type_id = t.leave_type_id 
    LEFT JOIN leave_balances b ON a.employee_id = b.employee_id AND a.leave_type_id = b.leave_type_id 
        AND (b.leave_year = YEAR(a.start_date) OR (t.one_time_only = 1 AND b.leave_year = '0000'))
    WHERE a.status = 'Pending'
    ORDER BY a.created_at ASC
");
$pending_leaves = $pending_stmt->fetchAll();

$types_stmt = $pdo->query("SELECT leave_type_id, leave_name FROM leave_types ORDER BY leave_name");
$leave_types_list = $types_stmt->fetchAll();

$filter_month = $_GET['filter_month'] ?? date('Y-m');
$filter_status = $_GET['filter_status'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';
$filter_name = $_GET['filter_name'] ?? '';

$start_date = $filter_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));
$display_month = date('F Y', strtotime($start_date));

$query = "
    SELECT a.*, e.full_name, e.employee_no, t.leave_name 
    FROM leave_applications a 
    JOIN employees e ON a.employee_id = e.employee_id 
    JOIN leave_types t ON a.leave_type_id = t.leave_type_id 
    WHERE (a.start_date BETWEEN ? AND ? OR a.end_date BETWEEN ? AND ?)
    AND a.status != 'Pending'
";
$params = [$start_date, $end_date, $start_date, $end_date];

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

$history_stmt = $pdo->prepare($query);
$history_stmt->execute($params);
$leave_history = $history_stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - Admin Portal</title>
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
            --warning-neon: #eab308;
            --danger-neon: #ef4444;
            --input-bg: #0b0d14;
            /* For matching filter inputs */
        }

        body {
            background-color: var(--bg-main);
            color: #ffffff;
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        /* Layout matching dashboard reference exactly */
        .main-content {
            margin-left: 280px;
            flex-grow: 1;
            width: calc(100% - 280px);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .panel-card {
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            overflow: hidden;
        }

        /* Solid Emerald Button matching the filter design */
        .btn-emerald-solid {
            background-color: var(--emerald-neon);
            color: #0b0d14 !important;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
        }

        .btn-emerald-solid:hover {
            background-color: #059669;
            color: #fff !important;
        }

        /* Filter Form Inputs */
        .filter-input {
            background-color: var(--input-bg) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #ffffff !important;
            font-size: 0.9rem;
        }

        .filter-input:focus {
            box-shadow: 0 0 0 0.25rem rgba(16, 185, 129, 0.25);
            border-color: var(--emerald-neon) !important;
        }

        ::placeholder {
            color: #ffffff !important;
            opacity: 0.5 !important;
        }

        /* Table Styling */
        .table-dark-custom {
            color: #ffffff;
            margin-bottom: 0;
        }

        .table-dark-custom thead th {
            background-color: rgba(0, 0, 0, 0.2);
            color: #a1a1aa;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            font-weight: 500;
            font-size: 0.85rem;
            padding: 15px;
        }

        .table-dark-custom tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .table-dark-custom tbody td {
            padding: 15px;
            background: transparent;
            color: #ffffff;
        }

        ::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
            opacity: 0.6;
        }
    </style>
</head>

<body>
    <div class="d-flex">
        <?php require 'sidebar.php'; ?>

        <div class="main-content p-5">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="mb-1 fw-bold text-white">Leave Management</h3>
                    <p class="text-white mb-0 fs-6" style="opacity: 0.7;">Review applications and generate leave reports.</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> bg-transparent border-<?= $messageType ?> text-white mb-4">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="panel-card mb-5" style="border-left: 4px solid var(--warning-neon);">
                <div class="d-flex align-items-center p-3 border-bottom border-secondary" style="border-color: rgba(255,255,255,0.05) !important;">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 35px; height: 35px; background: rgba(234, 179, 8, 0.15); color: var(--warning-neon);">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-white">Pending Action Required (<?= count($pending_leaves) ?>)</h5>
                </div>

                <div class="table-responsive">
                    <?php if (count($pending_leaves) > 0): ?>
                        <table class="table table-dark-custom">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Leave Details</th>
                                    <th>Duration</th>
                                    <th>Reason</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_leaves as $req): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-white"><?= htmlspecialchars($req['full_name']) ?></div>
                                            <div class="small text-muted">#<?= htmlspecialchars($req['employee_no']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-medium text-emerald" style="color: var(--emerald-neon);"><?= htmlspecialchars($req['leave_name']) ?></div>
                                            <div class="small text-muted">Available Balance: <?= $req['remaining_balance'] ?> Days</div>
                                        </td>
                                        <td>
                                            <div class="text-white">
                                                <?= !empty($req['start_date']) ? date('d/m/Y', strtotime($req['start_date'])) : 'Invalid Date' ?>
                                                <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                                <?= !empty($req['end_date']) ? date('d/m/Y', strtotime($req['end_date'])) : 'Invalid Date' ?>
                                            </div>
                                            <div class="small text-warning" style="color: var(--warning-neon) !important;"><?= $req['total_days'] ?> Days requested</div>
                                        </td>
                                        <td><?= htmlspecialchars($req['reason']) ?></td>
                                        <td class="text-end">
                                            <?php if (!empty($req['attachment'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-info rounded-circle me-1" data-bs-toggle="modal" data-bs-target="#attachModal<?= $req['leave_id'] ?>" title="View Attachment">
                                                    <i class="bi bi-paperclip"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm text-white rounded-pill px-4" style="background: #4f46e5;" data-bs-toggle="modal" data-bs-target="#reviewModal<?= $req['leave_id'] ?>">Review</button>

                                            <?php if (!empty($req['attachment'])): ?>
                                                <div class="modal fade text-start" id="attachModal<?= $req['leave_id'] ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content panel-card">
                                                            <div class="modal-header border-bottom border-secondary">
                                                                <h5 class="modal-title fw-bold text-white"><i class="bi bi-paperclip me-2"></i> Supporting Document</h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body p-4 text-center">
                                                                <?php
                                                                $att_ext = strtolower(pathinfo($req['attachment'], PATHINFO_EXTENSION));
                                                                $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                                                ?>
                                                                <?php if (in_array($att_ext, $image_exts)): ?>
                                                                    <img src="../uploads/leaves/<?= htmlspecialchars($req['attachment']) ?>" alt="Attachment" class="img-fluid rounded-3 border border-secondary" style="max-height: 450px; object-fit: contain;">
                                                                <?php else: ?>
                                                                    <i class="bi bi-file-earmark-text text-info" style="font-size: 4rem;"></i>
                                                                    <p class="text-white mt-3 mb-1 text-break"><?= htmlspecialchars($req['attachment']) ?></p>
                                                                <?php endif; ?>
                                                                <div class="mt-3">
                                                                    <a href="../uploads/leaves/<?= htmlspecialchars($req['attachment']) ?>" target="_blank" class="btn btn-sm btn-outline-light rounded-pill px-4">
                                                                        <i class="bi bi-box-arrow-up-right me-1"></i> Open in New Tab
                                                                    </a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="modal fade text-start" id="reviewModal<?= $req['leave_id'] ?>" tabindex="-1">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content panel-card">
                                                        <div class="modal-header border-bottom" style="border-color: rgba(255,255,255,0.05) !important;">
                                                            <h5 class="modal-title fw-bold text-white">Review Application</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST">
                                                            <div class="modal-body p-4 text-white">
                                                                <input type="hidden" name="leave_id" value="<?= $req['leave_id'] ?>">
                                                                <div class="mb-3">
                                                                    <label class="form-label text-white small text-uppercase">Admin Remarks (Optional)</label>
                                                                    <textarea class="form-control" name="admin_remarks" rows="2" placeholder="Leave a note..." style="background-color: var(--bg-main); border: 1px solid rgba(255,255,255,0.1); color: #fff;"></textarea>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer border-top" style="border-color: rgba(255,255,255,0.05) !important;">
                                                                <button type="submit" name="action" value="reject_leave" class="btn btn-outline-danger rounded-pill px-4">Reject</button>
                                                                <button type="submit" name="action" value="approve_leave" class="btn btn-success rounded-pill px-4" style="background-color: var(--emerald-neon); border-color: var(--emerald-neon);">Approve Leave</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 80px; height: 80px; background: rgba(16, 185, 129, 0.1); color: var(--emerald-neon);">
                                <i class="bi bi-check2-all" style="font-size: 2.5rem;"></i>
                            </div>
                            <h5 class="text-white fw-bold">All caught up!</h5>
                            <p class="text-white mb-0">There are no pending leave applications to review right now.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Leave Master Record -->
            <div class="d-flex justify-content-between align-items-center mb-3 mt-5">
                <h4 class="fw-bold mb-0 text-white">Leave Master Record</h4>
                <form method="GET" action="leave_export.php" target="_blank" class="m-0">
                    <input type="hidden" name="filter_month" value="<?= htmlspecialchars($filter_month) ?>">
                    <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type) ?>">
                    <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filter_status) ?>">
                    <input type="hidden" name="filter_name" value="<?= htmlspecialchars($filter_name) ?>">
                    <button type="submit" class="btn text-white rounded-pill px-4" style="background: #4f46e5;">
                        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                    </button>
                </form>
            </div>

            <div class="panel-card p-3 mb-4">
                <form method="GET" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <div class="input-group">
                            <span class="input-group-text border-0" style="background-color: var(--input-bg); color: rgba(255,255,255,0.5);">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" name="filter_name" class="form-control filter-input border-start-0 ps-0" placeholder="Search by full name..." value="<?= htmlspecialchars($filter_name) ?>">
                        </div>
                    </div>

                    <div class="col-md-2">
                        <input type="month" name="filter_month" class="form-control filter-input" value="<?= htmlspecialchars($filter_month) ?>" title="Filter by Month">
                    </div>

                    <div class="col-md-3">
                        <select name="filter_type" class="form-select filter-input">
                            <option value="">All Leave Types</option>
                            <?php foreach ($leave_types_list as $t): ?>
                                <option value="<?= $t['leave_type_id'] ?>" <?= $filter_type == $t['leave_type_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['leave_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <select name="filter_status" class="form-select filter-input">
                            <option value="">All Statuses</option>
                            <option value="Approved" <?= $filter_status == 'Approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="Rejected" <?= $filter_status == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="Cancelled" <?= $filter_status == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <button type="submit" class="btn btn-emerald-solid w-100 rounded-3 py-2">
                            <i class="bi bi-funnel-fill me-1"></i> Filter
                        </button>
                    </div>
                </form>
            </div>

            <div class="panel-card" id="report-content">
                <div class="table-responsive">
                    <table class="table table-dark-custom" id="leaveTable">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Dates</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Attachment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($leave_history) > 0): foreach ($leave_history as $h): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-white"><?= htmlspecialchars($h['full_name']) ?></div>
                                            <div class="small text-muted">#<?= htmlspecialchars($h['employee_no']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($h['leave_name']) ?></td>
                                        <td>
                                            <?= !empty($h['start_date']) ? date('d/m/y', strtotime($h['start_date'])) : 'N/A' ?> to
                                            <?= !empty($h['end_date']) ? date('d/m/y', strtotime($h['end_date'])) : 'N/A' ?>
                                        </td>
                                        <td><?= $h['total_days'] ?></td>
                                        <td>
                                            <?php
                                            if ($h['status'] == 'Approved') echo '<span class="px-3 py-1 rounded-pill" style="background: rgba(16,185,129,0.1); color: var(--emerald-neon); font-size: 0.85rem;"><i class="bi bi-check-circle-fill me-1"></i> Approved</span>';
                                            elseif ($h['status'] == 'Rejected') echo '<span class="px-3 py-1 rounded-pill" style="background: rgba(239,68,68,0.1); color: var(--danger-neon); font-size: 0.85rem;"><i class="bi bi-x-circle-fill me-1"></i> Rejected</span>';
                                            elseif ($h['status'] == 'Cancelled') echo '<span class="px-3 py-1 rounded-pill text-white" style="background: rgba(255,255,255,0.05); font-size: 0.85rem;">Cancelled</span>';
                                            ?>
                                        </td>
                                        <td><small class="text-white"><?= htmlspecialchars($h['admin_remarks'] ?? '-') ?></small></td>
                                        <td>
                                            <?php if (!empty($h['attachment'])): ?>
                                                <a href="../uploads/leaves/<?= htmlspecialchars($h['attachment']) ?>" target="_blank" class="text-info" title="View Attachment">
                                                    <i class="bi bi-paperclip fs-5"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="7" class="text-center p-5">
                                        <div class="py-5 text-muted">
                                            <i class="bi bi-folder-x mb-3 d-block" style="font-size: 3rem; opacity: 0.3;"></i>
                                            <h5 class="text-white fw-medium">No records found</h5>
                                            <p class="mb-0">There are no leave records matching your current filters.</p>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>