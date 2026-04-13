<?php
// profile.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// Get user details with account status
$user_details = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            COUNT(DISTINCT b.id) as account_count,
            COUNT(DISTINCT d.id) as deposit_count,
            COUNT(DISTINCT w.id) as withdrawal_count
        FROM users u
        LEFT JOIN balances b ON u.id = b.user_id AND (b.available_balance > 0 OR b.pending_balance > 0)
        LEFT JOIN deposits d ON u.id = d.user_id
        LEFT JOIN withdrawals w ON u.id = w.user_id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$user_id]);
    $user_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_details) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_details = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_details['account_count'] = 0;
        $user_details['deposit_count'] = 0;
        $user_details['withdrawal_count'] = 0;
    }
    
} catch (Exception $e) {
    error_log("User details error: " . $e->getMessage());
    $user_details = [];
}

// ==================== ACCOUNT STATUS & APPEAL HANDLING ====================
$appeal_errors = [];
$appeal_success = false;

// Check if account is suspended/under_review/locked
$account_status = $user_details['account_status'] ?? 'active';
$suspension_reason = $user_details['suspension_reason'] ?? '';
$appeal_status = $user_details['appeal_status'] ?? 'none';
$appeal_message = $user_details['appeal_message'] ?? '';

// Handle appeal submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_appeal'])) {
    $appeal_message = trim($_POST['appeal_message'] ?? '');
    
    if (empty($appeal_message)) {
        $appeal_errors[] = "Please provide a reason for your appeal.";
    } elseif (strlen($appeal_message) < 20) {
        $appeal_errors[] = "Appeal message must be at least 20 characters.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update user appeal status
            $stmt = $pdo->prepare("
                UPDATE users SET 
                appeal_status = 'pending',
                appeal_message = ?,
                appeal_submitted_at = NOW(),
                account_status = 'under_review'
                WHERE id = ?
            ");
            $stmt->execute([$appeal_message, $user_id]);
            
            // Insert into user_appeals table
            $stmt = $pdo->prepare("
                INSERT INTO user_appeals 
                (user_id, appeal_message, status, submitted_at) 
                VALUES (?, ?, 'pending', NOW())
            ");
            $stmt->execute([$user_id, $appeal_message]);
            
            // Notify admins
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, is_admin) 
                SELECT ?, 'appeal', 'New Appeal Submitted', 
                       CONCAT('User ', ?, ' has submitted an appeal'), 1
                FROM users WHERE role = 'admin'
            ");
            $stmt->execute([$user_id, $fullName]);
            
            $pdo->commit();
            $appeal_success = true;
            
            // Refresh user details
            $user_details['appeal_status'] = 'pending';
            $user_details['appeal_message'] = $appeal_message;
            $user_details['account_status'] = 'under_review';
            $account_status = 'under_review';
            $appeal_status = 'pending';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $appeal_errors[] = "Failed to submit appeal: " . $e->getMessage();
            error_log("Appeal submission error: " . $e->getMessage());
        }
    }
}

// ==================== REGULAR PROFILE UPDATES ====================
$update_errors = [];
$update_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    // Only allow profile updates if account is active
    if ($account_status !== 'active') {
        $update_errors[] = "Cannot update profile while account is " . $account_status;
    } else {
        $new_full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $date_of_birth = trim($_POST['date_of_birth'] ?? '');
        
        if (empty($new_full_name)) {
            $update_errors[] = "Full name is required.";
        }
        
        if (!empty($phone) && !preg_match('/^[0-9+\-\s()]{10,20}$/', $phone)) {
            $update_errors[] = "Please enter a valid phone number.";
        }
        
        if (!empty($date_of_birth)) {
            $dob_timestamp = strtotime($date_of_birth);
            $min_age = strtotime('-18 years');
            if ($dob_timestamp > $min_age) {
                $update_errors[] = "You must be at least 18 years old.";
            }
        }
        
        if (empty($update_errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, 
                        phone = ?, 
                        address = ?, 
                        country = ?, 
                        date_of_birth = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $new_full_name,
                    $phone,
                    $address,
                    $country,
                    $date_of_birth ?: null,
                    $user_id
                ]);
                
                $_SESSION['full_name'] = $new_full_name;
                $update_success = true;
                $user_details['full_name'] = $new_full_name;
                $user_details['phone'] = $phone;
                $user_details['address'] = $address;
                $user_details['country'] = $country;
                $user_details['date_of_birth'] = $date_of_birth;
                
            } catch (Exception $e) {
                $update_errors[] = "Update failed: " . $e->getMessage();
                error_log("Profile update error: " . $e->getMessage());
            }
        }
    }
}

// Handle password change (only for active accounts)
$password_errors = [];
$password_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if ($account_status !== 'active') {
        $password_errors[] = "Cannot change password while account is " . $account_status;
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password)) {
            $password_errors[] = "Current password is required.";
        }
        
        if (empty($new_password)) {
            $password_errors[] = "New password is required.";
        } elseif (strlen($new_password) < 8) {
            $password_errors[] = "New password must be at least 8 characters.";
        } elseif (!preg_match('/[A-Z]/', $new_password) || 
                  !preg_match('/[a-z]/', $new_password) || 
                  !preg_match('/[0-9]/', $new_password)) {
            $password_errors[] = "Password must include uppercase, lowercase, and numbers.";
        }
        
        if ($new_password !== $confirm_password) {
            $password_errors[] = "New passwords do not match.";
        }
        
        if (empty($password_errors)) {
            try {
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user || !password_verify($current_password, $user['password_hash'])) {
                    $password_errors[] = "Current password is incorrect.";
                } else {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_hash, $user_id]);
                    $password_success = true;
                    
                    // Send security notification
                    sendSecurityNotification($user_id, 'password_change');
                }
                
            } catch (Exception $e) {
                $password_errors[] = "Password change failed: " . $e->getMessage();
                error_log("Password change error: " . $e->getMessage());
            }
        }
    }
}

// Handle KYC upload (only for active accounts)
$kyc_errors = [];
$kyc_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_kyc'])) {
    if ($account_status !== 'active') {
        $kyc_errors[] = "Cannot submit KYC while account is " . $account_status;
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET kyc_status = 'pending', 
                    kyc_submitted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$user_id]);
            
            $user_details['kyc_status'] = 'pending';
            $kyc_success = true;
            
            // Notify admin
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, is_admin, created_at)
                SELECT ?, 'admin_kyc', 'New KYC Submission', 
                       CONCAT('User ', ?, ' has submitted KYC documents'), 1, NOW()
                FROM users WHERE role = 'admin'
            ");
            $stmt->execute([$user_id, $fullName]);
            
        } catch (Exception $e) {
            $kyc_errors[] = "KYC submission failed: " . $e->getMessage();
            error_log("KYC upload error: " . $e->getMessage());
        }
    }
}

// Handle 2FA toggle (only for active accounts)
$twofa_errors = [];
$twofa_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_2fa'])) {
    if ($account_status !== 'active') {
        $twofa_errors[] = "Cannot change 2FA settings while account is " . $account_status;
    } else {
        $enable_2fa = $_POST['enable_2fa'] === '1';
        
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET two_factor_enabled = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$enable_2fa ? 1 : 0, $user_id]);
            
            $user_details['two_factor_enabled'] = $enable_2fa ? 1 : 0;
            $twofa_success = true;
            
            sendSecurityNotification($user_id, $enable_2fa ? '2fa_enabled' : '2fa_disabled');
            
        } catch (Exception $e) {
            $twofa_errors[] = "2FA update failed: " . $e->getMessage();
            error_log("2FA toggle error: " . $e->getMessage());
        }
    }
}

// Get login history
$login_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            login_time,
            ip_address,
            user_agent,
            success
        FROM login_history 
        WHERE user_id = ? 
        ORDER BY login_time DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $login_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Login history error: " . $e->getMessage());
}

// Security notification function
function sendSecurityNotification($user_id, $type) {
    // Implementation for security notifications
    return true;
}

include './dashboard_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Profile - Zeus Bank</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            background: #0a0a0c;
            font-family: 'Inter', -apple-system, sans-serif;
            color: #fff;
            padding: 20px;
            min-height: 100vh;
        }
        
        .profile-container {
            max-width: 480px;
            margin: 0 auto;
            padding: 80px 0 100px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #9d50ff;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 25px;
            padding: 10px 0;
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 900;
            margin-bottom: 30px;
            color: #fff;
        }
        
        /* Profile Header */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #9d50ff, #6a11cb);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 900;
            color: white;
        }
        
        .profile-info {
            flex: 1;
        }
        
        .profile-name {
            font-size: 1.3rem;
            font-weight: 900;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .profile-email {
            font-size: 0.9rem;
            color: #94a3b8;
            margin-bottom: 10px;
        }
        
        .profile-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        
        /* Status Banner */
        .status-banner {
            background: #1e293b;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid;
        }
        
        .status-banner.active { border-color: #10b981; }
        .status-banner.suspended { border-color: #f59e0b; }
        .status-banner.under_review { border-color: #3b82f6; }
        .status-banner.locked { border-color: #ef4444; }
        
        .status-banner-icon {
            font-size: 2rem;
            margin-bottom: 15px;
        }
        
        .status-banner-title {
            font-size: 1.3rem;
            font-weight: 900;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .status-banner-text {
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        /* Appeal Status */
        .appeal-status {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
        }
        
        .appeal-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .appeal-approved {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .appeal-rejected {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .appeal-message-box {
            background: #0f172a;
            padding: 15px;
            border-radius: 12px;
            margin-top: 15px;
            border-left: 4px solid #f59e0b;
        }
        
        .appeal-form {
            background: #0f172a;
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #111113;
            border-radius: 15px;
            padding: 15px;
            text-align: center;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: 900;
            color: #fff;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 0.7rem;
            color: #94a3b8;
        }
        
        /* Profile Sections */
        .profile-section {
            background: #111113;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid rgba(157, 80, 255, 0.1);
            position: relative;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 800;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            color: #9d50ff;
        }
        
        .edit-btn {
            padding: 8px 16px;
            background: rgba(157, 80, 255, 0.1);
            color: #9d50ff;
            border: none;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .edit-btn:hover {
            background: rgba(157, 80, 255, 0.2);
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 14px 16px;
            background: #0a0a0c;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #fff;
            font-size: 16px;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.3s;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #9d50ff;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #9d50ff, #6a11cb);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            opacity: 0.95;
        }
        
        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Security Settings */
        .security-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 15px;
            margin-bottom: 12px;
        }
        
        .security-info {
            flex: 1;
        }
        
        .security-title {
            font-weight: 700;
            color: #fff;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }
        
        .security-desc {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #64748b;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #9d50ff;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }
        
        input:disabled + .toggle-slider {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Login History */
        .login-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 15px;
            margin-bottom: 12px;
        }
        
        .login-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .login-success {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .login-failed {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .login-details {
            flex: 1;
        }
        
        .login-time {
            font-weight: 700;
            color: #fff;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }
        
        .login-meta {
            font-size: 0.75rem;
            color: #94a3b8;
        }
        
        /* KYC Status */
        .kyc-status {
            padding: 20px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 15px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .kyc-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        
        .kyc-verified { color: #10b981; }
        .kyc-pending { color: #f59e0b; animation: pulse 2s infinite; }
        .kyc-unverified { color: #ef4444; }
        .kyc-rejected { color: #ef4444; }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .kyc-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 8px;
        }
        
        .kyc-desc {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .kyc-requirements {
            text-align: left;
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 20px;
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .requirement-check {
            color: #10b981;
        }
        
        .kyc-pending-info {
            margin-top: 20px;
            padding: 15px;
            background: rgba(245, 158, 11, 0.1);
            border-radius: 12px;
            border-left: 4px solid #f59e0b;
            text-align: left;
        }
        
        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #f59e0b;
        }
        
        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #3b82f6;
        }
        
        /* Disabled State */
        .disabled-section {
            opacity: 0.7;
            pointer-events: none;
            position: relative;
        }
        
        .disabled-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        
        .disabled-message {
            background: #1e293b;
            color: #94a3b8;
            padding: 15px 25px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        /* Danger Zone */
        .danger-zone {
            background: rgba(239, 68, 68, 0.05);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 20px;
            padding: 25px;
            margin-top: 30px;
        }
        
        .danger-title {
            font-size: 1rem;
            font-weight: 800;
            color: #ef4444;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .danger-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 12px;
            margin-bottom: 12px;
        }
        
        .danger-info h4 {
            color: #fff;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .danger-info p {
            color: #94a3b8;
            font-size: 0.8rem;
        }
        
        .danger-btn {
            padding: 10px 20px;
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .danger-btn:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: #111113;
            border-radius: 20px;
            padding: 25px;
            width: 100%;
            max-width: 400px;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            color: #fff;
            font-size: 1.2rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .modal-actions button {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.1);
            color: #94a3b8;
        }
        
        .btn-outline:hover {
            border-color: #9d50ff;
            color: #9d50ff;
        }
        
        /* KYC Modal Specific Styles */
        .kyc-step {
            display: none;
        }
        
        .kyc-step.active {
            display: block;
        }
        
        .document-types {
            display: grid;
            gap: 10px;
            margin: 20px 0;
        }
        
        .document-type {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .document-type:hover {
            border-color: #9d50ff;
            background: rgba(157, 80, 255, 0.05);
        }
        
        .document-type.selected {
            border-color: #9d50ff;
            background: rgba(157, 80, 255, 0.1);
        }
        
        .doc-icon {
            width: 40px;
            height: 40px;
            background: rgba(157, 80, 255, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: #9d50ff;
            font-size: 1.25rem;
        }
        
        .doc-info h5 {
            margin: 0 0 5px 0;
            color: #fff;
        }
        
        .doc-info p {
            margin: 0;
            color: #94a3b8;
            font-size: 0.9rem;
        }
        
        .upload-area {
            margin: 20px 0;
            padding: 20px;
            border: 2px dashed rgba(255,255,255,0.1);
            border-radius: 12px;
            text-align: center;
        }
        
        .upload-preview {
            margin-bottom: 15px;
            min-height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .upload-preview img {
            max-width: 200px;
            max-height: 150px;
            border-radius: 4px;
        }
        
        .pdf-preview {
            padding: 20px;
            background: rgba(0,0,0,0.2);
            border-radius: 8px;
            color: #ef4444;
            font-size: 2rem;
        }
        
        .upload-controls input[type="file"] {
            display: none;
        }
        
        .upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: rgba(157, 80, 255, 0.2);
            color: #9d50ff;
            border: 1px solid rgba(157, 80, 255, 0.3);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-btn:hover {
            background: rgba(157, 80, 255, 0.3);
        }
        
        .upload-note {
            margin-top: 8px;
            font-size: 0.85rem;
            color: #94a3b8;
        }
        
        .upload-tips {
            margin: 20px 0;
            padding: 15px;
            background: rgba(0,0,0,0.2);
            border-radius: 12px;
            border-left: 4px solid #9d50ff;
        }
        
        .upload-tips h5 {
            margin: 0 0 10px 0;
            color: #fff;
        }
        
        .upload-tips ul {
            margin: 0;
            padding-left: 20px;
            color: #94a3b8;
        }
        
        .upload-tips li {
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .success-message {
            text-align: center;
            padding: 30px 20px;
        }
        
        .success-icon {
            font-size: 48px;
            color: #10b981;
            margin-bottom: 20px;
        }
        
        .success-message h4 {
            color: #fff;
            margin: 0 0 10px 0;
        }
        
        .success-message p {
            color: #94a3b8;
            margin: 0 0 10px 0;
        }
        
        .kyc-status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .kyc-unverified {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .kyc-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .kyc-verified {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .kyc-rejected {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        @media (max-width: 480px) {
            .profile-container {
                padding: 60px 0 80px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .status-banner-title {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .document-type {
                flex-direction: column;
                text-align: center;
            }
            
            .doc-icon {
                margin-right: 0;
                margin-bottom: 10px;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-actions button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="profile-container">
        <a href="./" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">My Profile</h1>
        
        <!-- ==================== ACCOUNT STATUS BANNER ==================== -->
        <?php if ($account_status !== 'active'): ?>
            <div class="status-banner <?php echo $account_status; ?>">
                <div class="status-banner-icon">
                    <?php if ($account_status == 'suspended'): ?>
                        <i class="fas fa-ban" style="color: #f59e0b;"></i>
                    <?php elseif ($account_status == 'under_review'): ?>
                        <i class="fas fa-clock" style="color: #3b82f6;"></i>
                    <?php elseif ($account_status == 'locked'): ?>
                        <i class="fas fa-lock" style="color: #ef4444;"></i>
                    <?php endif; ?>
                </div>
                
                <h2 class="status-banner-title">
                    Account <?php echo ucfirst($account_status); ?>
                    <?php if ($appeal_status == 'pending'): ?>
                        <span class="appeal-status appeal-pending">Appeal Pending</span>
                    <?php elseif ($appeal_status == 'approved'): ?>
                        <span class="appeal-status appeal-approved">Appeal Approved</span>
                    <?php elseif ($appeal_status == 'rejected'): ?>
                        <span class="appeal-status appeal-rejected">Appeal Rejected</span>
                    <?php endif; ?>
                </h2>
                
                <div class="status-banner-text">
                    <?php if ($account_status == 'suspended'): ?>
                        <p><strong>Reason for suspension:</strong> <?php echo htmlspecialchars($suspension_reason ?: 'No reason provided'); ?></p>
                        <p>Your account has been suspended. You cannot perform any transactions or update your profile. You may submit an appeal below to request account restoration.</p>
                    <?php elseif ($account_status == 'under_review'): ?>
                        <p>Your appeal is currently under review by our admin team. You will be notified once a decision has been made.</p>
                        <?php if (!empty($appeal_message)): ?>
                            <div class="appeal-message-box">
                                <strong>Your appeal message:</strong><br>
                                <?php echo nl2br(htmlspecialchars($appeal_message)); ?>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($account_status == 'locked'): ?>
                        <p><strong>This account has been permanently locked.</strong></p>
                        <p>Your appeal was rejected and your account cannot be reinstated. This decision is final.</p>
                        <p>Please contact support if you have any questions.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Appeal Form - Only show if suspended and no pending appeal -->
                <?php if ($account_status == 'suspended' && $appeal_status == 'none'): ?>
                    <div class="appeal-form">
                        <h3 style="color: #fff; margin-bottom: 15px; font-size: 1.1rem;">
                            <i class="fas fa-gavel"></i> Submit an Appeal
                        </h3>
                        <p style="color: #94a3b8; margin-bottom: 20px;">
                            If you believe this suspension was in error, please submit an appeal explaining your situation.
                        </p>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="submit_appeal" value="1">
                            
                            <div class="form-group">
                                <label class="form-label">Appeal Message</label>
                                <textarea name="appeal_message" 
                                          class="form-textarea" 
                                          rows="5" 
                                          placeholder="Explain why your account should be restored..."
                                          required></textarea>
                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 5px;">
                                    Minimum 20 characters. Be specific and provide any relevant information.
                                </div>
                            </div>
                            
                            <?php if (!empty($appeal_errors)): ?>
                                <div class="alert alert-error">
                                    <?php foreach ($appeal_errors as $error): ?>
                                        <div><?php echo $error; ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($appeal_success): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> Your appeal has been submitted successfully! Our team will review it shortly.
                                </div>
                            <?php endif; ?>
                            
                            <button type="submit" class="submit-btn">
                                <i class="fas fa-paper-plane"></i> Submit Appeal
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($fullName, 0, 1)); ?>
            </div>
            <div class="profile-info">
                <h2 class="profile-name"><?php echo htmlspecialchars($fullName); ?></h2>
                <p class="profile-email"><?php echo htmlspecialchars($user_details['email'] ?? ''); ?></p>
                <?php if ($account_status == 'active'): ?>
                    <div class="profile-status kyc-status-badge kyc-<?php echo $user_details['kyc_status'] ?? 'unverified'; ?>">
                        <i class="fas fa-user-check"></i>
                        <?php echo ucfirst($user_details['kyc_status'] ?? 'unverified'); ?>
                    </div>
                <?php else: ?>
                    <div class="profile-status" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                        <i class="fas fa-ban"></i>
                        <?php echo ucfirst($account_status); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Stats - Hide for locked accounts -->
        <?php if ($account_status != 'locked'): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $user_details['account_count'] ?? 0; ?></div>
                <div class="stat-label">Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $user_details['deposit_count'] ?? 0; ?></div>
                <div class="stat-label">Deposits</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $user_details['withdrawal_count'] ?? 0; ?></div>
                <div class="stat-label">Withdrawals</div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Success Messages -->
        <?php if ($update_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Profile updated successfully!
            </div>
        <?php endif; ?>
        
        <?php if ($password_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Password changed successfully!
            </div>
        <?php endif; ?>
        
        <?php if ($kyc_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> KYC documents submitted for verification!
            </div>
        <?php endif; ?>
        
        <?php if ($twofa_success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 2FA settings updated!
            </div>
        <?php endif; ?>
        
        <!-- KYC Verification Section -->
        <div class="profile-section <?php echo ($account_status != 'active') ? 'disabled-section' : ''; ?>" style="position: relative;">
            <?php if ($account_status != 'active'): ?>
                <div class="disabled-overlay">
                    <div class="disabled-message">
                        <i class="fas fa-lock"></i> KYC verification unavailable while account is <?php echo $account_status; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-id-card section-icon"></i> Identity Verification
                </h3>
                <span class="profile-status kyc-status-badge kyc-<?php echo $user_details['kyc_status'] ?? 'unverified'; ?>">
                    <?php echo ucfirst($user_details['kyc_status'] ?? 'unverified'); ?>
                </span>
            </div>
            
            <div class="kyc-status">
                <div class="kyc-icon kyc-<?php echo $user_details['kyc_status'] ?? 'unverified'; ?>">
                    <?php if (($user_details['kyc_status'] ?? '') == 'verified'): ?>
                        <i class="fas fa-check-circle"></i>
                    <?php elseif (($user_details['kyc_status'] ?? '') == 'pending'): ?>
                        <i class="fas fa-clock"></i>
                    <?php elseif (($user_details['kyc_status'] ?? '') == 'rejected'): ?>
                        <i class="fas fa-times-circle"></i>
                    <?php else: ?>
                        <i class="fas fa-exclamation-circle"></i>
                    <?php endif; ?>
                </div>
                
                <h4 class="kyc-title">
                    <?php if (($user_details['kyc_status'] ?? '') == 'verified'): ?>
                        Identity Verified
                    <?php elseif (($user_details['kyc_status'] ?? '') == 'pending'): ?>
                        Verification in Progress
                    <?php elseif (($user_details['kyc_status'] ?? '') == 'rejected'): ?>
                        Verification Required
                    <?php else: ?>
                        Identity Not Verified
                    <?php endif; ?>
                </h4>
                
                <p class="kyc-desc">
                    <?php if (($user_details['kyc_status'] ?? '') == 'verified'): ?>
                        Your identity has been verified. You have full access to all banking features.
                    <?php elseif (($user_details['kyc_status'] ?? '') == 'pending'): ?>
                        Your documents are under review. This usually takes 1-2 business days.
                    <?php elseif (($user_details['kyc_status'] ?? '') == 'rejected'): ?>
                        Your verification was rejected. Please re-submit your documents.
                    <?php else: ?>
                        Verify your identity to unlock higher limits and full banking features.
                    <?php endif; ?>
                </p>
                
                <?php if (($user_details['kyc_status'] ?? '') == 'unverified' || ($user_details['kyc_status'] ?? '') == 'rejected'): ?>
                    <button type="button" class="submit-btn" data-toggle="modal" data-target="#kycUploadModal" onclick="showKycUploadForm()">
                        <i class="fas fa-upload"></i> Start Verification
                    </button>
                    
                    <div class="kyc-requirements">
                        <p><strong>You'll need:</strong></p>
                        <div class="requirement-item">
                            <i class="fas fa-check requirement-check"></i>
                            <span>Government-issued ID (Passport, Driver's License)</span>
                        </div>
                        <div class="requirement-item">
                            <i class="fas fa-check requirement-check"></i>
                            <span>Proof of address (Utility bill, Bank statement)</span>
                        </div>
                        <div class="requirement-item">
                            <i class="fas fa-check requirement-check"></i>
                            <span>Selfie with your ID</span>
                        </div>
                    </div>
                <?php elseif (($user_details['kyc_status'] ?? '') == 'pending'): ?>
                    <div class="kyc-pending-info">
                        <p><i class="fas fa-info-circle"></i> Your verification is being processed. Please wait for admin review.</p>
                        <button type="button" class="btn-outline" onclick="showKycUploadForm()">
                            <i class="fas fa-redo"></i> Update Documents
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($kyc_errors)): ?>
                    <div class="alert alert-error">
                        <?php foreach ($kyc_errors as $error): ?>
                            <div><?php echo $error; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- KYC Upload Modal -->
        <div class="modal" id="kycUploadModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-id-card"></i> Upload KYC Documents</h3>
                    <button type="button" class="modal-close" onclick="closeKycModal()">&times;</button>
                </div>
                
                <div class="modal-body">
                    <!-- Document Selection Step -->
                    <div class="kyc-step active" id="step1">
                        <h4>Select Document Type</h4>
                        <p>Choose which document you want to upload</p>
                        
                        <div class="document-types">
                            <div class="document-type" data-type="id_card">
                                <div class="doc-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div class="doc-info">
                                    <h5>ID Card</h5>
                                    <p>National ID Card or Government ID</p>
                                </div>
                            </div>
                            
                            <div class="document-type" data-type="passport">
                                <div class="doc-icon">
                                    <i class="fas fa-passport"></i>
                                </div>
                                <div class="doc-info">
                                    <h5>Passport</h5>
                                    <p>Passport document</p>
                                </div>
                            </div>
                            
                            <div class="document-type" data-type="drivers_license">
                                <div class="doc-icon">
                                    <i class="fas fa-car"></i>
                                </div>
                                <div class="doc-info">
                                    <h5>Driver's License</h5>
                                    <p>Driver's license card</p>
                                </div>
                            </div>
                            
                            <div class="document-type" data-type="proof_of_address">
                                <div class="doc-icon">
                                    <i class="fas fa-home"></i>
                                </div>
                                <div class="doc-info">
                                    <h5>Proof of Address</h5>
                                    <p>Utility bill or bank statement</p>
                                </div>
                            </div>
                            
                            <div class="document-type" data-type="selfie">
                                <div class="doc-icon">
                                    <i class="fas fa-camera"></i>
                                </div>
                                <div class="doc-info">
                                    <h5>Selfie</h5>
                                    <p>Selfie photo with your ID</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="modal-actions">
                            <button type="button" class="btn-outline" onclick="closeKycModal()">
                                Cancel
                            </button>
                            <button type="button" class="submit-btn" onclick="proceedToUpload()" id="proceedBtn" disabled>
                                Next
                            </button>
                        </div>
                    </div>
                    
                    <!-- Document Upload Step -->
                    <div class="kyc-step" id="step2">
                        <h4>Upload <span id="docTypeName"></span></h4>
                        <p>Upload clear photos of your document</p>
                        
                        <form id="kycUploadForm" enctype="multipart/form-data" method="POST" action="">
                            <input type="hidden" name="upload_kyc" value="1">
                            <input type="hidden" name="document_type" id="documentType">
                            
                            <div class="upload-area" id="frontUploadArea">
                                <div class="upload-preview" id="frontPreview"></div>
                                <div class="upload-controls">
                                    <input type="file" name="document_front" id="documentFront" accept="image/*,.pdf" required>
                                    <label for="documentFront" class="upload-btn">
                                        <i class="fas fa-upload"></i>
                                        <span>Upload Front Side</span>
                                    </label>
                                    <p class="upload-note">Max size: 5MB • JPG, PNG, PDF</p>
                                </div>
                            </div>
                            
                            <div class="upload-area" id="backUploadArea" style="display: none;">
                                <div class="upload-preview" id="backPreview"></div>
                                <div class="upload-controls">
                                    <input type="file" name="document_back" id="documentBack" accept="image/*,.pdf">
                                    <label for="documentBack" class="upload-btn">
                                        <i class="fas fa-upload"></i>
                                        <span>Upload Back Side</span>
                                    </label>
                                    <p class="upload-note">Max size: 5MB • JPG, PNG, PDF</p>
                                </div>
                            </div>
                            
                            <div class="upload-tips">
                                <h5><i class="fas fa-lightbulb"></i> Upload Tips:</h5>
                                <ul>
                                    <li>Ensure all text is clear and readable</li>
                                    <li>Use good lighting and avoid glare</li>
                                    <li>Take photo straight on (not at an angle)</li>
                                    <li>Make sure all edges are visible</li>
                                </ul>
                            </div>
                            
                            <div class="modal-actions">
                                <button type="button" class="btn-outline" onclick="backToStep1()">
                                    Back
                                </button>
                                <button type="submit" class="submit-btn" id="uploadSubmit">
                                    Submit Document
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Success Step -->
                    <div class="kyc-step" id="step3">
                        <div class="success-message">
                            <div class="success-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h4>Document Uploaded Successfully!</h4>
                            <p>Your document has been submitted for verification.</p>
                            <p>You will receive a notification once it's reviewed.</p>
                            
                            <div class="modal-actions">
                                <button type="button" class="submit-btn" onclick="location.reload()">
                                    Done
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Personal Information -->
        <div class="profile-section <?php echo ($account_status != 'active') ? 'disabled-section' : ''; ?>" style="position: relative;">
            <?php if ($account_status != 'active'): ?>
                <div class="disabled-overlay">
                    <div class="disabled-message">
                        <i class="fas fa-lock"></i> Profile editing unavailable while account is <?php echo $account_status; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-user section-icon"></i> Personal Information
                </h3>
                <button class="edit-btn" onclick="toggleEdit('personalInfo')" <?php echo ($account_status != 'active') ? 'disabled' : ''; ?>>
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
            
            <form method="POST" action="" id="personalInfo" style="display: none;">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" 
                           name="full_name" 
                           class="form-input" 
                           value="<?php echo htmlspecialchars($user_details['full_name'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" 
                               name="phone" 
                               class="form-input" 
                               value="<?php echo htmlspecialchars($user_details['phone'] ?? ''); ?>"
                               placeholder="+1 (123) 456-7890">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" 
                               name="date_of_birth" 
                               class="form-input" 
                               value="<?php echo htmlspecialchars($user_details['date_of_birth'] ?? ''); ?>"
                               max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Country</label>
                    <select name="country" class="form-select">
                        <option value="">Select Country</option>
                        <option value="AF" <?php echo ($user_details['country'] ?? '') == 'AF' ? 'selected' : ''; ?>>Afghanistan</option>
                        <option value="AL" <?php echo ($user_details['country'] ?? '') == 'AL' ? 'selected' : ''; ?>>Albania</option>
                        <option value="DZ" <?php echo ($user_details['country'] ?? '') == 'DZ' ? 'selected' : ''; ?>>Algeria</option>
                        <option value="US" <?php echo ($user_details['country'] ?? '') == 'US' ? 'selected' : ''; ?>>United States</option>
                        <option value="UK" <?php echo ($user_details['country'] ?? '') == 'UK' ? 'selected' : ''; ?>>United Kingdom</option>
                        <option value="CA" <?php echo ($user_details['country'] ?? '') == 'CA' ? 'selected' : ''; ?>>Canada</option>
                        <option value="AU" <?php echo ($user_details['country'] ?? '') == 'AU' ? 'selected' : ''; ?>>Australia</option>
                        <!-- Add more countries as needed -->
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" 
                              class="form-textarea" 
                              placeholder="Your full address"><?php echo htmlspecialchars($user_details['address'] ?? ''); ?></textarea>
                </div>
                
                <?php if (!empty($update_errors)): ?>
                    <div class="alert alert-error">
                        <?php foreach ($update_errors as $error): ?>
                            <div><?php echo $error; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <button type="submit" class="submit-btn">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </form>
            
            <div id="personalInfoDisplay">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <div>
                        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 5px;">Phone</div>
                        <div style="font-weight: 600; color: #fff;">
                            <?php echo !empty($user_details['phone']) ? htmlspecialchars($user_details['phone']) : 'Not provided'; ?>
                        </div>
                    </div>
                    
                    <div>
                        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 5px;">Date of Birth</div>
                        <div style="font-weight: 600; color: #fff;">
                            <?php echo !empty($user_details['date_of_birth']) ? date('M d, Y', strtotime($user_details['date_of_birth'])) : 'Not provided'; ?>
                        </div>
                    </div>
                    
                    <div>
                        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 5px;">Country</div>
                        <div style="font-weight: 600; color: #fff;">
                            <?php echo !empty($user_details['country']) ? htmlspecialchars($user_details['country']) : 'Not provided'; ?>
                        </div>
                    </div>
                    
                    <div>
                        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 5px;">Member Since</div>
                        <div style="font-weight: 600; color: #fff;">
                            <?php echo !empty($user_details['created_at']) ? date('M d, Y', strtotime($user_details['created_at'])) : 'N/A'; ?>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($user_details['address'])): ?>
                <div style="margin-top: 20px;">
                    <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 5px;">Address</div>
                    <div style="font-weight: 600; color: #fff; line-height: 1.5;">
                        <?php echo htmlspecialchars($user_details['address']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Security Settings -->
        <div class="profile-section <?php echo ($account_status != 'active') ? 'disabled-section' : ''; ?>" style="position: relative;">
            <?php if ($account_status != 'active'): ?>
                <div class="disabled-overlay">
                    <div class="disabled-message">
                        <i class="fas fa-lock"></i> Security settings unavailable while account is <?php echo $account_status; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-shield-alt section-icon"></i> Security Settings
                </h3>
            </div>
            
            <!-- Password Change -->
            <div class="security-item">
                <div class="security-info">
                    <div class="security-title">Password</div>
                    <div class="security-desc">Last changed: <?php echo date('M d, Y'); ?></div>
                </div>
                <button class="edit-btn" onclick="toggleEdit('passwordChange')" <?php echo ($account_status != 'active') ? 'disabled' : ''; ?>>
                    Change
                </button>
            </div>
            
            <form method="POST" action="" id="passwordChange" style="display: none; margin-top: 20px;">
                <input type="hidden" name="change_password" value="1">
                
                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-input" required>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 5px;">
                        Minimum 8 characters with uppercase, lowercase, and numbers
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-input" required>
                </div>
                
                <?php if (!empty($password_errors)): ?>
                    <div class="alert alert-error">
                        <?php foreach ($password_errors as $error): ?>
                            <div><?php echo $error; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <button type="submit" class="submit-btn">
                    <i class="fas fa-key"></i> Update Password
                </button>
            </form>
            
            <!-- 2FA -->
            <div class="security-item">
                <div class="security-info">
                    <div class="security-title">Two-Factor Authentication</div>
                    <div class="security-desc">Add an extra layer of security to your account</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" 
                           <?php echo ($user_details['two_factor_enabled'] ?? 0) ? 'checked' : ''; ?>
                           onchange="toggle2FA(this)"
                           <?php echo ($account_status != 'active') ? 'disabled' : ''; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            
            <!-- Login History -->
            <?php if (!empty($login_history)): ?>
            <div style="margin-top: 25px;">
                <h4 style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 15px;">Recent Login Activity</h4>
                <?php foreach ($login_history as $login): ?>
                <div class="login-item">
                    <div class="login-icon <?php echo $login['success'] ? 'login-success' : 'login-failed'; ?>">
                        <i class="fas fa-<?php echo $login['success'] ? 'check' : 'times'; ?>"></i>
                    </div>
                    <div class="login-details">
                        <div class="login-time">
                            <?php echo date('M d, H:i', strtotime($login['login_time'])); ?>
                        </div>
                        <div class="login-meta">
                            <?php echo $login['ip_address']; ?> • 
                            <?php echo $login['success'] ? 'Successful' : 'Failed'; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Danger Zone - Only show for active accounts -->
        <?php if ($account_status == 'active'): ?>
        <div class="danger-zone">
            <h3 class="danger-title">
                <i class="fas fa-exclamation-triangle"></i> Danger Zone
            </h3>
            
            <div class="danger-item">
                <div class="danger-info">
                    <h4>Close Account</h4>
                    <p>Permanently delete your account and all data</p>
                </div>
                <button class="danger-btn" onclick="showCloseAccountModal()">
                    Close Account
                </button>
            </div>
            
            <div class="danger-item">
                <div class="danger-info">
                    <h4>Delete All Data</h4>
                    <p>Remove all your transaction history and personal data</p>
                </div>
                <button class="danger-btn" onclick="showDeleteDataModal()">
                    Delete Data
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modals -->
    <div class="modal" id="closeAccountModal">
        <div class="modal-content">
            <h3 style="color: #ef4444; margin-bottom: 15px;">
                <i class="fas fa-exclamation-triangle"></i> Close Account
            </h3>
            <p style="color: #94a3b8; margin-bottom: 20px; line-height: 1.5;">
                Are you sure you want to close your account? This action cannot be undone. 
                All your data, balances, and transaction history will be permanently deleted.
            </p>
            <div style="display: flex; gap: 10px;">
                <button onclick="closeModal('closeAccountModal')" 
                        style="flex: 1; padding: 12px; background: rgba(255,255,255,0.05); color: #94a3b8; border: none; border-radius: 10px; font-weight: 700;">
                    Cancel
                </button>
                <button onclick="confirmCloseAccount()" 
                        style="flex: 1; padding: 12px; background: #ef4444; color: white; border: none; border-radius: 10px; font-weight: 700;">
                    Yes, Close Account
                </button>
            </div>
        </div>
    </div>
    
    <div class="modal" id="deleteDataModal">
        <div class="modal-content">
            <h3 style="color: #ef4444; margin-bottom: 15px;">
                <i class="fas fa-exclamation-triangle"></i> Delete All Data
            </h3>
            <p style="color: #94a3b8; margin-bottom: 20px; line-height: 1.5;">
                This will permanently delete all your transaction history and personal data. 
                Your account will remain active but empty. This action cannot be undone.
            </p>
            <div style="display: flex; gap: 10px;">
                <button onclick="closeModal('deleteDataModal')" 
                        style="flex: 1; padding: 12px; background: rgba(255,255,255,0.05); color: #94a3b8; border: none; border-radius: 10px; font-weight: 700;">
                    Cancel
                </button>
                <button onclick="confirmDeleteData()" 
                        style="flex: 1; padding: 12px; background: #ef4444; color: white; border: none; border-radius: 10px; font-weight: 700;">
                    Delete All Data
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // KYC Modal variables
        let selectedDocumentType = '';
        let requiresBackUpload = false;
        
        // Document type selection
        document.querySelectorAll('.document-type').forEach(el => {
            el.addEventListener('click', function() {
                // Remove selection from all
                document.querySelectorAll('.document-type').forEach(d => {
                    d.classList.remove('selected');
                });
                
                // Add selection to clicked
                this.classList.add('selected');
                
                // Enable proceed button
                const proceedBtn = document.getElementById('proceedBtn');
                if (proceedBtn) proceedBtn.disabled = false;
                
                // Store selected type
                selectedDocumentType = this.dataset.type;
                
                // Check if this document type requires back upload
                requiresBackUpload = ['id_card', 'drivers_license'].includes(selectedDocumentType);
            });
        });
        
        function proceedToUpload() {
            if (!selectedDocumentType) return;
            
            // Update UI for step 2
            document.getElementById('step1').classList.remove('active');
            document.getElementById('step2').classList.add('active');
            
            // Set document type in form
            document.getElementById('documentType').value = selectedDocumentType;
            
            // Update document type name
            const docNames = {
                'id_card': 'ID Card',
                'passport': 'Passport',
                'drivers_license': 'Driver\'s License',
                'proof_of_address': 'Proof of Address',
                'selfie': 'Selfie'
            };
            document.getElementById('docTypeName').textContent = docNames[selectedDocumentType];
            
            // Show/hide back upload based on document type
            if (requiresBackUpload) {
                document.getElementById('backUploadArea').style.display = 'block';
                document.getElementById('documentBack').required = true;
            } else {
                document.getElementById('backUploadArea').style.display = 'none';
                document.getElementById('documentBack').required = false;
            }
        }
        
        function backToStep1() {
            document.getElementById('step2').classList.remove('active');
            document.getElementById('step1').classList.add('active');
            resetUploadForm();
        }
        
        function resetUploadForm() {
            const form = document.getElementById('kycUploadForm');
            if (form) form.reset();
            
            const frontPreview = document.getElementById('frontPreview');
            const backPreview = document.getElementById('backPreview');
            if (frontPreview) frontPreview.innerHTML = '';
            if (backPreview) backPreview.innerHTML = '';
            
            selectedDocumentType = '';
            
            // Remove selection
            document.querySelectorAll('.document-type').forEach(d => {
                d.classList.remove('selected');
            });
            
            // Disable proceed button
            const proceedBtn = document.getElementById('proceedBtn');
            if (proceedBtn) proceedBtn.disabled = true;
        }
        
        // File preview for front document
        const documentFront = document.getElementById('documentFront');
        if (documentFront) {
            documentFront.addEventListener('change', function(e) {
                previewFile(e.target, 'frontPreview');
            });
        }
        
        // File preview for back document
        const documentBack = document.getElementById('documentBack');
        if (documentBack) {
            documentBack.addEventListener('change', function(e) {
                previewFile(e.target, 'backPreview');
            });
        }
        
        function previewFile(input, previewId) {
            const preview = document.getElementById(previewId);
            if (!preview) return;
            
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    if (file.type.startsWith('image/')) {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.style.maxWidth = '200px';
                        img.style.maxHeight = '150px';
                        preview.appendChild(img);
                    } else if (file.type === 'application/pdf') {
                        const pdfDiv = document.createElement('div');
                        pdfDiv.className = 'pdf-preview';
                        pdfDiv.innerHTML = `<i class="fas fa-file-pdf"></i> PDF Document`;
                        preview.appendChild(pdfDiv);
                    }
                };
                
                reader.readAsDataURL(file);
            }
        }
        
        // Handle KYC form submission
        const kycForm = document.getElementById('kycUploadForm');
        if (kycForm) {
            kycForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const submitBtn = document.getElementById('uploadSubmit');
                const originalText = submitBtn ? submitBtn.innerHTML : 'Submit Document';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
                }
                
                // For demo purposes, show success after 1 second
                // In production, you would submit via AJAX
                setTimeout(() => {
                    document.getElementById('step2').classList.remove('active');
                    document.getElementById('step3').classList.add('active');
                    
                    // Also submit the form via POST to trigger PHP handler
                    const formData = new FormData(kycForm);
                    fetch('', {
                        method: 'POST',
                        body: formData
                    }).catch(error => console.error('KYC submission error:', error));
                }, 1000);
            });
        }
        
        // Modal functions
        function showKycUploadForm() {
            const modal = document.getElementById('kycUploadModal');
            if (modal) modal.classList.add('active');
        }
        
        function closeKycModal() {
            const modal = document.getElementById('kycUploadModal');
            if (modal) {
                modal.classList.remove('active');
                // Reset to step 1
                document.querySelectorAll('.kyc-step').forEach(step => step.classList.remove('active'));
                const step1 = document.getElementById('step1');
                if (step1) step1.classList.add('active');
                resetUploadForm();
            }
        }
        
        // Toggle edit forms
        function toggleEdit(formId) {
            const form = document.getElementById(formId);
            const display = document.getElementById(formId + 'Display');
            
            if (form && display) {
                if (form.style.display === 'none' || form.style.display === '') {
                    form.style.display = 'block';
                    display.style.display = 'none';
                } else {
                    form.style.display = 'none';
                    display.style.display = 'block';
                }
            }
        }
        
        // Toggle 2FA
        function toggle2FA(checkbox) {
            const enable2fa = checkbox.checked ? '1' : '0';
            
            // Submit form
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const input1 = document.createElement('input');
            input1.type = 'hidden';
            input1.name = 'toggle_2fa';
            input1.value = '1';
            form.appendChild(input1);
            
            const input2 = document.createElement('input');
            input2.type = 'hidden';
            input2.name = 'enable_2fa';
            input2.value = enable2fa;
            form.appendChild(input2);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Modal functions
        function showCloseAccountModal() {
            const modal = document.getElementById('closeAccountModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function showDeleteDataModal() {
            const modal = document.getElementById('deleteDataModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }
        
        function confirmCloseAccount() {
            if (confirm('Are you absolutely sure? This cannot be undone!')) {
                alert('Account closure request submitted. An admin will contact you shortly.');
                closeModal('closeAccountModal');
            }
        }
        
        function confirmDeleteData() {
            if (confirm('This will delete ALL your data. Are you sure?')) {
                alert('Data deletion request submitted. This may take 24-48 hours.');
                closeModal('deleteDataModal');
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }
        
        // Auto-hide success messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-success');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
            
            // Set max date for date of birth (18 years ago)
            const dobInput = document.querySelector('[name="date_of_birth"]');
            if (dobInput) {
                const maxDate = new Date();
                maxDate.setFullYear(maxDate.getFullYear() - 18);
                dobInput.max = maxDate.toISOString().split('T')[0];
            }
            
            // Auto-scroll to appeal form if there are errors
            <?php if (!empty($appeal_errors) || $appeal_success): ?>
                const appealForm = document.querySelector('.appeal-form');
                if (appealForm) appealForm.scrollIntoView({ behavior: 'smooth' });
            <?php endif; ?>
        });
    </script>
</body>
</html>