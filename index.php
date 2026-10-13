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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPD Reporting Portal</title>
    <link rel="icon" type="image/webp" href="<?php echo $baseUrl; ?>/assets/images/logo-rc.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@1,500;1,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --portal-blue: #0087cd;
            --portal-blue-dark: #004467;
            --portal-bg: #e3e3e3;
            --portal-text: rgba(0, 0, 0, 0.9);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--portal-text);
            background: var(--portal-bg);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        /* ─── hero ─── */
        .portal-home {
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100vh;
            min-height: 640px;
            overflow: hidden;
            background:
                /* Group 1 — Ellipse 11 (low-left) */
                radial-gradient(42% 58% at 19.5% 90%, rgba(255, 245, 0, 0.95) 0%, rgba(255, 245, 0, 0.58) 44%, transparent 76%),
                /* Group 1 — Ellipse 12 (bottom-center) */
                radial-gradient(30% 42% at 60.5% 104%, rgba(255, 245, 0, 0.9) 0%, rgba(255, 245, 0, 0.52) 44%, transparent 74%),
                /* Group 1 — Ellipse 13 (lower-right) */
                radial-gradient(30% 42% at 88% 92%, rgba(255, 245, 0, 0.88) 0%, rgba(255, 245, 0, 0.5) 44%, transparent 74%),
                var(--portal-bg);
        }

        /* Ellipse 14 — large soft gray glow over the glass strips */
        .top-glow {
            position: absolute;
            left: 42.8%;
            top: 7.5%;
            width: 143%;
            height: 106%;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background: rgba(214, 214, 214, 0.88);
            filter: blur(120px);
            pointer-events: none;
            z-index: 2;
        }

        /* Frame 42 — refracted glass strips */
        .glass-strips {
            position: absolute;
            inset: 0;
            display: flex;
            gap: 0;
            opacity: 0.44;
            pointer-events: none;
            z-index: 1;
        }

        .glass-strip {
            width: 3.75rem;
            flex: 0 0 auto;
            background:
                linear-gradient(90deg, rgba(217, 217, 217, 0.4) 0%, rgba(120, 120, 120, 0.18) 50%, rgba(53, 52, 52, 0.4) 100%),
                linear-gradient(180deg, rgba(217, 217, 217, 0.8) 0%, rgba(217, 217, 217, 0.4) 71%, rgba(217, 217, 217, 0.1) 100%);
            background-blend-mode: multiply, normal;
            box-shadow: inset 1px 0 rgba(255, 255, 255, 0.6), inset -1px 0 rgba(0, 0, 0, 0.05);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
        }

        .home-content {
            position: relative;
            z-index: 4;
            flex: 0 0 auto;
            width: min(1309px, calc(100% - 3rem));
            margin: 0 auto;
            padding: 23px 0 0;
        }

        /* header nav styles live in layouts/landing-header.php */

        /* ─── hero text ─── */
        .hero {
            width: min(995px, 100%);
            margin: 72px auto clamp(32px, 5vh, 60px);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 18px;
            text-align: center;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            border-radius: 100px;
            background: rgba(255, 255, 255, 0.6);
            font-size: 14px;
            font-weight: 500;
            color: #000;
        }

        .hero-title {
            margin: 0;
            font-size: clamp(3.25rem, 7.1vw, 5.625rem);
            line-height: 1.06;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: #0b4865;
            background: linear-gradient(180deg, #0e5278 0%, #063248 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-title-accent {
            display: block;
            margin-top: 0.02em;
            font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
            font-style: italic;
            font-weight: 500;
            font-size: 1.16em;
            line-height: 0.96;
            letter-spacing: 0;
            color: #0a8fd1;
            background: linear-gradient(180deg, #0a8fd1 0%, #075286 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-description {
            width: min(847px, 100%);
            margin: 0;
            font-size: clamp(1rem, 1.6vw, 1.25rem);
            line-height: 1.2;
            font-weight: 500;
            color: #000;
        }

        /* Frame 30 — "View upcoming Events" pill */
        .hero-events {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 35px;
            margin-top: 4px;
            padding: 8px 24px;
            border-radius: 100px;
            font-size: 15px;
            font-weight: 500;
            line-height: 1;
            color: #fff;
            white-space: nowrap;
            cursor: pointer;
            background: linear-gradient(180deg, rgba(0, 135, 205, 0.85) 0%, rgba(0, 43, 65, 0.85) 100%);
            box-shadow: inset 0 1.5px 3px rgba(255, 255, 255, 0.5);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .hero-events:hover {
            transform: translateY(-1px);
            box-shadow: inset 0 1.5px 3px rgba(255, 255, 255, 0.6), 0 8px 20px rgba(0, 135, 205, 0.28);
        }

        /* ─── dashboard preview ─── */
        .dashboard-wrap {
            position: relative;
            z-index: 3;
            flex: 0 0 auto;
            width: min(1207px, 84vw);
            margin: auto auto 0;
        }

        /* Ellipse 10 — bright yellow flare hugging the dashboard's top edge */
        .dashboard-flare {
            position: absolute;
            top: -16px;
            left: 50%;
            transform: translateX(-50%);
            width: 96%;
            height: 130px;
            border-radius: 50%;
            background: radial-gradient(ellipse at center, rgba(255, 245, 0, 1) 0%, rgba(255, 245, 0, 0.66) 45%, transparent 74%);
            filter: blur(45px);
            pointer-events: none;
            z-index: 0;
        }

        .dashboard-frame {
            position: relative;
            z-index: 1;
            width: 100%;
            aspect-ratio: 1207 / 444;
            padding: 10px 10px 0;
            border-radius: 20px 20px 0 0;
            background: rgba(0, 135, 205, 0.4);
            overflow: hidden;
            box-shadow: 0 -1px 60px rgba(0, 0, 0, 0.1);
        }

        .dashboard-frame img {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 12px 12px 0 0;
        }

        /* Ellipse 15 — soft white glow easing the dashboard into the footer */
        .hero-into-next {
            position: absolute;
            left: 50%;
            bottom: -90px;
            transform: translateX(-50%);
            width: 124%;
            height: 250px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            filter: blur(55px);
            z-index: 5;
            pointer-events: none;
        }


        /* ─── GSAP reveal ─── */
        .hero-title .word {
            display: inline-block;
            padding-bottom: 0.12em;
            background: linear-gradient(180deg, #0e5278 0%, #063248 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            will-change: transform, opacity;
        }

        .hero-description .word {
            display: inline-block;
            will-change: transform, opacity;
        }

        /* pre-hide animated blocks (only while the animation is armed) to avoid a flash */
        .gsap-anim .home-nav,
        .gsap-anim .hero-badge,
        .gsap-anim .hero-title,
        .gsap-anim .hero-description,
        .gsap-anim .hero-events,
        .gsap-anim .top-glow,
        .gsap-anim .dashboard-wrap,
        .gsap-anim .hero-into-next {
            opacity: 0;
        }

        /* ─── tablet (641–1024px) ─── */
        @media (max-width: 1024px) {
            .home-content {
                width: min(100% - 2.5rem, 920px);
            }

            .hero {
                margin-top: 72px;
            }

            .hero-title {
                font-size: clamp(3rem, 8vw, 4.5rem);
            }

            .hero-events {
                margin-top: -2px;
                padding: 8px 22px;
                font-size: 14px;
            }

            .dashboard-wrap {
                width: 88vw;
            }
        }

        /* ─── mobile (≤640px) ─── */
        @media (max-width: 640px) {
            .portal-home {
                height: 100svh;
                min-height: 560px;
            }

            .glass-strips {
                gap: 0;
            }

            .glass-strip {
                width: 2.5rem;
            }

            .top-glow {
                filter: blur(80px);
            }

            .home-content {
                width: min(100% - 1.5rem, 560px);
                padding-top: 14px;
            }

            .hero {
                margin-top: 52px;
                gap: 14px;
            }

            .hero-badge {
                font-size: 12.5px;
                padding: 9px 14px;
            }

            .hero-title {
                font-size: clamp(2.25rem, 12vw, 3.1rem);
            }

            .hero-description {
                font-size: 1rem;
                line-height: 1.4;
            }

            .hero-events {
                margin-top: -2px;
                min-height: 32px;
                padding: 7px 20px;
                font-size: 13.5px;
            }

            .dashboard-wrap {
                width: 92vw;
            }

            .hero-into-next {
                height: 110px;
                bottom: -52px;
                filter: blur(34px);
            }
        }

        /* short / zoomed-in / scaled viewports — keep the hero compact so it never crowds the dashboard */
        @media (max-height: 640px) {
            .hero {
                margin-top: 28px;
                gap: 10px;
            }

            .hero-title {
                font-size: clamp(2.1rem, 6.4vh, 4rem);
                line-height: 1.05;
            }

            .hero-badge {
                font-size: 12.5px;
                padding: 8px 14px;
            }

            .hero-description {
                font-size: clamp(0.9rem, 2.4vh, 1.1rem);
                line-height: 1.35;
            }

            .hero-events {
                margin-top: 0;
                min-height: 30px;
                padding: 6px 18px;
                font-size: 13px;
            }
        }
    </style>
    <script>
        (function () {
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!reduce) document.documentElement.classList.add('gsap-anim');
        })();
    </script>
</head>
<body>
    <main class="portal-home">
        <div class="glass-strips" aria-hidden="true">
            <?php for ($i = 0; $i < 48; $i++): ?>
                <span class="glass-strip"></span>
            <?php endfor; ?>
        </div>

        <div class="top-glow" aria-hidden="true"></div>

        <div class="home-content">
            <?php include __DIR__ . '/layouts/landing-header.php'; ?>

            <section class="hero" aria-labelledby="home-title">
                <div class="hero-badge">Reporting made easy, capture all your zonal progress!</div>
                <h1 class="hero-title" id="home-title">Manage Reports with <span class="hero-title-accent">Confidence</span></h1>
                <p class="hero-description">Access reports, track submissions, and manage reporting activities through a secure and centralized platform.</p>
                <a class="hero-events" href="<?php echo $baseUrl; ?>/login.php">Get started now</a>
            </section>
        </div>

        <div class="dashboard-wrap">
            <div class="dashboard-flare" aria-hidden="true"></div>
            <section class="dashboard-frame" aria-label="Dashboard preview">
                <img src="<?php echo $baseUrl; ?>/assets/images/home-dashboard.png" alt="GPD Reporting Portal dashboard preview">
            </section>
        </div>

        <div class="hero-into-next" aria-hidden="true"></div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
        (function () {
            var root = document.documentElement;
            var armed = root.classList.contains('gsap-anim');

            // No GSAP (CDN blocked) or reduced motion → show everything, bail.
            if (!window.gsap || !armed) {
                root.classList.remove('gsap-anim');
                return;
            }

            // Split an element's text into <span class="word"> units, preserving
            // spaces and leaving any child elements (e.g. the serif accent) intact.
            function splitWords(selector) {
                var el = document.querySelector(selector);
                if (!el) return;
                var frag = document.createDocumentFragment();
                Array.prototype.forEach.call(el.childNodes, function (node) {
                    if (node.nodeType === 3) {
                        node.textContent.split(/(\s+)/).forEach(function (part) {
                            if (part === '') return;
                            if (/^\s+$/.test(part)) {
                                frag.appendChild(document.createTextNode(part));
                            } else {
                                var w = document.createElement('span');
                                w.className = 'word';
                                w.textContent = part;
                                frag.appendChild(w);
                            }
                        });
                    } else {
                        frag.appendChild(node.cloneNode(true));
                    }
                });
                el.innerHTML = '';
                el.appendChild(frag);
            }

            splitWords('.hero-title');
            splitWords('.hero-description');

            // reveal the containers that were pre-hidden (words start hidden instead)
            gsap.set(['.hero-title', '.hero-description'], { opacity: 1 });

            var finePointer = window.matchMedia && window.matchMedia('(pointer: fine)').matches;

            var tl = gsap.timeline({ defaults: { ease: 'power3.out' } });

            // everything enters at the same time (position 0), each keeping its own motion
            tl.fromTo('.home-nav', { y: -18, opacity: 0 }, { y: 0, opacity: 1, duration: 0.8 }, 0)
              .fromTo('.hero-badge', { y: -18, opacity: 0 }, { y: 0, opacity: 1, duration: 0.8 }, 0)
              .fromTo('.hero-title .word, .hero-title-accent',
                      { y: -40, opacity: 0 },
                      { y: 0, opacity: 1, duration: 0.85, stagger: 0.06 }, 0)
              .fromTo('.hero-description .word',
                      { y: -14, opacity: 0 },
                      { y: 0, opacity: 1, duration: 0.7, stagger: 0.015 }, 0)
              .fromTo('.hero-events',
                      { y: -14, opacity: 0 },
                      { y: 0, opacity: 1, duration: 0.85 }, 0)
              .fromTo('.top-glow',
                      { opacity: 0 }, { opacity: 1, duration: 1.1 }, 0)
              .fromTo('.dashboard-wrap',
                      { y: 80, opacity: 0, scale: 0.97 },
                      { y: 0, opacity: 1, scale: 1, duration: 1.2, ease: 'expo.out', transformOrigin: '50% 0%' }, 0)
              .fromTo('.hero-into-next',
                      { opacity: 0 }, { opacity: 1, duration: 1.2 }, 0);

            // ─── premium interactions ───
            if (finePointer) {
                // gentle parallax on the big soft glow as the cursor moves
                var glow = document.querySelector('.top-glow');
                if (glow) {
                    // animate x/y in px so the translate(-50%, -50%) centering is preserved
                    var gx = gsap.quickTo(glow, 'x', { duration: 0.9, ease: 'power3' });
                    var gy = gsap.quickTo(glow, 'y', { duration: 0.9, ease: 'power3' });
                    window.addEventListener('mousemove', function (e) {
                        var nx = (e.clientX / window.innerWidth) - 0.5;
                        var ny = (e.clientY / window.innerHeight) - 0.5;
                        gx(nx * 26);
                        gy(ny * 20);
                    });
                }
            }
        })();
    </script>
</body>
</html>
