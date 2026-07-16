<?php
session_start();

// Check if user is logged in and is an Employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

$emp_id = $_SESSION['user_id'];
$message = '';
$messageType = '';

// --- HANDLE FORM SUBMISSIONS ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    
    // 1. UPDATE PROFILE DETAILS (AND PHOTO)
    if ($_POST['action'] == 'update_profile') {
        try {
            // First, get current photo to keep it if no new one is uploaded
            $stmt = $pdo->prepare("SELECT profile_photo FROM employees WHERE employee_id = ?");
            $stmt->execute([$emp_id]);
            $current_photo = $stmt->fetchColumn();
            $profile_photo_path = $current_photo;

            // Handle Photo Upload
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $file_extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $new_filename = 'emp_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $target_file = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target_file)) {
                        $profile_photo_path = $target_file;
                    }
                } else {
                    throw new Exception("Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.");
                }
            }

            $stmt = $pdo->prepare("
                UPDATE employees SET 
                    marital_status = ?, phone = ?, personal_email = ?, 
                    address1 = ?, address2 = ?, city = ?, state = ?, postcode = ?, 
                    emergency_name = ?, emergency_relationship = ?, emergency_phone = ?,
                    profile_photo = ?
                WHERE employee_id = ?
            ");
            
            $stmt->execute([
                $_POST['marital_status'], $_POST['phone'], $_POST['personal_email'],
                $_POST['address1'], $_POST['address2'], $_POST['city'], $_POST['state'], $_POST['postcode'],
                $_POST['emergency_name'], $_POST['emergency_relationship'], $_POST['emergency_phone'],
                $profile_photo_path, $emp_id
            ]);
            
            $message = "Profile details updated successfully!";
            $messageType = "success";
        } catch(Exception $e) {
            $message = "Error updating profile: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // 2. UPDATE PASSWORD
    if ($_POST['action'] == 'update_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Fetch current password hash
        $stmt = $pdo->prepare("SELECT password FROM employees WHERE employee_id = ?");
        $stmt->execute([$emp_id]);
        $user = $stmt->fetch();
        
        if (!password_verify($current_password, $user['password'])) {
            $message = "Incorrect current password.";
            $messageType = "danger";
        } elseif ($new_password !== $confirm_password) {
            $message = "New passwords do not match.";
            $messageType = "danger";
        } else {
            // Validate Password Strength in PHP (Backend)
            $uppercase = preg_match('@[A-Z]@', $new_password);
            $number    = preg_match('@[0-9]@', $new_password);
            $specialChars = preg_match('@[^\w]@', $new_password);

            if(!$uppercase || !$number || !$specialChars || strlen($new_password) < 8) {
                $message = "Password does not meet the security requirements.";
                $messageType = "danger";
            } else {
                // Hash and Update
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE employees SET password = ?, is_first_login = 0 WHERE employee_id = ?");
                $update->execute([$new_hash, $emp_id]);
                
                $message = "Password changed successfully!";
                $messageType = "success";
            }
        }
    }
}

// --- FETCH EMPLOYEE DATA ---
$stmt = $pdo->prepare("
    SELECT e.*, d.department_name 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.department_id 
    WHERE e.employee_id = ?
");
$stmt->execute([$emp_id]);
$emp = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Finora Portal</title>
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
            background-image: radial-gradient(circle at top left, rgba(16, 185, 129, 0.05), transparent 40%),
                              radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.05), transparent 40%);
            min-height: 100vh;
        }

        .main-content { margin-left: 280px; flex-grow: 1; padding: 40px; }
        
        .panel-card { 
            background: var(--bg-card); 
            border: 1px solid rgba(255, 255, 255, 0.05); 
            border-radius: 16px; 
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .card-header-custom {
            background: var(--bg-panel);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding: 20px 25px;
        }

        /* Form styling */
        .form-label { color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem; }
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
        .form-control:disabled, .form-control[readonly] {
            background-color: rgba(255,255,255,0.02);
            opacity: 0.7;
            cursor: not-allowed;
        }
        .form-control[type="file"]::file-selector-button {
            background-color: var(--bg-card); color: var(--text-light); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 4px; padding: 5px 10px; margin-right: 10px; transition: 0.3s;
        }
        .form-control[type="file"]::file-selector-button:hover { background-color: var(--emerald-neon); color: #000; }

        select option { background-color: var(--bg-card); color: var(--text-light); }

        .btn-emerald-solid {
            background-color: var(--emerald-neon); color: #0b0d14 !important; border-radius: 8px; font-weight: 600;
            transition: all 0.3s; box-shadow: 0 0 15px var(--emerald-glow); padding: 10px 25px; border: none;
        }
        .btn-emerald-solid:hover { background-color: #059669; color: #fff !important; transform: translateY(-2px); }
        
        .btn-emerald-solid:disabled {
            background-color: rgba(255, 255, 255, 0.05) !important;
            color: #94a3b8 !important;
            box-shadow: none;
            cursor: not-allowed;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .generic-avatar, .user-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 2px solid var(--emerald-neon);
            box-shadow: 0 0 15px var(--emerald-glow);
        }
        .generic-avatar {
            background: rgba(16, 185, 129, 0.1);
            color: var(--emerald-neon);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
        }
        .user-avatar {
            object-fit: cover;
        }

        .req-list { list-style: none; padding-left: 0; font-size: 0.85rem; }
        .req-list li { 
            margin-bottom: 8px; 
            color: #cbd5e1; 
            transition: 0.3s; 
            background: rgba(255, 255, 255, 0.03); 
            padding: 10px 15px; 
            border-radius: 8px; 
            border: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
        }
        .req-list li.valid { 
            color: var(--emerald-neon); 
            border-color: rgba(16, 185, 129, 0.3); 
            background: rgba(16, 185, 129, 0.05); 
        }
        .req-list li i { margin-right: 8px; font-size: 1rem; }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar Navigation -->
        <?php require 'sidebar.php'; ?>

        <!-- Main Content Area -->
        <div class="main-content p-4 p-lg-5">
            
            <div class="d-flex justify-content-between align-items-center mb-4 p-4 shadow-sm panel-card">
                <div>
                    <h4 class="mb-1 fw-bold text-white">My Profile</h4>
                    <p class="text-white mb-0 fs-6">Manage your personal information and security settings.</p>
                </div>
            </div>

            <?php if($message): ?>
                <div class="alert alert-<?= $messageType ?> bg-transparent border-<?= $messageType ?> border-opacity-50 text-white mb-4 rounded-3" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($emp['is_first_login'])): ?>
                <div class="alert alert-warning bg-transparent border-warning text-warning border-opacity-50 mb-4 rounded-3" role="alert">
                    <i class="bi bi-shield-exclamation me-2"></i> Welcome! For security, please set your own password below before continuing to use the system.
                </div>
            <?php endif; ?>

            <div class="row g-4">
                
                <!-- Left Column: Profile Card & Read-Only Info -->
                <div class="col-xl-4">
                    <!-- Avatar Card -->
                    <div class="panel-card mb-4">
                        <div class="card-body text-center p-5">
                            <div class="d-flex justify-content-center mb-3">
                                <?php if (!empty($emp['profile_photo']) && file_exists($emp['profile_photo'])): ?>
                                    <img src="<?= htmlspecialchars($emp['profile_photo']) ?>" alt="Profile" class="user-avatar">
                                <?php else: ?>
                                    <div class="generic-avatar">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h4 class="fw-bold mb-1"><?= htmlspecialchars($emp['full_name']) ?></h4>
                            <p class="text-emerald fw-medium mb-1"><?= htmlspecialchars($emp['position']) ?></p>
                            <span class="badge rounded-pill px-3 py-2 mt-2" style="background: rgba(255,255,255,0.1); color: #fff;">
                                ID: <?= htmlspecialchars($emp['employee_no']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Work Information (Read Only) -->
                    <div class="panel-card mb-4">
                        <div class="card-header-custom d-flex align-items-center">
                            <i class="bi bi-briefcase-fill text-emerald me-2 fs-5"></i>
                            <h6 class="mb-0 fw-bold text-white">Employment Details</h6>
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control text-white" value="<?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Hire Date</label>
                                <input type="text" class="form-control text-white" value="<?= date('d M Y', strtotime($emp['hire_date'])) ?>" readonly>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">IC / Passport</label>
                                <input type="text" class="form-control text-white" value="<?= htmlspecialchars($emp['ic_passport']) ?>" readonly>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Editable Forms -->
                <div class="col-xl-8">
                    
                    <!-- Editable Profile Form -->
                    <div class="panel-card mb-4">
                        <div class="card-header-custom d-flex align-items-center">
                            <i class="bi bi-person-lines-fill text-emerald me-2 fs-5"></i>
                            <h6 class="mb-0 fw-bold text-white">Personal & Contact Information</h6>
                        </div>
                        <div class="card-body p-4 p-lg-5">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_profile">
                                
                                <div class="row g-4 mb-4">
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Change Profile Photo</label>
                                        <input type="file" class="form-control" name="profile_photo" accept="image/jpeg, image/png, image/webp, image/gif">
                                        <small class="text-white d-block mt-1">Recommended: Square image, max 2MB.</small>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Phone Number *</label>
                                        <input type="text" class="form-control" name="phone" required placeholder="0123456789" value="<?= htmlspecialchars($emp['phone'] ?? '') ?>" minlength="10" maxlength="11" pattern="\d{10,11}" title="Phone number must be 10 to 11 digits">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Personal Email</label>
                                        <input type="email" class="form-control" name="personal_email" value="<?= htmlspecialchars($emp['personal_email']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Marital Status</label>
                                        <select class="form-select" name="marital_status">
                                            <option value="Single" <?= $emp['marital_status'] == 'Single' ? 'selected' : '' ?>>Single</option>
                                            <option value="Married" <?= $emp['marital_status'] == 'Married' ? 'selected' : '' ?>>Married</option>
                                            <option value="Divorced" <?= $emp['marital_status'] == 'Divorced' ? 'selected' : '' ?>>Divorced</option>
                                            <option value="Widowed" <?= $emp['marital_status'] == 'Widowed' ? 'selected' : '' ?>>Widowed</option>
                                        </select>
                                    </div>
                                </div>

                                <h6 class="text-emerald mb-3 mt-4" style="font-size: 0.9rem;">HOME ADDRESS</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-12">
                                        <input type="text" class="form-control" name="address1" placeholder="Address Line 1" value="<?= htmlspecialchars($emp['address1']) ?>">
                                    </div>
                                    <div class="col-12">
                                        <input type="text" class="form-control" name="address2" placeholder="Address Line 2" value="<?= htmlspecialchars($emp['address2']) ?>">
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" class="form-control" name="city" placeholder="City" value="<?= htmlspecialchars($emp['city']) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <select class="form-select" name="state">
                                            <option value="Selangor" <?= $emp['state'] == 'Selangor' ? 'selected' : '' ?>>Selangor</option>
                                            <option value="Kuala Lumpur" <?= $emp['state'] == 'Kuala Lumpur' ? 'selected' : '' ?>>Kuala Lumpur</option>
                                            <option value="Johor" <?= $emp['state'] == 'Johor' ? 'selected' : '' ?>>Johor</option>
                                            <option value="Penang" <?= $emp['state'] == 'Penang' ? 'selected' : '' ?>>Penang</option>
                                            <!-- Add other states as needed -->
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="postcode" value="<?= htmlspecialchars($emp['postcode'] ?? '') ?>" minlength="5" maxlength="5" pattern="\d{5}" title="Postcode must be exactly 5 digits">
                                    </div>
                                </div>

                                <h6 class="text-emerald mb-3 mt-4" style="font-size: 0.9rem;">EMERGENCY CONTACT</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <label class="form-label">Contact Name</label>
                                        <input type="text" class="form-control" name="emergency_name" value="<?= htmlspecialchars($emp['emergency_name']) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Relationship</label>
                                        <input type="text" class="form-control" name="emergency_relationship" value="<?= htmlspecialchars($emp['emergency_relationship']) ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" name="emergency_phone" value="<?= htmlspecialchars($emp['emergency_phone'] ?? '') ?>" minlength="10" maxlength="11" pattern="\d{10,11}" title="Emergency phone number must be 10 to 11 digits">
                                    </div>
                                </div>
                                
                                <div class="text-end border-top border-secondary pt-4 mt-2" style="border-color: rgba(255,255,255,0.05) !important;">
                                    <button type="submit" class="btn btn-emerald-solid px-4"><i class="bi bi-save-fill me-2"></i> Update Profile</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Change Password Form -->
                    <div class="panel-card" id="security">
                        <div class="card-header-custom d-flex align-items-center">
                            <i class="bi bi-shield-lock-fill text-warning me-2 fs-5"></i>
                            <h6 class="mb-0 fw-bold text-white">Security Settings</h6>
                        </div>
                        <div class="card-body p-4 p-lg-5">
                            <form method="POST" id="passwordForm">
                                <input type="hidden" name="action" value="update_password">
                                
                                <div class="row">
                                    <div class="col-md-7 pe-md-5 border-end border-secondary" style="border-color: rgba(255,255,255,0.05) !important;">
                                        <div class="mb-4">
                                            <label class="form-label">Current Password *</label>
                                            <input type="password" class="form-control" name="current_password" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">New Password *</label>
                                            <input type="password" class="form-control" name="new_password" id="new_password" required>
                                        </div>
                                        <div class="mb-4">
                                            <label class="form-label">Confirm New Password *</label>
                                            <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                                            <div id="matchMessage" class="mt-2 small"></div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-emerald-solid px-4" id="btnUpdatePassword" disabled>
                                            <i class="bi bi-key-fill me-2"></i> Change Password
                                        </button>
                                    </div>
                                    
                                    <div class="col-md-5 ps-md-4 mt-4 mt-md-0">
                                        <h6 class="text-white mb-3" style="font-size: 0.85rem; letter-spacing: 1px;">PASSWORD REQUIREMENTS</h6>
                                        <ul class="req-list">
                                            <li id="req-length"><i class="bi bi-circle"></i> Minimum 8 characters</li>
                                            <li id="req-upper"><i class="bi bi-circle"></i> At least one uppercase letter</li>
                                            <li id="req-number"><i class="bi bi-circle"></i> At least one number</li>
                                            <li id="req-special"><i class="bi bi-circle"></i> At least one special character</li>
                                        </ul>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Real-time Password Validation Script
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const btnUpdate = document.getElementById('btnUpdatePassword');
        const matchMessage = document.getElementById('matchMessage');
        
        // Requirements UI elements
        const reqLength = document.getElementById('req-length');
        const reqUpper = document.getElementById('req-upper');
        const reqNumber = document.getElementById('req-number');
        const reqSpecial = document.getElementById('req-special');

        function validatePassword() {
            const val = newPassword.value;
            let isValid = true;

            // Length (8+)
            if (val.length >= 8) {
                reqLength.classList.add('valid');
                reqLength.innerHTML = '<i class="bi bi-check-circle-fill"></i> Minimum 8 characters';
            } else {
                reqLength.classList.remove('valid');
                reqLength.innerHTML = '<i class="bi bi-circle"></i> Minimum 8 characters';
                isValid = false;
            }

            // Uppercase
            if (/[A-Z]/.test(val)) {
                reqUpper.classList.add('valid');
                reqUpper.innerHTML = '<i class="bi bi-check-circle-fill"></i> At least one uppercase letter';
            } else {
                reqUpper.classList.remove('valid');
                reqUpper.innerHTML = '<i class="bi bi-circle"></i> At least one uppercase letter';
                isValid = false;
            }

            // Number
            if (/[0-9]/.test(val)) {
                reqNumber.classList.add('valid');
                reqNumber.innerHTML = '<i class="bi bi-check-circle-fill"></i> At least one number';
            } else {
                reqNumber.classList.remove('valid');
                reqNumber.innerHTML = '<i class="bi bi-circle"></i> At least one number';
                isValid = false;
            }

            // Special Character
            if (/[^\w]/.test(val)) {
                reqSpecial.classList.add('valid');
                reqSpecial.innerHTML = '<i class="bi bi-check-circle-fill"></i> At least one special character';
            } else {
                reqSpecial.classList.remove('valid');
                reqSpecial.innerHTML = '<i class="bi bi-circle"></i> At least one special character';
                isValid = false;
            }

            return isValid;
        }

        function checkMatch() {
            if (confirmPassword.value.length > 0) {
                if (newPassword.value === confirmPassword.value) {
                    matchMessage.innerHTML = '<span class="text-success"><i class="bi bi-check"></i> Passwords match</span>';
                    return true;
                } else {
                    matchMessage.innerHTML = '<span class="text-danger"><i class="bi bi-x"></i> Passwords do not match</span>';
                    return false;
                }
            }
            matchMessage.innerHTML = '';
            return false;
        }

        function handleInput() {
            const isStrengthValid = validatePassword();
            const isMatchValid = checkMatch();
            
            // Enable button only if strength rules met AND passwords match
            btnUpdate.disabled = !(isStrengthValid && isMatchValid);
        }

        newPassword.addEventListener('input', handleInput);
        confirmPassword.addEventListener('input', handleInput);
    </script>
</body>
</html>