<?php
// deposit.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';


if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// Get user's current balances
$stmt = $pdo->prepare("
    SELECT currency_code, available_balance, pending_balance 
    FROM balances 
    WHERE user_id = ? AND (available_balance > 0 OR pending_balance > 0)
    ORDER BY currency_code
");
$stmt->execute([$user_id]);
$balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get supported currencies
$stmt = $pdo->query("SELECT DISTINCT currency_code FROM balances ORDER BY currency_code");
$currencies = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get recent deposit history
$stmt = $pdo->prepare("
    SELECT d.*, b.currency_code 
    FROM deposits d
    LEFT JOIN balances b ON d.balance_id = b.id
    WHERE d.user_id = ? 
    ORDER BY d.created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$deposit_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token (add this to your functions)
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid. Please try again.";
    }
    
    $amount = trim($_POST['amount'] ?? '');
    $currency = trim($_POST['currency'] ?? '');
    $method = trim($_POST['method'] ?? '');
    $transaction_id = trim($_POST['transaction_id'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
        $errors[] = "Please enter a valid deposit amount.";
    }
    
    if ($amount > 100000) { // Limit to $100,000 per deposit
        $errors[] = "Maximum deposit amount is $100,000 per transaction.";
    }
    
    if (!in_array($currency, $currencies)) {
        $errors[] = "Please select a valid currency.";
    }
    
    if (empty($method) || !in_array($method, ['bank_transfer', 'crypto', 'card', 'other'])) {
        $errors[] = "Please select a valid deposit method.";
    }
    
    if ($method == 'crypto' && empty($transaction_id)) {
        $errors[] = "Please enter the transaction ID for crypto deposits.";
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // 1. Get or create balance record for this currency
            $stmt = $pdo->prepare("
                SELECT id FROM balances 
                WHERE user_id = ? AND currency_code = ?
                LIMIT 1
            ");
            $stmt->execute([$user_id, $currency]);
            $balance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$balance) {
                // Create new balance record for this currency
                $stmt = $pdo->prepare("
                    INSERT INTO balances (user_id, currency_code, available_balance, pending_balance)
                    VALUES (?, ?, 0, 0)
                ");
                $stmt->execute([$user_id, $currency]);
                $balance_id = $pdo->lastInsertId();
            } else {
                $balance_id = $balance['id'];
            }
            
            // 2. Create deposit record (pending admin approval)
            $stmt = $pdo->prepare("
                INSERT INTO deposits (
                    user_id, balance_id, amount, currency_code, 
                    method, transaction_id, notes, status, 
                    admin_notes, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', '', NOW(), NOW())
            ");
            $stmt->execute([
                $user_id,
                $balance_id,
                $amount,
                $currency,
                $method,
                $transaction_id,
                $notes
            ]);
            
            $deposit_id = $pdo->lastInsertId();
            
            // 3. Create notification for admin
            $stmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, is_read, created_at
                ) VALUES (?, 'deposit', 'New Deposit Request', ?, 0, NOW())
            ");
            $notification_message = "User {$fullName} requested a deposit of {$amount} {$currency}";
            $stmt->execute([$user_id, $notification_message]);
            
            // 4. Also notify admins (if you have admin users)
            $stmt = $pdo->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, is_read, created_at, is_admin
                ) 
                SELECT id, 'admin_deposit', 'New Deposit Requires Approval', ?, 0, NOW(), 1
                FROM users 
                WHERE role = 'admin'
            ");
            $admin_message = "Deposit #{$deposit_id}: {$amount} {$currency} from {$fullName}";
            $stmt->execute([$admin_message]);
            
            $pdo->commit();
            
            // Send email notification (optional)
            sendDepositNotification($user_id, $deposit_id, $amount, $currency);
            
            $success = true;
            $success_message = "Deposit request submitted successfully! It will be processed after admin approval.";
            
            // Refresh page to show new deposit in history
            header("Location: deposit.php?success=1");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "An error occurred. Please try again. Error: " . $e->getMessage();
            error_log("Deposit error: " . $e->getMessage());
        }
    }
}

// Generate CSRF token
$csrf_token = generateCSRFToken();

include './dashboard_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Deposit Funds - Swyft Trust Bank</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        
        .deposit-container {
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
        
        /* Deposit Form */
        .deposit-form {
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
        
        .form-input, .form-select {
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
        
        .form-input:focus, .form-select:focus {
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
        
        .method-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 10px;
        }
        
        .method-option {
            background: #0a0a0c;
            border: 2px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .method-option:hover {
            border-color: rgba(157, 80, 255, 0.3);
        }
        
        .method-option.active {
            border-color: #9d50ff;
            background: rgba(157, 80, 255, 0.05);
        }
        
        .method-icon {
            font-size: 1.5rem;
            color: #9d50ff;
            margin-bottom: 8px;
        }
        
        .method-name {
            font-size: 0.85rem;
            font-weight: 600;
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
        
        .submit-btn:active {
            transform: translateY(0);
        }
        
        /* Deposit History */
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
        
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .status-approved {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .status-rejected {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
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
        
        .error-list {
            list-style: none;
            margin-top: 10px;
        }
        
        .error-list li {
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        /* Balance Cards */
        .balance-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .balance-card {
            background: #111113;
            border-radius: 15px;
            padding: 18px;
            text-align: center;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .balance-currency {
            font-size: 0.8rem;
            color: #9d50ff;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .balance-amount {
            font-size: 1.3rem;
            font-weight: 900;
            color: #fff;
        }
        
        .balance-label {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 5px;
        }
        
        @media (max-width: 480px) {
            .deposit-container {
                padding: 60px 0 80px;
            }
            
            .method-options {
                grid-template-columns: 1fr;
            }
            
            .balance-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="deposit-container">
<a href="/dashboard/" class="back-btn"> <!-- Absolute path -->
<i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Deposit Funds</h1>
        
        <!-- Display Success Message -->
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Deposit request submitted successfully! It will be processed after admin approval.
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
        
        <!-- Current Balances -->
        <?php if (!empty($balances)): ?>
        <div class="balance-cards">
            <?php foreach ($balances as $balance): ?>
            <div class="balance-card">
                <div class="balance-currency"><?php echo $balance['currency_code']; ?></div>
                <div class="balance-amount">
                    <?php 
                    $decimal_places = in_array($balance['currency_code'], ['BTC', 'ETH', 'XRP']) ? 8 : 2;
                    echo number_format($balance['available_balance'], $decimal_places);
                    ?>
                </div>
                <div class="balance-label">Available Balance</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Deposit Form -->
        <div class="deposit-form">
            <form id="depositForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Amount -->
                <div class="form-group">
                    <label class="form-label">Deposit Amount</label>
                    <div class="amount-input-group">
                        <span class="currency-symbol" id="currencySymbol">$</span>
                        <input type="number" 
                               name="amount" 
                               class="form-input" 
                               placeholder="0.00" 
                               step="0.01" 
                               min="1" 
                               max="100000" 
                               required
                               oninput="validateAmount(this)">
                    </div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 5px;">
                        Min: $1.00 | Max: $100,000
                    </div>
                </div>
                
                <!-- Currency -->
                <div class="form-group">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-select" id="currencySelect" onchange="updateCurrencySymbol()" required>
                        <option value="">Select Currency</option>
                        <?php foreach ($currencies as $currency): ?>
                            <option value="<?php echo htmlspecialchars($currency); ?>">
                                <?php echo htmlspecialchars($currency); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Deposit Method -->
                <div class="form-group">
                    <label class="form-label">Deposit Method</label>
                    <div class="method-options" id="methodOptions">
                        <div class="method-option" data-method="bank_transfer">
                            <div class="method-icon"><i class="fas fa-university"></i></div>
                            <div class="method-name">Bank Transfer</div>
                        </div>
                        <div class="method-option" data-method="crypto">
                            <div class="method-icon"><i class="fas fa-coins"></i></div>
                            <div class="method-name">BTC</div>
                        </div>
                        <div class="method-option" data-method="crypto">
                            <div class="method-icon"><i class="fas fa-coins"></i></div>
                            <div class="method-name">USDT</div>
                        </div>
                        <div class="method-option" data-method="crypto">
                            <div class="method-icon"><i class="fas fa-coins"></i></div>
                            <div class="method-name">USDC</div>
                        </div>
                        <div class="method-option" data-method="card">
                            <div class="method-icon"><i class="fas fa-credit-card"></i></div>
                            <div class="method-name">Apple GiftCard</div>
                        </div>
                        <div class="method-option" data-method="card">
                            <div class="method-icon"><i class="fas fa-credit-card"></i></div>
                            <div class="method-name">Zelle</div>
                        </div>
                        <div class="method-option" data-method="card">
                            <div class="method-icon"><i class="fas fa-credit-card"></i></div>
                            <div class="method-name">Cashapp</div>
                        </div>
                        <div class="method-option" data-method="card">
                            <div class="method-icon"><i class="fas fa-credit-card"></i></div>
                            <div class="method-name">Chime</div>
                        </div>
                        <div class="method-option" data-method="card">
                            <div class="method-icon"><i class="fas fa-credit-card"></i></div>
                            <div class="method-name">Revolut</div>
                        </div>
                        
                    </div>
                    <input type="hidden" name="method" id="selectedMethod" required>
                </div>
                
                <!-- Transaction ID (for crypto) -->
                <div class="form-group" id="transactionIdGroup" style="display: none;">
                    <label class="form-label">Transaction ID / Hash</label>
                    <input type="text" name="transaction_id" class="form-input" placeholder="Enter transaction ID">
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 5px;">
                        Required for crypto deposits
                    </div>
                </div>
                
                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">Additional Notes (Optional)</label>
                    <textarea name="notes" class="form-input" rows="3" placeholder="Any additional information for the admin..."></textarea>
                </div>
                
                <!-- Terms -->
                <div class="form-group" style="font-size: 0.85rem; color: #94a3b8; line-height: 1.5;">
                    <input type="checkbox" id="terms" required style="margin-right: 8px;">
                    <label for="terms">I confirm that this deposit is from my own funds and complies with all applicable laws and regulations.</label>
                </div>
                
                <button type="submit" class="submit-btn">
                    <i class="fas fa-paper-plane"></i> Submit Deposit Request
                </button>
            </form>
        </div>
        
        <!-- Deposit History -->
        <?php if (!empty($deposit_history)): ?>
        <div class="history-section">
            <h2 class="section-title">
                Recent Deposits
                <span style="font-size: 0.9rem; color: #9d50ff;">Last 10 transactions</span>
            </h2>
            
            <div class="history-list">
                <?php foreach ($deposit_history as $deposit): ?>
                <div class="history-item">
                    <div class="history-header">
                        <div class="history-amount">
                            <?php echo number_format($deposit['amount'], 2); ?> 
                            <?php echo $deposit['currency_code']; ?>
                        </div>
                        <span class="history-status status-<?php echo $deposit['status']; ?>">
                            <?php echo ucfirst($deposit['status']); ?>
                        </span>
                    </div>
                    <div class="history-details">
                        <div>
                            <i class="fas fa-calendar"></i> 
                            <?php echo date('M d, Y', strtotime($deposit['created_at'])); ?>
                        </div>
                        <div>
                            <i class="fas fa-clock"></i> 
                            <?php echo date('H:i', strtotime($deposit['created_at'])); ?>
                        </div>
                    </div>
                    <?php if (!empty($deposit['admin_notes'])): ?>
                    <div style="margin-top: 10px; padding: 10px; background: rgba(255,255,255,0.05); border-radius: 8px;">
                        <div style="font-size: 0.75rem; color: #9d50ff; font-weight: 600;">
                            <i class="fas fa-info-circle"></i> Admin Notes
                        </div>
                        <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 5px;">
                            <?php echo htmlspecialchars($deposit['admin_notes']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Method Selection
        document.querySelectorAll('.method-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove active class from all options
                document.querySelectorAll('.method-option').forEach(opt => {
                    opt.classList.remove('active');
                });
                
                // Add active class to clicked option
                this.classList.add('active');
                
                // Update hidden input
                const method = this.dataset.method;
                document.getElementById('selectedMethod').value = method;
                
                // Show/hide transaction ID field for crypto
                const transactionIdGroup = document.getElementById('transactionIdGroup');
                if (method === 'crypto') {
                    transactionIdGroup.style.display = 'block';
                    document.querySelector('[name="transaction_id"]').required = true;
                } else {
                    transactionIdGroup.style.display = 'none';
                    document.querySelector('[name="transaction_id"]').required = false;
                }
            });
        });
        
        // Currency symbol update
        function updateCurrencySymbol() {
            const currencySelect = document.getElementById('currencySelect');
            const currencySymbol = document.getElementById('currencySymbol');
            const selectedCurrency = currencySelect.value;
            
            // Update symbol based on currency
            if (selectedCurrency === 'USD' || selectedCurrency === 'EUR' || selectedCurrency === 'GBP') {
                currencySymbol.textContent = selectedCurrency === 'USD' ? '$' : 
                                           selectedCurrency === 'EUR' ? '€' : '£';
            } else if (selectedCurrency === 'BTC' || selectedCurrency === 'ETH') {
                currencySymbol.textContent = '₿';
            } else {
                currencySymbol.textContent = selectedCurrency + ' ';
            }
            
            // Update placeholder based on currency
            const amountInput = document.querySelector('[name="amount"]');
            if (selectedCurrency === 'BTC' || selectedCurrency === 'ETH') {
                amountInput.placeholder = '0.00000000';
                amountInput.step = '0.00000001';
            } else {
                amountInput.placeholder = '0.00';
                amountInput.step = '0.01';
            }
        }
        
        // Amount validation
        function validateAmount(input) {
            let value = parseFloat(input.value);
            
            if (value < 1) {
                input.value = 1;
            } else if (value > 100000) {
                input.value = 100000;
            }
        }
        
        // Form submission validation
        document.getElementById('depositForm').addEventListener('submit', function(e) {
            const amount = document.querySelector('[name="amount"]').value;
            const currency = document.querySelector('[name="currency"]').value;
            const method = document.getElementById('selectedMethod').value;
            const terms = document.getElementById('terms').checked;
            
            let errors = [];
            
            if (!amount || parseFloat(amount) <= 0) {
                errors.push('Please enter a valid amount.');
            }
            
            if (!currency) {
                errors.push('Please select a currency.');
            }
            
            if (!method) {
                errors.push('Please select a deposit method.');
            }
            
            if (!terms) {
                errors.push('You must agree to the terms.');
            }
            
            if (method === 'crypto' && !document.querySelector('[name="transaction_id"]').value) {
                errors.push('Transaction ID is required for crypto deposits.');
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
            }
        });
        
        // Auto-select first method option
        document.addEventListener('DOMContentLoaded', function() {
            const firstMethod = document.querySelector('.method-option');
            if (firstMethod) {
                firstMethod.click();
            }
            
            // Set default currency if only one exists
            const currencySelect = document.getElementById('currencySelect');
            if (currencySelect.options.length === 2) { // 1 option + "Select Currency"
                currencySelect.selectedIndex = 1;
                updateCurrencySymbol();
            }
            
            // Show success message from URL parameter
            if (window.location.search.includes('success=1')) {
                setTimeout(() => {
                    window.history.replaceState({}, document.title, window.location.pathname);
                }, 3000);
            }
        });
    </script>
</body>
</html>