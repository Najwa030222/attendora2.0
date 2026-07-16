<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQs & Support - Attendora</title>
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
            background-image: radial-gradient(circle at top left, rgba(16, 185, 129, 0.08), transparent 40%),
                              radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.05), transparent 40%);
            min-height: 100vh;
        }
        .text-muted { color: var(--text-muted) !important; }
        .text-emerald { color: var(--emerald-neon) !important; }
        .main-content { margin-left: 280px; flex-grow: 1; width: calc(100% - 280px); min-height: 100vh; overflow-x: hidden; }
        .top-header { background: var(--bg-panel); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; }
        .panel-card { background: var(--bg-card); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 16px; }

        .accordion-item { background: var(--bg-card); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px !important; margin-bottom: 10px; overflow: hidden; }
        .accordion-button { background: var(--bg-card); color: var(--text-light); font-weight: 600; font-size: 0.95rem; box-shadow: none !important; }
        .accordion-button:not(.collapsed) { background: rgba(16, 185, 129, 0.08); color: var(--emerald-neon); }
        .accordion-button::after { filter: invert(1); }
        .accordion-body { color: var(--text-muted); font-size: 0.9rem; line-height: 1.6; background: var(--bg-card); }

        .support-card { background: var(--bg-card); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 24px; text-align: center; height: 100%; transition: all 0.3s; }
        .support-card:hover { border-color: rgba(16,185,129,0.3); transform: translateY(-4px); }
        .support-icon { width: 56px; height: 56px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; background: rgba(16,185,129,0.1); color: var(--emerald-neon); margin: 0 auto 14px; }

        .btn-emerald-solid { background-color: var(--emerald-neon); color: #0b0d14 !important; border-radius: 8px; font-weight: 600; transition: all 0.3s; box-shadow: 0 0 15px var(--emerald-glow); border: none; }
        .btn-emerald-solid:hover { background-color: #059669; color: #fff !important; }

        .bot-float-btn {
            position: fixed; bottom: 30px; right: 30px; width: 62px; height: 62px;
            background: linear-gradient(135deg, var(--emerald-neon), #059669);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 20px rgba(16,185,129,0.5); z-index: 1050; text-decoration: none;
            transition: all 0.3s; animation: botPulse 2.5s infinite;
        }
        .bot-float-btn:hover { transform: scale(1.1); box-shadow: 0 6px 28px rgba(16,185,129,0.7); }
        .bot-float-btn i { font-size: 1.8rem; color: #0b0d14; }
        @keyframes botPulse {
            0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.5); }
            70% { box-shadow: 0 0 0 14px rgba(16,185,129,0); }
            100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
        }
        .bot-tooltip {
            position: fixed; bottom: 42px; right: 100px; background: var(--bg-card);
            border: 1px solid rgba(16,185,129,0.3); color: var(--text-light); padding: 8px 14px;
            border-radius: 10px; font-size: 0.85rem; z-index: 1050; white-space: nowrap;
            box-shadow: 0 4px 15px rgba(0,0,0,0.4);
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php require 'sidebar.php'; ?>

        <div class="main-content p-5">
            <div class="top-header d-flex justify-content-between align-items-center mb-5 p-4 shadow-sm">
                <div>
                    <h4 class="mb-1 fw-bold">FAQs & Support</h4>
                    <p class="text-muted mb-0 fs-6">Company policy reference and support contacts.</p>
                </div>
            </div>

            <div class="accordion mb-5" id="faqAccordion">

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            What are the office hours?
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">8:00 AM to 5:00 PM (9 hours including a 1-hour break). Working hours calculation always starts from 8:00 AM regardless of an early clock-in.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            How is overtime eligibility controlled?
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Via the <strong>overtime_eligible</strong> flag on each department (currently set for Information Technology and Operations). Employees outside those departments still have hours tracked in the DB, but OT is greyed out and not shown/counted for them.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            Which leave types are "Consecutive" / one-time only?
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Marriage, Maternity, and Paternity Leave are Consecutive (must be one continuous block, locked per year while Pending/Approved). Haji Leave is also Consecutive but additionally one-time-only for life (45 days, Muslim employees only) - it never resets yearly.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                            How do I approve or reject a leave/attendance request?
                        </button>
                    </h2>
                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Pending Leave Applications and Late Clock-In Requests both appear at the top of their respective pages (Leave Applications / Attendance). Use the Approve or Reject buttons directly on each request row.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                            How does the late-flag "standing" system work?
                        </button>
                    </h2>
                    <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Under 5 Late statuses in a calendar month = Good Standing (green). 5-9 = Warning (yellow). 10+ = Frequent Lateness (red). This resets automatically every month based on the attendance_date.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6">
                            How do I export attendance records?
                        </button>
                    </h2>
                    <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">On the Attendance page, use the Day/Month/Year filter (and optionally the name/department search) to narrow down what you want, then click Export PDF - it opens a printable report matching exactly what's currently filtered. Search for one employee to get a single-employee report.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq7">
                            What's the default password for a new employee?
                        </button>
                    </h2>
                    <div id="faq7" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">New employees are created with the password <strong>Attendora@123!</strong> and are required to change it on first login via a blocking popup before they can use the system.</div>
                    </div>
                </div>

            </div>

            <!-- Support Section -->
            <h5 class="fw-bold text-white mb-3">Need further support?</h5>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="support-card">
                        <div class="support-icon"><i class="bi bi-envelope-fill"></i></div>
                        <h6 class="fw-bold text-white mb-2">Email Us</h6>
                        <p class="text-muted small mb-3">For general inquiries or issues.</p>
                        <a href="mailto:finora_help@gmail.com" class="btn btn-outline-light rounded-pill px-4">finora_help@gmail.com</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="support-card">
                        <div class="support-icon"><i class="bi bi-flag-fill"></i></div>
                        <h6 class="fw-bold text-white mb-2">File a Report</h6>
                        <p class="text-muted small mb-3">Report an issue or submit a formal inquiry.</p>
                        <a href="https://forms.gle/K9R5QfidAxYfWqtu7" target="_blank" class="btn btn-emerald-solid rounded-pill px-4">Open Form</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="support-card">
                        <div class="support-icon"><i class="bi bi-telegram"></i></div>
                        <h6 class="fw-bold text-white mb-2">Chat with FinoraBot</h6>
                        <p class="text-muted small mb-3">Instant answers via Telegram.</p>
                        <a href="https://t.me/FinoraHelpBot" target="_blank" class="btn btn-outline-light rounded-pill px-4">Open Telegram</a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Floating Telegram Bot Button -->
    <div class="bot-tooltip d-none d-md-block" id="botTooltip">Need help? Chat with FinoraBot 🤖</div>
    <a href="https://t.me/FinoraHelpBot" target="_blank" class="bot-float-btn" title="Chat with FinoraBot on Telegram">
        <i class="bi bi-robot"></i>
    </a>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        setTimeout(() => {
            const tip = document.getElementById('botTooltip');
            if (tip) tip.style.display = 'none';
        }, 5000);
    </script>
</body>
</html>