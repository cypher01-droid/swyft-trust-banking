<?php
// fix_session.php
session_start();
require_once '../includes/db.php';

echo "<h2>Fixing Session</h2>";

// Check current session
echo "Current session user_id: " . ($_SESSION['user_id'] ?? 'not set') . "<br>";
echo "Current session role: " . ($_SESSION['role'] ?? 'not set') . "<br>";

// Find the actual admin user
$stmt = $pdo->query("SELECT id, email, full_name, role FROM users WHERE role = 'admin' LIMIT 1");
$admin = $stmt->fetch();

if ($admin) {
    echo "Found admin user:<br>";
    echo "- ID: {$admin['id']}<br>";
    echo "- Email: {$admin['email']}<br>";
    echo "- Name: {$admin['full_name']}<br>";
    echo "- Role: {$admin['role']}<br>";
    
    // Update session
    $_SESSION['user_id'] = $admin['id'];
    $_SESSION['full_name'] = $admin['full_name'];
    $_SESSION['email'] = $admin['email'];
    $_SESSION['role'] = $admin['role'];
    
    echo "<h3 style='color: green;'>✅ Session updated!</h3>";
    echo "New session user_id: " . $_SESSION['user_id'] . "<br>";
    
    // Test if it works
    echo "<h3>Testing...</h3>";
    echo "<a href='test_audit_logs.php'>Test Audit Logs Again</a><br>";
    echo "<a href='index.php'>Go to Admin Dashboard</a>";
} else {
    echo "❌ No admin user found in database!";
}