<?php
// admin/includes/auth.php

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin-login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['full_name'] ?? 'Admin';

// Handle admin actions
function handleAdminAction($action, $id, $tab) {
    global $pdo;
    
    switch($action) {
        case 'approve':
        case 'reject':
            // Handle deposit/withdrawal approvals
            if (in_array($tab, ['deposits', 'withdrawals'])) {
                $table = $tab;
                $new_status = ($action === 'approve') ? 'approved' : 'rejected';
                
                try {
                    $stmt = $pdo->prepare("UPDATE {$table} SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_status, $id]);
                    
                    $_SESSION['admin_message'] = ucfirst($tab) . " #{$id} has been {$new_status}!";
                    $_SESSION['admin_message_type'] = 'success';
                } catch (Exception $e) {
                    $_SESSION['admin_message'] = "Error: " . $e->getMessage();
                    $_SESSION['admin_message_type'] = 'error';
                }
            }
            break;
    }
}

// Handle bulk actions
function handleBulkAction($action, $ids, $tab) {
    global $pdo;
    
    if (empty($ids)) return;
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    switch($action) {
        case 'approve_all':
            $stmt = $pdo->prepare("UPDATE {$tab} SET status = 'approved' WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            break;
        case 'reject_all':
            $stmt = $pdo->prepare("UPDATE {$tab} SET status = 'rejected' WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            break;
    }
}
?>