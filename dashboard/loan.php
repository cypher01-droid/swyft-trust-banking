<?php
// loan.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// Get user's current balances for reference
$balances = getUserBalances($user_id, $pdo);

// Get loan history (including tracking codes)
$stmt = $pdo->prepare("
    SELECT * FROM loans 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$loan_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle loan request
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid. Please try again.";
    }
    
    $loan_type = trim($_POST['loan_type'] ?? '');
    $requested_amount = trim($_POST['requested_amount'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $repayment_period = trim($_POST['repayment_period'] ?? '');
    $agree_terms = isset($_POST['agree_terms']);
    
    // Validation
    if (empty($loan_type)) {
        $errors[] = "Please select a loan type.";
    }
    
    $allowed_loan_types = ['personal', 'business', 'emergency', 'education'];
    if (!in_array($loan_type, $allowed_loan_types)) {
        $errors[] = "Invalid loan type selected.";
    }
    
    if (!is_numeric($requested_amount) || $requested_amount <= 0) {
        $errors[] = "Please enter a valid loan amount.";
    }
    
    $requested_amount = floatval($requested_amount);
    if ($requested_amount < 100) {
        $errors[] = "Minimum loan amount is $100.";
    }
    
    if ($requested_amount > 50000) { // Maximum loan limit
        $errors[] = "Maximum loan amount is $50,000.";
    }
    
    if (empty($purpose)) {
        $errors[] = "Please describe the purpose of the loan.";
    }
    
    if (strlen($purpose) < 10) {
        $errors[] = "Purpose description must be at least 10 characters.";
    }
    
    if (strlen($purpose) > 500) {
        $errors[] = "Purpose description too long (max 500 characters).";
    }
    
    $allowed_repayment_periods = ['3', '6', '12', '24', '36'];
    if (!in_array($repayment_period, $allowed_repayment_periods)) {
        $errors[] = "Invalid repayment period selected.";
    }
    
    if (!$agree_terms) {
        $errors[] = "You must agree to the terms and conditions.";
    }
    
    // Check user eligibility
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as active_loans 
                FROM loans 
                WHERE user_id = ? AND status IN ('pending', 'approved')
            ");
            $stmt->execute([$user_id]);
            $active_loans = $stmt->fetchColumn();
            
            if ($active_loans >= 3) {
                $errors[] = "You have too many active loan applications. Please wait for existing applications to be processed.";
            }
        } catch (Exception $e) {
            error_log("Eligibility check error: " . $e->getMessage());
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // GENERATE TRACKING CODE - Improved version
            function generateTrackingCode($pdo) {
                $prefix = 'LN';
                $maxAttempts = 10;
                $attempt = 0;
                
                do {
                    // Generate random part (8 digits)
                    $random = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
                    
                    // Format: LN-XXX-XXXXX
                    $code = sprintf(
                        '%s-%s-%s',
                        $prefix,
                        substr($random, 0, 3),
                        substr($random, 3, 5)
                    );
                    
                    // Check if code already exists
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE tracking_code = ?");
                    $stmt->execute([$code]);
                    $count = $stmt->fetchColumn();
                    
                    if ($count === 0) {
                        return $code;
                    }
                    
                    $attempt++;
                    if ($attempt >= $maxAttempts) {
                        // Fallback: use timestamp-based code
                        $timestamp = time();
                        $code = sprintf(
                            '%s-%s-%s',
                            $prefix,
                            substr($timestamp, -3),
                            substr(str_pad(mt_rand(0, 99999), 5, '0', STR_PAD_LEFT), 0, 5)
                        );
                        break;
                    }
                } while (true);
                
                return $code;
            }
            
            // Generate unique tracking code
            $tracking_code = generateTrackingCode($pdo);
            
            // Create loan request WITH TRACKING CODE
            $stmt = $pdo->prepare("
                INSERT INTO loans (
                    user_id, 
                    loan_type, 
                    requested_amount, 
                    purpose,
                    repayment_period,
                    tracking_code,
                    status, 
                    created_at, 
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
            ");
            
            $stmt->execute([
                $user_id,
                $loan_type,
                $requested_amount,
                htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8'),
                $repayment_period,
                $tracking_code
            ]);
            
            $new_loan_id = $pdo->lastInsertId();
            
            // Create loan status tracking record
            $stmt = $pdo->prepare("
                INSERT INTO loan_status_history (
                    loan_id, 
                    status, 
                    notes, 
                    created_at
                ) VALUES (?, 'submitted', 'Loan application submitted by user', NOW())
            ");
            $stmt->execute([$new_loan_id]);
            
            // Notify admin
            $admin_message = "User {$fullName} requested a {$loan_type} loan of \${$requested_amount}. ";
            $admin_message .= "Tracking Code: {$tracking_code} | Loan ID: {$new_loan_id}";
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, is_read, is_admin, created_at
                ) VALUES (?, 'loan', 'New Loan Request', ?, 0, 1, NOW())
            ");
            $stmt->execute([$user_id, $admin_message]);
            
            // Notify user with tracking code
            $user_message = "✅ Your {$loan_type} loan request for \${$requested_amount} has been submitted!\n\n";
            $user_message .= "📋 **Tracking Code:** {$tracking_code}\n";
            $user_message .= "🔢 **Loan ID:** {$new_loan_id}\n";
            $user_message .= "📝 Use the tracking code to check your loan status anytime.\n";
            $user_message .= "⏳ **Status:** Under Review\n";
            $user_message .= "📅 **Submitted:** " . date('F j, Y g:i A');
            $user_message .= "\n\nYou'll be notified once a decision has been made.";
            
            addNotification(
                $user_id,
                'Loan Request Submitted',
                $user_message,
                'loan'
            );
            
            $pdo->commit();
            
            // Redirect to success page with tracking code
            header("Location: loan.php?success=1&tracking_code=" . urlencode($tracking_code) . "&loan_id=" . $new_loan_id);
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "An error occurred while processing your request. Please try again.";
            error_log("Loan request error for user {$user_id}: " . $e->getMessage());
        }
    }
}

$csrf_token = generateCSRFToken();
include './dashboard_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Loan - Swyft Trust Bank</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #0a0a0c;
            font-family: 'Inter', sans-serif;
            color: #fff;
            padding: 20px;
            min-height: 100vh;
        }
        
        .loan-container {
            max-width: 600px;
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
            margin-bottom: 10px;
            color: #fff;
        }
        
        .page-subtitle {
            color: #94a3b8;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }
        
        /* SUCCESS MODAL */
        .success-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 20px;
        }
        
        .modal-content {
            background: #111113;
            border-radius: 20px;
            padding: 30px;
            max-width: 500px;
            width: 100%;
            border: 1px solid rgba(157, 80, 255, 0.2);
            text-align: center;
        }
        
        .success-icon {
            font-size: 4rem;
            color: #10b981;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 15px;
            color: #fff;
        }
        
        .modal-subtitle {
            color: #94a3b8;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .tracking-box {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .tracking-label {
            font-size: 0.9rem;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        
        .tracking-code {
            font-family: 'Courier New', monospace;
            font-size: 1.8rem;
            font-weight: 900;
            color: #10b981;
            letter-spacing: 2px;
            margin: 10px 0;
        }
        
        .tracking-note {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 10px;
        }
        
        .modal-buttons {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }
        
        .modal-btn {
            flex: 1;
            padding: 14px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #9d50ff;
            color: white;
            border: none;
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: #94a3b8;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .modal-btn:hover {
            transform: translateY(-2px);
        }
        
        /* Loan Form */
        .loan-form {
            background: #111113;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
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
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #9d50ff;
        }
        
        .amount-input-group {
            position: relative;
        }
        
        .currency-symbol {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 700;
            color: #9d50ff;
        }
        
        .amount-input-group .form-input {
            padding-left: 40px;
        }
        
        .loan-types {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 10px;
        }
        
        @media (max-width: 480px) {
            .loan-types {
                grid-template-columns: 1fr;
            }
        }
        
        .loan-type {
            background: #0a0a0c;
            border: 2px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .loan-type:hover {
            border-color: rgba(157, 80, 255, 0.3);
        }
        
        .loan-type.active {
            border-color: #9d50ff;
            background: rgba(157, 80, 255, 0.05);
        }
        
        .loan-icon {
            font-size: 1.5rem;
            color: #9d50ff;
            margin-bottom: 8px;
        }
        
        .loan-name {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .loan-desc {
            font-size: 0.75rem;
            color: #94a3b8;
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
            margin-top: 10px;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            opacity: 0.95;
        }
        
        /* Loan History */
        .history-section {
            margin-top: 40px;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .history-item {
            background: #111113;
            border-radius: 15px;
            padding: 18px;
            border-left: 4px solid;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .history-item:hover {
            transform: translateY(-2px);
        }
        
        .loan-personal {
            border-left-color: #9d50ff;
        }
        
        .loan-business {
            border-left-color: #10b981;
        }
        
        .loan-emergency {
            border-left-color: #f59e0b;
        }
        
        .loan-education {
            border-left-color: #3b82f6;
        }
        
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .history-amount {
            font-weight: 800;
            font-size: 1.1rem;
            color: #fff;
        }
        
        .history-status {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .status-approved {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .status-declined {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .history-details {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        
        .history-purpose {
            font-size: 0.85rem;
            color: #cbd5e1;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .tracking-badge {
            display: inline-block;
            background: rgba(157, 80, 255, 0.1);
            color: #9d50ff;
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 4px;
            margin-top: 8px;
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
        
        .error-list {
            list-style: none;
            margin-top: 10px;
        }
        
        .error-list li {
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        /* Loan Terms */
        .terms-box {
            background: rgba(157, 80, 255, 0.05);
            border: 1px solid rgba(157, 80, 255, 0.2);
            border-radius: 12px;
            padding: 15px;
            margin-top: 20px;
            font-size: 0.85rem;
        }
        
        .terms-title {
            color: #9d50ff;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .terms-list {
            list-style: none;
            color: #94a3b8;
            line-height: 1.6;
        }
        
        .terms-list li {
            margin-bottom: 8px;
            padding-left: 20px;
            position: relative;
        }
        
        .terms-list li:before {
            content: "•";
            color: #9d50ff;
            position: absolute;
            left: 0;
        }
        
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-top: 15px;
            font-size: 0.85rem;
            color: #94a3b8;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-top: 3px;
        }
        
        .checkbox-group a {
            color: #9d50ff;
            text-decoration: none;
        }
        
        .checkbox-group a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="loan-container">
        <a href="/dashboard/" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Apply for a Loan</h1>
        <p class="page-subtitle">Get the financial support you need with competitive rates and flexible terms</p>
        
       <!-- Display Success Message -->
<?php if (isset($_GET['success']) && $_GET['success'] == '1' && isset($_GET['tracking_code'])): ?>
    <div class="success-modal" id="successModal">
        <div class="modal-content">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2 class="modal-title">Loan Application Submitted!</h2>
            <p class="modal-subtitle">
                Your loan application has been successfully submitted and is now under review.<br>
                You'll be notified once a decision has been made.
            </p>
            
            <div class="tracking-box">
                <div class="tracking-label">Your Loan Tracking Code</div>
                <div class="tracking-code" id="trackingCodeDisplay">
                    <?php echo htmlspecialchars($_GET['tracking_code']); ?>
                </div>
                <div class="tracking-note">
                    <i class="fas fa-info-circle"></i> Save this code to track your loan status
                </div>
                
                <!-- Loan Details -->
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.9rem;">
                        <span style="color: #94a3b8;">Loan ID:</span>
                        <span style="color: #fff; font-weight: 600;">
                            <?php echo isset($_GET['loan_id']) ? htmlspecialchars($_GET['loan_id']) : 'N/A'; ?>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span style="color: #94a3b8;">Submitted:</span>
                        <span style="color: #fff;"><?php echo date('M d, Y H:i'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="modal-buttons">
                <button class="modal-btn btn-secondary" onclick="copyTrackingCode()" id="copyBtn">
                    <i class="fas fa-copy"></i> Copy Tracking Code
                </button>
                <a href="loan-status.php?code=<?php echo urlencode($_GET['tracking_code']); ?>" class="modal-btn btn-primary">
                    <i class="fas fa-search"></i> Track Status Now
                </a>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: rgba(157, 80, 255, 0.05); border-radius: 10px; font-size: 0.85rem; color: #94a3b8;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <i class="fas fa-clock" style="color: #f59e0b;"></i>
                    <strong>Estimated review time:</strong> 24-48 hours
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-envelope" style="color: #3b82f6;"></i>
                    <strong>Notification:</strong> You'll receive email updates
                </div>
            </div>
            
            <!-- Print Button -->
            <button onclick="printLoanConfirmation()" style="margin-top: 15px; background: none; border: none; color: #9d50ff; cursor: pointer; font-size: 0.9rem;">
                <i class="fas fa-print"></i> Print Confirmation
            </button>
        </div>
    </div>
<?php endif; ?>
        
        <!-- Display Errors -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> Please fix the following errors:
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- Loan Form -->
        <div class="loan-form">
            <form id="loanForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Loan Type -->
                <div class="form-group">
                    <label class="form-label">Loan Type</label>
                    <div class="loan-types" id="loanTypes">
                        <div class="loan-type loan-personal" data-type="personal">
                            <div class="loan-icon"><i class="fas fa-user"></i></div>
                            <div class="loan-name">Personal Loan</div>
                            <div class="loan-desc">For personal expenses</div>
                        </div>
                        <div class="loan-type loan-business" data-type="business">
                            <div class="loan-icon"><i class="fas fa-briefcase"></i></div>
                            <div class="loan-name">Business Loan</div>
                            <div class="loan-desc">For business growth</div>
                        </div>
                        <div class="loan-type loan-emergency" data-type="emergency">
                            <div class="loan-icon"><i class="fas fa-ambulance"></i></div>
                            <div class="loan-name">Emergency Loan</div>
                            <div class="loan-desc">For urgent needs</div>
                        </div>
                        <div class="loan-type loan-education" data-type="education">
                            <div class="loan-icon"><i class="fas fa-graduation-cap"></i></div>
                            <div class="loan-name">Education Loan</div>
                            <div class="loan-desc">For education expenses</div>
                        </div>
                    </div>
                    <input type="hidden" name="loan_type" id="selectedLoanType" required>
                </div>
                
                <!-- Loan Amount -->
                <div class="form-group">
                    <label class="form-label">Loan Amount (USD)</label>
                    <div class="amount-input-group">
                        <span class="currency-symbol">$</span>
                        <input type="number" 
                               name="requested_amount" 
                               class="form-input" 
                               placeholder="5,000" 
                               step="100" 
                               min="100" 
                               max="50000" 
                               required
                               oninput="validateLoanAmount(this)">
                    </div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 5px;">
                        Min: $100 | Max: $50,000
                    </div>
                </div>
                
                <!-- Repayment Period -->
                <div class="form-group">
                    <label class="form-label">Repayment Period</label>
                    <select name="repayment_period" class="form-select" required>
                        <option value="">Select period</option>
                        <option value="3">3 months</option>
                        <option value="6">6 months</option>
                        <option value="12">12 months</option>
                        <option value="24">24 months</option>
                        <option value="36">36 months</option>
                    </select>
                </div>
                
                <!-- Loan Purpose -->
                <div class="form-group">
                    <label class="form-label">Purpose of Loan</label>
                    <textarea name="purpose" class="form-textarea" 
                              placeholder="Please describe what you need the loan for..." 
                              required></textarea>
                </div>
                
                <!-- Loan Terms -->
                <div class="terms-box">
                    <div class="terms-title">
                        <i class="fas fa-file-contract"></i> Loan Terms & Conditions
                    </div>
                    <ul class="terms-list">
                        <li>Interest rates vary from 5% to 15% based on credit assessment</li>
                        <li>Minimum repayment period: 3 months</li>
                        <li>Maximum repayment period: 36 months</li>
                        <li>Late payment fee: 2% of overdue amount</li>
                        <li>Early repayment is allowed with no penalty</li>
                        <li>Approval subject to credit verification</li>
                    </ul>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="agree_terms" name="agree_terms" required>
                        <label for="agree_terms">
                            I have read and agree to the <a href="/terms" target="_blank">Terms & Conditions</a> 
                            and confirm all information provided is accurate.
                        </label>
                    </div>
                </div>
                
                <button type="submit" class="submit-btn">
                    <i class="fas fa-paper-plane"></i> Submit Loan Application
                </button>
            </form>
        </div>
        
        <!-- Loan History -->
        <?php if (!empty($loan_history)): ?>
        <div class="history-section">
            <h2 class="section-title">
                Loan History
                <span style="font-size: 0.9rem; color: #9d50ff;">Last 10 applications</span>
            </h2>
            
            <div class="history-list">
                <?php foreach ($loan_history as $loan): 
                    $loan_class = 'loan-' . ($loan['loan_type'] ?? 'personal');
                ?>
                <div class="history-item <?php echo $loan_class; ?>" onclick="showLoanDetails(<?php echo $loan['id']; ?>)">
                    <div class="history-header">
                        <div class="history-amount">
                            $<?php echo number_format($loan['requested_amount'], 2); ?>
                            <span style="font-size: 0.9rem; color: #94a3b8; font-weight: normal;">
                                (<?php echo ucfirst($loan['loan_type'] ?? 'Personal'); ?>)
                            </span>
                        </div>
                        <span class="history-status status-<?php echo $loan['status']; ?>">
                            <?php echo ucfirst($loan['status']); ?>
                        </span>
                    </div>
                    <div class="history-details">
                        <div>
                            <i class="fas fa-calendar"></i> 
                            <?php echo date('M d, Y', strtotime($loan['created_at'])); ?>
                        </div>
                        <div>
                            <?php if (!empty($loan['repayment_period'])): ?>
                                <i class="fas fa-clock"></i> 
                                <?php echo $loan['repayment_period']; ?> months
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($loan['tracking_code'])): ?>
                    <div class="tracking-badge">
                        <i class="fas fa-barcode"></i> <?php echo $loan['tracking_code']; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($loan['purpose'])): ?>
                    <div class="history-purpose">
                        <strong>Purpose:</strong> <?php echo htmlspecialchars(substr($loan['purpose'], 0, 50)); ?><?php echo strlen($loan['purpose']) > 50 ? '...' : ''; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Loan Type Selection
        document.querySelectorAll('.loan-type').forEach(type => {
            type.addEventListener('click', function() {
                // Remove active class from all types
                document.querySelectorAll('.loan-type').forEach(t => {
                    t.classList.remove('active');
                });
                
                // Add active class to clicked type
                this.classList.add('active');
                
                // Update hidden input
                const loanType = this.dataset.type;
                document.getElementById('selectedLoanType').value = loanType;
            });
        });
        
        // Auto-select first loan type
        document.addEventListener('DOMContentLoaded', function() {
            const firstType = document.querySelector('.loan-type');
            if (firstType) {
                firstType.click();
            }
            
            // Set default amount
            const amountInput = document.querySelector('[name="requested_amount"]');
            if (amountInput && !amountInput.value) {
                amountInput.value = '5000';
            }
            
            // Set default repayment period
            const periodSelect = document.querySelector('[name="repayment_period"]');
            if (periodSelect) {
                periodSelect.value = '12';
            }
            
            // Show success modal if tracking code exists
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('success') && urlParams.has('tracking_code')) {
                const modal = document.getElementById('successModal');
                if (modal) {
                    modal.style.display = 'flex';
                }
            }
        });
        
        // Loan amount validation
        function validateLoanAmount(input) {
            let value = parseFloat(input.value);
            
            if (value < 100) {
                input.value = 100;
            } else if (value > 50000) {
                input.value = 50000;
            }
        }
        
        // Form validation
        document.getElementById('loanForm').addEventListener('submit', function(e) {
            const loanType = document.getElementById('selectedLoanType').value;
            const amount = document.querySelector('[name="requested_amount"]').value;
            const purpose = document.querySelector('[name="purpose"]').value;
            const period = document.querySelector('[name="repayment_period"]').value;
            const agreeTerms = document.getElementById('agree_terms').checked;
            
            let errors = [];
            
            if (!loanType) {
                errors.push('Please select a loan type.');
            }
            
            if (!amount || parseFloat(amount) < 100) {
                errors.push('Please enter a valid amount (minimum $100).');
            }
            
            if (!purpose || purpose.length < 10) {
                errors.push('Please describe the loan purpose (minimum 10 characters).');
            }
            
            if (!period) {
                errors.push('Please select a repayment period.');
            }
            
            if (!agreeTerms) {
                errors.push('You must agree to the terms and conditions.');
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
            }
        });
        
        // Copy tracking code to clipboard
        // Enhanced copy tracking code function
function copyTrackingCode() {
    const codeElement = document.getElementById('trackingCodeDisplay');
    const code = codeElement.textContent.trim();
    
    navigator.clipboard.writeText(code).then(() => {
        // Show success feedback
        const button = document.getElementById('copyBtn');
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Copied!';
        button.style.background = '#10b981';
        button.disabled = true;
        
        // Flash the tracking code
        codeElement.style.transform = 'scale(1.05)';
        codeElement.style.transition = 'transform 0.2s';
        setTimeout(() => {
            codeElement.style.transform = 'scale(1)';
        }, 200);
        
        // Reset button after 2 seconds
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.style.background = '';
            button.disabled = false;
        }, 2000);
        
        // Also copy loan details to clipboard as text
        const loanId = document.querySelector('[data-loan-id]')?.dataset.loanId || 'N/A';
        const date = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const fullDetails = `Loan Tracking Code: ${code}\nLoan ID: ${loanId}\nSubmitted: ${date}\nStatus: Under Review`;
        navigator.clipboard.writeText(fullDetails).catch(console.error);
        
    }).catch(err => {
        console.error('Failed to copy: ', err);
        
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = code;
        document.body.appendChild(textArea);
        textArea.select();
        
        try {
            document.execCommand('copy');
            alert('Tracking code copied to clipboard!');
        } catch (err) {
            alert('Failed to copy. Please copy manually: ' + code);
        }
        
        document.body.removeChild(textArea);
    });
}

// Print confirmation function
function printLoanConfirmation() {
    const trackingCode = document.getElementById('trackingCodeDisplay').textContent;
    const loanId = document.querySelector('[data-loan-id]')?.dataset.loanId || 'N/A';
    const date = new Date().toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Loan Application Confirmation</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .logo { font-size: 24px; font-weight: bold; color: #9d50ff; }
                .details { border: 2px solid #333; padding: 20px; border-radius: 10px; }
                .code { font-family: monospace; font-size: 24px; font-weight: bold; text-align: center; margin: 20px 0; padding: 15px; background: #f5f5f5; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="logo">Swyft Trust Bank</div>
                <h1>Loan Application Confirmation</h1>
            </div>
            <div class="details">
                <h2>Application Details</h2>
                <p><strong>Tracking Code:</strong></p>
                <div class="code">${trackingCode}</div>
                <p><strong>Loan ID:</strong> ${loanId}</p>
                <p><strong>Submitted:</strong> ${date}</p>
                <p><strong>Status:</strong> Under Review</p>
                <p><strong>Note:</strong> Keep this confirmation for your records. Use the tracking code to check your loan status online.</p>
            </div>
            <div class="footer">
                <p>Swyft Trust Bank | Customer Support: support@swyftbank.com | Phone: 1-800-SWYFT-BANK</p>
                <p>This is an automated confirmation. Please do not reply to this document.</p>
            </div>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
    printWindow.close();
}

// Auto-focus on modal if present
document.addEventListener('DOMContentLoaded', function() {
    const successModal = document.getElementById('successModal');
    if (successModal) {
        successModal.style.display = 'flex';
        // Add loan ID to modal for reference
        const urlParams = new URLSearchParams(window.location.search);
        const loanId = urlParams.get('loan_id');
        if (loanId) {
            const trackingBox = document.querySelector('.tracking-box');
            const loanIdElement = document.createElement('div');
            loanIdElement.dataset.loanId = loanId;
            loanIdElement.style.display = 'none';
            trackingBox.appendChild(loanIdElement);
        }
    }
});
        
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('successModal');
            if (modal && event.target === modal) {
                modal.style.display = 'none';
                // Remove query parameters from URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
        
        // Show loan details (placeholder function)
        function showLoanDetails(loanId) {
            window.location.href = 'loan-status.php?id=' + loanId;
        }
        
    </script>
</body>
</html>