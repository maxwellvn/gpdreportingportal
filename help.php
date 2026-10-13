<?php
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

Auth::requireLogin();

$auth = new Auth();
$user = $auth->getUser($_SESSION['user_id']);
$_SESSION['user_data'] = $user;

require_once 'layouts/header.php';
?>

<style>
.help-page { max-width: 52rem; margin: 0 auto; padding: 1.75rem 1rem 4rem; }
.help-breadcrumb { display:flex; align-items:center; gap:0.35rem; font-size:0.78rem; color:var(--neutral-400); margin-bottom:1.25rem; flex-wrap:wrap; }
.help-breadcrumb a { color:var(--neutral-500); text-decoration:none; }
.help-breadcrumb a:hover { color:var(--primary-600); }
.help-breadcrumb .sep { color:var(--neutral-300); }
.help-head { margin-bottom:2rem; }
.help-head h1 { font-size:1.25rem; font-weight:700; color:var(--neutral-900); margin:0 0 0.3rem; }
.help-head p  { font-size:0.8125rem; color:var(--neutral-500); margin:0; }

/* Quick links */
.help-quick { display:grid; grid-template-columns:repeat(3,1fr); gap:0.75rem; margin-bottom:2.25rem; }
@media(max-width:600px){ .help-quick { grid-template-columns:1fr 1fr; } }
@media(max-width:380px){ .help-quick { grid-template-columns:1fr; } }
.help-quick-item { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; padding:1rem; text-align:center; text-decoration:none; color:var(--neutral-700); transition:border-color 0.15s, box-shadow 0.15s; }
.help-quick-item:hover { border-color:var(--primary-400); box-shadow:0 2px 8px rgba(14,165,233,0.08); color:var(--primary-700); }
.help-quick-icon { font-size:1.4rem; color:var(--primary-500); margin-bottom:0.45rem; }
.help-quick-label { font-size:0.78rem; font-weight:600; }
.help-quick-sub { font-size:0.7rem; color:var(--neutral-400); margin-top:0.15rem; }

/* FAQ */
.help-section { margin-bottom:2rem; }
.help-section-title { font-size:0.875rem; font-weight:700; color:var(--neutral-700); text-transform:uppercase; letter-spacing:0.05em; margin:0 0 0.75rem; padding-bottom:0.5rem; border-bottom:2px solid var(--primary-100); }
.help-faq { border:1px solid var(--neutral-200); border-radius:var(--radius-lg); background:#fff; overflow:hidden; }
.help-faq-item { border-bottom:1px solid var(--neutral-100); }
.help-faq-item:last-child { border-bottom:none; }
.help-faq-q { display:flex; align-items:center; justify-content:space-between; gap:0.75rem; padding:0.9rem 1.1rem; cursor:pointer; font-size:0.8375rem; font-weight:600; color:var(--neutral-800); user-select:none; }
.help-faq-q:hover { background:var(--neutral-50); }
.help-faq-q .faq-icon { color:var(--primary-400); font-size:0.75rem; transition:transform 0.2s; flex-shrink:0; }
.help-faq-item.open .faq-icon { transform:rotate(180deg); }
.help-faq-a { display:none; padding:0 1.1rem 1rem; font-size:0.8125rem; color:var(--neutral-600); line-height:1.65; }
.help-faq-a ul { margin:0.5rem 0 0 1rem; padding:0; }
.help-faq-a li { margin-bottom:0.35rem; }
.help-faq-item.open .help-faq-a { display:block; }

/* Contact card */
.help-contact { border:1px solid var(--primary-200); border-radius:var(--radius-lg); background:var(--primary-50); padding:1.25rem 1.4rem; display:flex; gap:1rem; align-items:flex-start; }
.help-contact-icon { width:2.5rem; height:2.5rem; border-radius:var(--radius-md); background:var(--primary-500); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.help-contact-body h3 { font-size:0.875rem; font-weight:700; color:var(--primary-800); margin:0 0 0.3rem; }
.help-contact-body p { font-size:0.8125rem; color:var(--primary-700); margin:0 0 0.65rem; line-height:1.5; }
.help-contact-body a { display:inline-flex; align-items:center; gap:0.35rem; font-size:0.78rem; font-weight:600; color:var(--primary-700); text-decoration:none; padding:0.35rem 0.8rem; border:1px solid var(--primary-300); border-radius:var(--radius-full); background:#fff; transition:background 0.15s; margin-right:0.5rem; margin-bottom:0.4rem; }
.help-contact-body a:hover { background:var(--primary-100); }
</style>

<div class="help-page">

    <!-- Breadcrumb -->
    <div class="help-breadcrumb">
        <a href="<?php echo BASE_URL; ?>/dashboard.php"><i class="fas fa-home" style="font-size:0.7rem;"></i> Dashboard</a>
        <span class="sep">/</span>
        <span>Help &amp; Support</span>
    </div>

    <div class="help-head">
        <h1><i class="fas fa-question-circle" style="color:var(--primary-500);margin-right:0.4rem;font-size:1rem;"></i>Help &amp; Support</h1>
        <p>Answers to common questions about using the GPD Reporting Portal.</p>
    </div>

    <!-- Quick links -->
    <div class="help-quick">
        <?php if (!isset($user['role']) || $user['role'] === 'user'): ?>
        <a href="<?php echo BASE_URL; ?>/pages/reports/create.php" class="help-quick-item" data-link-loading>
            <div class="help-quick-icon"><i class="fas fa-paper-plane"></i></div>
            <div class="help-quick-label">Submit Report</div>
            <div class="help-quick-sub">File this month's report</div>
        </a>
        <?php endif; ?>
        <a href="<?php echo BASE_URL; ?>/pages/reports/list.php" class="help-quick-item" data-link-loading>
            <div class="help-quick-icon"><i class="fas fa-list"></i></div>
            <div class="help-quick-label">My Reports</div>
            <div class="help-quick-sub">View your history</div>
        </a>
        <a href="<?php echo BASE_URL; ?>/profile.php" class="help-quick-item" data-link-loading>
            <div class="help-quick-icon"><i class="fas fa-user-circle"></i></div>
            <div class="help-quick-label">My Account</div>
            <div class="help-quick-sub">Update profile &amp; password</div>
        </a>
        <a href="<?php echo BASE_URL; ?>/dashboard.php" class="help-quick-item" data-link-loading>
            <div class="help-quick-icon"><i class="fas fa-tachometer-alt"></i></div>
            <div class="help-quick-label">Dashboard</div>
            <div class="help-quick-sub">View your status at a glance</div>
        </a>
    </div>

    <!-- FAQ: Submitting Reports -->
    <div class="help-section">
        <div class="help-section-title">Submitting Reports</div>
        <div class="help-faq">
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    When do I need to submit my monthly report?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Reports are due at the end of each month. You can submit for the <strong>current month</strong> at any time during that month, or retroactively for past months. You <strong>cannot</strong> submit for a future month.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    How do I submit a report?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Click <strong>Submit Report</strong> in the navigation bar or on your Dashboard. Fill in the 6-step form — you can move between steps freely and your progress is auto-saved as a draft in your browser. When all fields are complete, click <strong>Submit Report</strong> on the last step.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    What if I submit the wrong numbers?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    You can edit a submitted report at any time. Go to <strong>My Reports</strong>, find the report, and click <strong>Edit</strong>. Make your corrections and save. Your changes will be reflected immediately.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    Can I submit reports for previous months?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Yes. On the Submit Report form, change the <strong>Year</strong> and <strong>Month</strong> selectors to the month you want to report for. You can go back up to 5 years.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    My draft disappeared — where did it go?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Drafts are saved in your browser's local storage and are specific to the device and browser you used. If you switched devices or cleared your browser data, the draft will not be available. Always try to complete and submit your report in one session if possible.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    What do I enter if I have no activity for a field?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Leave the field blank or enter <strong>0</strong>. Both are treated the same — all fields default to 0 if left empty.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    What files can I attach to a report?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    <ul>
                        <li><strong>Testimonies &amp; Innovations documents</strong> — PDF, DOC, or DOCX (max 10 MB each).</li>
                        <li><strong>Report images</strong> — JPG, PNG, or GIF, up to 10 images per report. You can drag and drop images directly onto the upload area.</li>
                    </ul>
                    All attachments are optional.
                </div>
            </div>
        </div>
    </div>

    <!-- FAQ: Understanding the Dashboard -->
    <div class="help-section">
        <div class="help-section-title">Understanding Your Dashboard</div>
        <div class="help-faq">
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    What does the "What you need to do now" section mean?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    This section shows your current submission status at a glance. A <span style="color:var(--success-600);font-weight:600;">green</span> indicator means you're up to date. An <span style="color:var(--danger-600);font-weight:600;">orange or red</span> indicator means there are months you still need to report for.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    What are "overdue" months?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Any month in the current calendar year that has passed and for which you have not submitted a report is considered overdue. The counter resets to zero at the start of each new year.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    What is a "consistency streak"?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Your streak is the number of consecutive months you have submitted a report without missing one. Missing a month resets it to zero. Aim to keep it going!
                </div>
            </div>
        </div>
    </div>

    <!-- FAQ: Account -->
    <div class="help-section">
        <div class="help-section-title">Account &amp; Login</div>
        <div class="help-faq">
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    How do I change my password?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Go to <a href="<?php echo BASE_URL; ?>/profile.php" style="color:var(--primary-600);">My Account</a> and scroll to the <strong>Change Password</strong> section. Enter your current password and your new password, then click <strong>Update Password</strong>.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    Can I log in with KingsChat?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Yes. On the login page click <strong>Continue with KingsChat</strong>. If your KingsChat account hasn't been linked yet, you'll be prompted to link it. You can also link it manually from <a href="<?php echo BASE_URL; ?>/profile.php" style="color:var(--primary-600);">My Account</a>.
                </div>
            </div>
            <div class="help-faq-item">
                <div class="help-faq-q" onclick="toggleFaq(this)">
                    I forgot my password — what do I do?
                    <i class="fas fa-chevron-down faq-icon"></i>
                </div>
                <div class="help-faq-a">
                    Contact your regional administrator or the portal support team (see contact details below). They can reset your password from the User Management panel.
                </div>
            </div>
        </div>
    </div>

    <!-- Contact -->
    <div class="help-section">
        <div class="help-section-title">Still need help?</div>
        <div class="help-contact">
            <div class="help-contact-icon" style="background:#fff;padding:0.2rem;border:1px solid var(--primary-100);">
                <img src="<?php echo BASE_URL; ?>/assets/images/kingschat-logo.png"
                     alt="KingsChat"
                     style="width:100%;height:100%;object-fit:contain;border-radius:6px;">
            </div>
            <div class="help-contact-body">
                <h3>Reach us on KingsChat</h3>
                <p>Message <strong>@gpd-tech</strong> on KingsChat for support. Please include your username, the month affected, and a brief description of your issue so we can help you quickly.</p>
                <a href="https://kingschat.online/user/gpd-tech" target="_blank" rel="noopener">
                    <img src="<?php echo BASE_URL; ?>/assets/images/kingschat-logo.png"
                         alt="KingsChat"
                         style="width:1rem;height:1rem;object-fit:contain;border-radius:3px;flex-shrink:0;">
                    Chat with @gpd-tech
                </a>
            </div>
        </div>
    </div>

</div>

<script>
function toggleFaq(el) {
    var item = el.parentElement;
    var wasOpen = item.classList.contains('open');
    // Close all
    document.querySelectorAll('.help-faq-item.open').forEach(function(i) { i.classList.remove('open'); });
    if (!wasOpen) item.classList.add('open');
}
</script>

<?php require_once 'layouts/footer.php'; ?>
