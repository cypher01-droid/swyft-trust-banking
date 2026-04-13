<?php
// test_user.php - Test user retrieval
session_start();

// Set admin session for testing (REMOVE THIS IN PRODUCTION)
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;

// Include database config
require_once '../includes/db.php';

echo "<h2>Testing User Retrieval</h2>";

try {
    // Test database connection
    echo "Testing database connection...<br>";
    $pdo->query("SELECT 1");
    echo "✅ Database connection successful<br><br>";
    
    // Test users table
    echo "Testing users table...<br>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    echo "✅ Users table exists (Count: {$result['count']})<br><br>";
    
    // Try to get a user
    echo "Testing user retrieval for ID 6...<br>";
    $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE id = ?");
    $stmt->execute([6]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✅ User found:<br>";
        echo "ID: " . $user['id'] . "<br>";
        echo "Email: " . $user['email'] . "<br>";
        echo "Name: " . $user['full_name'] . "<br>";
    } else {
        echo "❌ User with ID 6 not found<br>";
    }
    
    echo "<br>Testing balances for user 6...<br>";
    $stmt = $pdo->prepare("SELECT * FROM balances WHERE user_id = ?");
    $stmt->execute([6]);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($balances) {
        echo "✅ Balances found: " . count($balances) . " currencies<br>";
        foreach ($balances as $balance) {
            echo "- {$balance['currency_code']}: Available={$balance['available_balance']}, Pending={$balance['pending_balance']}<br>";
        }
    } else {
        echo "⚠️ No balances found for user 6<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
}