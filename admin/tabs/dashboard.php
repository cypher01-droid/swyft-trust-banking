<?php
// admin/tabs/dashboard.php

// Get dashboard stats
$stats = [
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn(),
    'pending_deposits' => $pdo->query("SELECT COUNT(*) FROM deposits WHERE status = 'pending'")->fetchColumn(),
    'pending_withdrawals' => $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn(),
    'pending_loans' => $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'pending'")->fetchColumn(),
    'pending_refunds' => $pdo->query("SELECT COUNT(*) FROM refunds WHERE status = 'pending'")->fetchColumn(),
    'pending_kyc' => $pdo->query("SELECT COUNT(*) FROM users WHERE kyc_status = 'pending'")->fetchColumn()
];

// Get recent transactions
$recent_transactions = $pdo->query("
    SELECT t.*, u.full_name 
    FROM transactions t 
    LEFT JOIN users u ON t.user_id = u.id 
    ORDER BY t.created_at DESC 
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Get recent logins
$recent_logins = $pdo->query("
    SELECT * FROM user_sessions 
    ORDER BY created_at DESC 
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="tab-content active" id="tab-dashboard">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon users">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon deposits">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_deposits']); ?></div>
            <div class="stat-label">Pending Deposits</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon withdrawals">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_withdrawals']); ?></div>
            <div class="stat-label">Pending Withdrawals</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon loans">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_loans']); ?></div>
            <div class="stat-label">Pending Loans</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon refunds">
                <i class="fas fa-undo"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_refunds']); ?></div>
            <div class="stat-label">Pending Refunds</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-id-card"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_kyc']); ?></div>
            <div class="stat-label">Pending KYC</div>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 30px;">
        <!-- Recent Transactions -->
        <div class="chart-container">
            <h3 class="chart-title">Recent Transactions</h3>
            <?php if (!empty($recent_transactions)): ?>
            <div style="max-height: 300px; overflow-y: auto;">
                <?php foreach ($recent_transactions as $tx): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border);">
                    <div>
                        <div style="font-weight: 600; font-size: 0.9rem;"><?php echo htmlspecialchars($tx['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo ucfirst($tx['type']); ?></div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-weight: 700; font-family: 'Courier New'; color: <?php echo $tx['type'] === 'deposit' ? 'var(--success)' : 'var(--danger)'; ?>;">
                            <?php echo $tx['type'] === 'deposit' ? '+' : '-'; ?>$<?php echo number_format($tx['amount'], 2); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);">
                            <?php echo time_ago($tx['created_at']); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 20px;">No recent transactions</p>
            <?php endif; ?>
        </div>
        
        <!-- Recent Logins -->
        <div class="chart-container">
            <h3 class="chart-title">Recent Logins</h3>
            <?php if (!empty($recent_logins)): ?>
            <div style="max-height: 300px; overflow-y: auto;">
                <?php foreach ($recent_logins as $login): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border);">
                    <div>
                        <div style="font-weight: 600; font-size: 0.9rem;"><?php echo htmlspecialchars($login['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo htmlspecialchars($login['ip_address'] ?? 'N/A'); ?></div>
                    </div>
                    <div style="text-align: right;">
                        <span class="status-badge <?php echo $login['success'] ? 'status-approved' : 'status-rejected'; ?>">
                            <?php echo $login['success'] ? 'Success' : 'Failed'; ?>
                        </span>
                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 5px;">
                            <?php echo time_ago($login['login_time']); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 20px;">No recent login activity</p>
            <?php endif; ?>
        </div>
    </div>
</div>