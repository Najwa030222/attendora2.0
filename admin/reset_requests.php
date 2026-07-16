<?php
session_start();

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
header("Location: ../login.php");
exit;
}

require '../config.php';

$success = '';
$error = '';
$admin_id = $_SESSION['user_id'];

// --- HANDLE APPROVE / REJECT ACTIONS ---
if (isset($_GET['action']) && isset($_GET['req_id'])) {
$req_id = $_GET['req_id'];
$action = $_GET['action'];

try {
    if ($action === 'resolve') {
        // 1. Get the Employee ID tied to this request
        $stmt = $pdo->prepare("SELECT employee_id FROM password_reset_requests WHERE request_id = ?");
        $stmt->execute([$req_id]);
        $emp_id = $stmt->fetchColumn();

        if ($emp_id) {
            // 2. Set employee's password back to default & force password change on next login
            $default_hash = password_hash('Attendora@123!', PASSWORD_DEFAULT);
            $upd_emp = $pdo->prepare("UPDATE employees SET password = ?, is_first_login = 1 WHERE employee_id = ?");
            $upd_emp->execute([$default_hash, $emp_id]);

            // 3. Mark the request as Resolved
            $upd_req = $pdo->prepare("UPDATE password_reset_requests SET status = 'Resolved', resolved_by = ? WHERE request_id = ?");
            $upd_req->execute([$admin_id, $req_id]);

            $success = "Password successfully reset to default! User is now forced to configure their password upon logging in.";
        } else {
            $error = "Request record not found.";
        }
    } elseif ($action === 'reject') {
        // Reject and update status
        $upd_req = $pdo->prepare("UPDATE password_reset_requests SET status = 'Rejected', resolved_by = ? WHERE request_id = ?");
        $upd_req->execute([$admin_id, $req_id]);

        $success = "Reset request successfully rejected.";
    }
} catch (PDOException $e) {
    $error = "Action Error: " . $e->getMessage();
}
}

// --- FETCH PENDING AND RECENT RESOLVED REQUESTS ---
$pending_stmt = $pdo->query("
SELECT r.request_id, r.requested_at, e.full_name, e.employee_no, d.department_name, e.position, e.profile_photo
FROM password_reset_requests r
JOIN employees e ON r.employee_id = e.employee_id
LEFT JOIN departments d ON e.department_id = d.department_id
WHERE r.status = 'Pending'
ORDER BY r.requested_at ASC
");
$pending_requests = $pending_stmt->fetchAll();

$resolved_stmt = $pdo->query("
SELECT r.request_id, r.resolved_at, r.status, e.full_name, e.employee_no
FROM password_reset_requests r
JOIN employees e ON r.employee_id = e.employee_id
WHERE r.status IN ('Resolved', 'Rejected')
ORDER BY r.resolved_at DESC
LIMIT 10
");
$resolved_requests = $resolved_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Password Recovery Requests - Attendora</title>
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
    }

    body { 
        background-color: var(--bg-main); 
        color: var(--text-light);
        font-family: 'Poppins', sans-serif;
        min-height: 100vh;
    }

    .main-content {
        margin-left: 280px;
        flex-grow: 1;
        width: calc(100% - 280px);
        min-height: 100vh;
        overflow-x: hidden;
    }

    .top-header { background: var(--bg-panel); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; }
    .panel-card { background: var(--bg-card); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; }

    .table-custom {
        color: #f8fafc !important; /* Force white text */
        border-color: rgba(255, 255, 255, 0.05);
    }
    .table-custom th { background-color: var(--bg-panel); border-bottom: 2px solid rgba(16, 185, 129, 0.3); color: #f8fafc !important; font-weight: 600; }
    .table-custom td { background-color: var(--bg-card); vertical-align: middle; color: #f8fafc !important; }

    .avatar-placeholder {
        width: 40px; height: 40px; font-size: 1.2rem;
        background: rgba(16, 185, 129, 0.1); color: var(--emerald-neon);
        border: 1px solid var(--emerald-neon); display: flex; align-items: center; justify-content: center;
    }
    .user-avatar { width: 40px; height: 40px; object-fit: cover; border: 1px solid var(--emerald-neon); }

    .btn-emerald {
        background-color: transparent; border: 1px solid var(--emerald-neon); color: var(--emerald-neon);
        transition: 0.3s;
    }
    .btn-emerald:hover { background-color: var(--emerald-neon); color: #0b0d14; box-shadow: 0 0 10px var(--emerald-glow); }

    /* Custom modal overrides to fit Finora's dark neon aesthetic */
    .modal-backdrop {
        background-color: #000 !important;
        opacity: 0.7 !important;
    }
    .btn-outline-light-custom {
        border: 1px solid rgba(255,255,255,0.1) !important;
        color: var(--text-light) !important;
        transition: 0.3s;
    }
    .btn-outline-light-custom:hover {
        background: rgba(255,255,255,0.05);
        color: #fff !important;
    }
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
                <h4 class="mb-1 fw-bold">Password Reset Requests</h4>
                <p class="text-white mb-0 fs-6">Manage employee claims to default passwords and account restoration.</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if($success): ?>
            <div class="alert alert-success bg-transparent text-emerald border-success border-opacity-50 mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= $success ?>
            </div>
        <?php endif; ?>
        
        <?php if($error): ?>
            <div class="alert alert-danger bg-transparent text-danger border-danger border-opacity-50 mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Pending Requests Section -->
        <div class="panel-card p-4 mb-4">
            <h5 class="fw-bold mb-3 text-emerald"><i class="bi bi-shield-exclamation me-2"></i> Pending Requests</h5>
            <?php if (empty($pending_requests)): ?>
                <p class="text-white mb-0 italic">All caught up! There are no pending reset requests.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle">
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th>Emp ID</th>
                                <th>Department</th>
                                <th>Requested On</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_requests as $req): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($req['profile_photo']) && file_exists($req['profile_photo'])): ?>
                                            <img src="<?= htmlspecialchars($req['profile_photo']) ?>" alt="" class="rounded-circle user-avatar me-3">
                                        <?php else: ?>
                                            <div class="rounded-circle avatar-placeholder me-3">
                                                <i class="bi bi-person-fill"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold text-white"><?= htmlspecialchars($req['full_name']) ?></div>
                                            <small style="color: #cbd5e1;"><?= htmlspecialchars($req['position']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-white"><?= htmlspecialchars($req['employee_no']) ?></td>
                                <td class="text-white"><?= htmlspecialchars($req['department_name'] ?? 'N/A') ?></td>
                                <td class="text-white"><?= date('d M Y, h:i A', strtotime($req['requested_at'])) ?></td>
                                <td class="text-end">
                                    <a href="reset_requests.php?action=resolve&req_id=<?= $req['request_id'] ?>" 
                                        class="btn btn-emerald btn-sm me-2" 
                                        onclick="event.preventDefault(); showFinoraConfirm(this.href, 'Are you sure you want to restore this password to the default Attendora@123! credentials? The user will be required to configure their password upon their next login.', false);">
                                        <i class="bi bi-check-circle me-1"></i> Approve & Reset
                                    </a>
                                    <a href="reset_requests.php?action=reject&req_id=<?= $req['request_id'] ?>" 
                                        class="btn btn-outline-danger btn-sm" 
                                        onclick="event.preventDefault(); showFinoraConfirm(this.href, 'Are you sure you want to reject this reset request?', true);">
                                        <i class="bi bi-x-circle me-1"></i> Reject
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recently Resolved History -->
        <div class="panel-card p-4">
            <h5 class="fw-bold mb-3 text-white"><i class="bi bi-clock-history me-2"></i> Action History</h5>
            <?php if (empty($resolved_requests)): ?>
                <p class="text-white mb-0 italic">No previous requests registered.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle">
                        <thead>
                            <tr>
                                <th>Staff Member</th>
                                <th>Emp ID</th>
                                <th>Resolved At</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resolved_requests as $req): ?>
                            <tr>
                                <td class="fw-bold text-white"><?= htmlspecialchars($req['full_name']) ?></td>
                                <td class="text-white"><?= htmlspecialchars($req['employee_no']) ?></td>
                                <td class="text-white"><?= date('d M Y, h:i A', strtotime($req['resolved_at'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= $req['status'] === 'Resolved' ? 'success' : 'danger' ?>-subtle text-<?= $req['status'] === 'Resolved' ? 'success' : 'danger' ?> border border-<?= $req['status'] === 'Resolved' ? 'success' : 'danger' ?> border-opacity-20">
                                        <?= $req['status'] ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Custom Tailored Finora Confirmation Modal -->
<div class="modal fade" id="finoraConfirmModal" tabindex="-1" aria-labelledby="finoraConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--bg-card); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; box-shadow: 0 15px 30px rgba(0,0,0,0.5), 0 0 20px rgba(16, 185, 129, 0.02);">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center" id="finoraConfirmModalLabel">
                    <i id="finoraConfirmIcon" class="bi bi-shield-exclamation text-emerald me-2 fs-4"></i> Confirm Action
                </h5>
                <button type="button" class="btn-close btn-close-white opacity-50" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-white opacity-75 py-4 px-4 fs-6" id="finoraConfirmModalBody">
                Are you sure you want to proceed?
            </div>
            <div class="modal-footer border-0 pb-4 pt-0 px-4">
                <button type="button" class="btn btn-outline-light-custom px-3 py-2 btn-sm" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="finoraConfirmBtn" class="btn btn-sm px-4 py-2 fw-semibold">Confirm</a>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle & Custom Logic -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let confirmModalInstance = null;

    function showFinoraConfirm(url, message, isDanger = false) {
        const modalTitle = document.getElementById('finoraConfirmModalLabel');
        const modalBody = document.getElementById('finoraConfirmModalBody');
        const confirmBtn = document.getElementById('finoraConfirmBtn');
        const confirmIcon = document.getElementById('finoraConfirmIcon');

        if (isDanger) {
            // Apply warning/danger theme context
            modalTitle.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-danger me-2 fs-4"></i> Reject Request';
            confirmIcon.className = "bi bi-exclamation-triangle-fill text-danger me-2 fs-4";
            confirmBtn.className = "btn btn-danger btn-sm px-4 py-2 text-white fw-bold";
            confirmBtn.style.boxShadow = "0 0 15px rgba(220, 53, 69, 0.4)";
            confirmBtn.style.border = "none";
            confirmBtn.style.backgroundColor = "#dc3545";
            confirmBtn.style.color = "#ffffff";
        } else {
            // Apply standard emerald confirmation context
            modalTitle.innerHTML = '<i class="bi bi-shield-exclamation text-emerald me-2 fs-4"></i> Approve Reset';
            confirmIcon.className = "bi bi-shield-exclamation text-emerald me-2 fs-4";
            confirmBtn.className = "btn btn-emerald btn-sm px-4 py-2 fw-bold";
            confirmBtn.style.boxShadow = "0 0 15px var(--emerald-glow)";
            confirmBtn.style.border = "none";
            confirmBtn.style.backgroundColor = "var(--emerald-neon)";
            confirmBtn.style.color = "#0b0d14";
        }

        modalBody.textContent = message;
        confirmBtn.href = url;

        if (!confirmModalInstance) {
            confirmModalInstance = new bootstrap.Modal(document.getElementById('finoraConfirmModal'));
        }
        confirmModalInstance.show();
    }
</script>
</body>
</html>