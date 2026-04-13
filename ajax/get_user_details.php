<?php
// admin/ajax/get_user_details.php
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die('Unauthorized');
}

$user_id = $_GET['id'] ?? 0;

// Get user details
$stmt = $pdo->prepare("
    SELECT 
        u.*,
        (SELECT COUNT(*) FROM deposits WHERE user_id = u.id) as total_deposits,
        (SELECT COUNT(*) FROM withdrawals WHERE user_id = u.id) as total_withdrawals,
        (SELECT COUNT(*) FROM loans WHERE user_id = u.id) as total_loans,
        (SELECT COUNT(*) FROM refunds WHERE user_id = u.id) as total_refunds
    FROM users u
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo '<div class="alert alert-danger">User not found</div>';
    exit;
}

// Get user balances
$stmt = $pdo->prepare("SELECT currency_code, available_balance, pending_balance FROM balances WHERE user_id = ? ORDER BY available_balance DESC");
$stmt->execute([$user_id]);
$balances = $stmt->fetchAll();

// Get recent transactions
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll();
?>

<div class="user-details">
    <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="flex: 1;">
            <h4 style="margin-top: 0; color: #333; border-bottom: 2px solid #9d50ff; padding-bottom: 10px;">Personal Information</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 8px 0; color: #666; width: 120px;">User ID:</td>
                    <td style="padding: 8px 0; font-weight: 600;">#<?php echo $user['id']; ?></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Full Name:</td>
                    <td style="padding: 8px 0;"><?php echo htmlspecialchars($user['full_name']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Email:</td>
                    <td style="padding: 8px 0;"><?php echo htmlspecialchars($user['email']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Phone:</td>
                    <td style="padding: 8px 0;"><?php echo htmlspecialchars($user['phone']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Country:</td>
                    <td style="padding: 8px 0;"><?php echo htmlspecialchars($user['country']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Date of Birth:</td>
                    <td style="padding: 8px 0;"><?php echo $user['date_of_birth'] ? date('F j, Y', strtotime($user['date_of_birth'])) : 'Not set'; ?></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Address:</td>
                    <td style="padding: 8px 0;"><?php echo nl2br(htmlspecialchars($user['address'])); ?></td>
                </tr>
            </table>
        </div>
        
        <div style="flex: 1;">
            <h4 style="margin-top: 0; color: #333; border-bottom: 2px solid #10b981; padding-bottom: 10px;">Account Information</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 8px 0; color: #666; width: 140px;">Account Status:</td>
                    <td style="padding: 8px 0;">
                        <span style="padding: 4px 12px; border-radius: 20px; background: #10b98120; color: #10b981; font-weight: 600;">
                            Active
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">KYC Status:</td>
                    <td style="padding: 8px 0;">
                        <span style="padding: 4px 12px; border-radius: 20px; background: <?php 
                            $kyc_colors = [
                                'verified' => '#10b981',
                                'pending' => '#f59e0b',
                                'unverified' => '#64748b'
                            ];
                            echo $kyc_colors[$user['kyc_status']] ?? '#64748b';
                        ?>20; color: <?php echo $kyc_colors[$user['kyc_status']] ?? '#64748b'; ?>; font-weight: 600;">
                            <?php echo ucfirst($user['kyc_status']); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">2FA Enabled:</td>
                    <td style="padding: 8px 0;">
                        <?php echo $user['two_factor_enabled'] ? '<span style="color: #10b981;">Yes</span>' : '<span style="color: #64748b;">No</span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Joined:</td>
                    <td style="padding: 8px 0;"><?php echo date('F j, Y g:i A', strtotime($user['created_at'])); ?></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Last Login:</td>
                    <td style="padding: 8px 0;">
                        <?php 
                        $stmt2 = $pdo->prepare("SELECT login_time FROM login_history WHERE user_id = ? ORDER BY login_time DESC LIMIT 1");
                        $stmt2->execute([$user_id]);
                        $last_login = $stmt2->fetchColumn();
                        echo $last_login ? date('F j, Y g:i A', strtotime($last_login)) : 'Never logged in';
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">Wallet PIN:</td>
                    <td style="padding: 8px 0;">
                        <?php echo $user['wallet_pin'] ? '****' : 'Not set'; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Balances -->
    <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
        <h4 style="margin-top: 0; color: #333; margin-bottom: 15px;">Account Balances</h4>
        <?php if (empty($balances)): ?>
            <p style="color: #666; margin: 0;">No balances found</p>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <?php foreach ($balances as $balance): 
                    $total = $balance['available_balance'] + $balance['pending_balance'];
                ?>
                <div style="background: white; padding: 15px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <div style="font-size: 0.9rem; color: #666; margin-bottom: 5px;"><?php echo $balance['currency_code']; ?></div>
                    <div style="font-size: 1.3rem; font-weight: 700; color: #333;">
                        <?php echo number_format($total, 2); ?>
                    </div>
                    <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                        Available: <?php echo number_format($balance['available_balance'], 2); ?> | 
                        Pending: <?php echo number_format($balance['pending_balance'], 2); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Statistics -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
        <div style="background: rgba(157, 80, 255, 0.1); padding: 15px; border-radius: 8px; text-align: center;">
            <div style="font-size: 1.8rem; font-weight: 700; color: #9d50ff;"><?php echo $user['total_deposits']; ?></div>
            <div style="font-size: 0.9rem; color: #666;">Total Deposits</div>
        </div>
        <div style="background: rgba(16, 185, 129, 0.1); padding: 15px; border-radius: 8px; text-align: center;">
            <div style="font-size: 1.8rem; font-weight: 700; color: #10b981;"><?php echo $user['total_withdrawals']; ?></div>
            <div style="font-size: 0.9rem; color: #666;">Total Withdrawals</div>
        </div>
        <div style="background: rgba(245, 158, 11, 0.1); padding: 15px; border-radius: 8px; text-align: center;">
            <div style="font-size: 1.8rem; font-weight: 700; color: #f59e0b;"><?php echo $user['total_loans']; ?></div>
            <div style="font-size: 0.9rem; color: #666;">Total Loans</div>
        </div>
        <div style="background: rgba(59, 130, 246, 0.1); padding: 15px; border-radius: 8px; text-align: center;">
            <div style="font-size: 1.8rem; font-weight: 700; color: #3b82f6;"><?php echo $user['total_refunds']; ?></div>
            <div style="font-size: 0.9rem; color: #666;">Total Refunds</div>
        </div>
    </div>
    
    <!-- Recent Transactions -->
    <div style="background: #f8f9fa; padding: 20px; border-radius: 10px;">
        <h4 style="margin-top: 0; color: #333; margin-bottom: 15px;">Recent Transactions</h4>
        <?php if (empty($transactions)): ?>
            <p style="color: #666; margin: 0;">No recent transactions</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #e5e7eb;">
                            <th style="padding: 10px; text-align: left;">Type</th>
                            <th style="padding: 10px; text-align: left;">Amount</th>
                            <th style="padding: 10px; text-align: left;">Status</th>
                            <th style="padding: 10px; text-align: left;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 10px;">
                                <span style="padding: 3px 8px; border-radius: 15px; background: <?php 
                                    $type_colors = [
                                        'deposit' => '#10b981',
                                        'withdrawal' => '#ef4444',
                                        'loan' => '#f59e0b',
                                        'refund' => '#3b82f6'
                                    ];
                                    echo $type_colors[$tx['type']] ?? '#64748b';
                                ?>20; color: <?php echo $type_colors[$tx['type']] ?? '#64748b'; ?>; font-size: 0.85rem;">
                                    <?php echo ucfirst($tx['type']); ?>
                                </span>
                            </td>
                            <td style="padding: 10px; font-weight: 600;">
                                <?php echo number_format($tx['amount'], 2); ?> <?php echo $tx['currency_code']; ?>
                            </td>
                            <td style="padding: 10px;">
                                <span style="padding: 3px 8px; border-radius: 15px; background: <?php 
                                    echo $tx['status'] == 'completed' ? '#10b98120' : '#f59e0b20';
                                ?>; color: <?php echo $tx['status'] == 'completed' ? '#10b981' : '#f59e0b'; ?>; font-size: 0.85rem;">
                                    <?php echo ucfirst($tx['status']); ?>
                                </span>
                            </td>
                            <td style="padding: 10px; font-size: 0.9rem; color: #666;">
                                <?php echo date('M d, Y H:i', strtotime($tx['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>