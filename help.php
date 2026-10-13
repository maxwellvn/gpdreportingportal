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
/* Help: FAQ accordion on native <details>; candidates for the shared theme. */
.faq { border: 1px solid var(--hairline); border-radius: var(--radius-lg); background: #fff; overflow: hidden; }
.faq-item + .faq-item { border-top: 1px solid var(--hairline); }
.faq-item > summary { display: flex; align-items: center; justify-content: space-between; gap: var(--spacing-3); padding: var(--spacing-4) var(--spacing-5); cursor: pointer; list-style: none; font-weight: 500; color: var(--ink); }
.faq-item > summary::-webkit-details-marker { display: none; }
.faq-item > summary:focus-visible { outline: none; box-shadow: inset var(--focus-ring); }
.faq-icon { flex-shrink: 0; font-size: var(--text-xs); color: var(--neutral-600); transition: transform var(--dur) var(--ease-out); }
.faq-item[open] > summary .faq-icon { transform: rotate(180deg); }
.faq-a { padding: 0 var(--spacing-5) var(--spacing-5); color: var(--slate); font-size: var(--text-sm); line-height: 1.65; max-width: 44rem; }
.faq-a ul { margin: 0 0 var(--spacing-2) 1.1rem; padding: 0; list-style: disc; }
.faq-a li { margin-bottom: var(--spacing-1); }
@media (hover: hover) and (pointer: fine) { .faq-item > summary:hover { background: var(--mist); } }
@media (prefers-reduced-motion: reduce) { .faq-icon { transition: none; } }
.help-contact { display: flex; gap: var(--spacing-4); align-items: flex-start; }
.help-contact img { width: 2.5rem; height: 2.5rem; object-fit: contain; border-radius: var(--radius-md); border: 1px solid var(--hairline); padding: 0.25rem; background: #fff; flex-shrink: 0; }
.help-contact h2 { font-size: var(--text-base); font-weight: 500; color: var(--ink); margin: 0 0 var(--spacing-1); }
.help-contact p { color: var(--slate); font-size: var(--text-sm); margin: 0 0 var(--spacing-4); max-width: 40rem; }
</style>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/dashboard.php">Home</a><span class="sep">/</span><span>Help &amp; support</span>
</nav>

<div class="page-head">
    <div>
        <div class="page-eyebrow">Support</div>
        <h1 class="page-title">Help &amp; support</h1>
        <p class="page-sub">Answers to common questions about using the GPD Reporting Portal.</p>
    </div>
    <?php if (!isset($user['role']) || $user['role'] === ROLE_RZM): ?>
    <div class="page-actions">
        <a href="<?php echo BASE_URL; ?>/pages/reports/create.php" class="btn btn-primary" data-link-loading><i class="fas fa-plus" aria-hidden="true"></i> Submit report</a>
    </div>
    <?php endif; ?>
</div>

<section class="section">
    <div class="section-head"><h2 class="section-title">Submitting reports</h2></div>
    <div class="faq">
            <details class="faq-item">
                <summary>When do I need to submit my monthly report?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Reports are due at the end of each month. You can submit for the <strong>current month</strong> at any time during that month, or retroactively for past months. You <strong>cannot</strong> submit for a future month.</div>
            </details>
            <details class="faq-item">
                <summary>How do I submit a report?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Click <strong>Submit report</strong> in the navigation bar or on your Home page. Fill in the 6-step form; you can move between steps freely and your progress is auto-saved as a draft in your browser. When all fields are complete, click <strong>Submit Report</strong> on the last step.</div>
            </details>
            <details class="faq-item">
                <summary>What if I submit the wrong numbers?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">You can edit a submitted report at any time. Go to <strong>History</strong>, find the report, and click <strong>Edit</strong>. Make your corrections and save. Your changes will be reflected immediately.</div>
            </details>
            <details class="faq-item">
                <summary>Can I submit reports for previous months?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Yes. On the Submit Report form, change the <strong>Year</strong> and <strong>Month</strong> selectors to the month you want to report for. You can go back up to 5 years.</div>
            </details>
            <details class="faq-item">
                <summary>My draft disappeared. Where did it go?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Drafts are saved in your browser's local storage and are specific to the device and browser you used. If you switched devices or cleared your browser data, the draft will not be available. Try to complete and submit your report in one session if possible.</div>
            </details>
            <details class="faq-item">
                <summary>What do I enter if I have no activity for a field?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Leave the field blank or enter <strong>0</strong>. Both are treated the same; all fields default to 0 if left empty.</div>
            </details>
            <details class="faq-item">
                <summary>What files can I attach to a report?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a"><ul>
                        <li><strong>Testimonies &amp; Innovations documents</strong>: PDF, DOC, or DOCX (max 10 MB each).</li>
                        <li><strong>Report images</strong>: JPG, PNG, or GIF, up to 10 images per report. You can drag and drop images directly onto the upload area.</li>
                    </ul>
                    <p>All attachments are optional.</p></div>
            </details>
    </div>
</section>

<?php if (!isset($user['role']) || $user['role'] === ROLE_RZM): ?>
<section class="section">
    <div class="section-head"><h2 class="section-title">Understanding your Home page</h2></div>
    <div class="faq">
            <details class="faq-item">
                <summary>What does the card at the top of my Home page mean?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">It shows this month's report status. <strong>Submitted</strong> means you're up to date. <strong>Not submitted</strong> or <strong>overdue</strong> means there are months you still need to report for; the card lists them with a Submit link for each.</div>
            </details>
            <details class="faq-item">
                <summary>What are "overdue" months?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Any month in the current calendar year that has passed and for which you have not submitted a report is considered overdue. The counter resets to zero at the start of each new year.</div>
            </details>
            <details class="faq-item">
                <summary>What is a "consistency streak"?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Your streak is the number of consecutive months you have submitted a report without missing one. Missing a month resets it to zero.</div>
            </details>
    </div>
</section>

<?php endif; ?>

<section class="section">
    <div class="section-head"><h2 class="section-title">Account &amp; login</h2></div>
    <div class="faq">
            <details class="faq-item">
                <summary>How do I change my password?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Go to <a href="<?php echo BASE_URL; ?>/profile.php" class="link">My Account</a> and use the <strong>Change password</strong> panel. Enter your current password and your new password, then click <strong>Update password</strong>.</div>
            </details>
            <details class="faq-item">
                <summary>Can I log in with KingsChat?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Yes. On the login page click <strong>Continue with KingsChat</strong>. If your KingsChat account hasn't been linked yet, you'll be prompted to link it. You can also link it manually from <a href="<?php echo BASE_URL; ?>/profile.php" class="link">My Account</a>.</div>
            </details>
            <details class="faq-item">
                <summary>I forgot my password. What do I do?<i class="fas fa-chevron-down faq-icon" aria-hidden="true"></i></summary>
                <div class="faq-a">Contact your regional administrator or the portal support team (see contact details below). They can reset your password from the User Management panel.</div>
            </details>
    </div>
</section>

<section class="section" aria-labelledby="contact-title">
    <div class="panel panel-muted">
        <div class="panel-body help-contact">
            <img src="<?php echo BASE_URL; ?>/assets/images/kingschat-logo.png" alt="">
            <div>
                <h2 id="contact-title">Still need help? Message @gpd-support on KingsChat</h2>
                <p>Include your username, the month affected, and a short description of the issue so we can help you quickly.</p>
                <a href="https://kingschat.online/user/gpd-support" target="_blank" rel="noopener" class="btn btn-secondary">Chat with @gpd-support <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
            </div>
        </div>
    </div>
</section>

<?php require_once 'layouts/footer.php'; ?>
