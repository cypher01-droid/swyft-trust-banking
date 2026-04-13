<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid document ID']);
    exit;
}

$db = new Database();
$documentId = (int)$_GET['id'];

// Get document details
$stmt = $db->pdo->prepare("
    SELECT k.*, u.email, u.full_name, u.kyc_status, u.id as user_id 
    FROM kyc_documents k 
    JOIN users u ON k.user_id = u.id 
    WHERE k.id = ?
");
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    echo json_encode(['success' => false, 'error' => 'Document not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => $document
]);