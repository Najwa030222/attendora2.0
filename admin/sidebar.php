<?php
// Detect current file name dynamically
$current_page = basename($_SERVER['PHP_SELF']);

// Safe database check for pending password reset requests
$pending_reset_count = 0;
if (isset($pdo)) {
    try {
        $count_stmt = $pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'Pending'");
        $pending_reset_count = (int)$count_stmt->fetchColumn();
    } catch (PDOException $e) {
        // Silently fail if table does not exist yet to prevent sidebar breakages
        $pending_reset_count = 0;
    }
}
?>

<style>
    /* Shared Sidebar styles to keep pages neat and consistent */
    .sidebar { 
        width: 280px;
        min-width: 280px;
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh; 
        background: var(--bg-panel); 
        border-right: 1px solid rgba(255, 255, 255, 0.05);
        display: flex;
        flex-direction: column;
        z-index: 1000;
        transition: transform 0.3s ease-in-out; /* Added for smooth mobile slide */
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

    /* Redesigned Logout Button - Full Width */
    .btn-logout {
        background-color: rgba(239, 68, 68, 0.1); 
        border: 1px solid rgba(239, 68, 68, 0.2);
        color: #ef4444 !important; 
        border-radius: 12px; 
        margin: 0; 
        width: 100%; 
        text-align: center; 
        padding: 12px 0 !important; 
        text-decoration: none; 
        transition: all 0.3s ease;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .btn-logout:hover {
        background-color: #ef4444; 
        color: #ffffff !important;
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        transform: translateY(-2px);
    }

    /* Mobile Overlay */
    .mobile-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(3px);
        z-index: 999;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    .mobile-overlay.show {
        display: block;
        opacity: 1;
    }

    /* Hamburger Button */
    .mobile-hamburger {
        display: none;
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 998;
        background: var(--bg-panel);
        border: 1px solid rgba(16,185,129,0.3);
        color: var(--emerald-neon);
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 1.5rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        cursor: pointer;
        transition: all 0.3s;
    }
    .mobile-hamburger:hover {
        background: rgba(16,185,129,0.1);
        border-color: var(--emerald-neon);
    }

    /* Responsive Breakpoints */
    @media (max-width: 991.98px) {
        .sidebar {
            transform: translateX(-100%); /* Hide sidebar off-screen initially */
        }
        .sidebar.show {
            transform: translateX(0); /* Slide in */
        }
        .mobile-hamburger { 
            display: block; /* Show hamburger button */
        }
        /* Automatically adjust main content for all pages importing this sidebar */
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 1.5rem !important; 
            padding-top: 80px !important; /* Leave space for hamburger */
        }
    }
</style>

<!-- Hamburger Toggle Button -->
<button class="mobile-hamburger" onclick="toggleSidebar()" aria-label="Toggle Navigation">
    <i class="bi bi-list"></i>
</button>

<!-- Dark Overlay for Mobile -->
<div class="mobile-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar shadow-lg">
    
    <!-- Mobile Close Button (Only visible on small screens) -->
    <div class="d-lg-none position-absolute" style="top: 15px; right: 20px; z-index: 1001;">
        <i class="bi bi-x-lg text-white fs-5" onclick="toggleSidebar()" style="cursor: pointer; opacity: 0.7;"></i>
    </div>

    <!-- Sidebar Brand / Logo -->
    <div class="p-4 text-center border-bottom border-secondary mb-3 pt-5 pt-lg-4" style="border-color: rgba(255,255,255,0.05) !important;">
        <img src="../finora_logo.png" alt="Finora" class="mb-1" style="max-height: 42px;">
        <small class="d-block text-white" style="letter-spacing: 1px;">ADMIN PORTAL</small>
    </div>
    
    <!-- Navigation Links -->
    <div class="mt-2 flex-grow-1">
        <a href="dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill me-3"></i> Dashboard
        </a>
        <a href="employees.php" class="<?= ($current_page === 'employees.php' || $current_page === 'add_employee.php' || $current_page === 'view_employee.php') ? 'active' : '' ?>">
            <i class="bi bi-people-fill me-3"></i> Employee Management
        </a>
        
        <!-- NEW RESET REQUESTS LINK WITH RED-GLOW BADGE -->


        <a href="attendance.php" class="<?= $current_page === 'attendance.php' ? 'active' : '' ?>">
            <i class="bi bi-clock-history me-3"></i> Attendance
        </a>
        <a href="leave.php" class="<?= $current_page === 'leave.php' ? 'active' : '' ?>">
            <i class="bi bi-calendar2-check-fill me-3"></i> Leave Applications
        </a>
        <a href="reports.php" class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line-fill me-3"></i> Reports
        </a>
        <a href="faq.php" class="<?= $current_page === 'faq.php' ? 'active' : '' ?>">
            <i class="bi bi-question-circle-fill me-3"></i> FAQs & Support
        </a>

                <a href="reset_requests.php" class="<?= $current_page === 'reset_requests.php' ? 'active' : '' ?> d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-shield-lock-fill me-3"></i> Reset Requests
            </span>
            <?php if ($pending_reset_count > 0): ?>
                <span class="badge rounded-pill bg-danger px-2 py-1" style="font-size: 0.75rem; box-shadow: 0 0 8px rgba(220, 53, 69, 0.4);">
                    <?= $pending_reset_count ?>
                </span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Logout Button -->
    <div class="p-4 mt-auto border-top" style="border-color: rgba(255,255,255,0.05) !important;">
        <a href="../logout.php" class="btn-logout"><i class="bi bi-box-arrow-left me-2"></i> Log Out</a>
    </div>
</div>

<script>
    // Simple JavaScript to toggle the sidebar and overlay classes
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('show');
        document.querySelector('.mobile-overlay').classList.toggle('show');
    }
</script>