<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$loan_id = $_POST['id'] ?? 0;
$action = $_POST['action'] ?? '';
$admin_message = $_POST['message'] ?? '';

if (!$loan_id || !in_array($action, ['approve', 'decline'])) {
    die(json_encode(['success' => false, 'message' => 'Invalid request']));
}

try {
    $pdo->beginTransaction();
    
    // Get loan details
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE id = ?");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();
    
    if (!$loan) {
        throw new Exception("Loan not found");
    }
    
    // Generate tracking code if not exists
    $tracking_code = $loan['tracking_code'];
    if (empty($tracking_code)) {
        $tracking_code = generateLoanTrackingCode();
    }
    
    $new_status = ($action === 'approve') ? 'approved' : 'declined';
    
    // Update loan
    $stmt = $pdo->prepare("
        UPDATE loans 
        SET status = ?, 
            tracking_code = ?,
            admin_notes = ?,
            admin_message = ?,
            processed_by = ?,
            processed_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $new_status,
        $tracking_code,
        "Processed by admin #" . $_SESSION['user_id'],
        $admin_message,
        $_SESSION['user_id'],
        $loan_id
    ]);
    
    // If approved, add to user balance
    if ($action === 'approve') {
        // Add to user's USD balance
        $stmt = $pdo->prepare("
            INSERT INTO balances (user_id, currency_code, available_balance)
            VALUES (?, 'USD', ?)
            ON DUPLICATE KEY UPDATE 
            available_balance = available_balance + VALUES(available_balance)
        ");
        $stmt->execute([$loan['user_id'], $loan['requested_amount']]);
        
        // Create transaction record
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                user_id, type, amount, currency_code, 
                reference_id, tracking_code, status, description, created_at
            ) VALUES (?, 'loan', ?, 'USD', ?, ?, 'completed', ?, NOW())
        ");
        $description = "Loan approved. " . $admin_message;
        $stmt->execute([
            $loan['user_id'],
            $loan['requested_amount'],
            $loan_id,
            $tracking_code,
            $description
        ]);
        
        $notification_title = "Loan Approved!";
        $notification_message = "Your loan request #{$loan_id} has been approved. Amount: $" . number_format($loan['requested_amount'], 2) . ". Tracking Code: {$tracking_code}. Message: {$admin_message}";
    } else {
        $notification_title = "Loan Declined";
        $notification_message = "Your loan request #{$loan_id} has been declined. Reason: {$admin_message}. Tracking Code: {$tracking_code}";
    }
    
    // Create notification for user
    $stmt = $pdo->prepare("
        INSERT INTO notifications (
            user_id, type, title, message, created_at
        ) VALUES (?, 'loan', ?, ?, NOW())
    ");
    $stmt->execute([$loan['user_id'], $notification_title, $notification_message]);
    
    // Send email (optional - you need to implement sendEmail function)
    // sendEmailToUser($loan['user_id'], $notification_title, $notification_message);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Loan has been {$new_status}",
        'tracking_code' => $tracking_code
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>