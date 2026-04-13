<?php 
session_start(); 
require_once '../includes/db.php';  

if (!isset($_SESSION['user_id'])) { 
    header("Location: ../login.php"); 
    exit(); 
} 

$user_id = $_SESSION['user_id']; 
$fullName = $_SESSION['full_name'];  

// ==================== CHECK ACCOUNT STATUS ====================
$account_status = 'active';
$suspension_reason = '';
$appeal_status = 'none';
$appeal_message = '';
$suspension_details = [];

try {
    $stmt = $pdo->prepare("
        SELECT account_status, suspension_reason, appeal_status, appeal_message,
               suspended_at, locked_at, restored_at
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_status) {
        $account_status = $user_status['account_status'] ?? 'active';
        $suspension_reason = $user_status['suspension_reason'] ?? '';
        $appeal_status = $user_status['appeal_status'] ?? 'none';
        $appeal_message = $user_status['appeal_message'] ?? '';
        $suspended_at = $user_status['suspended_at'] ?? null;
        $locked_at = $user_status['locked_at'] ?? null;
        
        // Get additional suspension history
        $stmt = $pdo->prepare("
            SELECT * FROM user_suspension_history 
            WHERE user_id = ? 
            ORDER BY performed_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $suspension_details = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Status check error: " . $e->getMessage());
}

// ==================== HANDLE APPEAL SUBMISSION ====================
$appeal_errors = [];
$appeal_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_appeal'])) {
    $appeal_text = trim($_POST['appeal_message'] ?? '');
    
    if (empty($appeal_text)) {
        $appeal_errors[] = "Please provide your appeal message.";
    } elseif (strlen($appeal_text) < 20) {
        $appeal_errors[] = "Appeal message must be at least 20 characters.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update user record
            $stmt = $pdo->prepare("
                UPDATE users SET 
                account_status = 'under_review',
                appeal_status = 'pending',
                appeal_message = ?,
                appeal_submitted_at = NOW(),
                updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$appeal_text, $user_id]);
            
            // Insert into appeals table
            $stmt = $pdo->prepare("
                INSERT INTO user_appeals 
                (user_id, appeal_message, status, submitted_at) 
                VALUES (?, ?, 'pending', NOW())
            ");
            $stmt->execute([$user_id, $appeal_text]);
            
            // Log the appeal submission
            $stmt = $pdo->prepare("
                INSERT INTO user_suspension_history 
                (user_id, action, reason_public, performed_by) 
                VALUES (?, 'appeal_submitted', ?, ?)
            ");
            $stmt->execute([$user_id, $appeal_text, $user_id]);
            
            // Notify admins
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, is_admin, created_at)
                SELECT ?, 'appeal', 'New Appeal Submitted', 
                       CONCAT('User ', ?, ' has submitted an appeal'), 1, NOW()
                FROM users WHERE role = 'admin'
            ");
            $stmt->execute([$user_id, $fullName]);
            
            $pdo->commit();
            
            $appeal_success = true;
            $account_status = 'under_review';
            $appeal_status = 'pending';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $appeal_errors[] = "Failed to submit appeal. Please try again.";
            error_log("Appeal error: " . $e->getMessage());
        }
    }
}

// ==================== IF ACCOUNT IS LOCKED - SHOW LOCKED MESSAGE AND EXIT ====================
if ($account_status === 'locked') {
    include './dashboard_header.php';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Account Locked - Zeus Bank</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                background: #0a0a0c;
                font-family: 'Inter', sans-serif;
                color: #fff;
                padding: 20px;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .locked-container {
                max-width: 480px;
                margin: 0 auto;
                text-align: center;
                padding: 40px 20px;
            }
            .lock-icon {
                font-size: 5rem;
                color: #ef4444;
                margin-bottom: 25px;
                animation: pulse 2s infinite;
            }
            @keyframes pulse {
                0% { opacity: 1; transform: scale(1); }
                50% { opacity: 0.8; transform: scale(1.05); }
                100% { opacity: 1; transform: scale(1); }
            }
            h1 {
                font-size: 2rem;
                font-weight: 900;
                color: #ef4444;
                margin-bottom: 20px;
            }
            .message-box {
                background: #1e293b;
                border-left: 4px solid #ef4444;
                padding: 25px;
                border-radius: 16px;
                margin-bottom: 30px;
                text-align: left;
            }
            .message-box h3 {
                color: #fff;
                margin-bottom: 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .message-box p {
                color: #94a3b8;
                line-height: 1.6;
                margin-bottom: 15px;
            }
            .reason-box {
                background: #0f172a;
                padding: 15px;
                border-radius: 12px;
                margin: 15px 0;
                border-left: 4px solid #f59e0b;
            }
            .support-btn {
                display: inline-block;
                padding: 16px 32px;
                background: linear-gradient(135deg, #9d50ff, #6a11cb);
                color: white;
                text-decoration: none;
                border-radius: 12px;
                font-weight: 700;
                margin-top: 20px;
                transition: transform 0.2s;
            }
            .support-btn:hover {
                transform: translateY(-2px);
            }
            .logout-btn {
                display: inline-block;
                padding: 12px 24px;
                background: rgba(239, 68, 68, 0.1);
                color: #ef4444;
                text-decoration: none;
                border-radius: 10px;
                font-weight: 600;
                margin-top: 20px;
                margin-left: 10px;
                border: 1px solid rgba(239, 68, 68, 0.3);
            }
        </style>
    </head>
    <body>
        <div class="locked-container">
            <div class="lock-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h1>Account Permanently Locked</h1>
            
            <div class="message-box">
                <h3><i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> This Account Has Been Locked</h3>
                
                <?php if (!empty($suspension_reason)): ?>
                <div class="reason-box">
                    <strong style="color: #ef4444; display: block; margin-bottom: 8px;">Reason for Lock:</strong>
                    <?php echo htmlspecialchars($suspension_reason); ?>
                </div>
                <?php endif; ?>
                
                <p>Your account has been permanently locked. This decision is final and your account cannot be reinstated.</p>
                <p style="margin-bottom: 0;">If you believe this was an error, please contact our support team for assistance.</p>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <a href="mailto:support@swyfttrust.com" class="support-btn">
                    <i class="fas fa-envelope"></i> Contact Support
                </a>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    include './dashboard_footer.php';
    exit();
}

// ==================== IF ACCOUNT IS SUSPENDED/UNDER REVIEW - SHOW RESTRICTED DASHBOARD ====================
if ($account_status === 'suspended' || $account_status === 'under_review') {
    include './dashboard_header.php';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Account Restricted - Zeus Bank</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                background: #0a0a0c;
                font-family: 'Inter', sans-serif;
                color: #fff;
                padding: 20px;
                min-height: 100vh;
            }
            .restricted-container {
                max-width: 480px;
                margin: 0 auto;
                padding: 80px 0 40px;
            }
            .status-banner {
                background: #1e293b;
                border-radius: 24px;
                padding: 30px 25px;
                margin-bottom: 30px;
                border-left: 4px solid <?php echo $account_status === 'suspended' ? '#f59e0b' : '#3b82f6'; ?>;
                box-shadow: 8px 8px 16px #050507, -8px -8px 16px #121217;
            }
            .status-icon {
                font-size: 3rem;
                margin-bottom: 20px;
                color: <?php echo $account_status === 'suspended' ? '#f59e0b' : '#3b82f6'; ?>;
            }
            .status-title {
                font-size: 1.8rem;
                font-weight: 900;
                margin-bottom: 15px;
                color: <?php echo $account_status === 'suspended' ? '#f59e0b' : '#3b82f6'; ?>;
            }
            .appeal-status-badge {
                display: inline-block;
                padding: 8px 16px;
                background: <?php 
                    echo $appeal_status === 'pending' ? 'rgba(245, 158, 11, 0.1)' : 
                         ($appeal_status === 'approved' ? 'rgba(16, 185, 129, 0.1)' : 
                         ($appeal_status === 'rejected' ? 'rgba(239, 68, 68, 0.1)' : 'rgba(100, 116, 139, 0.1)'));
                ?>;
                color: <?php 
                    echo $appeal_status === 'pending' ? '#f59e0b' : 
                         ($appeal_status === 'approved' ? '#10b981' : 
                         ($appeal_status === 'rejected' ? '#ef4444' : '#94a3b8'));
                ?>;
                border-radius: 30px;
                font-size: 0.9rem;
                font-weight: 700;
                margin-bottom: 20px;
            }
            .info-card {
                background: #111113;
                border-radius: 20px;
                padding: 25px;
                margin-bottom: 25px;
                border: 1px solid rgba(255, 255, 255, 0.05);
            }
            .info-title {
                font-size: 1rem;
                font-weight: 800;
                color: #94a3b8;
                margin-bottom: 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .reason-box {
                background: #0f172a;
                padding: 20px;
                border-radius: 16px;
                margin-bottom: 20px;
                border-left: 4px solid #f59e0b;
            }
            .appeal-form {
                background: #0f172a;
                border-radius: 20px;
                padding: 25px;
                margin-top: 20px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-label {
                display: block;
                font-size: 0.9rem;
                font-weight: 600;
                color: #94a3b8;
                margin-bottom: 8px;
            }
            .form-textarea {
                width: 100%;
                padding: 16px;
                background: #0a0a0c;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 12px;
                color: #fff;
                font-size: 16px;
                font-family: 'Inter', sans-serif;
                min-height: 120px;
                resize: vertical;
            }
            .form-textarea:focus {
                outline: none;
                border-color: #9d50ff;
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
                transition: transform 0.2s;
            }
            .submit-btn:hover {
                transform: translateY(-2px);
            }
            .submit-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
                transform: none;
            }
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
            .action-buttons {
                display: flex;
                gap: 15px;
                margin-top: 30px;
            }
            .logout-btn {
                flex: 1;
                padding: 14px;
                background: rgba(239, 68, 68, 0.1);
                color: #ef4444;
                border: 1px solid rgba(239, 68, 68, 0.3);
                border-radius: 12px;
                font-weight: 600;
                text-decoration: none;
                text-align: center;
                transition: all 0.3s;
            }
            .logout-btn:hover {
                background: rgba(239, 68, 68, 0.2);
            }
            .support-btn {
                flex: 1;
                padding: 14px;
                background: rgba(59, 130, 246, 0.1);
                color: #3b82f6;
                border: 1px solid rgba(59, 130, 246, 0.3);
                border-radius: 12px;
                font-weight: 600;
                text-decoration: none;
                text-align: center;
                transition: all 0.3s;
            }
            .support-btn:hover {
                background: rgba(59, 130, 246, 0.2);
            }
            .back-link {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #9d50ff;
                text-decoration: none;
                font-weight: 600;
                margin-bottom: 25px;
            }
            .timeline {
                margin-top: 30px;
            }
            .timeline-item {
                display: flex;
                gap: 15px;
                padding: 15px 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            }
            .timeline-icon {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 0.9rem;
            }
            .timeline-content {
                flex: 1;
            }
            .timeline-title {
                font-weight: 700;
                color: #fff;
                margin-bottom: 4px;
            }
            .timeline-date {
                font-size: 0.75rem;
                color: #64748b;
            }
            @media (max-width: 480px) {
                .restricted-container { padding: 60px 0 40px; }
                .action-buttons { flex-direction: column; }
            }
        </style>
    </head>
    <body>
        <div class="restricted-container">
            <a href="../logout.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Logout
            </a>
            
            <div class="status-banner">
                <div class="status-icon">
                    <?php if ($account_status === 'suspended'): ?>
                        <i class="fas fa-ban"></i>
                    <?php else: ?>
                        <i class="fas fa-clock"></i>
                    <?php endif; ?>
                </div>
                
                <div class="status-title">
                    Account <?php echo $account_status === 'suspended' ? 'Suspended' : 'Under Review'; ?>
                </div>
                
                <div class="appeal-status-badge">
                    <?php if ($appeal_status === 'pending'): ?>
                        <i class="fas fa-hourglass-half"></i> Appeal Pending Review
                    <?php elseif ($appeal_status === 'approved'): ?>
                        <i class="fas fa-check-circle"></i> Appeal Approved
                    <?php elseif ($appeal_status === 'rejected'): ?>
                        <i class="fas fa-times-circle"></i> Appeal Rejected
                    <?php else: ?>
                        <i class="fas fa-exclamation-circle"></i> No Appeal Submitted
                    <?php endif; ?>
                </div>
                
                <div class="reason-box">
                    <strong style="display: block; margin-bottom: 10px; color: #f59e0b;">
                        <i class="fas fa-info-circle"></i> Reason for Suspension:
                    </strong>
                    <p style="color: #e2e8f0; line-height: 1.6; margin-bottom: 10px;">
                        <?php echo !empty($suspension_reason) ? htmlspecialchars($suspension_reason) : 'No reason provided'; ?>
                    </p>
                    <?php if (!empty($suspended_at)): ?>
                    <small style="color: #94a3b8;">
                        Suspended on: <?php echo date('F j, Y \a\t g:i A', strtotime($suspended_at)); ?>
                    </small>
                    <?php endif; ?>
                </div>
                
                <div style="color: #94a3b8; line-height: 1.6;">
                    <?php if ($account_status === 'suspended'): ?>
                        <p>Your account has been temporarily suspended. During this time:</p>
                        <ul style="margin-left: 20px; margin-top: 10px; list-style-type: disc;">
                            <li>You cannot make deposits or withdrawals</li>
                            <li>You cannot trade or transfer funds</li>
                            <li>Your balances are frozen and cannot be accessed</li>
                            <li>You can submit an appeal to request restoration</li>
                        </ul>
                    <?php else: ?>
                        <p>Your appeal is currently under review by our compliance team.</p>
                        <p style="margin-top: 10px;">This process typically takes 1-2 business days. You will receive an email notification once a decision has been made.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($appeal_message) && $appeal_status === 'pending'): ?>
            <div class="info-card">
                <div class="info-title">
                    <i class="fas fa-paper-plane" style="color: #f59e0b;"></i> Your Submitted Appeal
                </div>
                <div style="background: #0f172a; padding: 20px; border-radius: 16px; border-left: 4px solid #f59e0b;">
                    <p style="color: #e2e8f0; line-height: 1.6; font-style: italic;">
                        "<?php echo nl2br(htmlspecialchars($appeal_message)); ?>"
                    </p>
                    <small style="display: block; margin-top: 15px; color: #94a3b8;">
                        Submitted: <?php echo date('F j, Y \a\t g:i A', strtotime($suspension_details['performed_at'] ?? 'now')); ?>
                    </small>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($account_status === 'suspended' && $appeal_status === 'none'): ?>
            <div class="appeal-form">
                <h3 style="color: #fff; margin-bottom: 15px; font-size: 1.2rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-gavel" style="color: #9d50ff;"></i> Submit an Appeal
                </h3>
                <p style="color: #94a3b8; margin-bottom: 25px; line-height: 1.6;">
                    If you believe this suspension was in error, please submit an appeal explaining your situation. 
                    Our compliance team will review your case and respond within 1-2 business days.
                </p>
                
                <form method="POST" action="">
                    <input type="hidden" name="submit_appeal" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Your Appeal Message</label>
                        <textarea name="appeal_message" 
                                  class="form-textarea" 
                                  placeholder="Please explain why your account should be restored. Include any relevant details or evidence..."
                                  required></textarea>
                        <small style="display: block; margin-top: 8px; color: #64748b; font-size: 0.75rem;">
                            <i class="fas fa-info-circle"></i> Minimum 20 characters. Be specific and professional.
                        </small>
                    </div>
                    
                    <?php if (!empty($appeal_errors)): ?>
                        <div class="alert alert-error">
                            <?php foreach ($appeal_errors as $error): ?>
                                <div><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($appeal_success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> Your appeal has been submitted successfully! 
                            Our team will review it within 1-2 business days.
                        </div>
                    <?php endif; ?>
                    
                    <div style="display: flex; gap: 15px; margin-top: 25px;">
                        <button type="submit" class="submit-btn">
                            <i class="fas fa-paper-plane"></i> Submit Appeal
                        </button>
                    </div>
                </form>
                
                <div style="margin-top: 25px; padding: 15px; background: rgba(157, 80, 255, 0.05); border-radius: 12px;">
                    <h4 style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-lightbulb" style="color: #9d50ff;"></i> Tips for a Successful Appeal:
                    </h4>
                    <ul style="color: #94a3b8; font-size: 0.8rem; list-style: none; padding-left: 0;">
                        <li style="margin-bottom: 8px;">• Be honest and transparent about your situation</li>
                        <li style="margin-bottom: 8px;">• Provide any evidence that supports your case</li>
                        <li style="margin-bottom: 8px;">• Explain why the suspension might be in error</li>
                        <li style="margin-bottom: 8px;">• Stay professional and respectful in your communication</li>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($appeal_status === 'pending'): ?>
            <div class="info-card">
                <div class="info-title">
                    <i class="fas fa-hourglass-half" style="color: #f59e0b;"></i> Appeal Status: Pending Review
                </div>
                <div style="text-align: center; padding: 20px;">
                    <div style="display: inline-block; width: 60px; height: 60px; border-radius: 50%; border: 3px solid #f59e0b; border-top-color: transparent; animation: spin 1s linear infinite; margin-bottom: 15px;"></div>
                    <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
                    <p style="color: #e2e8f0; margin-bottom: 10px;">Your appeal is being reviewed by our compliance team.</p>
                    <p style="color: #94a3b8; font-size: 0.9rem;">We'll notify you via email once a decision has been made.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($appeal_status === 'rejected'): ?>
            <div class="info-card">
                <div class="info-title">
                    <i class="fas fa-times-circle" style="color: #ef4444;"></i> Appeal Status: Rejected
                </div>
                <div class="alert alert-error" style="margin-bottom: 0;">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Your appeal has been reviewed and rejected. Further appeals may not be considered.
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($suspension_details)): ?>
            <div class="info-card">
                <div class="info-title">
                    <i class="fas fa-history" style="color: #94a3b8;"></i> Recent Activity
                </div>
                <div class="timeline">
                    <?php if ($suspended_at): ?>
                    <div class="timeline-item">
                        <div class="timeline-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">Account Suspended</div>
                            <div class="timeline-date"><?php echo date('F j, Y \a\t g:i A', strtotime($suspended_at)); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($appeal_status === 'pending'): ?>
                    <div class="timeline-item">
                        <div class="timeline-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                            <i class="fas fa-paper-plane"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">Appeal Submitted</div>
                            <div class="timeline-date"><?php echo date('F j, Y \a\t g:i A'); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="action-buttons">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
                <a href="mailto:support@zeusbank.com?subject=Account%20Suspension%20-%20User%20ID%3A%20<?php echo $user_id; ?>" class="support-btn">
                    <i class="fas fa-headset"></i> Contact Support
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    include './dashboard_footer.php';
    exit();
}

// ==================== IF ACCOUNT IS ACTIVE - SHOW FULL DASHBOARD ====================

// Fetch ALL user balances
function getAllUserBalances($user_id, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                currency_code, 
                available_balance, 
                pending_balance,
                (available_balance + pending_balance) as total_balance
            FROM balances 
            WHERE user_id = ? 
            ORDER BY total_balance DESC
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching balances: " . $e->getMessage());
        return [];
    }
}

// Fetch specific currency balances
function getCurrencyBalance($user_id, $currency_code, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                available_balance,
                pending_balance,
                (available_balance + pending_balance) as total_balance
            FROM balances 
            WHERE user_id = ? AND currency_code = ?
        ");
        $stmt->execute([$user_id, $currency_code]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['available_balance' => 0, 'pending_balance' => 0, 'total_balance' => 0];
    } catch (Exception $e) {
        error_log("Error fetching $currency_code balance: " . $e->getMessage());
        return ['available_balance' => 0, 'pending_balance' => 0, 'total_balance' => 0];
    }
}

// Get all balances
$all_balances = getAllUserBalances($user_id, $pdo);

// Get specific balances for main display
$usd_balance_data = getCurrencyBalance($user_id, 'USD', $pdo);
$eur_balance_data = getCurrencyBalance($user_id, 'EUR', $pdo);
$btc_balance_data = getCurrencyBalance($user_id, 'BTC', $pdo);
$jpy_balance_data = getCurrencyBalance($user_id, 'JPY', $pdo);
$gbp_balance_data = getCurrencyBalance($user_id, 'GBP', $pdo);
$mxn_balance_data = getCurrencyBalance($user_id, 'MXN', $pdo);
$sek_balance_data = getCurrencyBalance($user_id, 'SEK', $pdo);

// Extract values with defaults
$usd_available = $usd_balance_data['available_balance'] ?? 0;
$usd_pending = $usd_balance_data['pending_balance'] ?? 0;
$usd_balance = $usd_balance_data['total_balance'] ?? 0;

$eur_available = $eur_balance_data['available_balance'] ?? 0;
$eur_pending = $eur_balance_data['pending_balance'] ?? 0;
$eur_balance = $eur_balance_data['total_balance'] ?? 0;

$btc_available = $btc_balance_data['available_balance'] ?? 0;
$btc_pending = $btc_balance_data['pending_balance'] ?? 0;
$btc_balance = $btc_balance_data['total_balance'] ?? 0;

$jpy_available = $jpy_balance_data['available_balance'] ?? 0;
$jpy_pending = $jpy_balance_data['pending_balance'] ?? 0;
$jpy_balance = $jpy_balance_data['total_balance'] ?? 0;

$gbp_available = $gbp_balance_data['available_balance'] ?? 0;
$gbp_pending = $gbp_balance_data['pending_balance'] ?? 0;
$gbp_balance = $gbp_balance_data['total_balance'] ?? 0;

$mxn_available = $mxn_balance_data['available_balance'] ?? 0;
$mxn_pending = $mxn_balance_data['pending_balance'] ?? 0;
$mxn_balance = $mxn_balance_data['total_balance'] ?? 0;

$sek_available = $sek_balance_data['available_balance'] ?? 0;
$sek_pending = $sek_balance_data['pending_balance'] ?? 0;
$sek_balance = $sek_balance_data['total_balance'] ?? 0;

// Get BTC price in USD (from API or default)
function getBTCPrice() {
    try {
        $url = "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd";
        $data = @file_get_contents($url);
        if ($data) {
            $prices = json_decode($data, true);
            return $prices['bitcoin']['usd'] ?? 45000;
        }
    } catch (Exception $e) {
        error_log("BTC price API error: " . $e->getMessage());
    }
    return 45000; // Default fallback
}

$btc_price_usd = getBTCPrice();
$btc_value_usd = $btc_balance * $btc_price_usd;

// Calculate portfolio analytics
function calculatePortfolioAnalytics($user_id, $pdo, $all_balances) {
    $analytics = [
        'total_available_usd' => 0,
        'total_pending_usd' => 0,
        'top_currency' => ['code' => 'USD', 'percentage' => 0],
        'currency_count' => 0
    ];
    
    if (empty($all_balances)) {
        return $analytics;
    }
    
    // Get BTC price for conversion
    $btc_price = getBTCPrice();
    
    // Calculate totals in USD
    foreach($all_balances as $balance) {
        if ($balance['currency_code'] === 'BTC') {
            $analytics['total_available_usd'] += $balance['available_balance'] * $btc_price;
            $analytics['total_pending_usd'] += $balance['pending_balance'] * $btc_price;
        } else {
            // For fiat currencies, assume 1:1 with USD for display
            // In production, you'd use actual exchange rates
            $analytics['total_available_usd'] += $balance['available_balance'];
            $analytics['total_pending_usd'] += $balance['pending_balance'];
        }
    }
    
    // Find top currency
    $analytics['currency_count'] = count($all_balances);
    if ($analytics['currency_count'] > 0) {
        $analytics['top_currency']['code'] = $all_balances[0]['currency_code'];
        
        // Calculate percentage
        $total_portfolio = $analytics['total_available_usd'] + $analytics['total_pending_usd'];
        if ($total_portfolio > 0) {
            if ($all_balances[0]['currency_code'] === 'BTC') {
                $top_value = ($all_balances[0]['available_balance'] + $all_balances[0]['pending_balance']) * $btc_price;
            } else {
                $top_value = $all_balances[0]['available_balance'] + $all_balances[0]['pending_balance'];
            }
            $analytics['top_currency']['percentage'] = ($top_value / $total_portfolio) * 100;
        }
    }
    
    return $analytics;
}

$portfolio_analytics = calculatePortfolioAnalytics($user_id, $pdo, $all_balances);

// Fetch live crypto prices
function getLiveCryptoPrices() {
    $cryptos = [
        'bitcoin' => ['symbol' => 'BTC', 'name' => 'Bitcoin'],
        'ethereum' => ['symbol' => 'ETH', 'name' => 'Ethereum'],
        'ripple' => ['symbol' => 'XRP', 'name' => 'Ripple'],
        'cardano' => ['symbol' => 'ADA', 'name' => 'Cardano'],
        'solana' => ['symbol' => 'SOL', 'name' => 'Solana'],
        'polkadot' => ['symbol' => 'DOT', 'name' => 'Polkadot'],
        'dogecoin' => ['symbol' => 'DOGE', 'name' => 'Dogecoin'],
        'litecoin' => ['symbol' => 'LTC', 'name' => 'Litecoin'],
        'chainlink' => ['symbol' => 'LINK', 'name' => 'Chainlink'],
        'stellar' => ['symbol' => 'XLM', 'name' => 'Stellar']
    ];
    
    $crypto_prices = [];
    
    try {
        $url = "https://api.coingecko.com/api/v3/simple/price?ids=" . 
               implode(',', array_keys($cryptos)) . 
               "&vs_currencies=usd&include_24h_change=true";
        $data = @file_get_contents($url);
        
        if ($data) {
            $prices = json_decode($data, true);
            
            foreach ($cryptos as $id => $info) {
                if (isset($prices[$id])) {
                    $crypto_prices[] = [
                        'symbol' => $info['symbol'],
                        'name' => $info['name'],
                        'price' => $prices[$id]['usd'],
                        'change_24h' => $prices[$id]['usd_24h_change'] ?? 0
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Crypto API error: " . $e->getMessage());
    }
    
    if (empty($crypto_prices)) {
        $crypto_prices = [
            ['symbol' => 'BTC', 'name' => 'Bitcoin', 'price' => rand(40000, 50000), 'change_24h' => rand(-300, 500)/100],
            ['symbol' => 'ETH', 'name' => 'Ethereum', 'price' => rand(2500, 3500), 'change_24h' => rand(-200, 400)/100],
            ['symbol' => 'XRP', 'name' => 'Ripple', 'price' => rand(0.5, 1.0), 'change_24h' => rand(-150, 300)/100],
            ['symbol' => 'ADA', 'name' => 'Cardano', 'price' => rand(0.4, 0.6), 'change_24h' => rand(-100, 200)/100],
            ['symbol' => 'SOL', 'name' => 'Solana', 'price' => rand(80, 120), 'change_24h' => rand(-200, 600)/100],
            ['symbol' => 'DOT', 'name' => 'Polkadot', 'price' => rand(6, 9), 'change_24h' => rand(-100, 150)/100],
            ['symbol' => 'DOGE', 'name' => 'Dogecoin', 'price' => rand(0.1, 0.2), 'change_24h' => rand(-200, 300)/100],
            ['symbol' => 'LTC', 'name' => 'Litecoin', 'price' => rand(70, 90), 'change_24h' => rand(-100, 100)/100],
            ['symbol' => 'LINK', 'name' => 'Chainlink', 'price' => rand(12, 18), 'change_24h' => rand(-150, 250)/100],
            ['symbol' => 'XLM', 'name' => 'Stellar', 'price' => rand(0.1, 0.15), 'change_24h' => rand(-50, 50)/100]
        ];
    }
    
    return $crypto_prices;
}

// Fetch live exchange rates
function getLiveExchangeRates() {
    $base_currency = 'USD';
    $target_currencies = [
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'JPY' => 'Japanese Yen',
        'CAD' => 'Canadian Dollar',
        'AUD' => 'Australian Dollar',
        'CHF' => 'Swiss Franc',
        'CNY' => 'Chinese Yuan',
        'INR' => 'Indian Rupee',
        'MXN' => 'Mexican Peso',
        'ZAR' => 'South African Rand'
    ];
    
    $exchange_rates = [];
    
    try {
        // You can add your API key here if you get one
        $api_key = 'a18195f8f1e6fe4bdff6dc0b'; // Your ExchangeRate-API key if you have one
        if (!empty($api_key)) {
            $url = "https://v6.exchangerate-api.com/v6/{$api_key}/latest/{$base_currency}";
            $data = @file_get_contents($url);
            
            if ($data) {
                $rates = json_decode($data, true);
                if ($rates['result'] === 'success') {
                    foreach ($target_currencies as $currency => $name) {
                        if (isset($rates['conversion_rates'][$currency])) {
                            $exchange_rates[] = [
                                'from' => $base_currency,
                                'to' => $currency,
                                'name' => $name,
                                'rate' => $rates['conversion_rates'][$currency],
                                'change' => rand(-20, 20) / 100
                            ];
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Exchange rate API error: " . $e->getMessage());
    }
    
    if (empty($exchange_rates)) {
        $static_rates = [
            'EUR' => 0.92, 'GBP' => 0.79, 'JPY' => 148.50, 'CAD' => 1.35,
            'AUD' => 1.50, 'CHF' => 0.88, 'CNY' => 7.18, 'INR' => 83.20,
            'MXN' => 17.25, 'ZAR' => 18.75
        ];
        
        foreach ($static_rates as $currency => $rate) {
            $exchange_rates[] = [
                'from' => 'USD',
                'to' => $currency,
                'name' => $target_currencies[$currency],
                'rate' => $rate + (rand(-50, 50) / 1000), // Add small random variation
                'change' => rand(-20, 20) / 100
            ];
        }
    }
    
    return $exchange_rates;
}

$crypto_prices = getLiveCryptoPrices();
$exchange_rates = getLiveExchangeRates();

include './dashboard_header.php'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>SWYFT TRUST BANK</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* MOBILE OPTIMIZATION */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        html, body {
            width: 100%;
            height: 100%;
            overflow-x: hidden;
            touch-action: pan-y;
            -webkit-overflow-scrolling: touch;
        }

        body {
            background: #0a0a0c;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #fff;
            font-size: 16px;
            line-height: 1.5;
        }

        /* MAIN CONTAINER */
        .zeus-container {
            padding: 80px 16px 120px;
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            min-height: 100vh;
        }

        /* UPDATE PORTFOLIO CARD FOR 3-COLUMN BALANCE DISPLAY */
        .portfolio-card {
            width: 100%;
            padding: 20px 16px;
            background: #0a0a0c;
            border-radius: 24px;
            margin-bottom: 25px;
            box-shadow: 8px 8px 16px #050507, -8px -8px 16px #121217;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }

        /* 3-COLUMN BALANCE LAYOUT */
        .balance-display {
            display: flex;
            justify-content: space-between;
            align-items: stretch;
            gap: 12px;
            margin: 20px 0;
        }

        .balance-column {
            flex: 1;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 18px;
            padding: 16px 8px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.05);
            min-height: 110px;
        }

        .usd-balance { border-top: 3px solid #10b981; }
        .eur-balance { border-top: 3px solid #3b82f6; }
        .btc-balance { border-top: 3px solid #f59e0b; }

        .currency-label {
            font-size: 0.75rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 8px;
        }

        .currency-amount {
            font-size: clamp(1.1rem, 5vw, 1.4rem);
            font-weight: 900;
            line-height: 1.2;
            margin: 5px 0;
        }

        .usd-amount { color: #10b981; }
        .eur-amount { color: #3b82f6; }
        .btc-amount { color: #f59e0b; }

        .currency-subtext {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 6px;
            font-weight: 600;
        }

        .usd-amount::before { content: "$"; font-size: 0.9em; margin-right: 2px; }
        .eur-amount::before { content: "€"; font-size: 0.9em; margin-right: 2px; }
        .btc-amount::before { content: "₿"; font-size: 0.9em; margin-right: 2px; }

        .portfolio-card h1 {
            font-size: clamp(1.5rem, 7vw, 1.8rem);
            text-align: center;
            margin: 0 0 15px 0;
            color: #fff;
        }

        .portfolio-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 16px;
            padding: 14px 10px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.03);
            min-height: 85px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-value {
            font-size: clamp(1.1rem, 4vw, 1.3rem);
            font-weight: 900;
            color: #fff;
            margin: 5px 0;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 5px;
        }

        .stat-subtext {
            font-size: 0.65rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .market-panel {
            background: #0a0a0c;
            border-radius: 22px;
            padding: 18px;
            margin-bottom: 20px;
            box-shadow: 6px 6px 12px #050507, -6px -6px 12px #121217;
            border: 1px solid rgba(255, 255, 255, 0.02);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .panel-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: #9d50ff;
            letter-spacing: 1px;
        }

        .refresh-btn {
            background: rgba(157, 80, 255, 0.1);
            border: none;
            color: #9d50ff;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .market-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .market-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
        }

        .market-item:last-child { border-bottom: none; }

        .coin-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .coin-symbol {
            font-weight: 800;
            color: #fff;
            font-size: 0.9rem;
        }

        .coin-name {
            font-size: 0.75rem;
            color: #64748b;
        }

        .price-info {
            text-align: right;
        }

        .current-price {
            font-weight: 800;
            color: #fff;
            font-size: 0.9rem;
        }

        .price-change {
            font-size: 0.75rem;
            font-weight: 700;
        }

        .positive { color: #10b981; }
        .negative { color: #ef4444; }

        .balance-stack {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin: 25px 0;
        }

        .neo-card {
            width: 100%;
            background: #0a0a0c;
            border-radius: 22px;
            padding: 18px;
            box-shadow: 6px 6px 12px #050507, -6px -6px 12px #121217;
            border: 1px solid rgba(255, 255, 255, 0.02);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .label-mini {
            font-size: 0.65rem;
            font-weight: 800;
            color: #64748b;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .card-amount {
            font-size: clamp(1.4rem, 6vw, 1.6rem);
            font-weight: 900;
            color: #fff;
            margin: 6px 0;
            letter-spacing: -0.5px;
        }

        .inner-vault {
            margin-top: 12px;
            padding: 10px;
            background: #0a0a0c;
            border-radius: 16px;
            box-shadow: inset 3px 3px 6px #050507, inset -3px -3px 6px #121217;
            display: flex;
            justify-content: space-between;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 25px;
        }

        .neo-btn {
            background: #0a0a0c;
            border-radius: 18px;
            padding: 14px 10px;
            text-align: center;
            text-decoration: none;
            box-shadow: 5px 5px 10px #050507, -5px -5px 10px #121217;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            min-height: 75px;
            justify-content: center;
            transition: all 0.1s ease;
            -webkit-user-select: none;
            user-select: none;
        }

        .neo-btn:active {
            box-shadow: inset 3px 3px 6px #050507, inset -3px -3px 6px #121217;
            transform: scale(0.98);
        }

        .btn-label {
            font-size: 0.7rem;
            font-weight: 800;
            color: #9d50ff;
            letter-spacing: 0.5px;
        }

        .btn-icon {
            width: 22px;
            height: 22px;
            color: #9d50ff;
        }

        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: #10b981;
            color: white;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            z-index: 1000;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            max-width: 300px;
        }

        .notification.error {
            background: #ef4444;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        @media screen and (max-width: 400px) {
            .balance-display { flex-direction: column; gap: 15px; }
            .balance-column { width: 100%; min-height: 95px; padding: 14px 12px; }
            .currency-amount { font-size: 1.3rem; }
            .portfolio-stats { grid-template-columns: 1fr; gap: 12px; }
            .stat-card { min-height: 75px; padding: 12px 10px; }
        }

        @media screen and (max-width: 320px) {
            .zeus-container { padding: 70px 12px 100px; }
            .action-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
        }

        @media screen and (min-width: 481px) {
            .zeus-container { max-width: 480px; }
        }
    </style>
</head>
<body>

<div class="zeus-container">
    <!-- 1. PORTFOLIO HEADER WITH BALANCES -->
    <div class="portfolio-card">
        <h1>Portfolio Balances</h1>
        
        <div class="balance-display">
            <!-- USD Balance -->
            <div class="balance-column usd-balance">
                <div class="currency-label">USD Balance</div>
                <div class="currency-amount usd-amount">
                    <?php echo number_format($usd_balance, 2); ?>
                </div>
                <div class="currency-subtext">
                    Available: $<?php echo number_format($usd_available, 2); ?>
                    <?php if ($usd_pending > 0): ?>
                    <br>Pending: $<?php echo number_format($usd_pending, 2); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- EUR Balance -->
            <div class="balance-column eur-balance">
                <div class="currency-label">EUR Balance</div>
                <div class="currency-amount eur-amount">
                    <?php echo number_format($eur_balance, 2); ?>
                </div>
                <div class="currency-subtext">
                    Available: €<?php echo number_format($eur_available, 2); ?>
                    <?php if ($eur_pending > 0): ?>
                    <br>Pending: €<?php echo number_format($eur_pending, 2); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- BTC Balance -->
            <div class="balance-column btc-balance">
                <div class="currency-label">Bitcoin</div>
                <div class="currency-amount btc-amount">
                    <?php echo number_format($btc_balance, 8); ?>
                </div>
                <div class="currency-subtext">
                    ≈ $<?php echo number_format($btc_value_usd, 2); ?>
                    <?php if ($btc_pending > 0): ?>
                    <br>Pending: ₿<?php echo number_format($btc_pending, 8); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- PORTFOLIO STATS -->
        <div class="portfolio-stats">
            <!-- 1. AVAILABLE BALANCE -->
            <div class="stat-card" style="border-top: 3px solid #10b981;">
                <div class="stat-label">Available</div>
                <div class="stat-value" style="color: #10b981;">
                    $<?php echo number_format($portfolio_analytics['total_available_usd'], 2); ?>
                </div>
                <div class="stat-subtext">Ready to Trade</div>
            </div>
            
            <!-- 2. PENDING BALANCE -->
            <div class="stat-card" style="border-top: 3px solid #f59e0b;">
                <div class="stat-label">Pending</div>
                <div class="stat-value" style="color: #f59e0b;">
                    $<?php echo number_format($portfolio_analytics['total_pending_usd'], 2); ?>
                </div>
                <div class="stat-subtext">In Process</div>
            </div>
            
            <!-- 3. CURRENCIES -->
            <div class="stat-card" style="border-top: 3px solid #8b5cf6;">
                <div class="stat-label">Currencies</div>
                <div class="stat-value" style="color: #8b5cf6; font-size: 1.1rem;">
                    <?php echo $portfolio_analytics['currency_count']; ?>
                </div>
                <div class="stat-subtext">
                    <?php echo $portfolio_analytics['top_currency']['code']; ?> (<?php echo number_format($portfolio_analytics['top_currency']['percentage'], 1); ?>%)
                </div>
            </div>
        </div>
    </div>
    
    <!-- 2. LIVE CRYPTO PRICES PANEL -->
    <div class="market-panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-coins"></i> CRYPTO MARKETS</h3>
            <div>
                <span style="font-size: 0.7rem; color: #64748b; margin-right: 10px;">
                    Updated: <span id="cryptoUpdated"><?php echo date('H:i'); ?></span>
                </span>
                <button class="refresh-btn" onclick="refreshCrypto()" id="cryptoRefresh">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        <div class="market-grid" id="cryptoGrid">
            <?php foreach($crypto_prices as $crypto): ?>
            <div class="market-item">
                <div class="coin-info">
                    <div class="coin-symbol"><?php echo $crypto['symbol']; ?></div>
                    <div class="coin-name"><?php echo $crypto['name']; ?></div>
                </div>
                <div class="price-info">
                    <div class="current-price">$<?php echo number_format($crypto['price'], ($crypto['price'] > 1000 ? 0 : 2)); ?></div>
                    <div class="price-change <?php echo $crypto['change_24h'] >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo ($crypto['change_24h'] >= 0 ? '▲' : '▼') . number_format(abs($crypto['change_24h']), 2); ?>%
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- 3. LIVE EXCHANGE RATES PANEL -->
    <div class="market-panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-exchange-alt"></i> EXCHANGE RATES (USD)</h3>
            <div>
                <span style="font-size: 0.7rem; color: #64748b; margin-right: 10px;">
                    Updated: <span id="ratesUpdated"><?php echo date('H:i'); ?></span>
                </span>
                <button class="refresh-btn" onclick="refreshRates()" id="ratesRefresh">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        <div class="market-grid" id="ratesGrid">
            <?php foreach($exchange_rates as $rate): ?>
            <div class="market-item">
                <div class="coin-info">
                    <div class="coin-symbol"><?php echo $rate['from']; ?>/<?php echo $rate['to']; ?></div>
                    <div class="coin-name"><?php echo $rate['name']; ?></div>
                </div>
                <div class="price-info">
                    <div class="current-price"><?php echo number_format($rate['rate'], 4); ?></div>
                    <div class="price-change <?php echo $rate['change'] >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo ($rate['change'] >= 0 ? '+' : '') . number_format($rate['change'], 2); ?>%
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- 4. ALL YOUR ASSETS -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin: 25px 0 12px 4px;">
        <p class="label-mini">YOUR ASSETS</p>
        <span style="font-size: 0.7rem; color: #9d50ff; font-weight: 600;">
            Currencies: <?php echo count($all_balances); ?>
        </span>
    </div>
    
    <div class="balance-stack">
        <?php if(count($all_balances) > 0): ?>
            <?php 
            // Calculate total portfolio value in USD for percentages
            $total_portfolio_usd = 0;
            foreach($all_balances as $bal) {
                if ($bal['currency_code'] === 'BTC') {
                    $total_portfolio_usd += $bal['total_balance'] * $btc_price_usd;
                } else {
                    $total_portfolio_usd += $bal['total_balance'];
                }
            }
            ?>
            
            <?php foreach($all_balances as $bal): 
                $currency_total = $bal['available_balance'] + $bal['pending_balance'];
                
                // Calculate USD value
                if ($bal['currency_code'] === 'BTC') {
                    $usd_value = $currency_total * $btc_price_usd;
                } else {
                    $usd_value = $currency_total;
                }
                
                $percentage = $total_portfolio_usd > 0 ? ($usd_value / $total_portfolio_usd) * 100 : 0;
                
                // Currency-specific colors
                $currency_colors = [
                    'USD' => '#10b981',
                    'MXN' => '#ec4899',
                    'SEK' => '#10b981',
                    'EUR' => '#3b82f6', 
                    'BTC' => '#f59e0b',
                    'ETH' => '#8b5cf6',
                    'GBP' => '#ef4444',
                    'JPY' => '#ec4899'
                ];
                
                $currency_color = $currency_colors[$bal['currency_code']] ?? '#9d50ff';
                
                // Format based on currency type
                $decimal_places = in_array($bal['currency_code'], ['BTC', 'ETH', 'XRP', 'LTC']) ? 8 : 2;
                $symbol = '';
                if ($bal['currency_code'] === 'USD') $symbol = '$';
                if ($bal['currency_code'] === 'EUR') $symbol = '€';
                if ($bal['currency_code'] === 'BTC') $symbol = '₿';
                if ($bal['currency_code'] === 'GBP') $symbol = '£';
                if ($bal['currency_code'] === 'JPY') $symbol = '¥';
                if ($bal['currency_code'] === 'MXN') $symbol = '$';
                if ($bal['currency_code'] === 'SEK') $symbol = 'kr';
            ?>
            <div class="neo-card">
                <div class="card-header">
                    <div>
                        <span class="label-mini"><?php echo $bal['currency_code']; ?> VAULT</span>
                        <span style="font-size: 0.6rem; color: <?php echo $currency_color; ?>; margin-left: 8px;">
                            <?php echo number_format($percentage, 1); ?>% of portfolio
                        </span>
                    </div>
                    <i class="fas fa-wallet" style="color: <?php echo $currency_color; ?>;"></i>
                </div>
                <div class="card-amount" style="color: <?php echo $currency_color; ?>;">
                    <?php echo $symbol . number_format($currency_total, $decimal_places); ?> 
                    <?php if (empty($symbol)) echo ' ' . $bal['currency_code']; ?>
                </div>
                <div class="inner-vault">
                    <div>
                        <p class="label-mini" style="color:#10b981; font-size:0.55rem;">Available</p>
                        <p style="font-weight:900; font-size:0.85rem; color:#fff;">
                            <?php echo $symbol . number_format($bal['available_balance'], $decimal_places); ?>
                            <?php if (empty($symbol)) echo ' ' . $bal['currency_code']; ?>
                        </p>
                    </div>
                    <?php if($bal['pending_balance'] > 0): ?>
                    <div style="text-align:right;">
                        <p class="label-mini" style="color:#f59e0b; font-size:0.55rem;">Pending</p>
                        <p style="font-weight:900; font-size:0.85rem; color:#fff;">
                            <?php echo $symbol . number_format($bal['pending_balance'], $decimal_places); ?>
                            <?php if (empty($symbol)) echo ' ' . $bal['currency_code']; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 10px; font-size: 0.7rem; color: #94a3b8;">
                    ≈ $<?php echo number_format($usd_value, 2); ?> USD
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="neo-card" style="text-align: center; padding: 30px;">
                <i class="fas fa-wallet" style="font-size: 2rem; color: #64748b; margin-bottom: 15px;"></i>
                <p style="color: #64748b; font-weight: 600;">No assets found</p>
                <a href="deposit.php" style="color: #9d50ff; font-size: 0.8rem; margin-top: 10px; display: inline-block;">
                    Make your first deposit →
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- 5. QUICK ACTIONS -->
    <div class="action-grid">
        <a href="deposit.php" class="neo-btn">
            <i class="fas fa-plus-circle btn-icon"></i>
            <span class="btn-label">Deposit</span>
        </a>
        <a href="withdraw.php" class="neo-btn">
            <i class="fas fa-arrow-up btn-icon"></i>
            <span class="btn-label">Withdraw</span>
        </a>
        <a href="swap.php" class="neo-btn">
            <i class="fas fa-exchange-alt btn-icon"></i>
            <span class="btn-label">Swap</span>
        </a>
        <a href="transfer.php" class="neo-btn">
            <i class="fas fa-paper-plane btn-icon"></i>
            <span class="btn-label">Transfer</span>
        </a>
        <a href="history.php" class="neo-btn">
            <i class="fas fa-history btn-icon"></i>
            <span class="btn-label">History</span>
        </a>
        <a href="profile.php" class="neo-btn">
            <i class="fas fa-user-circle btn-icon"></i>
            <span class="btn-label">Profile</span>
        </a>
    </div>
</div>

<!-- JAVASCRIPT FUNCTIONS -->
<script>
// Refresh crypto prices
function refreshCrypto() {
    const btn = document.getElementById('cryptoRefresh');
    const grid = document.getElementById('cryptoGrid');
    const timeSpan = document.getElementById('cryptoUpdated');
    
    btn.classList.add('loading');
    grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;">Refreshing...</div>';
    
    fetch('?action=refresh_crypto&t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                data.prices.forEach(crypto => {
                    const changeClass = crypto.change_24h >= 0 ? 'positive' : 'negative';
                    const changeIcon = crypto.change_24h >= 0 ? '▲' : '▼';
                    const priceFormatted = crypto.price > 1000 
                        ? crypto.price.toLocaleString('en-US', {maximumFractionDigits: 0})
                        : crypto.price.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    
                    html += `
                    <div class="market-item">
                        <div class="coin-info">
                            <div class="coin-symbol">${crypto.symbol}</div>
                            <div class="coin-name">${crypto.name}</div>
                        </div>
                        <div class="price-info">
                            <div class="current-price">$${priceFormatted}</div>
                            <div class="price-change ${changeClass}">
                                ${changeIcon}${Math.abs(crypto.change_24h).toFixed(2)}%
                            </div>
                        </div>
                    </div>`;
                });
                grid.innerHTML = html;
                
                const now = new Date();
                timeSpan.textContent = now.getHours().toString().padStart(2, '0') + ':' + 
                                      now.getMinutes().toString().padStart(2, '0');
                
                showNotification('Crypto prices updated successfully!', 'success');
            } else {
                grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;">Update failed</div>';
                showNotification('Failed to update crypto prices', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;">Connection error</div>';
            showNotification('Network error', 'error');
        })
        .finally(() => {
            btn.classList.remove('loading');
        });
}

// Refresh exchange rates
function refreshRates() {
    const btn = document.getElementById('ratesRefresh');
    const grid = document.getElementById('ratesGrid');
    const timeSpan = document.getElementById('ratesUpdated');
    
    btn.classList.add('loading');
    grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;">Refreshing...</div>';
    
    fetch('?action=refresh_rates&t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                data.rates.forEach(rate => {
                    const changeClass = rate.change >= 0 ? 'positive' : 'negative';
                    const changeSign = rate.change >= 0 ? '+' : '';
                    
                    html += `
                    <div class="market-item">
                        <div class="coin-info">
                            <div class="coin-symbol">${rate.from}/${rate.to}</div>
                            <div class="coin-name">${rate.name}</div>
                        </div>
                        <div class="price-info">
                            <div class="current-price">${parseFloat(rate.rate).toFixed(4)}</div>
                            <div class="price-change ${changeClass}">
                                ${changeSign}${rate.change.toFixed(2)}%
                            </div>
                        </div>
                    </div>`;
                });
                grid.innerHTML = html;
                
                const now = new Date();
                timeSpan.textContent = now.getHours().toString().padStart(2, '0') + ':' + 
                                      now.getMinutes().toString().padStart(2, '0');
                
                showNotification('Exchange rates updated successfully!', 'success');
            } else {
                grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;">Update failed</div>';
                showNotification('Failed to update exchange rates', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;">Connection error</div>';
            showNotification('Network error', 'error');
        })
        .finally(() => {
            btn.classList.remove('loading');
        });
}

// Notification function
function showNotification(message, type = 'info') {
    const existing = document.querySelector('.notification');
    if (existing) existing.remove();
    
    const notification = document.createElement('div');
    notification.className = `notification ${type === 'error' ? 'error' : ''}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Auto-refresh every 60 seconds
let autoRefreshInterval;
function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(() => {
        refreshCrypto();
        refreshRates();
    }, 60000);
}

document.addEventListener('DOMContentLoaded', function() {
    startAutoRefresh();
});

// Handle AJAX refresh actions
const urlParams = new URLSearchParams(window.location.search);
const action = urlParams.get('action');

if (action === 'refresh_crypto') {
    const crypto_prices = <?php echo json_encode(getLiveCryptoPrices()); ?>;
    console.log('Sending crypto data');
    const response = { success: true, prices: crypto_prices };
    document.write(JSON.stringify(response));
    document.close();
}

if (action === 'refresh_rates') {
    const exchange_rates = <?php echo json_encode(getLiveExchangeRates()); ?>;
    console.log('Sending rates data');
    const response = { success: true, rates: exchange_rates };
    document.write(JSON.stringify(response));
    document.close();
}
</script>

<?php include './dashboard_footer.php'; ?>
