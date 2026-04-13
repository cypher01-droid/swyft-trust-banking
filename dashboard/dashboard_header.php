<?php
// dashboard_header.php - Updated with active account check

// PHP logic for dynamic user data
$fullName = $_SESSION['full_name'] ?? "User Account";
$userId = $_SESSION['user_id'] ?? "000";
$account_status = $_SESSION['account_status'] ?? 'active';

// Check if account is active - if not, redirect to appropriate page
if ($account_status !== 'active') {
    // For suspended/under review/locked accounts, redirect to dashboard which handles status
    header("Location: index.php");
    exit();
}
?>

<style>
/* Reset for clean mobile fit */
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; margin: 0; padding: 0; }

:root {
  --bg-card: #0a0a0c;
  --text-main: #ffffff;
  --text-muted: #94a3b8;
  --accent-purple: #9d50ff;
  --glass-border: rgba(157, 80, 255, 0.3);
  --danger: #ff4d4d;
  --success: #10b981;
  --warning: #f59e0b;
}

.beast-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0 1.5rem; height: 70px; width: 100%;
  background: rgba(10, 10, 12, 0.85); backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--glass-border);
  position: fixed; top: 0; z-index: 9999;
}

.header-brand { 
  display: flex; align-items: center; gap: 10px; 
  font-weight: 800; font-size: 1.1rem; color: #fff; 
}
.brand-dot { 
  width: 10px; height: 10px; background: var(--accent-purple); 
  border-radius: 50%; box-shadow: 0 0 15px var(--accent-purple); 
}

.header-icon-btn {
  background: var(--bg-card); border: 1px solid var(--glass-border);
  color: var(--text-main); width: 44px; height: 44px; border-radius: 14px;
  display: grid; place-items: center; cursor: pointer; position: relative;
  transition: all 0.2s ease;
}

.header-icon-btn:hover {
  background: rgba(157, 80, 255, 0.1);
  border-color: var(--accent-purple);
}

.header-icon-btn:active {
  transform: scale(0.95);
}

.active-badge { 
  position: absolute; top: 8px; right: 8px; width: 8px; height: 8px; 
  background: var(--success); border-radius: 50%; border: 2px solid #000; 
}

/* God Level Overlay */
.global-overlay {
  position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85);
  backdrop-filter: blur(10px); z-index: 10000;
  display: none; justify-content: center; align-items: center; padding: 20px;
}

.modern-modal {
  background: var(--bg-card); width: 100%; max-width: 350px;
  border-radius: 30px; padding: 25px; border: 1px solid var(--glass-border);
  box-shadow: 0 30px 60px rgba(0,0,0,0.8);
  animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

.hidden { display: none !important; }

/* Profile Links Styling */
.modal-links {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.modal-links button {
  display: flex; align-items: center; gap: 12px; padding: 15px; width: 100%;
  background: rgba(255,255,255,0.03); border: none; border-radius: 15px;
  color: white; font-weight: 600; cursor: pointer;
  transition: all 0.2s ease;
  text-align: left;
}

.modal-links button:hover {
  background: rgba(157, 80, 255, 0.1);
  transform: translateX(5px);
}

.modal-links button i {
  width: 20px;
  color: var(--accent-purple);
}

.danger-link { 
  color: var(--danger) !important; 
  margin-top: 10px; 
  border: 1px solid rgba(255, 77, 77, 0.2) !important; 
}

.danger-link:hover {
  background: rgba(255, 77, 77, 0.1) !important;
}

.danger-link i {
  color: var(--danger) !important;
}

.user-info {
  margin-bottom: 25px;
  padding-bottom: 20px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}

.user-info h3 {
  font-size: 1.2rem; 
  color: white;
  margin-bottom: 5px;
}

.user-info p {
  color: var(--text-muted); 
  font-size: 0.8rem;
  margin-bottom: 5px;
}

.status-badge {
  display: inline-block;
  padding: 4px 12px;
  background: rgba(16, 185, 129, 0.1);
  color: var(--success);
  border-radius: 20px;
  font-size: 0.7rem;
  font-weight: 700;
  margin-top: 8px;
}

/* Notification styles */
.notification-item {
  display: flex;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid rgba(255,255,255,0.05);
}

.notification-item:last-child {
  border-bottom: none;
}

.notification-icon {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(157, 80, 255, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--accent-purple);
}

.notification-content {
  flex: 1;
}

.notification-title {
  font-weight: 700;
  color: white;
  font-size: 0.9rem;
  margin-bottom: 4px;
}

.notification-time {
  font-size: 0.7rem;
  color: var(--text-muted);
}

.empty-state {
  text-align: center;
  padding: 30px 20px;
  color: var(--text-muted);
}

.empty-state i {
  font-size: 2.5rem;
  margin-bottom: 15px;
  opacity: 0.5;
}

/* Responsive adjustments */
@media (max-width: 480px) {
  .beast-header {
    padding: 0 1rem;
  }
  
  .modern-modal {
    max-width: 90%;
  }
}

/* Loading state */
.loading {
  opacity: 0.7;
  pointer-events: none;
}
</style>

<header class="beast-header">
    <button class="header-icon-btn" onclick="openMenu('userModal')">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
        </svg>
    </button>
    
    <div class="header-brand">
        <span class="brand-dot"></span>
        <span>SWYFT TRUST</span>
    </div>
    
    <button class="header-icon-btn" onclick="openMenu('notifModal')">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span class="active-badge"></span>
    </button>
</header>

<div id="godOverlay" class="global-overlay" onclick="closeMenus()">
    <!-- User Profile Modal -->
    <div id="userModal" class="modern-modal hidden" onclick="event.stopPropagation()">
        <div class="user-info">
            <h3><?php echo htmlspecialchars($fullName); ?></h3>
            <p>Secure Vault ID: #<?php echo $userId; ?></p>
            <span class="status-badge">
                <i class="fas fa-check-circle"></i> Active Account
            </span>
        </div>
        
        <div class="modal-links">
            <button onclick="location.href='profile.php'">
                <i class="fas fa-user-circle"></i>
                Profile Settings
            </button>
            <button onclick="location.href='security.php'">
                <i class="fas fa-shield-alt"></i>
                Security Hub
            </button>
            <button onclick="location.href='kyc.php'">
                <i class="fas fa-id-card"></i>
                KYC Verification
            </button>
            <button onclick="location.href='settings.php'">
                <i class="fas fa-cog"></i>
                Account Settings
            </button>
            <button class="danger-link" onclick="location.href='../logout.php'">
                <i class="fas fa-sign-out-alt"></i>
                Secure Sign Out
            </button>
        </div>
    </div>
    
    <!-- Notifications Modal -->
    <div id="notifModal" class="modern-modal hidden" onclick="event.stopPropagation()">
        <h3 style="margin-bottom:20px; color:white; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-bell" style="color: var(--accent-purple);"></i>
            Notifications
        </h3>
        
        <?php
        // Fetch user notifications from database
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($notifications) > 0) {
                foreach ($notifications as $notif) {
                    $time = strtotime($notif['created_at']);
                    $time_diff = time() - $time;
                    
                    if ($time_diff < 60) {
                        $time_text = 'Just now';
                    } elseif ($time_diff < 3600) {
                        $mins = floor($time_diff / 60);
                        $time_text = $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
                    } elseif ($time_diff < 86400) {
                        $hours = floor($time_diff / 3600);
                        $time_text = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
                    } else {
                        $time_text = date('M d', $time);
                    }
                    ?>
                    <div class="notification-item">
                        <div class="notification-icon">
                            <i class="fas fa-<?php echo $notif['icon'] ?? 'bell'; ?>"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                            <div style="color: var(--text-muted); font-size: 0.8rem; margin-bottom: 4px;">
                                <?php echo htmlspecialchars($notif['message']); ?>
                            </div>
                            <div class="notification-time"><?php echo $time_text; ?></div>
                        </div>
                    </div>
                    <?php
                }
            } else {
                ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <p>No new notifications</p>
                    <small style="color: var(--text-muted);">We'll notify you when something arrives</small>
                </div>
                <?php
            }
        } catch (Exception $e) {
            ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <p>No new notifications</p>
            </div>
            <?php
        }
        ?>
        
        <?php if (count($notifications) > 5): ?>
        <button onclick="location.href='notifications.php'" style="width:100%; margin-top:20px; padding:12px; background:rgba(157,80,255,0.1); border:none; border-radius:12px; color:var(--accent-purple); font-weight:600; cursor:pointer;">
            View All Notifications
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- GTranslate Widget -->
<div class="gtranslate_wrapper"></div>

<script>
window.gtranslateSettings = {
    "default_language": "en",
    "native_language_names": true,
    "detect_browser_language": true,
    "languages": ["en", "fr", "it", "es", "de"],
    "wrapper_selector": ".gtranslate_wrapper",
    "flag_size": 24,
    "flag_style": "3d",
    "alt_flags": {
        "en": "usa",
        "es": "mexico"
    }
};
</script>
<script src="https://cdn.gtranslate.net/widgets/latest/dwf.js" defer></script>

<script>
// Menu functions
function openMenu(id) {
    const overlay = document.getElementById('godOverlay');
    overlay.style.display = 'flex';
    
    // Hide all modals first
    document.querySelectorAll('.modern-modal').forEach(m => {
        m.classList.add('hidden');
    });
    
    // Show selected modal
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('hidden');
    }
}

function closeMenus() {
    const overlay = document.getElementById('godOverlay');
    overlay.style.display = 'none';
    
    // Hide all modals
    document.querySelectorAll('.modern-modal').forEach(m => {
        m.classList.add('hidden');
    });
}

// Close menus with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMenus();
    }
});

// Prevent body scroll when menus are open
function toggleBodyScroll(disable) {
    if (disable) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
}

// Enhanced menu open
function openMenu(id) {
    const overlay = document.getElementById('godOverlay');
    overlay.style.display = 'flex';
    toggleBodyScroll(true);
    
    document.querySelectorAll('.modern-modal').forEach(m => {
        m.classList.add('hidden');
    });
    
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('hidden');
    }
}

// Enhanced close menus
function closeMenus() {
    const overlay = document.getElementById('godOverlay');
    overlay.style.display = 'none';
    toggleBodyScroll(false);
    
    document.querySelectorAll('.modern-modal').forEach(m => {
        m.classList.add('hidden');
    });
}

// Fetch unread notification count
function fetchNotificationCount() {
    fetch('ajax/get_notification_count.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('.active-badge');
            if (badge) {
                if (data.count > 0) {
                    badge.style.display = 'block';
                    badge.textContent = data.count > 9 ? '9+' : data.count;
                } else {
                    badge.style.display = 'none';
                }
            }
        })
        .catch(error => console.error('Error fetching notification count:', error));
}

// Mark notifications as read when modal opens
function openNotificationModal() {
    openMenu('notifModal');
    
    // Mark as read after 2 seconds
    setTimeout(() => {
        fetch('ajax/mark_notifications_read.php', { method: 'POST' })
            .then(() => {
                const badge = document.querySelector('.active-badge');
                if (badge) badge.style.display = 'none';
            })
            .catch(error => console.error('Error marking notifications:', error));
    }, 2000);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Fetch notification count if endpoint exists
    // fetchNotificationCount();
    
    // Add Font Awesome if not already loaded
    if (!document.querySelector('link[href*="font-awesome"]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
        document.head.appendChild(link);
    }
});
</script>

<!-- Chatway Widget -->
<script id="chatway" async="true" src="https://cdn.chatway.app/widget.js?id=veJnUJPNcIOX"></script>