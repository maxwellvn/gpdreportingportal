<?php
/**
 * Landing-page header / navbar — reusable component.
 *
 * Usage (inside the centered content rail):
 *     <?php include __DIR__ . '/landing-header.php'; ?>
 *
 * Expects $baseUrl; falls back to BASE_URL or ''. Keeps the same look and
 * position as the rest of the landing page, and on mobile collapses the
 * Login / Sign Up actions into a themed hamburger menu.
 */
if (!isset($baseUrl)) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
}
?>
<style>
    .home-nav {
        position: relative;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        padding: 8px;
    }

    .home-brand {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        font-size: 20px;
        font-weight: 500;
        color: rgba(0, 0, 0, 0.8);
        white-space: nowrap;
    }

    .home-brand img {
        width: 32px;
        height: 32px;
        object-fit: contain;
    }

    .home-actions {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .home-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 35px;
        padding: 8px 14px;
        border-radius: 50px;
        border: 0;
        font: inherit;
        font-size: 16px;
        font-weight: 500;
        line-height: 1;
        cursor: pointer;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .home-button:hover {
        transform: translateY(-1px);
    }

    /* Login — blue fill with white→transparent gradient border */
    .home-button-primary {
        color: #fff;
        border: 1px solid transparent;
        background:
            linear-gradient(#0087cd, #0087cd) padding-box,
            linear-gradient(140deg, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.35)) border-box;
    }

    /* Sign Up — white fill with inner shadow */
    .home-button-secondary {
        color: #000;
        background: #fff;
        box-shadow: inset 0 -2px 10px rgba(177, 177, 177, 0.25);
    }

    /* hamburger toggle — hidden on desktop, themed white pill */
    .home-nav-toggle {
        display: none;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 38px;
        padding: 0;
        border: 1px solid transparent;
        border-radius: 12px;
        background:
            linear-gradient(#ffffff, #ffffff) padding-box,
            linear-gradient(140deg, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.4)) border-box;
        box-shadow: inset 0 -2px 10px rgba(177, 177, 177, 0.25);
        cursor: pointer;
    }

    .home-nav-toggle .bar {
        display: block;
        width: 18px;
        height: 2px;
        border-radius: 2px;
        background: #0b2a3d;
        transition: transform 0.22s ease, opacity 0.18s ease;
    }

    .home-nav-toggle .bar + .bar {
        margin-top: 4px;
    }

    .home-nav-toggle.is-open .bar:nth-child(1) {
        transform: translateY(6px) rotate(45deg);
    }

    .home-nav-toggle.is-open .bar:nth-child(2) {
        opacity: 0;
    }

    .home-nav-toggle.is-open .bar:nth-child(3) {
        transform: translateY(-6px) rotate(-45deg);
    }

    @media (max-width: 640px) {
        .home-brand {
            padding: 6px 0;
            font-size: 16px;
        }

        .home-brand img {
            width: 28px;
            height: 28px;
        }

        .home-nav-toggle {
            display: inline-flex;
        }

        .home-actions {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
            width: min(230px, 72vw);
            padding: 12px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.9);
            -webkit-backdrop-filter: blur(14px);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 22px 44px rgba(0, 0, 0, 0.16);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
            z-index: 30;
        }

        .home-actions.is-open {
            opacity: 1;
            visibility: visible;
            transform: none;
        }

        .home-actions .home-button {
            width: 100%;
            min-height: 40px;
            font-size: 15px;
        }
    }
</style>

<header class="home-nav">
    <a class="home-brand" href="<?php echo $baseUrl; ?>/" aria-label="GPD Reporting Portal home">
        <img src="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp" alt="">
        <span>GPD Reporting Portal</span>
    </a>
    <button type="button" class="home-nav-toggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="home-nav-menu">
        <span class="bar"></span>
        <span class="bar"></span>
        <span class="bar"></span>
    </button>
    <nav class="home-actions" id="home-nav-menu" aria-label="Account">
        <a class="home-button home-button-primary" href="<?php echo $baseUrl; ?>/login.php">Login</a>
        <a class="home-button home-button-secondary" href="<?php echo $baseUrl; ?>/register.php">Sign Up</a>
    </nav>
</header>

<script>
    (function () {
        var toggle = document.querySelector('.home-nav-toggle');
        var menu = document.getElementById('home-nav-menu');
        if (!toggle || !menu) return;

        function setOpen(open) {
            menu.classList.toggle('is-open', open);
            toggle.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            setOpen(!menu.classList.contains('is-open'));
        });

        document.addEventListener('click', function (e) {
            if (!menu.classList.contains('is-open')) return;
            if (menu.contains(e.target) || toggle.contains(e.target)) return;
            setOpen(false);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setOpen(false);
        });
    })();
</script>
