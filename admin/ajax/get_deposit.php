<?php
// get_deposit.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('Unauthorized access');
    }
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('Invalid deposit ID');
    }
    
    $depositId = intval($_GET['id']);
    
    // CORRECT PATH
    require_once dirname(__DIR__) . '/../includes/db.php';
    
    if (!isset($pdo)) {
        throw new Exception('Database connection error');
    }
    
    $stmt = $pdo->prepare("SELECT d.*, u.email, u.full_name FROM deposits d 
                          JOIN users u ON d.user_id = u.id 
                          WHERE d.id = ?");
    $stmt->execute([$depositId]);
    $deposit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$deposit) {
        throw new Exception('Deposit not found');
    }
    
    echo json_encode(['success' => true, 'data' => $deposit]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;