<?php
// admin/ajax/update_balance.php
require_once '../../includes/db.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// CSRF protection
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch']);
    exit();
}

// Process the update (your existing update_balance.php logic)
$user_id = $_POST['user_id'] ?? 0;
$currency_code = $_POST['currency_code'] ?? '';
$type = $_POST['type'] ?? 'credit';
$amount = $_POST['amount'] ?? 0;
$reason = $_POST['reason'] ?? '';

// Your existing balance update logic here...

echo json_encode([
    'success' => true,
    'message' => 'Balance updated successfully'
]);
?>