<?php
/**
 * Landing-page top bar, drawn on the blue hero. Expects $baseUrl; falls back to BASE_URL.
 * ponytail: two links, no hamburger; "Register" hides under 480px (the hero repeats it).
 */
if (!isset($baseUrl)) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
}
?>
<style>
    .landing-nav { position: relative; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: var(--spacing-3); max-width: 1180px; margin: 0 auto; padding: var(--spacing-5) var(--gutter); }
    .landing-brand { display: inline-flex; align-items: center; gap: var(--spacing-2); font-weight: 500; color: #fff; text-decoration: none; white-space: nowrap; }
    .landing-brand img { width: 2rem; height: 2rem; object-fit: contain; }
    .landing-nav-actions { display: flex; align-items: center; gap: var(--spacing-2); }
    .landing-nav .btn-ghost { color: #fff; }
    .landing-nav .btn-light { background: #fff; color: var(--brand-navy); border-color: #fff; }
    @media (hover: hover) and (pointer: fine) {
        .landing-nav .btn-ghost:hover { background: rgba(255, 255, 255, 0.1); }
        .landing-nav .btn-light:hover { background: var(--brand-sky); border-color: var(--brand-sky); }
    }
    @media (max-width: 480px) {
        .landing-nav-register { display: none; }
    }
</style>
<header class="landing-nav">
    <a class="landing-brand" href="<?php echo $baseUrl; ?>/" aria-label="GPD Reporting Portal home">
        <img src="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp" alt="" width="32" height="32">
        <span>GPD Reporting Portal</span>
    </a>
    <nav class="landing-nav-actions" aria-label="Account">
        <a class="btn btn-ghost btn-sm landing-nav-register" href="<?php echo $baseUrl; ?>/register.php">Register</a>
        <a class="btn btn-light btn-sm" href="<?php echo $baseUrl; ?>/login.php">Sign in</a>
    </nav>
</header>
