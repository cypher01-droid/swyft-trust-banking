<?php
// transfer.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// Get user balances
$balances = getUserBalances($user_id, $pdo);

// Handle transfer
$errors = [];
$success = false;
$transfer_id = 0;
$tracking_code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid. Please try again.";
    }
    
    $recipient_email = trim($_POST['recipient_email'] ?? '');
    $amount = floatval(trim($_POST['amount'] ?? '0'));
    $currency = trim($_POST['currency'] ?? 'USD');
    $description = trim($_POST['description'] ?? '');
    
    // Validation
    if (empty($recipient_email)) {
        $errors[] = "Please enter recipient's email address.";
    } elseif (!filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    } elseif ($recipient_email === $_SESSION['email']) {
        $errors[] = "You cannot transfer to yourself.";
    }
    
    if ($amount <= 0) {
        $errors[] = "Please enter a valid amount.";
    }
    
    if ($amount > 10000) { // Maximum transfer limit
        $errors[] = "Maximum transfer amount is $10,000.";
    }
    
    // Check if recipient exists
    $recipient = null;
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$recipient_email, $user_id]);
            $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$recipient) {
                $errors[] = "Recipient not found. Please check the email address.";
            }
        } catch (Exception $e) {
            $errors[] = "Error checking recipient. Please try again.";
            error_log("Recipient check error: " . $e->getMessage());
        }
    }
    
    // Check sender's balance
    if (empty($errors)) {
        $user_balance = 0;
        foreach ($balances as $balance) {
            if ($balance['currency_code'] === $currency) {
                $user_balance = $balance['available_balance'];
                break;
            }
        }
        
        if ($amount > $user_balance) {
            $errors[] = "Insufficient balance. You have $" . number_format($user_balance, 2) . " available.";
        }
    }
    
    // Process transfer
    // Process transfer
if (empty($errors)) {
    $result = processTransferTransaction(
        $user_id,
        $recipient['id'],
        $amount,
        $currency,
        $description,
        $pdo
    );
    
    if ($result['success']) {
        $success = true;
        $transfer_id = $result['transfer_id'];
        $tracking_code = $result['tracking_code'];
        
        // Notify recipient
        $recipient_message = "💸 You received a transfer of " . $currency . " " . number_format($net_amount, 2) . " from " . $fullName . ".\n\n";
        $recipient_message .= "📝 Description: " . $description . "\n";
        $recipient_message .= "🔢 Tracking Code: " . $tracking_code . "\n";
        $recipient_message .= "⏳ Status: Pending approval\n";
        $recipient_message .= "💰 Amount after fee: " . $currency . " " . number_format($net_amount, 2) . "\n";
        $recipient_message .= "📅 Date: " . date('F j, Y g:i A');
        
        addNotification(
            $recipient['id'],
            'Transfer Received',
            $recipient_message,
            'transfer'
        );
        
        // Notify sender
        $sender_message = "💸 You sent a transfer of " . $currency . " " . number_format($amount, 2) . " to " . $recipient['full_name'] . ".\n\n";
        $sender_message .= "📝 Description: " . $description . "\n";
        $sender_message .= "🔢 Tracking Code: " . $tracking_code . "\n";
        $sender_message .= "⏳ Status: Pending\n";
        $sender_message .= "💰 Fee: " . $currency . " " . number_format($fee_amount, 2) . " (1%)\n";
        $sender_message .= "💰 Net amount sent: " . $currency . " " . number_format($net_amount, 2) . "\n";
        $sender_message .= "📅 Date: " . date('F j, Y g:i A');
        
        addNotification(
            $user_id,
            'Transfer Sent',
            $sender_message,
            'transfer'
        );
        
        // Clear form
        $_POST = [];
    } else {
        $errors[] = "Transfer failed: " . $result['error'];
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
    <title>Transfer Money - Swyft Trust Bank</title>
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
        
        .transfer-container {
            max-width: 500px;
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
        
        /* Success Modal */
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
        
        /* Transfer Form */
        .transfer-form {
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
            min-height: 80px;
            resize: vertical;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #9d50ff;
        }
        
        .amount-input-group {
            position: relative;
        }
        
        .currency-select {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: #0a0a0c;
            color: #fff;
            border: none;
            font-weight: 700;
        }
        
        .balance-info {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
        }
        
        .balance-amount {
            color: #10b981;
            font-weight: 700;
        }
        
        /* Fee Display */
        .fee-display {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        
        .fee-title {
            color: #f59e0b;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .fee-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        
        .fee-item {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #94a3b8;
        }
        
        .fee-value {
            color: #fff;
            font-weight: 600;
        }
        
        /* Submit Button */
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
        
        .submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            opacity: 0.95;
        }
        
        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Recent Transfers */
        .recent-section {
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
        
        .recent-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .recent-item {
            background: #111113;
            border-radius: 15px;
            padding: 18px;
            border-left: 4px solid #9d50ff;
        }
        
        .recent-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .recent-amount {
            font-weight: 800;
            font-size: 1.1rem;
            color: #fff;
        }
        
        .recent-status {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .recent-details {
            font-size: 0.8rem;
            color: #94a3b8;
            display: flex;
            justify-content: space-between;
        }
        
        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 600;
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
    </style>
</head>
<body>
    <div class="transfer-container">
        <a href="./" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Transfer Money</h1>
        <p class="page-subtitle">Send money instantly to other Swyft Trust Bank users</p>
        
        <!-- Success Modal -->
        <?php if ($success): ?>
            <div class="success-modal" id="successModal">
                <div class="modal-content">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2 class="modal-title">Transfer Initiated!</h2>
                    <p class="modal-subtitle">
                        Your transfer has been initiated successfully.<br>
                        The recipient will receive the funds once the transaction is processed.
                    </p>
                    
                    <div class="tracking-box">
                        <div class="tracking-label">Tracking Code</div>
                        <div class="tracking-code" id="trackingCodeDisplay">
                            <?php echo htmlspecialchars($tracking_code); ?>
                        </div>
                        <p style="font-size: 0.85rem; color: #64748b; margin-top: 10px;">
                            <i class="fas fa-info-circle"></i> Use this code to track your transfer status
                        </p>
                    </div>
                    
                    <div class="modal-buttons">
                        <button class="modal-btn btn-secondary" onclick="copyTrackingCode()">
                            <i class="fas fa-copy"></i> Copy Code
                        </button>
                        <a href="history.php?type=transfer" class="modal-btn btn-primary">
                            <i class="fas fa-history"></i> View History
                        </a>
                    </div>
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
        
        <!-- Transfer Form -->
        <div class="transfer-form">
            <form id="transferForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Recipient Email -->
                <div class="form-group">
                    <label class="form-label">Recipient Email Address</label>
                    <input type="email" 
                           name="recipient_email" 
                           class="form-input" 
                           placeholder="recipient@example.com" 
                           value="<?php echo htmlspecialchars($_POST['recipient_email'] ?? ''); ?>"
                           required>
                </div>
                
                <!-- Amount -->
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <div class="amount-input-group">
                        <input type="number" 
                               name="amount" 
                               class="form-input" 
                               placeholder="100.00" 
                               step="0.01" 
                               min="1" 
                               max="10000" 
                               value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
                               required
                               oninput="calculateFees()">
                        <select name="currency" class="currency-select" onchange="updateBalance()">
                            <?php foreach ($balances as $balance): ?>
                                <option value="<?php echo $balance['currency_code']; ?>" 
                                    <?php echo ($_POST['currency'] ?? 'USD') === $balance['currency_code'] ? 'selected' : ''; ?>>
                                    <?php echo $balance['currency_code']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="balance-info">
                        <span>Available Balance:</span>
                        <span class="balance-amount" id="availableBalance">
                            <?php 
                            $default_currency = $_POST['currency'] ?? 'USD';
                            $default_balance = 0;
                            foreach ($balances as $balance) {
                                if ($balance['currency_code'] === $default_currency) {
                                    $default_balance = $balance['available_balance'];
                                    break;
                                }
                            }
                            echo $default_currency . ' ' . number_format($default_balance, 2);
                            ?>
                        </span>
                    </div>
                </div>
                
                <!-- Fee Display (Calculated by JavaScript) -->
                <div class="fee-display" id="feeDisplay" style="display: none;">
                    <div class="fee-title">
                        <i class="fas fa-percentage"></i> Transfer Fee
                    </div>
                    <div class="fee-details">
                        <div class="fee-item">
                            <span>Transfer Fee (1%):</span>
                            <span class="fee-value" id="feeAmount">$0.00</span>
                        </div>
                        <div class="fee-item">
                            <span>Net Amount Sent:</span>
                            <span class="fee-value" id="netAmount">$0.00</span>
                        </div>
                    </div>
                </div>
                
                <!-- Description -->
                <div class="form-group">
                    <label class="form-label">Description (Optional)</label>
                    <textarea name="description" 
                              class="form-textarea" 
                              placeholder="What's this transfer for?"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>
                
                <button type="submit" class="submit-btn">
                    <i class="fas fa-paper-plane"></i> Send Transfer
                </button>
            </form>
        </div>
        
        <!-- Recent Transfers -->
        <?php
        // Get recent transfers
        try {
            $stmt = $pdo->prepare("
                SELECT t.*, u.full_name as recipient_name 
                FROM transfers t
                JOIN users u ON t.receiver_id = u.id
                WHERE t.sender_id = ?
                ORDER BY t.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$user_id]);
            $recent_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $recent_transfers = [];
            error_log("Recent transfers error: " . $e->getMessage());
        }
        ?>
        
        <?php if (!empty($recent_transfers)): ?>
        <div class="recent-section">
            <h2 class="section-title">
                Recent Transfers
                <span style="font-size: 0.9rem; color: #9d50ff;">Last 5 transfers</span>
            </h2>
            
            <div class="recent-list">
                <?php foreach ($recent_transfers as $transfer): ?>
                <div class="recent-item">
                    <div class="recent-header">
                        <div class="recent-amount">
                            <?php echo $transfer['currency_code'] . ' ' . number_format($transfer['amount'], 2); ?>
                            <span style="font-size: 0.9rem; color: #94a3b8; font-weight: normal;">
                                to <?php echo htmlspecialchars($transfer['recipient_name']); ?>
                            </span>
                        </div>
                        <span class="recent-status status-<?php echo $transfer['status']; ?>">
                            <?php echo ucfirst($transfer['status']); ?>
                        </span>
                    </div>
                    <div class="recent-details">
                        <div>
                            <i class="fas fa-calendar"></i> 
                            <?php echo date('M d, Y', strtotime($transfer['created_at'])); ?>
                        </div>
                        <div>
                            <?php if (!empty($transfer['tracking_code'])): ?>
                                <i class="fas fa-hashtag"></i> <?php echo $transfer['tracking_code']; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($transfer['description'])): ?>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: #cbd5e1;">
                        <i class="fas fa-comment"></i> <?php echo htmlspecialchars($transfer['description']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Fee calculation
        function calculateFees() {
            const amountInput = document.querySelector('[name="amount"]');
            const currencySelect = document.querySelector('[name="currency"]');
            const feeDisplay = document.getElementById('feeDisplay');
            const feeAmount = document.getElementById('feeAmount');
            const netAmount = document.getElementById('netAmount');
            
            const amount = parseFloat(amountInput.value) || 0;
            const currency = currencySelect.value;
            
            if (amount > 0) {
                // Calculate fee (1% or minimum $1)
                const feePercentage = 0.01;
                let fee = amount * feePercentage;
                if (fee < 1) fee = 1;
                const net = amount - fee;
                
                feeAmount.textContent = currency + ' ' + fee.toFixed(2);
                netAmount.textContent = currency + ' ' + net.toFixed(2);
                feeDisplay.style.display = 'block';
            } else {
                feeDisplay.style.display = 'none';
            }
        }
        
        // Update available balance display
        function updateBalance() {
            const currencySelect = document.querySelector('[name="currency"]');
            const selectedCurrency = currencySelect.value;
            const balanceElement = document.getElementById('availableBalance');
            
            // This would typically come from an API, but for now we'll update from PHP data
            // The PHP already set the initial balance, so we'll just trigger fee calculation
            calculateFees();
        }
        
        // Copy tracking code
        function copyTrackingCode() {
            const codeElement = document.getElementById('trackingCodeDisplay');
            const code = codeElement.textContent.trim();
            
            navigator.clipboard.writeText(code).then(() => {
                const button = event.target.closest('button');
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i> Copied!';
                button.style.background = '#10b981';
                
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.style.background = '';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                alert('Failed to copy tracking code. Please copy manually.');
            });
        }
        
        // Form validation
        document.getElementById('transferForm').addEventListener('submit', function(e) {
            const amountInput = document.querySelector('[name="amount"]');
            const amount = parseFloat(amountInput.value);
            const currency = document.querySelector('[name="currency"]').value;
            
            // Get balance from display (we'd need to parse it)
            const balanceText = document.getElementById('availableBalance').textContent;
            const balance = parseFloat(balanceText.split(' ')[1]) || 0;
            
            if (amount > 10000) {
                e.preventDefault();
                alert('Maximum transfer amount is $10,000.');
                return;
            }
            
            if (amount > balance) {
                e.preventDefault();
                alert('Insufficient balance. Please enter a lower amount.');
                return;
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            calculateFees();
            
            // Show success modal if transfer was successful
            <?php if ($success): ?>
                const modal = document.getElementById('successModal');
                if (modal) {
                    modal.style.display = 'flex';
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>