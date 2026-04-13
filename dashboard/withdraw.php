<?php
// withdraw.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// Get user's available balances (only where they have funds)
$stmt = $pdo->prepare("
    SELECT id, currency_code, available_balance 
    FROM balances 
    WHERE user_id = ? AND available_balance > 0
    ORDER BY available_balance DESC
");
$stmt->execute([$user_id]);
$balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get withdrawal methods (from settings or database)
$withdrawal_methods = [
    'bank' => ['name' => 'Bank Transfer', 'fee' => 15, 'min' => 50, 'max' => 10000, 'processing' => '2-3 business days'],
    'cashapp' => ['name' => 'CashApp', 'fee' => 5, 'min' => 50, 'max' => 10000, 'processing' => '15-30 minutes'],
    'crypto' => ['name' => 'Cryptocurrency', 'fee' => 0.5, 'min' => 10, 'max' => 50000, 'processing' => '15-30 minutes'],
    'card' => ['name' => 'Credit/Debit Card', 'fee' => 2.5, 'min' => 20, 'max' => 5000, 'processing' => '1-2 business days'],
    'paypal' => ['name' => 'PayPal/Skrill', 'fee' => 3.5, 'min' => 10, 'max' => 10000, 'processing' => '24 hours']
];

// Get recent withdrawal history
$stmt = $pdo->prepare("
    SELECT w.*, b.currency_code 
    FROM withdrawals w
    LEFT JOIN balances b ON w.balance_id = b.id
    WHERE w.user_id = ? 
    ORDER BY w.created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$withdrawal_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid. Please try again.";
    }
    
    $amount = trim($_POST['amount'] ?? '');
    $currency = trim($_POST['currency'] ?? '');
    $method = trim($_POST['method'] ?? '');
    $wallet_address = trim($_POST['wallet_address'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
        $errors[] = "Please enter a valid withdrawal amount.";
    }
    
    // Check if user has enough balance in selected currency
    $user_balance = 0;
    $balance_id = null;
    foreach ($balances as $bal) {
        if ($bal['currency_code'] === $currency) {
            $user_balance = $bal['available_balance'];
            $balance_id = $bal['id'];
            break;
        }
    }
    
    if (!$balance_id) {
        $errors[] = "You don't have funds in the selected currency.";
    } elseif ($amount > $user_balance) {
        $errors[] = "Insufficient balance. Available: " . number_format($user_balance, 2) . " " . $currency;
    }
    
    // Check method limits
    if (isset($withdrawal_methods[$method])) {
        $method_info = $withdrawal_methods[$method];
        if ($amount < $method_info['min']) {
            $errors[] = "Minimum withdrawal for {$method_info['name']} is {$method_info['min']}.";
        }
        if ($amount > $method_info['max']) {
            $errors[] = "Maximum withdrawal for {$method_info['name']} is {$method_info['max']}.";
        }
    }
    
    // Validate method-specific details
    if ($method === 'crypto' && empty($wallet_address)) {
        $errors[] = "Wallet address is required for cryptocurrency withdrawals.";
    }
    
    if ($method === 'bank' && empty($bank_details)) {
        $errors[] = "Bank details are required for bank transfers.";
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Calculate fee
            $fee_percentage = $withdrawal_methods[$method]['fee'];
            $fee_amount = ($amount * $fee_percentage) / 100;
            $net_amount = $amount - $fee_amount;
            
            // 1. Create withdrawal record
            $stmt = $pdo->prepare("
                INSERT INTO withdrawals (
                    user_id, balance_id, amount, currency_code, 
                    method, wallet_address, bank_details, notes,
                    fee_percentage, fee_amount, net_amount,
                    status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
            ");
            $stmt->execute([
                $user_id,
                $balance_id,
                $amount,
                $currency,
                $method,
                $wallet_address,
                $bank_details,
                $notes,
                $fee_percentage,
                $fee_amount,
                $net_amount
            ]);
            
            $withdrawal_id = $pdo->lastInsertId();
            
            // 2. Lock the funds (move from available to pending)
            $stmt = $pdo->prepare("
                UPDATE balances 
                SET available_balance = available_balance - ?,
                    pending_balance = pending_balance + ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$amount, $amount, $balance_id, $user_id]);
            
            // 3. Create transaction record
            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    user_id, type, amount, currency_code, description, status, reference_id
                ) VALUES (?, 'withdrawal', ?, ?, 'Withdrawal request', 'pending', ?)
            ");
            $stmt->execute([$user_id, $amount, $currency, $withdrawal_id]);
            
            // 4. Create notification for admin
            $stmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, is_read, is_admin, created_at
                ) 
                SELECT ?, 'admin_withdrawal', 'New Withdrawal Request', ?, 0, 1, NOW()
                FROM users 
                WHERE role = 'admin'
            ");
            $admin_message = "Withdrawal #{$withdrawal_id}: {$amount} {$currency} from {$fullName}";
            $stmt->execute([$user_id, $admin_message]);
            
            // 5. Notify user
            $stmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, is_read, created_at
                ) VALUES (?, 'withdrawal', 'Withdrawal Requested', ?, 0, NOW())
            ");
            $user_message = "Withdrawal of {$amount} {$currency} submitted. Processing time: {$withdrawal_methods[$method]['processing']}.";
            $stmt->execute([$user_id, $user_message]);
            
            $pdo->commit();
            
            // Email notification (optional)
            sendWithdrawalNotification($user_id, $withdrawal_id, $amount, $currency, $net_amount, $fee_amount);
            
            header("Location: withdraw.php?success=1&id=" . $withdrawal_id);
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "An error occurred: " . $e->getMessage();
            error_log("Withdrawal error: " . $e->getMessage());
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Withdraw Funds - Zeus Bank</title>
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
        
        .withdraw-container {
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
        
        /* Balance Summary */
        .balance-summary {
            background: linear-gradient(135deg, rgba(157, 80, 255, 0.1), rgba(106, 17, 203, 0.1));
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(157, 80, 255, 0.2);
        }
        
        .balance-title {
            font-size: 0.9rem;
            color: #9d50ff;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .balance-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .balance-item {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 15px;
            padding: 15px;
            text-align: center;
        }
        
        .balance-item .currency {
            font-size: 0.8rem;
            color: #9d50ff;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .balance-item .amount {
            font-size: 1.2rem;
            font-weight: 900;
            color: #fff;
        }
        
        /* Withdrawal Form */
        .withdraw-form {
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
        
        /* Method Selection */
        .method-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 10px;
        }
        
        .method-card {
            background: #0a0a0c;
            border: 2px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .method-card:hover {
            border-color: rgba(157, 80, 255, 0.3);
        }
        
        .method-card.active {
            border-color: #9d50ff;
            background: rgba(157, 80, 255, 0.05);
        }
        
        .method-icon {
            font-size: 1.5rem;
            color: #9d50ff;
            margin-bottom: 8px;
        }
        
        .method-name {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .method-details {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        /* Fee Calculator */
        .fee-calculator {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 12px;
            padding: 15px;
            margin-top: 10px;
            display: none;
        }
        
        .fee-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        .fee-row.total {
            font-weight: 800;
            font-size: 1rem;
            color: #9d50ff;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Withdrawal Details */
        .details-group {
            display: none;
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
            margin-top: 20px;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            opacity: 0.95;
        }
        
        /* History */
        .history-section {
            margin-top: 40px;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #fff;
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
            border-left: 4px solid #9d50ff;
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
        
        .status-pending { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .status-processing { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .status-completed { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .status-rejected { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        
        .history-details {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #94a3b8;
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
        
        @media (max-width: 480px) {
            .withdraw-container {
                padding: 60px 0 80px;
            }
            
            .method-cards {
                grid-template-columns: 1fr;
            }
            
            .balance-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="withdraw-container">
<a href="/dashboard/" class="back-btn"> <!-- Absolute path -->
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Withdraw Funds</h1>
        
        <!-- Success Message -->
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                Withdrawal request submitted successfully!<br>
                <small>Reference ID: #<?php echo $_GET['id'] ?? 'N/A'; ?></small>
            </div>
        <?php endif; ?>
        
        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> 
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Balance Summary -->
        <div class="balance-summary">
            <div class="balance-title">
                <i class="fas fa-wallet"></i> Available for Withdrawal
            </div>
            <?php if (!empty($balances)): ?>
                <div class="balance-grid">
                    <?php foreach ($balances as $balance): ?>
                        <div class="balance-item" data-currency="<?php echo $balance['currency_code']; ?>">
                            <div class="currency"><?php echo $balance['currency_code']; ?></div>
                            <div class="amount">
                                <?php 
                                $decimal_places = in_array($balance['currency_code'], ['BTC', 'ETH', 'XRP']) ? 8 : 2;
                                echo number_format($balance['available_balance'], $decimal_places);
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; color: #64748b; padding: 20px;">
                    <i class="fas fa-wallet" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                    <p>No funds available for withdrawal</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Withdrawal Form -->
        <?php if (!empty($balances)): ?>
        <div class="withdraw-form">
            <form id="withdrawForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Amount -->
                <div class="form-group">
                    <label class="form-label">Amount to Withdraw</label>
                    <div class="amount-input-group">
                        <span class="currency-symbol" id="currencySymbol">$</span>
                        <input type="number" 
                               name="amount" 
                               class="form-input" 
                               placeholder="0.00" 
                               step="0.01" 
                               min="1" 
                               required
                               id="amountInput"
                               oninput="calculateFees()">
                    </div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 5px;">
                        Available: <span id="availableBalance">0.00</span>
                    </div>
                </div>
                
                <!-- Currency -->
                <div class="form-group">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-select" id="currencySelect" onchange="updateCurrencyInfo()" required>
                        <option value="">Select Currency</option>
                        <?php foreach ($balances as $balance): ?>
                            <option value="<?php echo $balance['currency_code']; ?>" 
                                    data-balance="<?php echo $balance['available_balance']; ?>"
                                    data-id="<?php echo $balance['id']; ?>">
                                <?php echo $balance['currency_code']; ?> 
                                (Available: <?php echo number_format($balance['available_balance'], 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Method -->
                <div class="form-group">
                    <label class="form-label">Withdrawal Method</label>
                    <div class="method-cards" id="methodCards">
                        <?php foreach ($withdrawal_methods as $key => $method): ?>
                            <div class="method-card" data-method="<?php echo $key; ?>">
                                <div class="method-icon">
                                    <?php if ($key === 'bank'): ?>
                                        <i class="fas fa-university"></i>
                                        <?php elseif ($key === 'cashapp'): ?>
                                        <i class="fas fa-university"></i>
                                    <?php elseif ($key === 'crypto'): ?>
                                        <i class="fas fa-coins"></i>
                                    <?php elseif ($key === 'card'): ?>
                                        <i class="fas fa-credit-card"></i>
                                    <?php else: ?>
                                        <i class="fas fa-money-bill-wave"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="method-name"><?php echo $method['name']; ?></div>
                                <div class="method-details">
                                    Fee: <?php echo $method['fee']; ?>% • 
                                    Min: <?php echo $method['min']; ?> • 
                                    <?php echo $method['processing']; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="method" id="selectedMethod" required>
                </div>
                
                <!-- Fee Calculator -->
                <div class="fee-calculator" id="feeCalculator">
                    <div class="fee-row">
                        <span>Withdrawal Amount:</span>
                        <span id="displayAmount">0.00</span>
                    </div>
                    <div class="fee-row">
                        <span>Processing Fee (<span id="feePercentage">0</span>%):</span>
                        <span id="feeAmount">0.00</span>
                    </div>
                    <div class="fee-row total">
                        <span>You Will Receive:</span>
                        <span id="netAmount">0.00</span>
                    </div>
                </div>
                
                <!-- Method-specific Details -->
                <div class="form-group details-group" id="cryptoDetails">
                    <label class="form-label">Wallet Address</label>
                    <input type="text" name="wallet_address" class="form-input" 
                           placeholder="Enter your cryptocurrency wallet address">
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 5px;">
                        Double-check the address. Transactions cannot be reversed.
                    </div>
                </div>
                
                <div class="form-group details-group" id="bankDetails">
                    <label class="form-label">Bank Details</label>
                    <textarea name="bank_details" class="form-textarea" rows="3" 
                              placeholder="Bank name, account number, routing number, etc."></textarea>
                </div>
                
                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">Additional Notes (Optional)</label>
                    <textarea name="notes" class="form-textarea" rows="2" 
                              placeholder="Any special instructions..."></textarea>
                </div>
                
                <!-- Terms -->
                <div class="form-group" style="font-size: 0.85rem; color: #94a3b8; line-height: 1.5;">
                    <input type="checkbox" id="terms" required style="margin-right: 8px;">
                    <label for="terms">I understand that withdrawal processing times vary by method and are subject to admin approval.</label>
                </div>
                
                <button type="submit" class="submit-btn" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> Request Withdrawal
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Withdrawal History -->
        <?php if (!empty($withdrawal_history)): ?>
        <div class="history-section">
            <h2 class="section-title">Recent Withdrawals</h2>
            
            <div class="history-list">
                <?php foreach ($withdrawal_history as $withdrawal): ?>
                <div class="history-item">
                    <div class="history-header">
                        <div class="history-amount">
                            <?php echo number_format($withdrawal['amount'], 2); ?> 
                            <?php echo $withdrawal['currency_code']; ?>
                        </div>
                        <span class="history-status status-<?php echo $withdrawal['status']; ?>">
                            <?php echo ucfirst($withdrawal['status']); ?>
                        </span>
                    </div>
                    <div class="history-details">
                        <div>
                            <i class="fas fa-calendar"></i> 
                            <?php echo date('M d, Y', strtotime($withdrawal['created_at'])); ?>
                        </div>
                        <div>
                            <i class="fas fa-clock"></i> 
                            <?php echo date('H:i', strtotime($withdrawal['created_at'])); ?>
                        </div>
                    </div>
                    <?php if ($withdrawal['fee_amount'] > 0): ?>
                    <div style="margin-top: 8px; font-size: 0.8rem; color: #64748b;">
                        Fee: <?php echo number_format($withdrawal['fee_amount'], 2); ?> 
                        (Net: <?php echo number_format($withdrawal['net_amount'], 2); ?>)
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Global variables
        let selectedMethod = '';
        let methodInfo = {};
        let availableBalance = 0;
        
        // Method Cards Selection
        document.querySelectorAll('.method-card').forEach(card => {
            card.addEventListener('click', function() {
                // Remove active class from all cards
                document.querySelectorAll('.method-card').forEach(c => {
                    c.classList.remove('active');
                });
                
                // Add active class to clicked card
                this.classList.add('active');
                
                // Set selected method
                selectedMethod = this.dataset.method;
                document.getElementById('selectedMethod').value = selectedMethod;
                
                // Get method info
                methodInfo = {
                    'bank': <?php echo json_encode($withdrawal_methods['bank'] ?? []); ?>,
                    'cashapp': <?php echo json_encode($withdrawal_methods['cashapp'] ?? []); ?>,
                    'crypto': <?php echo json_encode($withdrawal_methods['crypto'] ?? []); ?>,
                    'card': <?php echo json_encode($withdrawal_methods['card'] ?? []); ?>,
                    'paypal': <?php echo json_encode($withdrawal_methods['paypal'] ?? []); ?>
                }[selectedMethod] || {};
                
                // Show fee calculator
                document.getElementById('feeCalculator').style.display = 'block';
                
                // Show/hide method-specific details
                document.getElementById('cryptoDetails').style.display = 
                    (selectedMethod === 'crypto') ? 'block' : 'none';
                document.getElementById('bankDetails').style.display = 
                    (selectedMethod === 'bank') ? 'block' : 'none';
                
                // Calculate fees
                calculateFees();
            });
        });
        
        // Update currency info
        function updateCurrencyInfo() {
            const select = document.getElementById('currencySelect');
            const selectedOption = select.options[select.selectedIndex];
            const currency = select.value;
            
            // Update currency symbol
            const symbolSpan = document.getElementById('currencySymbol');
            if (currency === 'USD') symbolSpan.textContent = '$';
            else if (currency === 'EUR') symbolSpan.textContent = '€';
            else if (currency === 'GBP') symbolSpan.textContent = '£';
            else if (currency === 'BTC' || currency === 'ETH') symbolSpan.textContent = '₿';
            else symbolSpan.textContent = currency + ' ';
            
            // Update available balance display
            availableBalance = parseFloat(selectedOption.dataset.balance) || 0;
            document.getElementById('availableBalance').textContent = 
                availableBalance.toLocaleString('en-US', { 
                    minimumFractionDigits: (currency === 'BTC' || currency === 'ETH') ? 8 : 2,
                    maximumFractionDigits: (currency === 'BTC' || currency === 'ETH') ? 8 : 2
                });
            
            // Update amount input step
            const amountInput = document.getElementById('amountInput');
            if (currency === 'BTC' || currency === 'ETH') {
                amountInput.step = '0.00000001';
                amountInput.placeholder = '0.00000000';
            } else {
                amountInput.step = '0.01';
                amountInput.placeholder = '0.00';
            }
            
            // Recalculate fees
            calculateFees();
        }
        
        // Calculate fees
        function calculateFees() {
            const amountInput = document.getElementById('amountInput');
            const amount = parseFloat(amountInput.value) || 0;
            
            if (!selectedMethod || !methodInfo.fee) return;
            
            const feePercentage = methodInfo.fee;
            const feeAmount = (amount * feePercentage) / 100;
            const netAmount = amount - feeAmount;
            
            // Update display
            document.getElementById('displayAmount').textContent = 
                amount.toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('feePercentage').textContent = feePercentage;
            document.getElementById('feeAmount').textContent = 
                feeAmount.toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('netAmount').textContent = 
                netAmount.toLocaleString('en-US', { minimumFractionDigits: 2 });
            
            // Validate against method limits
            const submitBtn = document.getElementById('submitBtn');
            if (amount < methodInfo.min) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.innerHTML = `<i class="fas fa-exclamation-circle"></i> Minimum: ${methodInfo.min}`;
            } else if (amount > methodInfo.max) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.innerHTML = `<i class="fas fa-exclamation-circle"></i> Maximum: ${methodInfo.max}`;
            } else if (amount > availableBalance) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.innerHTML = `<i class="fas fa-exclamation-circle"></i> Insufficient Balance`;
            } else {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.innerHTML = `<i class="fas fa-paper-plane"></i> Request Withdrawal`;
            }
        }
        
        // Form validation
        document.getElementById('withdrawForm').addEventListener('submit', function(e) {
            const amount = parseFloat(document.getElementById('amountInput').value) || 0;
            const currency = document.getElementById('currencySelect').value;
            const method = document.getElementById('selectedMethod').value;
            const terms = document.getElementById('terms').checked;
            
            let errors = [];
            
            if (!amount || amount <= 0) {
                errors.push('Please enter a valid amount.');
            }
            
            if (!currency) {
                errors.push('Please select a currency.');
            }
            
            if (!method) {
                errors.push('Please select a withdrawal method.');
            }
            
            if (amount > availableBalance) {
                errors.push('Insufficient balance.');
            }
            
            if (methodInfo.min && amount < methodInfo.min) {
                errors.push(`Minimum withdrawal for ${methodInfo.name} is ${methodInfo.min}.`);
            }
            
            if (methodInfo.max && amount > methodInfo.max) {
                errors.push(`Maximum withdrawal for ${methodInfo.name} is ${methodInfo.max}.`);
            }
            
            if (method === 'crypto' && !document.querySelector('[name="wallet_address"]').value) {
                errors.push('Wallet address is required for crypto withdrawals.');
            }
            
            if (method === 'bank' && !document.querySelector('[name="bank_details"]').value) {
                errors.push('Bank details are required for bank transfers.');
            }
            
            if (!terms) {
                errors.push('You must agree to the terms.');
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
            } else {
                // Show confirmation
                if (!confirm(`Confirm withdrawal of ${amount} ${currency}?\nYou will receive: ${(amount - (amount * methodInfo.fee / 100)).toFixed(2)} ${currency}\nProcessing time: ${methodInfo.processing}`)) {
                    e.preventDefault();
                }
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-select first currency with balance
            const currencySelect = document.getElementById('currencySelect');
            if (currencySelect.options.length > 1) {
                currencySelect.selectedIndex = 1;
                updateCurrencyInfo();
            }
            
            // Auto-select first method
            const firstMethod = document.querySelector('.method-card');
            if (firstMethod) {
                firstMethod.click();
            }
            
            // Set max amount on input
            document.getElementById('amountInput').addEventListener('input', function() {
                if (this.value > availableBalance) {
                    this.value = availableBalance;
                }
                calculateFees();
            });
        });
    </script>
</body>
</html>