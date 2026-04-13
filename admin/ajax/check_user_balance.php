<?php
require_once '../includes/db.php';
session_start();

// Check admin authentication
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized access']));
}

if (!isset($_GET['user_id']) || !isset($_GET['currency_code'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Missing parameters']));
}

$userId = intval($_GET['user_id']);
$currencyCode = $_GET['currency_code'];

try {
    // Get current balance
    $stmt = $pdo->prepare("
        SELECT available_balance, pending_balance 
        FROM balances 
        WHERE user_id = ? AND currency_code = ?
    ");
    $stmt->execute([$userId, $currencyCode]);
    $balance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$balance) {
        $balance = [
            'available_balance' => '0.00000000',
            'pending_balance' => '0.00000000'
        ];
    }
    
    // Get recent transactions for this currency
    $stmt = $pdo->prepare("
        SELECT type, amount, created_at, description 
        FROM transactions 
        WHERE user_id = ? AND currency_code = ?
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId, $currencyCode]);
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'balance' => $balance,
        'recent_transactions' => $recentTransactions,
        'currency_code' => $currencyCode
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
}