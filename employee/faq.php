<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
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

        /* Accordion Dark Theme Override */
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

        /* Floating Telegram Bot Button */
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
                    <p class="text-muted mb-0 fs-6">Find quick answers, or reach out if you need more help.</p>
                </div>
            </div>

            <!-- FAQ Accordion -->
            <div class="accordion mb-5" id="faqAccordion">

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            What are the office hours?
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Office hours are 8:00 AM to 5:00 PM (9 hours, including a 1-hour break). Your working hours calculation always starts from 8:00 AM even if you clock in earlier - arriving early doesn't add extra credited hours.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            Who is eligible for overtime (OT)?
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Only employees in the <strong>Information Technology</strong> and <strong>Operations</strong> departments are eligible for overtime pay/tracking. Employees in other departments still have their working hours recorded, but overtime hours aren't calculated or shown for them.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            Which leave types must be taken as one continuous block?
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body"><strong>Marriage Leave, Maternity Leave, Paternity Leave,</strong> and <strong>Haji Leave</strong> are "Consecutive" leave types - once you have a Pending or Approved application for one of these, you can't submit another until it's cancelled, rejected, or (for Haji Leave) never again since it's one-time only.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                            How many days is Haji Leave, and how often can I apply?
                        </button>
                    </h2>
                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Haji Leave is <strong>45 days</strong>, and it can only be applied for <strong>once during your entire employment</strong> - it does not reset yearly like other leave types. It's only available to Muslim employees.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                            Do I need to upload an attachment when applying for leave?
                        </button>
                    </h2>
                    <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Only for <strong>Medical Leave</strong> and <strong>Hospitalisation Leave</strong> - a supporting document (e.g. a medical certificate) is required before you can submit these. Other leave types don't require an attachment.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6">
                            What happens if I clock in late?
                        </button>
                    </h2>
                    <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">If you clock in after 8:00 AM but before 11:00 AM, your status will be marked "Late". If it's past 11:00 AM, you'll need to submit a Late Clock-In request with your estimated arrival time and a reason, which your manager/HR will review and approve.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq7">
                            What happens if I clock in or out while outside the office?
                        </button>
                    </h2>
                    <div id="faq7" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">You'll be asked to take a live photo for verification, and your location will be recorded and shown to HR. This applies to both regular clock-in/out and the Late Clock-In request flow.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq8">
                            What does my monthly "standing" status mean?
                        </button>
                    </h2>
                    <div id="faq8" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">It reflects how many times you've been late this month. Under 5 times keeps you in <strong>Good Standing</strong> (green). 5 or more shows a <strong>Warning</strong> (yellow). 10 or more shows <strong>Frequent Lateness</strong> (red). It resets at the start of each month.</div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq9">
                            How do I change my password?
                        </button>
                    </h2>
                    <div id="faq9" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">Go to <strong>My Profile</strong> and use the Security section to set a new password.</div>
                    </div>
                </div>

            </div>

            <!-- Support Section -->
            <h5 class="fw-bold text-white mb-3">Still need help?</h5>
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
                        <h6 class="fw-bold text-white mb-2">Chat with FinoraHelp</h6>
                        <p class="text-muted small mb-3">Instant answers via Telegram.</p>
                        <a href="https://t.me/FinoraHelpBot" target="_blank" class="btn btn-outline-light rounded-pill px-4">Open Telegram</a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Floating Telegram Bot Button -->
    <div class="bot-tooltip d-none d-md-block" id="botTooltip">Need help? Chat with FinoraHelp 🤖</div>
    <a href="https://t.me/FinoraHelpBot" target="_blank" class="bot-float-btn" title="Chat with FinoraBot on Telegram">
        <i class="bi bi-robot"></i>
    </a>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide the tooltip after a few seconds so it doesn't stay in the way
        setTimeout(() => {
            const tip = document.getElementById('botTooltip');
            if (tip) tip.style.display = 'none';
        }, 5000);
    </script>
</body>
</html>