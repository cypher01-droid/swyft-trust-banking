<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

$db = new Database();
$userId = (int)$_GET['user_id'];

// Get user info
$stmt = $db->pdo->prepare("SELECT id, email, full_name, kyc_status FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all documents for user
$stmt = $db->pdo->prepare("
    SELECT k.*, 
           a.full_name as admin_name 
    FROM kyc_documents k 
    LEFT JOIN users a ON k.verified_by = a.id 
    WHERE k.user_id = ? 
    ORDER BY 
        CASE k.status 
            WHEN 'pending' THEN 1
            WHEN 'rejected' THEN 2
            WHEN 'verified' THEN 3
        END,
        k.created_at DESC
");
$stmt->execute([$userId]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'user' => $user,
    'data' => $documents
]);