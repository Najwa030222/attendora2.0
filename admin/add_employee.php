<?php
session_start();

// Check if user is logged in and is an Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require '../config.php';

// Fetch departments for the dropdown
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();

$success = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Generate a random employee number (e.g., EMP-1001)
        $emp_no = 'EMP-' . mt_rand(1000, 9999); 
        
        // Default password (hashed) - employee-side password change isn't built yet
        $hashed_password = password_hash('Attendora@123!', PASSWORD_DEFAULT);

        // --- HANDLE PROFILE PHOTO UPLOAD ---
        $profile_photo_path = null;
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/';
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = 'emp_' . time() . '_' . uniqid() . '.' . $file_extension;
                $target_file = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target_file)) {
                    $profile_photo_path = $target_file;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO employees (
                employee_no, username, password, is_first_login, full_name, ic_passport, dob, gender, 
                nationality, race, religion, marital_status, phone, personal_email, address1, 
                address2, city, state, postcode, emergency_name, emergency_relationship, 
                emergency_phone, department_id, position, reporting_manager, 
                hire_date, employment_status, profile_photo
            ) VALUES (
                ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        $stmt->execute([
            $emp_no,
            $_POST['username'],
            $hashed_password,
            $_POST['full_name'],
            $_POST['ic_passport'],
            $_POST['dob'],
            $_POST['gender'],
            $_POST['nationality'],
            $_POST['race'],
            $_POST['religion'],
            $_POST['marital_status'],
            $_POST['phone'],
            $_POST['personal_email'],
            $_POST['address1'],
            $_POST['address2'],
            $_POST['city'],
            $_POST['state'],
            $_POST['postcode'],
            $_POST['emergency_name'],
            $_POST['emergency_relationship'],
            $_POST['emergency_phone'],
            $_POST['department_id'],
            $_POST['position'],
            $_POST['reporting_manager'],
            $_POST['hire_date'],
            $_POST['employment_status'],
            $profile_photo_path
        ]);

        $success = "Employee " . htmlspecialchars($_POST['full_name']) . " successfully added! (Emp ID: $emp_no)";
    } catch(PDOException $e) {
        if($e->getCode() == 23000) {
            $error = "Error: Username or IC/Passport already exists in the system.";
        } else {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee - Attendora</title>
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
            box-shadow: 0 0 15px var(--emerald-glow-strong);
        }

        .top-header { background: var(--bg-panel); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; }
        .panel-card { background: var(--bg-card); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; }
        
        .form-label { color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem; }
        .form-control, .form-select { 
            background-color: var(--bg-panel); border: 1px solid rgba(255,255,255,0.1); 
            color: var(--text-light); padding: 10px 15px; border-radius: 8px;
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--bg-panel); color: var(--text-light); 
            border-color: var(--emerald-neon); box-shadow: 0 0 10px var(--emerald-glow);
        }
        .form-control::placeholder { color: rgba(255,255,255,0.2); }
        .form-control[type="file"]::file-selector-button {
            background-color: var(--bg-card); color: var(--text-light); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 4px; padding: 5px 10px; margin-right: 10px; transition: 0.3s;
        }
        .form-control[type="file"]::file-selector-button:hover { background-color: var(--emerald-neon); color: #000; }
        
        select option { background-color: var(--bg-card); color: var(--text-light); }

        .section-title { 
            color: var(--emerald-neon); font-size: 1.1rem; border-bottom: 1px solid rgba(16, 185, 129, 0.2); 
            padding-bottom: 10px; margin-bottom: 20px; margin-top: 30px; text-shadow: 0 0 10px var(--emerald-glow);
        }

        .btn-emerald-solid {
            background-color: var(--emerald-neon); color: #0b0d14 !important; border-radius: 8px; font-weight: 600;
            transition: all 0.3s; box-shadow: 0 0 15px var(--emerald-glow); padding: 10px 25px; border: none;
        }
        .btn-emerald-solid:hover { background-color: #059669; color: #fff !important; transform: translateY(-2px); }
        
        .btn-outline-light-custom {
            border: 1px solid rgba(255,255,255,0.2); color: var(--text-light); background: transparent; transition: 0.3s;
        }
        .btn-outline-light-custom:hover { background: rgba(255,255,255,0.05); color: white; }
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
                    <h4 class="mb-1 fw-bold">Add New Employee</h4>
                    <p class="text-muted mb-0 fs-6">Register a new staff member into the Finora system.</p>
                </div>
                <a href="employees.php" class="btn btn-outline-light-custom px-4 py-2">
                    <i class="bi bi-arrow-left me-2"></i> Back to Directory
                </a>
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

            <!-- Form Card -->
            <div class="card panel-card shadow-lg">
                <div class="card-body p-5">
                    <form action="add_employee.php" method="POST" enctype="multipart/form-data">
                        
                        <h5 class="section-title mt-0"><i class="bi bi-shield-lock me-2"></i> System Credentials</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">System Username *</label>
                                <input type="text" class="form-control" name="username" required placeholder="e.g. jdoe">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Default Password</label>
                                <input type="text" class="form-control text-muted" value="Attendora@123! (Default)" disabled>
                                <small class="text-muted" style="font-size: 0.7rem;">Employee will be prompted to change this upon first login.</small>
                            </div>
                        </div>

                        <h5 class="section-title"><i class="bi bi-person-lines-fill me-2"></i> Personal Information</h5>
                        <div class="row g-4">
                            <!-- New Profile Photo Upload Field -->
                            <div class="col-12 mb-2">
                                <label class="form-label">Profile Photo (Optional)</label>
                                <input type="file" class="form-control" name="profile_photo" accept="image/jpeg, image/png, image/webp, image/gif">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="full_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">IC / Passport Number *</label>
                                <input type="text" class="form-control" name="ic_passport" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="dob">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gender</label>
                                <select class="form-select" name="gender">
                                    <option value="" disabled selected>Select...</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Marital Status</label>
                                <select class="form-select" name="marital_status">
                                    <option value="" disabled selected>Select...</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Divorced">Divorced</option>
                                    <option value="Widowed">Widowed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nationality</label>
                                <input type="text" class="form-control" name="nationality" value="Malaysian">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Race / Ethnicity</label>
                                <input type="text" class="form-control" name="race">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Religion</label>
                                <select class="form-select" name="religion">
                                    <option value="" disabled selected>Select...</option>
                                    <option value="Islam">Islam</option>
                                    <option value="Christianity">Christianity</option>
                                    <option value="Buddhism">Buddhism</option>
                                    <option value="Hinduism">Hinduism</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <h5 class="section-title"><i class="bi bi-geo-alt-fill me-2"></i> Contact Details</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Phone Number *</label>
                                <input type="text" class="form-control" name="phone" required placeholder="0123456789" minlength="10" maxlength="11" pattern="\d{10,11}" title="Phone number must be 10 to 11 digits">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Personal Email</label>
                                <input type="email" class="form-control" name="personal_email" placeholder="email@example.com">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address Line 1</label>
                                <input type="text" class="form-control" name="address1">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address Line 2 (Optional)</label>
                                <input type="text" class="form-control" name="address2">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">City</label>
                                <input type="text" class="form-control" name="city">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">State</label>
                                <select class="form-select" name="state">
                                    <option value="Selangor" selected>Selangor</option>
                                    <option value="Johor">Johor</option>
                                    <option value="Kedah">Kedah</option>
                                    <option value="Kelantan">Kelantan</option>
                                    <option value="Melaka">Melaka</option>
                                    <option value="Negeri Sembilan">Negeri Sembilan</option>
                                    <option value="Pahang">Pahang</option>
                                    <option value="Perak">Perak</option>
                                    <option value="Perlis">Perlis</option>
                                    <option value="Pulau Pinang">Pulau Pinang</option>
                                    <option value="Sabah">Sabah</option>
                                    <option value="Sarawak">Sarawak</option>
                                    <option value="Terengganu">Terengganu</option>
                                    <option value="W.P. Kuala Lumpur">W.P. Kuala Lumpur</option>
                                    <option value="W.P. Labuan">W.P. Labuan</option>
                                    <option value="W.P. Putrajaya">W.P. Putrajaya</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Postcode</label>
                                <input type="text" class="form-control" name="postcode" minlength="5" maxlength="5" pattern="\d{5}" title="Postcode must be exactly 5 digits">
                            </div>
                        </div>

                        <h5 class="section-title"><i class="bi bi-briefcase-fill me-2"></i> Employment Details</h5>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department_id">
                                    <option value="" disabled selected>Select Department...</option>
                                    <?php foreach($departments as $dept): ?>
                                        <option value="<?= $dept['department_id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Job Position</label>
                                <input type="text" class="form-control" name="position" placeholder="e.g. Software Engineer">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Hire Date</label>
                                <input type="date" class="form-control" name="hire_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="employment_status">
                                    <option value="Active">Active</option>
                                    <option value="On Probation" selected>On Probation</option>
                                    <option value="Resigned">Resigned</option>
                                    <option value="Terminated">Terminated</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Reporting Manager</label>
                                <input type="text" class="form-control" name="reporting_manager" placeholder="Name of direct supervisor">
                            </div>
                        </div>

                        <h5 class="section-title"><i class="bi bi-heart-pulse-fill me-2"></i> Emergency Contact</h5>
                        <div class="row g-4 mb-5">
                            <div class="col-md-4">
                                <label class="form-label">Contact Name</label>
                                <input type="text" class="form-control" name="emergency_name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Relationship</label>
                                <input type="text" class="form-control" name="emergency_relationship" placeholder="e.g. Spouse, Parent">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Emergency Phone</label>
                                <input type="text" class="form-control" name="emergency_phone" minlength="10" maxlength="11" pattern="\d{10,11}" title="Emergency phone number must be 10 to 11 digits">
                            </div>
                        </div>

                        <hr class="border-secondary opacity-25 mb-4">
                        
                        <div class="d-flex justify-content-end">
                            <button type="reset" class="btn btn-outline-light-custom px-4 py-2 me-3">Clear Form</button>
                            <button type="submit" class="btn btn-emerald-solid">
                                <i class="bi bi-person-check-fill me-2"></i> Register Employee
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</body>
</html>