/**
 * Preloader script to ensure the preloader is properly hidden
 */
(function() {
    // Hide preloader immediately when script loads
    function hidePreloader() {
        const preloader = document.getElementById('preloader');
        if (preloader) {
            preloader.classList.add('fade-out');
            setTimeout(() => {
                preloader.style.display = 'none';
            }, 500);
        }
    }

    // Try to hide preloader as soon as possible
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        // Document already loaded
        hidePreloader();
    } else {
        // Wait for document to load
        document.addEventListener('DOMContentLoaded', hidePreloader);
    }

    // Fallback - hide preloader after 3 seconds no matter what
    setTimeout(hidePreloader, 3000);
})();