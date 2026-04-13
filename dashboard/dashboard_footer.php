<?php
// dashboard_footer.php - Updated with active account check

// PHP logic to handle "Active" state based on the current file
$current_page = basename($_SERVER['PHP_SELF']);

// Check if account is active - if not, hide navigation completely
$account_status = $_SESSION['account_status'] ?? 'active';

// Only show navigation for active accounts
if ($account_status !== 'active') {
    // Don't show navigation for suspended/under review/locked accounts
    return;
}
?>

<style>
/* --- BOTTOM NAV VARIABLES --- */
:root {
  --bg-deep: #050507;
  --bg-card: #0a0a0c;
  --text-main: #ffffff;
  --text-muted: #94a3b8;
  --accent-purple: #9d50ff;
  --glass-border: rgba(157, 80, 255, 0.3);
  --success: #10b981;
  --warning: #f59e0b;
}

/* --- DOCK CONTAINER (STOPS THE ZOOM) --- */
.nav-container {
    position: fixed;
    bottom: 20px;
    left: 0;
    right: 0;
    display: flex;
    justify-content: center;
    padding: 0 1.5rem;
    z-index: 1000;
    width: 100%;
    pointer-events: none; 
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from { transform: translateY(100px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.bottom-nav {
    pointer-events: auto;
    width: 100%;
    max-width: 450px;
    height: 75px;
    background: rgba(10, 10, 12, 0.9);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border-radius: 24px;
    border: 1px solid var(--glass-border);
    display: flex;
    justify-content: space-around;
    align-items: center;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
    padding: 0 10px;
}

.nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-decoration: none;
    color: var(--text-muted);
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
    -webkit-tap-highlight-color: transparent;
}

/* Active State Logic */
.nav-item.active {
    color: var(--accent-purple);
    transform: translateY(-5px);
}

.nav-item.active svg {
    filter: drop-shadow(0 0 8px var(--accent-purple));
}

.nav-item.active::after {
    content: '';
    position: absolute;
    bottom: -8px;
    width: 4px;
    height: 4px;
    background: var(--accent-purple);
    border-radius: 50%;
    box-shadow: 0 0 10px var(--accent-purple);
}

/* Badge for notifications */
.nav-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: var(--success);
    color: white;
    font-size: 0.6rem;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
    border: 2px solid var(--bg-card);
}

/* --- CENTER FLOATING ACTION BUTTON --- */
.center-wrapper {
    position: relative;
    transform: translateY(-30px);
}

.center-btn {
    width: 62px;
    height: 62px;
    background: linear-gradient(135deg, var(--accent-purple), #c084fc);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 4px solid var(--bg-deep);
    box-shadow: 0 10px 25px rgba(157, 80, 255, 0.4);
    color: white;
    transition: all 0.2s ease;
    cursor: pointer;
    text-decoration: none;
}

.center-btn:hover {
    transform: scale(1.1);
}

.center-btn:active {
    transform: scale(0.9);
}

/* Touch-Feedback for Mobile */
.nav-item:active {
    opacity: 0.7;
    transform: scale(0.95);
}

/* Tooltip on hover (desktop) */
.nav-item[data-tooltip]:hover::before {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 80px;
    background: var(--bg-card);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
    border: 1px solid var(--glass-border);
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    z-index: 1001;
}

/* Loading state */
.nav-container.loading {
    opacity: 0.5;
    pointer-events: none;
}

/* Responsive adjustments */
@media (max-width: 380px) {
    .bottom-nav {
        height: 70px;
        padding: 0 5px;
    }
    
    .center-btn {
        width: 58px;
        height: 58px;
    }
    
    .nav-item svg {
        width: 22px;
        height: 22px;
    }
}

@media (min-width: 768px) {
    .bottom-nav {
        max-width: 500px;
        height: 80px;
    }
    
    .center-btn {
        width: 68px;
        height: 68px;
    }
}

/* Dark mode optimization */
@media (prefers-color-scheme: dark) {
    .bottom-nav {
        background: rgba(10, 10, 12, 0.95);
    }
}

/* Prevent navigation overlap on short screens */
@media (max-height: 600px) {
    .nav-container {
        bottom: 10px;
    }
    
    .center-wrapper {
        transform: translateY(-20px);
    }
    
    .center-btn {
        width: 55px;
        height: 55px;
    }
}

/* Animation for badge pulse */
@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}

.nav-badge.new {
    animation: pulse 2s infinite;
}
</style>

<?php
// Fetch unread notification count for badge
$notification_count = 0;
if (isset($pdo) && isset($user_id)) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $notification_count = $stmt->fetchColumn();
    } catch (Exception $e) {
        // Silently fail - badge won't show
        error_log("Failed to fetch notification count for nav: " . $e->getMessage());
    }
}
?>

<div class="nav-container">
    <nav class="bottom-nav">
        <!-- Home -->
        <a href="./index.php" 
           class="nav-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>" 
           data-tooltip="Dashboard">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
        </a>

        <!-- Stats / Analytics -->
        <a href="./stats.php" 
           class="nav-item <?php echo ($current_page == 'stats.php' || $current_page == 'analytics.php') ? 'active' : ''; ?>" 
           data-tooltip="Analytics">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
        </a>

        <!-- Center "Menu" Action - Quick Actions -->
        <div class="center-wrapper">
            <a href="./menu.php" class="center-btn" data-tooltip="Quick Actions">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7"></rect>
                    <rect x="14" y="3" width="7" height="7"></rect>
                    <rect x="14" y="14" width="7" height="7"></rect>
                    <rect x="3" y="14" width="7" height="7"></rect>
                </svg>
            </a>
        </div>

        <!-- Cards / Payment Methods -->
        <a href="./cards.php" 
           class="nav-item <?php echo ($current_page == 'cards.php' || $current_page == 'payment-methods.php') ? 'active' : ''; ?>" 
           data-tooltip="Cards & Payments">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                <line x1="1" y1="10" x2="23" y2="10"></line>
            </svg>
            <?php if ($current_page == 'cards.php'): ?>
                <span class="nav-badge new" style="background: var(--accent-purple);">!</span>
            <?php endif; ?>
        </a>

        <!-- Profile / Settings -->
        <a href="./profile.php" 
           class="nav-item <?php echo ($current_page == 'profile.php' || $current_page == 'settings.php') ? 'active' : ''; ?>" 
           data-tooltip="Profile">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
            <?php if ($notification_count > 0): ?>
                <span class="nav-badge"><?php echo min($notification_count, 9); ?>+</span>
            <?php endif; ?>
        </a>
    </nav>
</div>

<!-- Extra space at the bottom of the page content so the dock doesn't cover anything -->
<div style="height: 100px;"></div>

<script>
// Bottom navigation interactive enhancements
document.addEventListener('DOMContentLoaded', function() {
    // Add haptic feedback simulation for mobile
    const navItems = document.querySelectorAll('.nav-item, .center-btn');
    
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            // Simulate haptic feedback (vibration) on supported devices
            if (window.navigator && window.navigator.vibrate) {
                window.navigator.vibrate(20);
            }
            
            // Add ripple effect
            const ripple = document.createElement('span');
            ripple.className = 'ripple';
            this.appendChild(ripple);
            
            const x = e.clientX - this.offsetLeft;
            const y = e.clientY - this.offsetTop;
            
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            
            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });
    
    // Add active state class based on scroll position (optional)
    function updateActiveNavOnScroll() {
        const sections = {
            'index.php': document.getElementById('dashboard-section'),
            'stats.php': document.getElementById('stats-section'),
            'cards.php': document.getElementById('cards-section'),
            'profile.php': document.getElementById('profile-section')
        };
        
        // Implementation depends on your page structure
    }
    
    // Prevent touch zoom on double tap navigation
    let lastTouchEnd = 0;
    document.querySelector('.bottom-nav').addEventListener('touchend', function(e) {
        const now = Date.now();
        if (now - lastTouchEnd <= 300) {
            e.preventDefault();
        }
        lastTouchEnd = now;
    });
    
    // Log navigation events (for analytics)
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            const page = this.getAttribute('href');
            console.log(`Navigation: ${page}`);
            
            // You could send this to your analytics endpoint
            if (typeof gtag !== 'undefined') {
                gtag('event', 'navigation', {
                    'event_category': 'bottom_nav',
                    'event_label': page
                });
            }
        });
    });
});

// Progressive Web App - hide navigation in standalone mode
if (window.matchMedia('(display-mode: standalone)').matches) {
    // App is running as PWA, adjust navigation if needed
    document.documentElement.style.setProperty('--bottom-nav-bottom', '10px');
}

// Function to dynamically update notification badge
function updateNavNotificationBadge(count) {
    const profileNav = document.querySelector('a[href="./profile.php"]');
    if (!profileNav) return;
    
    let badge = profileNav.querySelector('.nav-badge');
    
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'nav-badge';
            profileNav.appendChild(badge);
        }
        badge.textContent = count > 9 ? '9+' : count;
    } else {
        if (badge) {
            badge.remove();
        }
    }
}

// Listen for notification updates
window.addEventListener('notificationUpdate', function(e) {
    updateNavNotificationBadge(e.detail.count);
});

// Prevent navigation container from interfering with clicks on small screens
document.querySelector('.nav-container').addEventListener('touchstart', function(e) {
    // Allow click-through to navigation items only
    if (!e.target.closest('.bottom-nav')) {
        e.preventDefault();
    }
});
</script>

<!-- Add ripple effect styles -->
<style>
.ripple {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    transform: scale(0);
    animation: ripple 0.6s linear;
    pointer-events: none;
    width: 100px;
    height: 100px;
    margin-left: -50px;
    margin-top: -50px;
}

@keyframes ripple {
    to {
        transform: scale(4);
        opacity: 0;
    }
}

.nav-item, .center-btn {
    position: relative;
    overflow: hidden;
}
</style>

<?php
// Optional: Add menu.php redirect if it doesn't exist
if (!file_exists('./menu.php')) {
    // Create a simple menu.php redirect to dashboard if not exists
    $menu_content = '<?php header("Location: ./index.php"); exit(); ?>';
    file_put_contents('./menu.php', $menu_content);
}
?>