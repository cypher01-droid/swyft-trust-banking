<?php
// admin_logout.php
session_start();
require_once 'includes/db.php';

if (isset($_SESSION['user_id'])) {
    // Clear admin session token if exists
    if (isset($_COOKIE['admin_token'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM admin_sessions WHERE token = ?");
            $stmt->execute([$_COOKIE['admin_token']]);
        } catch (Exception $e) {
            // Log error but continue
            error_log("Logout token cleanup error: " . $e->getMessage());
        }
        
        // Clear cookie
        setcookie('admin_token', '', time() - 3600, '/');
    }
    
    // Log logout activity
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO admin_activity_log (admin_id, action, details, ip_address, user_agent) 
                VALUES (?, 'logout', 'Admin logged out', ?, ?)
            ");
            $stmt->execute([$_SESSION['user_id'], $ip, $user_agent]);
        } catch (Exception $e) {
            error_log("Logout logging error: " . $e->getMessage());
        }
    }
    
    // Clear all session data
    session_unset();
    session_destroy();
}

// Redirect to login
header("Location: admin-login.php");
exit();