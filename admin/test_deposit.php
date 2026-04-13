<?php
// test_deposit.php
session_start();
require_once '../includes/db.php';

echo "<h2>Testing Deposit System</h2>";

// Set admin session for testing
$_SESSION['user_id'] = 5; // Your actual admin ID
$_SESSION['role'] = 'admin';

// Check deposits table
echo "<h3>1. Checking deposits table:</h3>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM deposits");
    $result = $stmt->fetch();
    echo "Total deposits: " . $result['count'] . "<br>";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM deposits WHERE status = 'pending'");
    $result = $stmt->fetch();
    echo "Pending deposits: " . $result['count'] . "<br>";
    
    // Show pending deposits
    $stmt = $pdo->query("SELECT d.*, u.email FROM deposits d 
                        JOIN users u ON d.user_id = u.id 
                        WHERE d.status = 'pending' 
                        LIMIT 5");
    $deposits = $stmt->fetchAll();
    
    if ($deposits) {
        echo "<h4>Pending deposits details:</h4>";
        foreach ($deposits as $deposit) {
            echo "ID: {$deposit['id']}, User: {$deposit['email']}, Amount: {$deposit['amount']} {$deposit['currency_code']}<br>";
        }
    } else {
        echo "No pending deposits found<br>";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

// Test the processDeposit function
echo "<h3>2. Testing processDeposit function:</h3>";

// Include your AdminFunctions class
require_once 'index.php'; // This includes the class definition

$admin = new AdminFunctions($pdo);

// Test with a sample deposit (use an actual pending deposit ID)
if (isset($deposits[0])) {
    $testDepositId = $deposits[0]['id'];
    echo "Testing with deposit ID: $testDepositId<br>";
    
    // Get deposit details before
    $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id = ?");
    $stmt->execute([$testDepositId]);
    $before = $stmt->fetch();
    
    echo "Before - Status: " . $before['status'] . "<br>";
    echo "Before - Amount: " . $before['amount'] . " " . $before['currency_code'] . "<br>";
    
    // Get user balance before
    $stmt = $pdo->prepare("SELECT available_balance FROM balances 
                          WHERE user_id = ? AND currency_code = ?");
    $stmt->execute([$before['user_id'], $before['currency_code']]);
    $balanceBefore = $stmt->fetch();
    echo "User balance before: " . ($balanceBefore['available_balance'] ?? '0') . "<br>";
    
    // Try to approve
    echo "<h4>Attempting to approve deposit...</h4>";
    $result = $admin->processDeposit($testDepositId, 'approved', 'Test approval');
    
    if ($result) {
        echo "✅ processDeposit returned true<br>";
        
        // Check after status
        $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id = ?");
        $stmt->execute([$testDepositId]);
        $after = $stmt->fetch();
        echo "After - Status: " . ($after['status'] ?? 'not found') . "<br>";
        
        // Check user balance after
        $stmt = $pdo->prepare("SELECT available_balance FROM balances 
                              WHERE user_id = ? AND currency_code = ?");
        $stmt->execute([$before['user_id'], $before['currency_code']]);
        $balanceAfter = $stmt->fetch();
        echo "User balance after: " . ($balanceAfter['available_balance'] ?? '0') . "<br>";
        
        if ($after['status'] === 'approved') {
            echo "✅ Deposit approved successfully!<br>";
            
            $balanceIncrease = ($balanceAfter['available_balance'] ?? 0) - ($balanceBefore['available_balance'] ?? 0);
            if (abs($balanceIncrease - $before['amount']) < 0.000001) {
                echo "✅ User balance updated correctly (+{$before['amount']})<br>";
            } else {
                echo "❌ User balance NOT updated correctly!<br>";
            }
        }
    } else {
        echo "❌ processDeposit returned false<br>";
    }
} else {
    echo "No pending deposits to test with<br>";
}

// Test the form submission
echo "<h3>3. Testing form submission:</h3>";
echo "<form method='POST' action='index.php' style='border: 1px solid #ccc; padding: 20px;'>
    <input type='hidden' name='action' value='process_deposit'>
    <input type='hidden' name='deposit_id' value='{$testDepositId ?? ''}'>
    <input type='hidden' name='status' value='approved'>
    <textarea name='admin_notes'>Test notes</textarea><br>
    <button type='submit'>Test Submit Form</button>
</form>";

echo "<hr><a href='index.php?section=deposits'>Go to Deposits Management</a>";