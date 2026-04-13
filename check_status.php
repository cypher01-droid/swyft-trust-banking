<?php
// loan_status.php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

// If user is logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard/");
    exit();
}

// Handle status check
$loan_data = null;
$error = '';
$tracking_code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tracking_code = trim($_POST['tracking_code'] ?? '');
    
    if (empty($tracking_code)) {
        $error = "Please enter a tracking code";
    } else {
        try {
            // Get loan by tracking code
            $stmt = $pdo->prepare("
                SELECT 
                    l.*,
                    u.full_name,
                    u.email,
                    u.phone,
                    u.kyc_status
                FROM loans l
                LEFT JOIN users u ON l.user_id = u.id
                WHERE l.tracking_code = ?
            ");
            $stmt->execute([$tracking_code]);
            $loan_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan_data) {
                $error = "No loan found with tracking code: " . htmlspecialchars($tracking_code);
            }
        } catch (Exception $e) {
            $error = "Error checking loan status: " . $e->getMessage();
        }
    }
}

// Get site info for header/footer
$site_title = "Swyft Trust Bank";
$site_description = "Check your loan application status";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Loan Status - <?php echo $site_title; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <div class="gtranslate_wrapper"></div>
<script>window.gtranslateSettings = {"default_language":"en","native_language_names":true,"detect_browser_language":true,"languages":["en","fr","it","es","de"],"wrapper_selector":".gtranslate_wrapper","flag_size":24,"flag_style":"3d","alt_flags":{"en":"usa","es":"mexico"}}</script>
<script src="https://cdn.gtranslate.net/widgets/latest/dwf.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #0a0a0c 0%, #1a1a2e 100%);
            font-family: 'Inter', -apple-system, sans-serif;
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 20px 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: 900;
            color: #fff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo-icon {
            color: #9d50ff;
        }
        
        .nav-links {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .nav-link {
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .nav-link:hover {
            color: #9d50ff;
        }
        
        .btn-login {
            background: rgba(157, 80, 255, 0.2);
            color: #9d50ff;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(157, 80, 255, 0.3);
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            background: rgba(157, 80, 255, 0.3);
            transform: translateY(-2px);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
        }
        
        .status-container {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        
        .page-title {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #9d50ff, #6a11cb);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-subtitle {
            color: #94a3b8;
            margin-bottom: 40px;
            font-size: 1.1rem;
        }
        
        /* Status Check Form */
        .status-form {
            margin-bottom: 40px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        
        .form-input {
            width: 100%;
            padding: 16px 20px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #fff;
            font-size: 16px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #9d50ff;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 3px rgba(157, 80, 255, 0.1);
        }
        
        .submit-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #9d50ff, #6a11cb);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(157, 80, 255, 0.3);
        }
        
        .submit-btn:active {
            transform: translateY(-1px);
        }
        
        /* Error Message */
        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            color: #ef4444;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Loan Status Display */
        .loan-status-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 30px;
            margin-top: 30px;
            border-left: 4px solid;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .status-pending { border-left-color: #f59e0b; }
        .status-approved { border-left-color: #10b981; }
        .status-declined { border-left-color: #ef4444; }
        
        .status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .status-title {
            font-size: 1.5rem;
            font-weight: 800;
        }
        
        .status-badge {
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-approved { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .badge-declined { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        
        .loan-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .detail-item {
            background: rgba(255, 255, 255, 0.03);
            padding: 15px;
            border-radius: 10px;
        }
        
        .detail-label {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-value {
            font-size: 1.1rem;
            font-weight: 700;
        }
        
        .tracking-code {
            background: rgba(157, 80, 255, 0.1);
            border: 1px solid rgba(157, 80, 255, 0.3);
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            margin-top: 20px;
        }
        
        .tracking-label {
            font-size: 0.9rem;
            color: #9d50ff;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .tracking-number {
            font-family: 'Courier New', monospace;
            font-size: 1.5rem;
            font-weight: 900;
            color: #fff;
            letter-spacing: 2px;
        }
        
        .admin-message {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 20px;
            margin-top: 25px;
        }
        
        .message-label {
            color: #9d50ff;
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .message-content {
            color: #cbd5e1;
            line-height: 1.6;
        }
        
        /* Timeline */
        .timeline {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .timeline-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: #fff;
        }
        
        .timeline-item {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            position: relative;
        }
        
        .timeline-item:not(:last-child):before {
            content: '';
            position: absolute;
            left: 11px;
            top: 28px;
            bottom: -20px;
            width: 2px;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .timeline-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #9d50ff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.8rem;
            color: white;
            z-index: 1;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-date {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-bottom: 5px;
        }
        
        .timeline-text {
            color: #fff;
            font-weight: 500;
        }
        
        /* Footer */
        .footer {
            background: rgba(255, 255, 255, 0.05);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 40px 0;
            text-align: center;
        }
        
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-bottom: 20px;
        }
        
        .footer-link {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .footer-link:hover {
            color: #9d50ff;
        }
        
        .copyright {
            color: #64748b;
            font-size: 0.85rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .status-container {
                padding: 30px 20px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .loan-details {
                grid-template-columns: 1fr;
            }
            
            .nav-links {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .status-container {
                padding: 20px 15px;
            }
            
            .page-title {
                font-size: 1.8rem;
            }
            
            .timeline-item {
                flex-direction: column;
                gap: 10px;
            }
            
            .timeline-item:not(:last-child):before {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <a href="/" class="logo">
                    <i class="fas fa-university logo-icon"></i>
                    <?php echo $site_title; ?>
                </a>
                
                <div class="nav-links">
                    <a href="/" class="nav-link">Home</a>
                    <a href="/about" class="nav-link">About</a>
                    <a href="/contact" class="nav-link">Contact</a>
                    <a href="/login.php" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <div class="status-container">
                <h1 class="page-title">Check Loan Status</h1>
                <p class="page-subtitle">Track your loan application in real-time using your tracking code</p>
                
                <!-- Status Check Form -->
                <form method="POST" action="" class="status-form">
                    <?php if ($error && !$loan_data): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label class="form-label">Enter Tracking Code</label>
                        <input type="text" 
                               name="tracking_code" 
                               class="form-input" 
                               placeholder="e.g., LN4D7A2B583214" 
                               value="<?php echo htmlspecialchars($tracking_code); ?>"
                               required>
                        <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 8px;">
                            <i class="fas fa-info-circle"></i> Enter the tracking code provided when you applied for the loan
                        </div>
                    </div>
                    
                    <button type="submit" class="submit-btn">
                        <i class="fas fa-search"></i> Check Status
                    </button>
                </form>
                
                <!-- Loan Status Display -->
                <?php if ($loan_data): ?>
                <div class="loan-status-card status-<?php echo $loan_data['status']; ?>">
                    <div class="status-header">
                        <div class="status-title">Loan Application Status</div>
                        <div class="status-badge badge-<?php echo $loan_data['status']; ?>">
                            <?php echo strtoupper($loan_data['status']); ?>
                        </div>
                    </div>
                    
                    <!-- Loan Details -->
                    <div class="loan-details">
                        <div class="detail-item">
                            <div class="detail-label">Loan ID</div>
                            <div class="detail-value">#<?php echo $loan_data['id']; ?></div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Loan Type</div>
                            <div class="detail-value"><?php echo ucfirst($loan_data['loan_type']); ?></div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Requested Amount</div>
                            <div class="detail-value">$<?php echo number_format($loan_data['requested_amount'], 2); ?></div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Applicant Name</div>
                            <div class="detail-value"><?php echo htmlspecialchars($loan_data['full_name']); ?></div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Application Date</div>
                            <div class="detail-value">
                                <?php echo date('F j, Y', strtotime($loan_data['created_at'])); ?>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Last Updated</div>
                            <div class="detail-value">
                                <?php 
                                $updated_date = !empty($loan_data['updated_at']) && $loan_data['updated_at'] != '0000-00-00 00:00:00' 
                                    ? date('F j, Y', strtotime($loan_data['updated_at'])) 
                                    : date('F j, Y', strtotime($loan_data['created_at']));
                                echo $updated_date;
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tracking Code Display -->
                    <div class="tracking-code">
                        <div class="tracking-label">Your Tracking Code</div>
                        <div class="tracking-number"><?php echo $loan_data['tracking_code']; ?></div>
                    </div>
                    
                    <!-- Admin Message (if exists) -->
                    <?php if (!empty($loan_data['admin_message'])): ?>
                    <div class="admin-message">
                        <div class="message-label">
                            <i class="fas fa-comment-alt"></i> Message from Bank
                        </div>
                        <div class="message-content">
                            <?php echo nl2br(htmlspecialchars($loan_data['admin_message'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Timeline -->
                    <div class="timeline">
                        <div class="timeline-title">Application Timeline</div>
                        
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-date">
                                    <?php echo date('F j, Y g:i A', strtotime($loan_data['created_at'])); ?>
                                </div>
                                <div class="timeline-text">
                                    Loan application submitted
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($loan_data['status'] == 'pending'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-date">Currently</div>
                                <div class="timeline-text">
                                    Application under review by our team
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($loan_data['processed_at']) && $loan_data['processed_at'] != '0000-00-00 00:00:00'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-date">
                                    <?php echo date('F j, Y g:i A', strtotime($loan_data['processed_at'])); ?>
                                </div>
                                <div class="timeline-text">
                                    Application <?php echo $loan_data['status']; ?> by bank
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($loan_data['status'] == 'approved'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-money-check-alt"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-date">Next Step</div>
                                <div class="timeline-text">
                                    Funds will be disbursed to your account
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                        <a href="javascript:window.print()" class="submit-btn" style="flex: 1; text-decoration: none; text-align: center;">
                            <i class="fas fa-print"></i> Print Status
                        </a>
                        <a href="/login.php" class="submit-btn" style="flex: 1; text-decoration: none; text-align: center; background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                            <i class="fas fa-arrow-down"></i> Withdraw
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Help Information -->
                <div style="margin-top: 40px; padding: 20px; background: rgba(255,255,255,0.03); border-radius: 10px;">
                    <h3 style="color: #9d50ff; margin-bottom: 15px; font-size: 1.2rem;">
                        <i class="fas fa-question-circle"></i> Need Help?
                    </h3>
                    <div style="color: #94a3b8; line-height: 1.6;">
                        <p>If you don't have your tracking code or need assistance:</p>
                        <ul style="margin-top: 10px; padding-left: 20px;">
                            <li>Check your email for the tracking code</li>
                            <li>Contact our support team at support@swyfttrust.com</li>
                            <li>Click Button at bottom right for Immediate Assistance</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-links">
                <a href="/privacy" class="footer-link">Privacy Policy</a>
                <a href="/terms" class="footer-link">Terms of Service</a>
                <a href="/security" class="footer-link">Security</a>
                <a href="/faq" class="footer-link">FAQ</a>
                <a href="/contact" class="footer-link">Contact Us</a>
            </div>
            <div class="copyright">
                &copy; <?php echo date('Y'); ?> <?php echo $site_title; ?>. All rights reserved.
            </div>
        </div>
    </footer>
    
    <script>
    // Auto-select tracking code input on page load
    document.addEventListener('DOMContentLoaded', function() {
        const trackingInput = document.querySelector('input[name="tracking_code"]');
        if (trackingInput && !trackingInput.value) {
            trackingInput.focus();
        }
        
        // Print functionality
        window.addEventListener('beforeprint', function() {
            document.querySelector('.status-form').style.display = 'none';
        });
        
        window.addEventListener('afterprint', function() {
            document.querySelector('.status-form').style.display = 'block';
        });
        
        // Copy tracking code to clipboard
        const copyButtons = document.querySelectorAll('.copy-tracking');
        copyButtons.forEach(button => {
            button.addEventListener('click', function() {
                const trackingCode = this.getAttribute('data-tracking');
                navigator.clipboard.writeText(trackingCode).then(() => {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    this.style.background = '#10b981';
                    
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.style.background = '';
                    }, 2000);
                });
            });
        });
    });
    </script>
    <!--Start of Tawk.to Script-->
<script type="text/javascript">
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
(function(){
var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
s1.async=true;
s1.src='https://embed.tawk.to/696765da015fb1197cae4aa3/1jetubof8';
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
<!--End of Tawk.to Script-->
</body>
</html>