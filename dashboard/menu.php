<?php
// menu.php - Main Transactions Menu
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// ==================== CHECK ACCOUNT STATUS ====================
$account_status = 'active';
$suspension_reason = '';
$appeal_status = 'none';
$appeal_message = '';
$suspended_at = null;

try {
    $stmt = $pdo->prepare("
        SELECT account_status, suspension_reason, appeal_status, appeal_message, suspended_at
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_status) {
        $account_status = $user_status['account_status'] ?? 'active';
        $suspension_reason = $user_status['suspension_reason'] ?? '';
        $appeal_status = $user_status['appeal_status'] ?? 'none';
        $appeal_message = $user_status['appeal_message'] ?? '';
        $suspended_at = $user_status['suspended_at'] ?? null;
    }
} catch (Exception $e) {
    error_log("Status check error in menu.php: " . $e->getMessage());
}

// ==================== IF ACCOUNT IS LOCKED - SHOW LOCKED MESSAGE ====================
if ($account_status === 'locked') {
    include './dashboard_header.php';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Account Locked - SwyftTrust Bank</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                background: #0a0a0c;
                font-family: 'Inter', -apple-system, sans-serif;
                color: #fff;
                padding: 20px;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .locked-container {
                max-width: 480px;
                margin: 0 auto;
                text-align: center;
                padding: 40px 20px;
            }
            .lock-icon {
                font-size: 5rem;
                color: #ef4444;
                margin-bottom: 25px;
                animation: pulse 2s infinite;
            }
            @keyframes pulse {
                0% { opacity: 1; transform: scale(1); }
                50% { opacity: 0.8; transform: scale(1.05); }
                100% { opacity: 1; transform: scale(1); }
            }
            h1 {
                font-size: 2rem;
                font-weight: 900;
                color: #ef4444;
                margin-bottom: 20px;
            }
            .message-box {
                background: #1e293b;
                border-left: 4px solid #ef4444;
                padding: 25px;
                border-radius: 16px;
                margin-bottom: 30px;
                text-align: left;
            }
            .message-box h3 {
                color: #fff;
                margin-bottom: 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .reason-box {
                background: #0f172a;
                padding: 15px;
                border-radius: 12px;
                margin: 15px 0;
                border-left: 4px solid #f59e0b;
            }
            .back-btn {
                display: inline-block;
                padding: 12px 24px;
                background: rgba(255,255,255,0.05);
                color: #94a3b8;
                text-decoration: none;
                border-radius: 10px;
                font-weight: 600;
                margin-top: 20px;
            }
            .back-btn:hover {
                background: rgba(255,255,255,0.1);
            }
        </style>
    </head>
    <body>
        <div class="locked-container">
            <div class="lock-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h1>Account Permanently Locked</h1>
            
            <div class="message-box">
                <h3><i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> Access Denied</h3>
                
                <?php if (!empty($suspension_reason)): ?>
                <div class="reason-box">
                    <strong style="color: #ef4444; display: block; margin-bottom: 8px;">Reason:</strong>
                    <?php echo htmlspecialchars($suspension_reason); ?>
                </div>
                <?php endif; ?>
                
                <p>Your account has been permanently locked. You cannot access any transaction features.</p>
                <p style="margin-bottom: 0;">Please contact support for assistance.</p>
            </div>
            
            <a href="./" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </body>
    </html>
    <?php
    include './dashboard_footer.php';
    exit();
}

// ==================== IF ACCOUNT IS SUSPENDED/UNDER REVIEW - SHOW RESTRICTED PAGE ====================
if ($account_status === 'suspended' || $account_status === 'under_review') {
    include './dashboard_header.php';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Account Restricted - SwyftTrust Bank</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                background: #0a0a0c;
                font-family: 'Inter', -apple-system, sans-serif;
                color: #fff;
                padding: 20px;
                min-height: 100vh;
            }
            .restricted-container {
                max-width: 480px;
                margin: 0 auto;
                padding: 80px 0 40px;
            }
            .back-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #9d50ff;
                text-decoration: none;
                font-weight: 600;
                margin-bottom: 25px;
            }
            .status-banner {
                background: #1e293b;
                border-radius: 24px;
                padding: 30px 25px;
                margin-bottom: 30px;
                border-left: 4px solid <?php echo $account_status === 'suspended' ? '#f59e0b' : '#3b82f6'; ?>;
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            }
            .status-icon {
                font-size: 3rem;
                margin-bottom: 20px;
                color: <?php echo $account_status === 'suspended' ? '#f59e0b' : '#3b82f6'; ?>;
            }
            .status-title {
                font-size: 1.8rem;
                font-weight: 900;
                margin-bottom: 15px;
                color: <?php echo $account_status === 'suspended' ? '#f59e0b' : '#3b82f6'; ?>;
            }
            .appeal-badge {
                display: inline-block;
                padding: 8px 16px;
                background: <?php 
                    echo $appeal_status === 'pending' ? 'rgba(245, 158, 11, 0.1)' : 
                         ($appeal_status === 'approved' ? 'rgba(16, 185, 129, 0.1)' : 
                         ($appeal_status === 'rejected' ? 'rgba(239, 68, 68, 0.1)' : 'rgba(100, 116, 139, 0.1)'));
                ?>;
                color: <?php 
                    echo $appeal_status === 'pending' ? '#f59e0b' : 
                         ($appeal_status === 'approved' ? '#10b981' : 
                         ($appeal_status === 'rejected' ? '#ef4444' : '#94a3b8'));
                ?>;
                border-radius: 30px;
                font-size: 0.9rem;
                font-weight: 700;
                margin-bottom: 20px;
            }
            .message-box {
                background: #0f172a;
                padding: 20px;
                border-radius: 16px;
                margin-bottom: 20px;
                border-left: 4px solid #f59e0b;
            }
            .restricted-note {
                color: #94a3b8;
                line-height: 1.6;
                margin-bottom: 25px;
            }
            .back-btn-large {
                display: inline-block;
                padding: 14px 28px;
                background: linear-gradient(135deg, #9d50ff, #6a11cb);
                color: white;
                text-decoration: none;
                border-radius: 12px;
                font-weight: 700;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="restricted-container">
            <a href="./" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            
            <div class="status-banner">
                <div class="status-icon">
                    <?php if ($account_status === 'suspended'): ?>
                        <i class="fas fa-ban"></i>
                    <?php else: ?>
                        <i class="fas fa-clock"></i>
                    <?php endif; ?>
                </div>
                
                <div class="status-title">
                    Account <?php echo $account_status === 'suspended' ? 'Suspended' : 'Under Review'; ?>
                </div>
                
                <div class="appeal-badge">
                    <?php if ($appeal_status === 'pending'): ?>
                        <i class="fas fa-hourglass-half"></i> Appeal Pending
                    <?php elseif ($appeal_status === 'approved'): ?>
                        <i class="fas fa-check-circle"></i> Appeal Approved
                    <?php elseif ($appeal_status === 'rejected'): ?>
                        <i class="fas fa-times-circle"></i> Appeal Rejected
                    <?php else: ?>
                        <i class="fas fa-exclamation-circle"></i> No Appeal Submitted
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($suspension_reason)): ?>
                <div class="message-box">
                    <strong style="color: #f59e0b; display: block; margin-bottom: 10px;">
                        <i class="fas fa-info-circle"></i> Reason:
                    </strong>
                    <?php echo htmlspecialchars($suspension_reason); ?>
                </div>
                <?php endif; ?>
                
                <div class="restricted-note">
                    <p><strong>Transaction features are temporarily unavailable.</strong></p>
                    <p style="margin-top: 10px;">
                        <?php if ($account_status === 'suspended'): ?>
                            Your account is suspended. Please submit an appeal from your profile page 
                            to request account restoration.
                        <?php else: ?>
                            Your appeal is under review. You will be notified once a decision is made.
                        <?php endif; ?>
                    </p>
                </div>
                
                <a href="./profile.php" class="back-btn-large">
                    <i class="fas fa-user"></i> Go to Profile
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    include './dashboard_footer.php';
    exit();
}

// ==================== ACTIVE ACCOUNT - SHOW FULL MENU ====================

// Get user's current status
$stmt = $pdo->prepare("
    SELECT kyc_status, created_at 
    FROM users 
    WHERE id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get pending requests counts
$pending_counts = [
    'deposits' => 0,
    'withdrawals' => 0,
    'loans' => 0,
    'refunds' => 0
];

try {
    // Count pending deposits
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM deposits WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    $pending_counts['deposits'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Count pending withdrawals
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM withdrawals WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    $pending_counts['withdrawals'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Count pending loans
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM loans WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    $pending_counts['loans'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // Count pending refunds (if table exists)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM refunds WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$user_id]);
        $pending_counts['refunds'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    } catch (Exception $e) {
        $pending_counts['refunds'] = 0;
    }
    
} catch (Exception $e) {
    error_log("Error counting pending requests: " . $e->getMessage());
}

// Get recent activities
$recent_activities = [];
try {
    $stmt = $pdo->prepare("
        (SELECT 'deposit' as type, id, amount, currency_code as currency, status, created_at 
         FROM deposits WHERE user_id = ? ORDER BY created_at DESC LIMIT 3)
        UNION ALL
        (SELECT 'withdrawal' as type, id, amount, currency_code as currency, status, created_at 
         FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 3)
        UNION ALL
        (SELECT 'loan' as type, id, amount, currency, status, created_at 
         FROM loans WHERE user_id = ? ORDER BY created_at DESC LIMIT 3)
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching activities: " . $e->getMessage());
}

// Include header
include './dashboard_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transactions Menu - SwyftTrust Bank</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            background: #0a0a0c;
            font-family: 'Inter', -apple-system, sans-serif;
            color: #fff;
            padding: 20px;
            min-height: 100vh;
        }
        
        .menu-container {
            max-width: 480px;
            margin: 0 auto;
            padding: 80px 0 100px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #9d50ff;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 25px;
            padding: 10px 0;
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 900;
            margin-bottom: 30px;
            color: #fff;
        }
        
        /* Status Overview */
        .status-overview {
            background: linear-gradient(135deg, rgba(157, 80, 255, 0.1), rgba(106, 17, 203, 0.1));
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(157, 80, 255, 0.2);
        }
        
        .status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .status-title {
            font-size: 1rem;
            color: #9d50ff;
            font-weight: 700;
        }
        
        .kyc-badge {
            padding: 6px 12px;
            background: <?php echo ($user['kyc_status'] ?? 'unverified') == 'verified' ? '#10b981' : '#f59e0b'; ?>;
            color: white;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .status-item {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 15px;
            padding: 15px;
            text-align: center;
        }
        
        .status-count {
            font-size: 1.5rem;
            font-weight: 900;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .status-label {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        /* Quick Actions Grid */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background: #111113;
            border-radius: 18px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            border: 1px solid rgba(157, 80, 255, 0.1);
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            border-color: #9d50ff;
            background: rgba(157, 80, 255, 0.05);
        }
        
        .action-icon {
            font-size: 2rem;
            color: #9d50ff;
            margin-bottom: 12px;
        }
        
        .action-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .action-desc {
            font-size: 0.75rem;
            color: #94a3b8;
        }
        
        .pending-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 800;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #0a0a0c;
        }
        
        /* Recent Activities */
        .activities-section {
            margin-top: 40px;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .view-all {
            font-size: 0.85rem;
            color: #9d50ff;
            text-decoration: none;
            font-weight: 600;
        }
        
        .activities-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .activity-item {
            background: #111113;
            border-radius: 15px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .deposit-icon { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .withdrawal-icon { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        .loan-icon { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        
        .activity-details {
            flex: 1;
            min-width: 0;
        }
        
        .activity-title {
            font-weight: 700;
            color: #fff;
            font-size: 0.95rem;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .activity-meta {
            font-size: 0.8rem;
            color: #94a3b8;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .activity-status {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
            white-space: nowrap;
        }
        
        .status-pending { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .status-approved { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .status-processing { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .status-completed { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .status-rejected { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        
        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        
        .member-since {
            font-size: 0.7rem;
            color: #94a3b8;
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }
        
        @media (max-width: 480px) {
            .menu-container {
                padding: 60px 0 80px;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .status-grid {
                grid-template-columns: 1fr;
            }
            
            .activity-item {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="menu-container">
        <a href="./" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Transactions Menu</h1>
        
        <!-- Success Messages -->
        <?php if (isset($_GET['loan_success']) && $_GET['loan_success'] == '1'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                Loan application submitted successfully!<br>
                <small>Reference: <?php echo htmlspecialchars($_GET['ref'] ?? ''); ?></small>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['refund_success']) && $_GET['refund_success'] == '1'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                Refund request submitted successfully!<br>
                <small>Reference: <?php echo htmlspecialchars($_GET['ref'] ?? ''); ?></small>
            </div>
        <?php endif; ?>
        
        <!-- Status Overview -->
        <div class="status-overview">
            <div class="status-header">
                <div class="status-title">Account Status</div>
                <div class="kyc-badge">
                    <?php echo ucfirst($user['kyc_status'] ?? 'unverified'); ?>
                </div>
            </div>
            
            <div class="status-grid">
                <div class="status-item">
                    <div class="status-count"><?php echo $pending_counts['deposits']; ?></div>
                    <div class="status-label">Pending Deposits</div>
                </div>
                <div class="status-item">
                    <div class="status-count"><?php echo $pending_counts['withdrawals']; ?></div>
                    <div class="status-label">Pending Withdrawals</div>
                </div>
                <div class="status-item">
                    <div class="status-count"><?php echo $pending_counts['loans']; ?></div>
                    <div class="status-label">Pending Loans</div>
                </div>
                <div class="status-item">
                    <div class="status-count"><?php echo $pending_counts['refunds']; ?></div>
                    <div class="status-label">Pending Refunds</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions Grid -->
        <div class="actions-grid">
            <a href="deposit.php" class="action-card">
                <div class="action-icon"><i class="fas fa-plus-circle"></i></div>
                <div class="action-title">Deposit Funds</div>
                <div class="action-desc">Add money to your account</div>
                <?php if ($pending_counts['deposits'] > 0): ?>
                    <div class="pending-badge"><?php echo $pending_counts['deposits']; ?></div>
                <?php endif; ?>
            </a>
            
            <a href="withdraw.php" class="action-card">
                <div class="action-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="action-title">Withdraw Funds</div>
                <div class="action-desc">Withdraw to your bank/crypto</div>
                <?php if ($pending_counts['withdrawals'] > 0): ?>
                    <div class="pending-badge"><?php echo $pending_counts['withdrawals']; ?></div>
                <?php endif; ?>
            </a>
            
            <a href="loan.php" class="action-card">
                <div class="action-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="action-title">Request Loan</div>
                <div class="action-desc">Apply for personal loan</div>
                <?php if ($pending_counts['loans'] > 0): ?>
                    <div class="pending-badge"><?php echo $pending_counts['loans']; ?></div>
                <?php endif; ?>
            </a>
            
            <?php if (isset($pending_counts['refunds'])): ?>
            <a href="refund.php" class="action-card">
                <div class="action-icon"><i class="fas fa-undo-alt"></i></div>
                <div class="action-title">Request Refund</div>
                <div class="action-desc">Request transaction refund</div>
                <?php if ($pending_counts['refunds'] > 0): ?>
                    <div class="pending-badge"><?php echo $pending_counts['refunds']; ?></div>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            
            <a href="history.php" class="action-card">
                <div class="action-icon"><i class="fas fa-history"></i></div>
                <div class="action-title">Transaction History</div>
                <div class="action-desc">View all transactions</div>
            </a>
            
            <a href="profile.php" class="action-card">
                <div class="action-icon"><i class="fas fa-user-cog"></i></div>
                <div class="action-title">Profile Settings</div>
                <div class="action-desc">Manage your account</div>
            </a>
        </div>
        
        <!-- Recent Activities -->
        <?php if (!empty($recent_activities)): ?>
        <div class="activities-section">
            <h2 class="section-title">
                Recent Activities
                <a href="history.php" class="view-all">View All →</a>
            </h2>
            
            <div class="activities-list">
                <?php foreach ($recent_activities as $activity): 
                    $type = $activity['type'];
                    $icon_class = $type . '-icon';
                    $type_name = ucfirst($type);
                ?>
                <div class="activity-item">
                    <div class="activity-icon <?php echo $icon_class; ?>">
                        <?php if ($type == 'deposit'): ?>
                            <i class="fas fa-plus"></i>
                        <?php elseif ($type == 'withdrawal'): ?>
                            <i class="fas fa-minus"></i>
                        <?php elseif ($type == 'loan'): ?>
                            <i class="fas fa-hand-holding-usd"></i>
                        <?php else: ?>
                            <i class="fas fa-undo-alt"></i>
                        <?php endif; ?>
                    </div>
                    <div class="activity-details">
                        <div class="activity-title">
                            <?php echo $type_name; ?>: 
                            <?php echo number_format($activity['amount'], 2); ?> 
                            <?php echo $activity['currency'] ?? 'USD'; ?>
                        </div>
                        <div class="activity-meta">
                            <span><?php echo date('M d, H:i', strtotime($activity['created_at'])); ?></span>
                            <span class="activity-status status-<?php echo $activity['status']; ?>">
                                <?php echo ucfirst($activity['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="member-since">
            <i class="fas fa-calendar-alt"></i> Member since: <?php echo isset($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'N/A'; ?>
        </div>
    </div>
</body>
</html>
<?php include './dashboard_footer.php'; ?>