<?php
// get_withdrawal.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('Unauthorized access');
    }
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('Invalid withdrawal ID');
    }
    
    $withdrawalId = intval($_GET['id']);
    
    // CORRECT PATH
    require_once dirname(__DIR__) . '/../includes/db.php';
    
    if (!isset($pdo)) {
        throw new Exception('Database connection error');
    }
    
    $stmt = $pdo->prepare("SELECT w.*, u.email, u.full_name FROM withdrawals w 
                          JOIN users u ON w.user_id = u.id 
                          WHERE w.id = ?");
    $stmt->execute([$withdrawalId]);
    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$withdrawal) {
        throw new Exception('Withdrawal not found');
    }
    
    echo json_encode(['success' => true, 'data' => $withdrawal]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;