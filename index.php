<?php
require_once 'config/config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$themeCssVersion = @filemtime(__DIR__ . '/assets/css/modern-theme.css') ?: time();
$utilCssVersion = @filemtime(__DIR__ . '/assets/css/utilities.css') ?: time();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPD Reporting Portal</title>
    <meta name="description" content="Monthly reporting for Rhapsody of Realities Global Partnership Distribution zones.">
    <link rel="icon" type="image/webp" href="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/modern-theme.css?v=<?php echo $themeCssVersion; ?>" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/utilities.css?v=<?php echo $utilCssVersion; ?>" rel="stylesheet">
    <style>
        .landing-wrap { max-width: 1180px; margin: 0 auto; padding: 0 var(--gutter); }

        /* Hero: navy to royal, bright-blue glow, faint gold star ring (same technique as .auth-aside) */
        .landing-hero {
            --overlap: clamp(5rem, 16vw, 13rem);
            position: relative; isolation: isolate; overflow: hidden;
            padding-bottom: calc(var(--overlap) + var(--spacing-16));
            color: #fff;
            background:
                radial-gradient(70% 90% at 85% 0%, rgba(0, 144, 216, 0.5) 0%, rgba(0, 144, 216, 0) 60%),
                linear-gradient(165deg, var(--brand-royal) 0%, var(--brand-navy) 75%);
        }
        /* gold star ring comes from .star-ring::after in modern-theme.css; place it top-right here */
        .landing-hero.star-ring::after { right: -10rem; top: -12rem; bottom: auto; opacity: 0.9; }
        .landing-hero-copy { padding-top: var(--spacing-16); max-width: 44rem; }
        .landing-kicker { display: inline-flex; align-items: center; gap: var(--spacing-2); font-family: var(--font-mono); font-size: 0.6875rem; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(255, 255, 255, 0.75); }
        .landing-hero h1 { margin-top: var(--spacing-5); font-size: clamp(2.75rem, 6vw, 4.25rem); line-height: 1.02; font-weight: 500; letter-spacing: -0.035em; color: #fff; }
        .landing-hero .lead { margin-top: var(--spacing-5); max-width: 32rem; font-size: var(--text-lg); line-height: 1.5; color: rgba(255, 255, 255, 0.75); }
        .landing-cta { display: flex; flex-wrap: wrap; gap: var(--spacing-3); margin-top: var(--spacing-8); }
        .btn-on-dark { background: transparent; color: #fff; border-color: rgba(255, 255, 255, 0.45); }
        @media (hover: hover) and (pointer: fine) {
            .btn-on-dark:hover { background: rgba(255, 255, 255, 0.1); border-color: #fff; }
        }

        /* Product shot: browser frame overlapping the hero's bottom edge */
        .landing-shot { position: relative; z-index: 1; margin-top: calc(-1 * clamp(5rem, 16vw, 13rem)); }
        .landing-frame { border-radius: var(--radius-xl); background: #fff; box-shadow: var(--shadow-xl), 0 0 0 1px rgba(255, 255, 255, 0.1); overflow: hidden; outline: 1px solid rgba(16, 33, 38, 0.08); outline-offset: -1px; }
        .landing-frame-bar { display: flex; align-items: center; gap: 6px; height: 2.25rem; padding: 0 var(--spacing-4); background: var(--neutral-50); border-bottom: 1px solid var(--hairline); }
        .landing-frame-bar span { width: 9px; height: 9px; border-radius: 50%; background: var(--neutral-300); }
        .landing-frame-bar em { margin-left: var(--spacing-3); font-style: normal; font-family: var(--font-mono); font-size: 0.6875rem; color: var(--neutral-500); }
        .landing-frame img { display: block; width: 100%; height: auto; }

        /* How reporting works: numbered rows on thin gold rules */
        .landing-process { padding-block: var(--spacing-16); }
        .landing-process .meta { color: var(--brand-gold-deep); }
        .landing-process h2 { margin-top: var(--spacing-3); font-size: clamp(1.75rem, 3.5vw, 2.5rem); line-height: 1.1; font-weight: 500; letter-spacing: -0.03em; }
        .landing-steps { list-style: none; margin: var(--spacing-10) 0 0; padding: 0; border-bottom: 1px solid var(--brand-gold); }
        .landing-steps li { display: grid; grid-template-columns: 4rem minmax(0, 1fr) minmax(0, 1.4fr); gap: var(--spacing-6); align-items: baseline; padding: var(--spacing-6) 0; border-top: 1px solid var(--brand-gold); }
        .landing-steps .num { font-family: var(--font-mono); font-size: var(--text-sm); color: var(--brand-gold-deep); font-variant-numeric: tabular-nums; }
        .landing-steps h3 { font-size: var(--text-lg); font-weight: 500; letter-spacing: -0.01em; }
        .landing-steps p { color: var(--slate); line-height: 1.6; }

        .landing-footer { background: var(--brand-navy); color: rgba(255, 255, 255, 0.75); padding: var(--spacing-10) 0; font-size: var(--text-sm); }
        .landing-footer .landing-wrap { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--spacing-5); }
        .landing-footer-brand { display: inline-flex; align-items: center; gap: var(--spacing-2); color: #fff; font-weight: 500; }
        .landing-footer-brand img { width: 1.75rem; height: 1.75rem; }
        .landing-footer nav { display: flex; gap: var(--spacing-5); }
        .landing-footer a { color: #fff; }
        .nowrap { white-space: nowrap; }

        @media (max-width: 760px) {
            .landing-steps li { grid-template-columns: 2.5rem minmax(0, 1fr); gap: var(--spacing-2) var(--spacing-4); }
            .landing-steps p { grid-column: 2; }
        }
        @media (max-width: 640px) {
            .landing-hero-copy { padding-top: var(--spacing-8); }
            .landing-cta .btn { flex: 1 1 100%; }
            .landing-hero { padding-bottom: calc(var(--overlap) + var(--spacing-10)); }
            .landing-hero::after { width: 26rem; height: 26rem; right: -15rem; top: -6rem; }
            .landing-frame img { aspect-ratio: 1 / 1; object-fit: cover; object-position: left top; }
            .landing-frame-bar em { display: none; }
            .landing-process { padding-block: var(--spacing-12); }
            .landing-footer .landing-wrap { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body class="brand-page">
    <header class="landing-hero star-ring">
        <?php include __DIR__ . '/layouts/landing-header.php'; ?>
        <div class="landing-wrap">
            <div class="landing-hero-copy">
                <p class="landing-kicker">Rhapsody of Realities &middot; Global Partnership Distribution</p>
                <h1 id="home-title">Every zone&rsquo;s monthly report, in one place.</h1>
                <p class="lead">Submit the month, see what is still due, and follow your zone across the year.</p>
                <div class="landing-cta">
                    <a class="btn btn-brand btn-lg" href="<?php echo $baseUrl; ?>/login.php">Sign in</a>
                    <a class="btn btn-on-dark btn-lg" href="<?php echo $baseUrl; ?>/register.php">Register your zone</a>
                </div>
            </div>
        </div>
    </header>

    <main>
        <figure class="landing-shot landing-wrap">
            <div class="landing-frame">
                <div class="landing-frame-bar" aria-hidden="true"><span></span><span></span><span></span><em>GPD Reporting Portal &middot; Statistics</em></div>
                <img src="<?php echo $baseUrl; ?>/assets/images/portal-overview.png" width="2880" height="1800"
                     alt="Statistics page showing network totals: reports submitted, compliance rate, copies sponsored, distributions and souls won, with a monthly breakdown">
            </div>
        </figure>

        <section class="landing-process landing-wrap" aria-labelledby="process-title">
            <p class="meta">How reporting works</p>
            <h2 id="process-title">Three steps, once a month.</h2>
            <ol class="landing-steps">
                <li>
                    <span class="num">01</span>
                    <h3>Register your zone</h3>
                    <p>Request an account, then message <span class="nowrap">@gpd-support</span> on KingsChat to have it approved.</p>
                </li>
                <li>
                    <span class="num">02</span>
                    <h3>Submit the month</h3>
                    <p>Enter copies, distributions and outreach figures. Drafts save, so you can finish later.</p>
                </li>
                <li>
                    <span class="num">03</span>
                    <h3>Follow your zone</h3>
                    <p>See which months are in, which are still due, and how the totals move over the year.</p>
                </li>
            </ol>
        </section>
    </main>

    <footer class="landing-footer">
        <div class="landing-wrap">
            <span class="landing-footer-brand">
                <img src="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp" alt="" width="28" height="28">
                GPD Reporting Portal
            </span>
            <nav aria-label="Footer">
                <a href="<?php echo $baseUrl; ?>/login.php">Sign in</a>
                <a href="<?php echo $baseUrl; ?>/register.php">Register</a>
            </nav>
            <span>&copy; <?php echo date('Y'); ?> Rhapsody of Realities &middot; Global Partnership Distribution</span>
        </div>
    </footer>
</body>
</html>
