<?php
session_start();
require 'config.php';

$success = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $employee_no = trim($_POST['employee_no']);

    if (empty($username) || empty($employee_no)) {
        $error = "Please fill in all fields.";
    } else {
        try {
            // Check if the employee exists with matching credentials
            $stmt = $pdo->prepare("SELECT employee_id, full_name FROM employees WHERE username = ? AND employee_no = ?");
            $stmt->execute([$username, $employee_no]);
            $emp = $stmt->fetch();

            if ($emp) {
                // Check if they already have a pending request
                $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE employee_id = ? AND status = 'Pending'");
                $check_stmt->execute([$emp['employee_id']]);
                
                if ($check_stmt->fetchColumn() > 0) {
                    $error = "You already have a pending reset request. Please wait for an administrator to resolve it.";
                } else {
                    // Create a new reset request
                    $ins_stmt = $pdo->prepare("INSERT INTO password_reset_requests (employee_id) VALUES (?)");
                    $ins_stmt->execute([$emp['employee_id']]);
                    $success = "Request submitted successfully! Please contact your administrator to receive your default login details.";
                }
            } else {
                $error = "No matching employee records found. Check your details and try again.";
            }
        } catch (PDOException $e) {
            $error = "System Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Finora Portal</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            background-image: radial-gradient(circle at center, rgba(16, 185, 129, 0.08), transparent 50%),
                              url('uploads/background.png');
            background-size: cover, cover;
            background-position: center, center;
            background-repeat: no-repeat, no-repeat;
            background-attachment: fixed, fixed;
        }

        .login-card {
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
        }

        .form-label { color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; }
        
        .form-control { 
            background-color: var(--bg-panel); 
            border: 1px solid rgba(255,255,255,0.1); 
            color: var(--text-light); 
            padding: 12px 15px; 
            border-radius: 8px;
        }
        .form-control:focus {
            background-color: var(--bg-panel); 
            color: var(--text-light); 
            border-color: var(--emerald-neon); 
            box-shadow: 0 0 10px var(--emerald-glow);
        }

        .btn-emerald-solid {
            background-color: var(--emerald-neon); color: #0b0d14 !important; border-radius: 8px; font-weight: 600;
            transition: all 0.3s; box-shadow: 0 0 15px var(--emerald-glow); padding: 12px; border: none; width: 100%;
        }
        .btn-emerald-solid:hover { background-color: #059669; color: #fff !important; transform: translateY(-2px); }
        
        .back-link {
            color: var(--emerald-neon);
            text-decoration: none;
            font-size: 0.9rem;
            transition: 0.3s;
        }
        .back-link:hover {
            color: #059669;
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="login-card p-5">
        <div class="text-center mb-4">
            <h3 class="fw-bold text-white">Reset Request</h3>
            <p class="text-white small">Enter your details to register a portal access reset request.</p>
        </div>

        <?php if($success): ?>
            <div class="alert alert-success bg-transparent border-success border-opacity-50 text-white mb-4" role="alert">
                <i class="bi bi-check-circle-fill text-emerald me-2"></i> <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert alert-danger bg-transparent border-danger border-opacity-50 text-white mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label class="form-label">System Username</label>
                <input type="text" class="form-control" name="username" placeholder="e.g. jdoe" required>
            </div>

            <div class="mb-4">
                <label class="form-label">Employee Number (ID)</label>
                <input type="text" class="form-control" name="employee_no" placeholder="e.g. EMP-1234" required>
            </div>

            <button type="submit" class="btn btn-emerald-solid mb-3">
                <i class="bi bi-send-fill me-2"></i> Submit Request
            </button>

            <div class="text-center mt-3">
                <a href="login.php" class="back-link"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
            </div>
        </form>
    </div>

</body>
</html>