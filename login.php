<?php
// Start a session to keep track of logged-in users
session_start();

// Include our database connection
require 'config.php';

$error = '';

// Check if the form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $login_input = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // 1. Check the admins table first
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = :input OR email = :input");
    $stmt->execute(['input' => $login_input]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        if ($admin['status'] !== 'Active') {
            $error = "Your admin account is inactive. Please contact HR for any inquiries.";
        } else {
            // Set Admin Session
            $_SESSION['user_id'] = $admin['admin_id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['name'] = $admin['full_name'];
            
            // Log the login
            $ip = $_SERVER['REMOTE_ADDR'];
            $logStmt = $pdo->prepare("INSERT INTO login_logs (user_type, admin_id, ip_address) VALUES ('Admin', :admin_id, :ip)");
            $logStmt->execute(['admin_id' => $admin['admin_id'], 'ip' => $ip]);
            
            // Redirect to the new admin folder
            header("Location: admin/dashboard.php");
            exit;
        }
    } else {
        // 2. If not admin, check the employees table
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE username = :input OR personal_email = :input");
        $stmt->execute(['input' => $login_input]);
        $employee = $stmt->fetch();

        if ($employee && password_verify($password, $employee['password'])) {
            if ($employee['employment_status'] === 'Resigned' || $employee['employment_status'] === 'Terminated') {
                $error = "Your employee account is no longer active.";
            } else {
                // Set Employee Session
                $_SESSION['user_id'] = $employee['employee_id'];
                $_SESSION['role'] = 'employee';
                $_SESSION['name'] = $employee['full_name'];
                
                // Log the login
                $ip = $_SERVER['REMOTE_ADDR'];
                $logStmt = $pdo->prepare("INSERT INTO login_logs (user_type, employee_id, ip_address) VALUES ('Employee', :employee_id, :ip)");
                $logStmt->execute(['employee_id' => $employee['employee_id'], 'ip' => $ip]);
                
                // Redirect to the new employee folder
                header("Location: employee/dashboard.php");
                exit;
            }
        } else {
            $error = "Invalid username/email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendora - Secure Login</title>
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
            background-image: radial-gradient(circle at top left, rgba(16, 185, 129, 0.15), transparent 40%),
                              radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.1), transparent 40%),
                              url('uploads/background.png');
            background-size: cover, cover, cover;
            background-position: center, center, center;
            background-repeat: no-repeat, no-repeat, no-repeat;
            background-attachment: fixed, fixed, fixed;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            max-width: 420px;
            width: 100%;
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5), 0 0 20px rgba(16, 185, 129, 0.05);
            position: relative;
            overflow: hidden;
        }
        
        /* Top Glowing Line */
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--emerald-neon);
            box-shadow: 0 0 15px var(--emerald-neon);
        }

        .text-emerald { 
            color: var(--emerald-neon) !important; 
            text-shadow: 0 0 10px var(--emerald-glow);
        }

        /* Form Inputs Dark Mode Styling */
        .form-label {
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
        }
        .form-control {
            background-color: var(--bg-panel);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text-light);
            padding: 12px 15px;
            border-radius: 10px;
        }
        .form-control:focus {
            background-color: var(--bg-panel);
            color: var(--text-light);
            border-color: var(--emerald-neon);
            box-shadow: 0 0 10px var(--emerald-glow);
        }
        .form-control::placeholder {
            color: rgba(255,255,255,0.2);
        }

        /* Input Group Icons */
        .input-group-text {
            background-color: var(--bg-panel);
            border: 1px solid rgba(255,255,255,0.1);
            border-right: none;
            color: var(--text-muted);
        }
        .form-control {
            border-left: none;
        }
        .form-control:focus + .input-group-text {
            border-color: var(--emerald-neon);
        }

        /* Neon Button */
        .btn-emerald {
            background-color: var(--emerald-neon);
            color: #000;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            padding: 12px;
            box-shadow: 0 0 15px var(--emerald-glow);
            transition: all 0.3s ease;
        }
        .btn-emerald:hover {
            background-color: #059669;
            color: #fff;
            box-shadow: 0 0 25px var(--emerald-glow);
            transform: translateY(-2px);
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-color: rgba(220, 53, 69, 0.3);
            color: #ff6b6b;
        }

        /* Forgot Password link styling */
        .forgot-link {
            color: var(--emerald-neon);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .forgot-link:hover {
            color: #059669;
            text-shadow: 0 0 8px var(--emerald-glow);
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="card login-card p-4 mx-3">
    <div class="card-body p-3">
        <!-- Brand Logo / Name -->
        <div class="text-center mb-3">
            <img src="finora_logo.png" alt="Finora Logo" style="max-width: 180px; height: auto; object-fit: contain;">
        </div>
        <h3 class="text-center mb-1 text-emerald fw-bold d-flex align-items-center justify-content-center" style="letter-spacing: 2px;">
             ATTENDORA
        </h3>
        <h6 class="text-center mb-4 text-white" style="font-size: 0.85rem; letter-spacing: 1px;">ATTENDANCE AND LEAVE APPLICATION PORTAL</h6>
        
        <!-- Error Message Display -->
        <?php if(!empty($error)): ?>
            <div class="alert alert-danger p-2 text-center rounded-3 fs-6 mb-4" role="alert">
                <i class="bi bi-exclamation-triangle me-1"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form action="login.php" method="POST">
            <div class="mb-4">
                <label for="username" class="form-label">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="username" name="username" required placeholder="admin or name@finora.com">
                </div>
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                    <input type="password" class="form-control border-end-0" id="password" name="password" required placeholder="••••••••">
                    <span class="input-group-text bg-transparent" style="cursor: pointer; border-left: none;" onclick="togglePassword()">
                        <i class="bi bi-eye-fill" id="toggleIcon"></i>
                    </span>
                </div>
            </div>
            
            <div class="text-center mb-4">
                <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
            </div>
            
            <div class="d-grid">
                <button type="submit" class="btn btn-emerald btn-lg text-uppercase" style="letter-spacing: 1px;">Login</button>
            </div>
        </form>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function togglePassword() {
        const pwd = document.getElementById('password');
        const icon = document.getElementById('toggleIcon');
        if (pwd.type === 'password') {
            pwd.type = 'text';
            icon.classList.remove('bi-eye-fill');
            icon.classList.add('bi-eye-slash-fill');
        } else {
            pwd.type = 'password';
            icon.classList.remove('bi-eye-slash-fill');
            icon.classList.add('bi-eye-fill');
        }
    }
</script>
</body>
</html>