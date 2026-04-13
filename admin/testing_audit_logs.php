<?php
// test_audit_logs.php
require_once '../includes/db.php';

echo "<h2>Testing Audit Logs</h2>";

// Check foreign key constraint
echo "<h3>1. Checking foreign key constraint:</h3>";
try {
    $stmt = $pdo->query("SHOW CREATE TABLE audit_logs");
    $result = $stmt->fetch();
    echo "<pre>" . htmlspecialchars($result['Create Table']) . "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Check if admin user exists
echo "<h3>2. Checking admin users:</h3>";
$stmt = $pdo->query("SELECT id, email, role FROM users WHERE role = 'admin'");
$admins = $stmt->fetchAll();

if ($admins) {
    echo "Found " . count($admins) . " admin users:<br>";
    foreach ($admins as $admin) {
        echo "- ID: {$admin['id']}, Email: {$admin['email']}, Role: {$admin['role']}<br>";
    }
} else {
    echo "No admin users found!<br>";
}

// Check current session admin
echo "<h3>3. Checking session:</h3>";
session_start();
echo "Session admin_id: " . ($_SESSION['user_id'] ?? 'Not set') . "<br>";
echo "Session role: " . ($_SESSION['role'] ?? 'Not set') . "<br>";

// Test inserting audit log
echo "<h3>4. Testing audit log insert:</h3>";
try {
    $adminId = $_SESSION['user_id'] ?? 1;
    
    // Check if this admin_id exists in users table
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$adminId]);
    $userExists = $stmt->fetch();
    
    if ($userExists) {
        echo "✅ Admin user ID $adminId exists in users table<br>";
        
        // Try to insert audit log
        $stmt = $pdo->prepare("INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, ip_address, user_agent) 
                              VALUES (?, 'test', 'test', 1, '127.0.0.1', 'test')");
        $stmt->execute([$adminId]);
        echo "✅ Audit log insert successful<br>";
    } else {
        echo "❌ Admin user ID $adminId does NOT exist in users table!<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}