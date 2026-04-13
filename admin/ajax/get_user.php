<?php
// get_user.php - COMPLETE UPDATED VERSION WITH BALANCE & ACCOUNT MANAGEMENT

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON header
header('Content-Type: application/json');

try {
    // Verify admin
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('Unauthorized access. Please login as admin.');
    }
    
    // Validate ID
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('Invalid user ID');
    }
    
    $userId = intval($_GET['id']);
    
    // Include database connection
    $configFile = dirname(__DIR__) . '/../includes/db.php';
    
    if (!file_exists($configFile)) {
        throw new Exception('Database config not found at: ' . $configFile);
    }
    
    require_once $configFile;
    
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection not established');
    }
    
    // Get user data with all fields
    $stmt = $pdo->prepare("SELECT 
        id, 
        full_name, 
        email, 
        phone, 
        kyc_status, 
        role, 
        country, 
        address,
        date_of_birth,
        account_status,
        suspension_reason,
        admin_notes_private,
        appeal_status,
        appeal_message,
        appeal_submitted_at,
        suspended_at,
        locked_at,
        suspended_by,
        restored_at,
        restored_by,
        DATE_FORMAT(created_at, '%Y-%m-%d') as created_at,
        DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') as updated_at
    FROM users 
    WHERE id = ?");
    
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Get ALL balances with complete fields
    $stmt = $pdo->prepare("SELECT 
        currency_code, 
        available_balance, 
        pending_balance,
        COALESCE(locked_balance, 0) as locked_balance,
        COALESCE(available_balance,0) + COALESCE(pending_balance,0) + COALESCE(locked_balance,0) as total_balance,
        last_deposit_at,
        last_withdrawal_at,
        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at,
        DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') as updated_at
    FROM balances 
    WHERE user_id = ? 
    ORDER BY currency_code");
    
    $stmt->execute([$userId]);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no balances found, create empty array
    if (empty($balances)) {
        $balances = [];
    }
    
    // Get balance history (last 20 entries)
    $stmt = $pdo->prepare("
        SELECT 
            bh.*,
            u.full_name as admin_name,
            u.email as admin_email
        FROM balance_history bh
        LEFT JOIN users u ON bh.created_by = u.id
        WHERE bh.user_id = ?
        ORDER BY bh.created_at DESC
        LIMIT 20
    ");
    
    $stmt->execute([$userId]);
    $balanceHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get suspension history (last 10 entries)
    $stmt = $pdo->prepare("
        SELECT 
            ush.*, 
            u.email as admin_email, 
            u.full_name as admin_name
        FROM user_suspension_history ush
        LEFT JOIN users u ON ush.performed_by = u.id
        WHERE ush.user_id = ?
        ORDER BY ush.performed_at DESC
        LIMIT 10
    ");
    
    $stmt->execute([$userId]);
    $suspensionHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format suspension history dates
    foreach ($suspensionHistory as &$entry) {
        if ($entry['performed_at']) {
            $entry['performed_at_formatted'] = date('M d, Y H:i', strtotime($entry['performed_at']));
        }
    }
    
    // Get pending appeal if any
    $stmt = $pdo->prepare("
        SELECT * FROM user_appeals 
        WHERE user_id = ? AND status = 'pending'
        ORDER BY submitted_at DESC 
        LIMIT 1
    ");
    
    $stmt->execute([$userId]);
    $pendingAppeal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get all appeals (for history)
    $stmt = $pdo->prepare("
        SELECT 
            ua.*,
            u.email as reviewer_email,
            u.full_name as reviewer_name
        FROM user_appeals ua
        LEFT JOIN users u ON ua.reviewed_by = u.id
        WHERE ua.user_id = ?
        ORDER BY ua.submitted_at DESC
        LIMIT 5
    ");
    
    $stmt->execute([$userId]);
    $appealHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent transactions
    $stmt = $pdo->prepare("
        SELECT 
            id,
            type,
            amount,
            currency_code,
            status,
            description,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at
        FROM transactions 
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    
    $stmt->execute([$userId]);
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get currency totals
    $stmt = $pdo->prepare("
        SELECT 
            currency_code,
            COUNT(*) as transaction_count,
            SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as total_deposits,
            SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_withdrawals
        FROM transactions 
        WHERE user_id = ? AND status = 'completed'
        GROUP BY currency_code
    ");
    
    $stmt->execute([$userId]);
    $transactionTotals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get suspension stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_suspensions,
            SUM(CASE WHEN action = 'suspended' THEN 1 ELSE 0 END) as suspend_count,
            SUM(CASE WHEN action = 'restored' THEN 1 ELSE 0 END) as restore_count,
            MAX(performed_at) as last_action_date
        FROM user_suspension_history 
        WHERE user_id = ?
    ");
    
    $stmt->execute([$userId]);
    $suspensionStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get admin who performed last actions
    if ($user['suspended_by']) {
        $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $stmt->execute([$user['suspended_by']]);
        $suspendedBy = $stmt->fetch(PDO::FETCH_ASSOC);
        $user['suspended_by_name'] = $suspendedBy ? $suspendedBy['full_name'] : null;
        $user['suspended_by_email'] = $suspendedBy ? $suspendedBy['email'] : null;
    }
    
    if ($user['restored_by']) {
        $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $stmt->execute([$user['restored_by']]);
        $restoredBy = $stmt->fetch(PDO::FETCH_ASSOC);
        $user['restored_by_name'] = $restoredBy ? $restoredBy['full_name'] : null;
        $user['restored_by_email'] = $restoredBy ? $restoredBy['email'] : null;
    }
    
    // Format dates for display
    $dateFields = ['appeal_submitted_at', 'suspended_at', 'locked_at', 'restored_at'];
    foreach ($dateFields as $field) {
        if (!empty($user[$field])) {
            $user[$field . '_formatted'] = date('M d, Y H:i', strtotime($user[$field]));
        }
    }
    
    // Add status labels for display
    $statusLabels = [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'under_review' => 'Under Review',
        'locked' => 'Permanently Locked'
    ];
    $user['account_status_label'] = $statusLabels[$user['account_status']] ?? ucfirst($user['account_status']);
    
    $appealStatusLabels = [
        'none' => 'No Appeal',
        'pending' => 'Pending Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected'
    ];
    $user['appeal_status_label'] = $appealStatusLabels[$user['appeal_status']] ?? ucfirst($user['appeal_status']);
    
    // Calculate total balance across all currencies
    $totalBalanceValue = 0;
    foreach ($balances as $balance) {
        $totalBalanceValue += ($balance['available_balance'] ?? 0) + 
                              ($balance['pending_balance'] ?? 0) + 
                              ($balance['locked_balance'] ?? 0);
    }
    
    // Return success with ALL data
    echo json_encode([
        'success' => true,
        'user' => $user,
        'balances' => $balances,
        'balanceHistory' => $balanceHistory,
        'suspensionHistory' => $suspensionHistory,
        'suspensionStats' => $suspensionStats,
        'pendingAppeal' => $pendingAppeal,
        'appealHistory' => $appealHistory,
        'recentTransactions' => $recentTransactions,
        'transactionTotals' => $transactionTotals,
        'summary' => [
            'total_balance' => $totalBalanceValue,
            'active_currencies' => count($balances),
            'total_transactions' => count($recentTransactions),
            'has_pending_appeal' => !empty($pendingAppeal)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

exit;
?>