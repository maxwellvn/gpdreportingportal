/**
 * Modern Theme JavaScript
 * 
 * This file contains JavaScript functions for the modern theme
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize modern preloader
    initPreloader();
    
    // Initialize dropdown menus
    initDropdowns();
    
    // Initialize mobile menu
    initMobileMenu();
    
    // Add active class to current page in navigation
    highlightCurrentPage();
});

/**
 * Initialize the preloader
 */
function initPreloader() {
    const preloader = document.querySelector('.modern-preloader');
    if (preloader) {
        // Hide preloader after a short delay
        setTimeout(() => {
            preloader.classList.add('fade-out');
            setTimeout(() => {
                preloader.style.display = 'none';
            }, 300);
        }, 500);
    }
}

/**
 * Initialize dropdown menus
 */
function initDropdowns() {
    // Profile dropdown
    const profileButton = document.querySelector('.modern-user-button');
    const profileDropdown = document.getElementById('profileDropdown');
    
    if (profileButton && profileDropdown) {
        // Toggle dropdown on click
        profileButton.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
            
            // Close dropdown when clicking outside
            if (!profileDropdown.classList.contains('hidden')) {
                document.addEventListener('click', function closeDropdown(e) {
                    if (!profileButton.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.add('hidden');
                        document.removeEventListener('click', closeDropdown);
                    }
                });
            }
        });
    }
}

/**
 * Initialize mobile menu
 */
function initMobileMenu() {
    const menuButton = document.querySelector('.modern-mobile-menu-button');
    const mobileMenu = document.getElementById('mobileMenu');
    
    if (menuButton && mobileMenu) {
        // Toggle mobile menu on click
        menuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            mobileMenu.classList.toggle('active');
            
            // Close menu when clicking outside
            if (mobileMenu.classList.contains('active')) {
                document.addEventListener('click', function closeMobileMenu(e) {
                    if (!menuButton.contains(e.target) && !mobileMenu.contains(e.target)) {
                        mobileMenu.classList.remove('active');
                        document.removeEventListener('click', closeMobileMenu);
                    }
                });
            }
        });
    }
}

/**
 * Highlight the current page in navigation
 */
function highlightCurrentPage() {
    const currentPath = window.location.pathname;
    const navItems = document.querySelectorAll('.modern-header-nav-item, .modern-mobile-menu-item');
    
    navItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href && currentPath.includes(href)) {
            item.classList.add('active');
        }
    });
}