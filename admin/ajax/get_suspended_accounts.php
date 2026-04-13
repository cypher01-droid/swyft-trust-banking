<?php
// get_suspended_accounts.php

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    // Verify admin
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('Unauthorized access. Please login as admin.');
    }
    
    require_once dirname(__DIR__) . '/../includes/db.php';
    
    $statusFilter = $_GET['status'] ?? null;
    
    $sql = "SELECT 
        id, 
        full_name, 
        email, 
        account_status, 
        suspension_reason, 
        appeal_status,
        appeal_message,
        appeal_submitted_at,
        suspended_at, 
        locked_at,
        DATE_FORMAT(suspended_at, '%Y-%m-%d %H:%i:%s') as suspended_at_formatted,
        DATE_FORMAT(locked_at, '%Y-%m-%d %H:%i:%s') as locked_at_formatted,
        DATE_FORMAT(appeal_submitted_at, '%Y-%m-%d %H:%i:%s') as appeal_submitted_at_formatted
        FROM users 
        WHERE account_status IN ('suspended', 'under_review', 'locked')";
    
    $params = [];
    
    if ($statusFilter && in_array($statusFilter, ['suspended', 'under_review', 'locked'])) {
        $sql .= " AND account_status = ?";
        $params[] = $statusFilter;
    }
    
    $sql .= " ORDER BY 
              CASE account_status 
                WHEN 'under_review' THEN 1
                WHEN 'suspended' THEN 2
                WHEN 'locked' THEN 3
              END,
              suspended_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending appeals count
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE appeal_status = 'pending'");
    $stmt->execute();
    $pendingCount = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'accounts' => $accounts,
        'pending_appeals' => $pendingCount,
        'total' => count($accounts)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

exit;
?>