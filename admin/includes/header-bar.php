<?php
// admin/includes/header-bar.php
$titles = [
    'dashboard' => 'Dashboard',
    'users' => 'User Management',
    'deposits' => 'Deposit Approvals',
    'withdrawals' => 'Withdrawal Approvals',
    'loans' => 'Loan Applications',
    'refunds' => 'Refund Requests',
    'transactions' => 'All Transactions',
    'kyc' => 'KYC Verification',
    'reports' => 'Financial Reports',
    'settings' => 'System Settings'
];
?>

<div class="header-bar">
    <h1 class="page-title">
        <?php echo $titles[$tab] ?? 'Admin Dashboard'; ?>
    </h1>
    
    <div class="header-actions">
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <form method="GET" style="display: inline;">
                <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                <input type="text" name="search" class="search-input" placeholder="Search..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </form>
        </div>
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="message-alert <?php echo $message_type; ?>">
    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>