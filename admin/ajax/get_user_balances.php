<?php
// admin/ajax/get_user_balances.php
session_start();
require_once '../../includes/db.php';

header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'User ID required']);
    exit;
}

try {
    // Get user details
    $stmt = $pdo->prepare("SELECT id, email, full_name, account_status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Get all balances for this user
    $stmt = $pdo->prepare("
        SELECT 
            currency_code,
            COALESCE(available_balance, 0) as available_balance,
            COALESCE(pending_balance, 0) as pending_balance,
            COALESCE(locked_balance, 0) as locked_balance,
            (COALESCE(available_balance, 0) + COALESCE(pending_balance, 0) + COALESCE(locked_balance, 0)) as total_balance,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') as last_updated
        FROM balances 
        WHERE user_id = ? 
        ORDER BY currency_code
    ");
    $stmt->execute([$userId]);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no balances found, return empty array
    if (empty($balances)) {
        $balances = [];
    }
    
    // Get recent balance history
    $stmt = $pdo->prepare("
        SELECT 
            bh.*,
            u.full_name as admin_name
        FROM balance_history bh
        LEFT JOIN users u ON bh.created_by = u.id
        WHERE bh.user_id = ?
        ORDER BY bh.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'user' => $user,
        'balances' => $balances,
        'history' => $history,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in get_user_balances.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("General error in get_user_balances.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>