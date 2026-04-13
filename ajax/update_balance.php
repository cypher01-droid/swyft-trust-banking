<?php
// ajax/update_balance.php
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF Protection - ADD THIS
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh the page.']);
    exit;
}

// Get POST data
$user_id = $_POST['user_id'] ?? 0;
$admin_notes = $_POST['admin_notes'] ?? '';
$currencies = $_POST['currency'] ?? [];
$actions = $_POST['action'] ?? [];
$amounts = $_POST['amount'] ?? [];
$descriptions = $_POST['description'] ?? [];

// Validate inputs
if (!$user_id || empty($currencies)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

// Check if user exists
$stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$results = [];
$pdo->beginTransaction();

try {
    // Process each currency adjustment
    foreach ($currencies as $index => $currency_code) {
        if (empty($currency_code) || empty($actions[$index]) || empty($amounts[$index])) {
            continue; // Skip incomplete entries
        }
        
        $currency_code = strtoupper(trim($currency_code));
        $action = $actions[$index];
        $amount = (float) $amounts[$index];
        $description = trim($descriptions[$index] ?? '');
        
        // Validate amount
        if ($amount <= 0) {
            throw new Exception("Invalid amount for $currency_code: Amount must be greater than 0");
        }
        
        // Get current balance
        $stmt = $pdo->prepare("SELECT id, available_balance FROM balances WHERE user_id = ? AND currency_code = ?");
        $stmt->execute([$user_id, $currency_code]);
        $balance = $stmt->fetch();
        
        $current_balance = $balance ? $balance['available_balance'] : 0;
        $balance_id = $balance ? $balance['id'] : null;
        
        // Calculate new balance based on action
        switch ($action) {
            case 'add':
                $new_balance = $current_balance + $amount;
                $transaction_type = 'adjustment_credit';
                $adjustment_type = 'credit';
                break;
                
            case 'subtract':
                if ($current_balance < $amount) {
                    throw new Exception("Insufficient $currency_code balance. Current: $current_balance, Attempted: $amount");
                }
                $new_balance = $current_balance - $amount;
                $transaction_type = 'adjustment_debit';
                $adjustment_type = 'debit';
                break;
                
            case 'set':
                $new_balance = $amount;
                $transaction_type = 'adjustment';
                $adjustment_type = $amount > $current_balance ? 'credit' : 'debit';
                break;
                
            default:
                throw new Exception("Invalid action: $action");
        }
        
        // Update or insert balance
        if ($balance_id) {
            $stmt = $pdo->prepare("UPDATE balances SET available_balance = ? WHERE id = ?");
            $stmt->execute([$new_balance, $balance_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO balances (user_id, currency_code, available_balance, locked_balance) VALUES (?, ?, ?, 0)");
            $stmt->execute([$user_id, $currency_code, $new_balance]);
            $balance_id = $pdo->lastInsertId();
        }
        
        // Build description with admin info
        $admin_name = $_SESSION['full_name'] ?? 'Admin';
        $txn_description = "Manual balance adjustment by $admin_name";
        if ($description) {
            $txn_description .= " - $description";
        }
        if ($admin_notes) {
            $txn_description .= " [Admin notes: $admin_notes]";
        }
        
        // Generate tracking code
        $tracking_code = 'ADJ-' . date('Ymd') . '-' . strtoupper(substr(md5(mt_rand() . time()), 0, 8));
        
        // Check transactions table structure and insert accordingly
        $stmt_check = $pdo->prepare("DESCRIBE transactions");
        $stmt_check->execute();
        $columns = $stmt_check->fetchAll(PDO::FETCH_COLUMN);
        
        // Determine which columns exist
        $has_reference_id = in_array('reference_id', $columns);
        $has_tracking_code = in_array('tracking_code', $columns);
        $has_payment_method = in_array('payment_method', $columns);
        $has_progress_percent = in_array('progress_percent', $columns);
        $has_admin_note = in_array('admin_note', $columns);
        $has_completed_at = in_array('completed_at', $columns);
        
        // Build insert query based on actual columns
        $insert_columns = ['user_id', 'type', 'amount', 'currency_code', 'description', 'status', 'created_at'];
        $placeholders = ['?', '?', '?', '?', '?', '?', 'NOW()'];
        $values = [$user_id, $transaction_type, $amount, $currency_code, $txn_description, 'completed'];
        
        if ($has_tracking_code) {
            $insert_columns[] = 'tracking_code';
            $placeholders[] = '?';
            $values[] = $tracking_code;
        }
        
        if ($has_payment_method) {
            $insert_columns[] = 'payment_method';
            $placeholders[] = '?';
            $values[] = 'manual_adjustment';
        }
        
        if ($has_progress_percent) {
            $insert_columns[] = 'progress_percent';
            $placeholders[] = '?';
            $values[] = 100;
        }
        
        if ($has_completed_at) {
            $insert_columns[] = 'completed_at';
            $placeholders[] = 'NOW()';
        }
        
        // Insert transaction record
        $sql = "INSERT INTO transactions (" . implode(', ', $insert_columns) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        
        $transaction_id = $pdo->lastInsertId();
        
        // Update reference_id if column exists
        if ($has_reference_id) {
            $stmt = $pdo->prepare("UPDATE transactions SET reference_id = ? WHERE id = ?");
            $stmt->execute([$transaction_id, $transaction_id]);
        }
        
        // Add to results
        $results[] = [
            'currency' => $currency_code,
            'action' => $action,
            'old_balance' => $current_balance,
            'new_balance' => $new_balance,
            'amount' => $amount,
            'tracking_code' => $tracking_code,
            'message' => "$currency_code: $action $amount. New balance: $new_balance"
        ];
    }
    
    // Create log directory if it doesn't exist
    $log_dir = '../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    // Log the adjustment
    $log_entry = date('Y-m-d H:i:s') . " | Admin ID: " . $_SESSION['user_id'] . 
                 " | Admin: " . ($_SESSION['full_name'] ?? 'Unknown') .
                 " | User ID: $user_id | User: " . $user['email'] .
                 " | Actions: " . json_encode($results) . 
                 " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n";
    
    file_put_contents($log_dir . '/balance_adjustments.log', $log_entry, FILE_APPEND);
    
    // Commit all changes
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => count($results) . ' balance(s) updated successfully',
        'results' => $results,
        'tracking_codes' => array_column($results, 'tracking_code')
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    
    error_log("Balance adjustment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'error_details' => $e->getMessage()
    ]);
}
?>