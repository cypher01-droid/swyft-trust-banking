<?php
// admin/includes/sidebar.php

// Get dashboard stats for badges
$stats = [
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn(),
    'pending_deposits' => $pdo->query("SELECT COUNT(*) FROM deposits WHERE status = 'pending'")->fetchColumn(),
    'pending_withdrawals' => $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn(),
    'pending_loans' => $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'pending'")->fetchColumn(),
    'pending_refunds' => $pdo->query("SELECT COUNT(*) FROM refunds WHERE status = 'pending'")->fetchColumn(),
    'pending_kyc' => $pdo->query("SELECT COUNT(*) FROM users WHERE kyc_status = 'pending'")->fetchColumn()
];
?>

<aside class="admin-sidebar">
    <div class="sidebar-header">
        <a href="?tab=dashboard" class="brand">
            <i class="fas fa-crown brand-icon"></i>
            <span class="nav-text">SWYFT ADMIN</span>
        </a>
    </div>
    
    <div class="admin-info">
        <div class="admin-name"><?php echo htmlspecialchars($admin_name); ?></div>
        <span class="admin-role">Administrator</span>
    </div>
    
    <nav class="nav-menu">
        <div class="nav-item">
            <a href="?tab=dashboard" class="nav-link <?php echo $tab === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=users" class="nav-link <?php echo $tab === 'users' ? 'active' : ''; ?>">
                <i class="fas fa-users nav-icon"></i>
                <span class="nav-text">Users</span>
                <span class="badge"><?php echo $stats['total_users']; ?></span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=deposits" class="nav-link <?php echo $tab === 'deposits' ? 'active' : ''; ?>">
                <i class="fas fa-money-check nav-icon"></i>
                <span class="nav-text">Deposits</span>
                <span class="badge"><?php echo $stats['pending_deposits']; ?></span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=withdrawals" class="nav-link <?php echo $tab === 'withdrawals' ? 'active' : ''; ?>">
                <i class="fas fa-wallet nav-icon"></i>
                <span class="nav-text">Withdrawals</span>
                <span class="badge"><?php echo $stats['pending_withdrawals']; ?></span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=loans" class="nav-link <?php echo $tab === 'loans' ? 'active' : ''; ?>">
                <i class="fas fa-hand-holding-usd nav-icon"></i>
                <span class="nav-text">Loans</span>
                <span class="badge"><?php echo $stats['pending_loans']; ?></span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=refunds" class="nav-link <?php echo $tab === 'refunds' ? 'active' : ''; ?>">
                <i class="fas fa-undo nav-icon"></i>
                <span class="nav-text">Refunds</span>
                <span class="badge"><?php echo $stats['pending_refunds']; ?></span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=transactions" class="nav-link <?php echo $tab === 'transactions' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt nav-icon"></i>
                <span class="nav-text">Transactions</span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=kyc" class="nav-link <?php echo $tab === 'kyc' ? 'active' : ''; ?>">
                <i class="fas fa-id-card nav-icon"></i>
                <span class="nav-text">KYC Verification</span>
                <span class="badge"><?php echo $stats['pending_kyc']; ?></span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=reports" class="nav-link <?php echo $tab === 'reports' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar nav-icon"></i>
                <span class="nav-text">Reports</span>
            </a>
        </div>
        
        <div class="nav-item">
            <a href="?tab=settings" class="nav-link <?php echo $tab === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog nav-icon"></i>
                <span class="nav-text">Settings</span>
            </a>
        </div>
    </nav>
</aside>