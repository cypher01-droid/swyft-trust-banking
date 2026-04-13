<?php
// get_loan.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('Unauthorized access');
    }
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('Invalid loan ID');
    }
    
    $loanId = intval($_GET['id']);
    
    // CORRECT PATH
    require_once dirname(__DIR__) . '/../includes/db.php';
    
    if (!isset($pdo)) {
        throw new Exception('Database connection error');
    }
    
    $stmt = $pdo->prepare("SELECT l.*, u.email, u.full_name, u.kyc_status FROM loans l 
                          JOIN users u ON l.user_id = u.id 
                          WHERE l.id = ?");
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$loan) {
        throw new Exception('Loan not found');
    }
    
    echo json_encode(['success' => true, 'data' => $loan]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;