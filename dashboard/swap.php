<?php
// swap.php
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

// Define available currencies (you can expand this list)
$available_currencies = [
    'USD' => ['name' => 'US Dollar', 'type' => 'fiat'],
    'EUR' => ['name' => 'Euro', 'type' => 'fiat'],
    'GBP' => ['name' => 'British Pound', 'type' => 'fiat'],
    'BTC' => ['name' => 'Bitcoin', 'type' => 'crypto'],
    'ETH' => ['name' => 'Ethereum', 'type' => 'crypto'],
    'USDT' => ['name' => 'Tether', 'type' => 'crypto'],
    'BNB' => ['name' => 'Binance Coin', 'type' => 'crypto']
];

// Handle swap
$errors = [];
$success = false;
$swap_id = 0;
$tracking_code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid. Please try again.";
    }
    
    $from_currency = trim($_POST['from_currency'] ?? 'USD');
    $to_currency = trim($_POST['to_currency'] ?? 'EUR');
    $amount = floatval(trim($_POST['amount'] ?? '0'));
    
    // Validation
    if ($amount <= 0) {
        $errors[] = "Please enter a valid amount.";
    }
    
    if ($from_currency === $to_currency) {
        $errors[] = "Cannot swap to the same currency.";
    }
    
    if (!array_key_exists($from_currency, $available_currencies)) {
        $errors[] = "Invalid source currency.";
    }
    
    if (!array_key_exists($to_currency, $available_currencies)) {
        $errors[] = "Invalid target currency.";
    }
    
    // Check user's balance
    if (empty($errors)) {
        $user_balance = 0;
        foreach ($balances as $balance) {
            if ($balance['currency_code'] === $from_currency) {
                $user_balance = $balance['available_balance'];
                break;
            }
        }
        
        if ($amount > $user_balance) {
            $errors[] = "Insufficient balance. You have " . $from_currency . " " . number_format($user_balance, 8) . " available.";
        }
    }
    
    // Get exchange rate (in real app, this would come from an API)
    if (empty($errors)) {
        // Mock exchange rates (in real app, fetch from API)
        $exchange_rates = [
            'USD_EUR' => 0.85,
            'USD_GBP' => 0.75,
            'USD_BTC' => 0.000025,
            'USD_ETH' => 0.0005,
            'EUR_USD' => 1.18,
            'GBP_USD' => 1.33,
            'BTC_USD' => 40000,
            'ETH_USD' => 2000,
            'BTC_ETH' => 20,
            'ETH_BTC' => 0.05,
            'USDT_USD' => 1,
            'USD_USDT' => 1,
            'BNB_USD' => 300,
            'USD_BNB' => 0.00333
        ];
        
        $rate_key = $from_currency . '_' . $to_currency;
        
        if (isset($exchange_rates[$rate_key])) {
            $exchange_rate = $exchange_rates[$rate_key];
        } else {
            // Default to 1:1 if rate not found (in real app, would be an error)
            $exchange_rate = 1;
            error_log("Exchange rate not found for: $rate_key");
        }
        
        // Calculate received amount (with 0.5% fee)
        $fee_percentage = 0.005; // 0.5% fee for swaps
        $fee_amount = $amount * $fee_percentage;
        $amount_after_fee = $amount - $fee_amount;
        $received_amount = $amount_after_fee * $exchange_rate;
    }
    
    // Process swap
    // Process swap
if (empty($errors)) {
    $result = processSwapTransaction(
        $user_id,
        $from_currency,
        $to_currency,
        $amount,
        $pdo
    );
    
    if ($result['success']) {
        $success = true;
        $swap_id = $result['swap_id'];
        $tracking_code = $result['tracking_code'];
        $exchange_rate = $result['exchange_rate'];
        $fee_amount = $result['fee'];
        $received_amount = $result['received_amount'];
        
        // Notify user
        $message = "🔄 Swap initiated: " . number_format($amount, 8) . " $from_currency to " . number_format($received_amount, 8) . " $to_currency\n\n";
        $message .= "💱 Exchange Rate: 1 $from_currency = " . number_format($exchange_rate, 8) . " $to_currency\n";
        $message .= "💰 Fee: " . number_format($fee_amount, 8) . " $from_currency (0.5%)\n";
        $message .= "🔢 Tracking Code: $tracking_code\n";
        $message .= "⏳ Status: Processing\n";
        $message .= "📅 Date: " . date('F j, Y g:i A');
        
        addNotification(
            $user_id,
            'Swap Initiated',
            $message,
            'swap'
        );
        
        // Clear form
        $_POST = [];
    } else {
        $errors[] = "Swap failed: " . $result['error'];
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
    <title>Swap Currencies - Swyft Trust Bank</title>
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
        
        .swap-container {
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
        
        /* Swap Form */
        .swap-form {
            background: #111113;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .swap-direction {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px 0;
        }
        
        .swap-arrow {
            width: 40px;
            height: 40px;
            background: rgba(157, 80, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9d50ff;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .swap-arrow:hover {
            background: rgba(157, 80, 255, 0.2);
            transform: rotate(180deg);
        }
        
        .currency-box {
            background: #0a0a0c;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .currency-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .currency-label {
            font-size: 0.9rem;
            color: #94a3b8;
            font-weight: 600;
        }
        
        .balance-info {
            font-size: 0.8rem;
            color: #64748b;
        }
        
        .balance-amount {
            color: #10b981;
            font-weight: 700;
        }
        
        .currency-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .currency-amount {
            flex: 1;
        }
        
        .form-input, .form-select {
            width: 100%;
            padding: 14px 16px;
            background: #111113;
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
        
        .currency-select {
            width: 120px;
            padding: 14px;
        }
        
        /* Rate Display */
        .rate-display {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        
        .rate-title {
            color: #3b82f6;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .rate-value {
            font-size: 1.1rem;
            font-weight: 900;
            color: #fff;
            text-align: center;
            margin: 10px 0;
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
        
        /* Currency Types */
        .currency-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding: 10px;
            background: #111113;
            border-radius: 12px;
        }
        
        .currency-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .currency-tab:hover {
            background: rgba(157, 80, 255, 0.1);
        }
        
        .currency-tab.active {
            background: #9d50ff;
            color: white;
        }
        
        /* Recent Swaps */
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
    <div class="swap-container">
        <a href="./" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Swap Currencies</h1>
        <p class="page-subtitle">Exchange between fiat and cryptocurrencies instantly</p>
        
        <!-- Success Modal -->
        <?php if ($success): ?>
            <div class="success-modal" id="successModal">
                <div class="modal-content">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2 class="modal-title">Swap Initiated!</h2>
                    <p class="modal-subtitle">
                        Your currency swap has been initiated successfully.<br>
                        The exchange will be processed shortly.
                    </p>
                    
                    <div class="tracking-box">
                        <div class="tracking-label">Tracking Code</div>
                        <div class="tracking-code" id="trackingCodeDisplay">
                            <?php echo htmlspecialchars($tracking_code); ?>
                        </div>
                        <p style="font-size: 0.85rem; color: #64748b; margin-top: 10px;">
                            <i class="fas fa-info-circle"></i> Use this code to track your swap status
                        </p>
                    </div>
                    
                    <div class="modal-buttons">
                        <button class="modal-btn btn-secondary" onclick="copyTrackingCode()">
                            <i class="fas fa-copy"></i> Copy Code
                        </button>
                        <a href="history.php?type=swap" class="modal-btn btn-primary">
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
        
        <!-- Swap Form -->
        <div class="swap-form">
            <form id="swapForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Currency Tabs (Optional filter) -->
                <div class="currency-tabs">
                    <div class="currency-tab active" onclick="setCurrencyFilter('all')">All</div>
                    <div class="currency-tab" onclick="setCurrencyFilter('fiat')">Fiat</div>
                    <div class="currency-tab" onclick="setCurrencyFilter('crypto')">Crypto</div>
                </div>
                
                <!-- From Currency -->
                <div class="currency-box">
                    <div class="currency-header">
                        <div class="currency-label">You Send</div>
                        <div class="balance-info">
                            Balance: <span class="balance-amount" id="fromBalance">
                                <?php 
                                $default_from = $_POST['from_currency'] ?? 'USD';
                                $default_from_balance = 0;
                                foreach ($balances as $balance) {
                                    if ($balance['currency_code'] === $default_from) {
                                        $default_from_balance = $balance['available_balance'];
                                        break;
                                    }
                                }
                                echo number_format($default_from_balance, 8);
                                ?>
                            </span>
                        </div>
                    </div>
                    <div class="currency-input-group">
                        <div class="currency-amount">
                            <input type="number" 
                                   name="amount" 
                                   class="form-input" 
                                   placeholder="100.00" 
                                   step="0.00000001" 
                                   min="0.00000001" 
                                   value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
                                   required
                                   oninput="calculateSwap()">
                        </div>
                        <select name="from_currency" class="currency-select" onchange="updateFromBalance()">
                            <?php foreach ($available_currencies as $code => $currency): ?>
                                <option value="<?php echo $code; ?>" 
                                        data-type="<?php echo $currency['type']; ?>"
                                        <?php echo ($_POST['from_currency'] ?? 'USD') === $code ? 'selected' : ''; ?>>
                                    <?php echo $code; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Swap Arrow -->
                <div class="swap-direction">
                    <div class="swap-arrow" onclick="swapCurrencies()">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                </div>
                
                <!-- To Currency -->
                <div class="currency-box">
                    <div class="currency-header">
                        <div class="currency-label">You Receive</div>
                        <div class="balance-info">
                            Balance: <span class="balance-amount" id="toBalance">
                                <?php 
                                $default_to = $_POST['to_currency'] ?? 'EUR';
                                $default_to_balance = 0;
                                foreach ($balances as $balance) {
                                    if ($balance['currency_code'] === $default_to) {
                                        $default_to_balance = $balance['available_balance'];
                                        break;
                                    }
                                }
                                echo number_format($default_to_balance, 8);
                                ?>
                            </span>
                        </div>
                    </div>
                    <div class="currency-input-group">
                        <div class="currency-amount">
                            <input type="text" 
                                   class="form-input" 
                                   id="receivedAmount" 
                                   placeholder="0.00" 
                                   readonly
                                   style="background: rgba(255,255,255,0.05);">
                        </div>
                        <select name="to_currency" class="currency-select" onchange="updateToBalance()">
                            <?php foreach ($available_currencies as $code => $currency): ?>
                                <option value="<?php echo $code; ?>" 
                                        data-type="<?php echo $currency['type']; ?>"
                                        <?php echo ($_POST['to_currency'] ?? 'EUR') === $code ? 'selected' : ''; ?>>
                                    <?php echo $code; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Exchange Rate Display -->
                <div class="rate-display" id="rateDisplay" style="display: none;">
                    <div class="rate-title">
                        <i class="fas fa-chart-line"></i> Exchange Rate
                    </div>
                    <div class="rate-value" id="exchangeRate">
                        1 USD = 0.85 EUR
                    </div>
                </div>
                
                <!-- Fee Display -->
                <div class="fee-display" id="feeDisplay" style="display: none;">
                    <div class="fee-title">
                        <i class="fas fa-percentage"></i> Swap Fee (0.5%)
                    </div>
                    <div class="fee-details">
                        <div class="fee-item">
                            <span>Fee Amount:</span>
                            <span class="fee-value" id="feeAmount">0.00 USD</span>
                        </div>
                        <div class="fee-item">
                            <span>Amount After Fee:</span>
                            <span class="fee-value" id="amountAfterFee">0.00 USD</span>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="submit-btn">
                    <i class="fas fa-sync-alt"></i> Swap Now
                </button>
            </form>
        </div>
        
        <!-- Recent Swaps -->
        <?php
        // Get recent swaps
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM swaps
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$user_id]);
            $recent_swaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $recent_swaps = [];
            error_log("Recent swaps error: " . $e->getMessage());
        }
        ?>
        
        <?php if (!empty($recent_swaps)): ?>
        <div class="recent-section">
            <h2 class="section-title">
                Recent Swaps
                <span style="font-size: 0.9rem; color: #9d50ff;">Last 5 swaps</span>
            </h2>
            
            <div class="recent-list">
                <?php foreach ($recent_swaps as $swap): ?>
                <div class="recent-item">
                    <div class="recent-header">
                        <div class="recent-amount">
                            <?php echo number_format($swap['amount_sent'], 8) . ' ' . $swap['from_currency']; ?>
                            <span style="font-size: 0.9rem; color: #94a3b8; font-weight: normal;">
                                → <?php echo number_format($swap['amount_received'], 8) . ' ' . $swap['to_currency']; ?>
                            </span>
                        </div>
                        <span class="recent-status status-<?php echo $swap['status']; ?>">
                            <?php echo ucfirst($swap['status']); ?>
                        </span>
                    </div>
                    <div class="recent-details">
                        <div>
                            <i class="fas fa-calendar"></i> 
                            <?php echo date('M d, Y', strtotime($swap['created_at'])); ?>
                        </div>
                        <div>
                            <?php if (!empty($swap['tracking_code'])): ?>
                                <i class="fas fa-hashtag"></i> <?php echo $swap['tracking_code']; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($swap['exchange_rate']): ?>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: #cbd5e1;">
                        <i class="fas fa-chart-line"></i> Rate: 1 <?php echo $swap['from_currency']; ?> = <?php echo number_format($swap['exchange_rate'], 8); ?> <?php echo $swap['to_currency']; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Mock exchange rates (in real app, fetch from API)
        const exchangeRates = {
            'USD_EUR': 0.85,
            'USD_GBP': 0.75,
            'USD_BTC': 0.000025,
            'USD_ETH': 0.0005,
            'EUR_USD': 1.18,
            'GBP_USD': 1.33,
            'BTC_USD': 40000,
            'ETH_USD': 2000,
            'BTC_ETH': 20,
            'ETH_BTC': 0.05,
            'USDT_USD': 1,
            'USD_USDT': 1,
            'BNB_USD': 300,
            'USD_BNB': 0.00333
        };
        
        // Calculate swap
        function calculateSwap() {
            const fromCurrency = document.querySelector('[name="from_currency"]').value;
            const toCurrency = document.querySelector('[name="to_currency"]').value;
            const amountInput = document.querySelector('[name="amount"]');
            const receivedInput = document.getElementById('receivedAmount');
            const rateDisplay = document.getElementById('rateDisplay');
            const rateValue = document.getElementById('exchangeRate');
            const feeDisplay = document.getElementById('feeDisplay');
            const feeAmount = document.getElementById('feeAmount');
            const amountAfterFee = document.getElementById('amountAfterFee');
            
            const amount = parseFloat(amountInput.value) || 0;
            
            if (amount > 0 && fromCurrency !== toCurrency) {
                // Get exchange rate
                const rateKey = fromCurrency + '_' + toCurrency;
                let rate = exchangeRates[rateKey] || 1;
                
                // Calculate with 0.5% fee
                const feePercentage = 0.005;
                const fee = amount * feePercentage;
                const amountAfterFeeValue = amount - fee;
                const received = amountAfterFeeValue * rate;
                
                // Update displays
                receivedInput.value = received.toFixed(8);
                rateValue.textContent = `1 ${fromCurrency} = ${rate.toFixed(8)} ${toCurrency}`;
                feeAmount.textContent = fee.toFixed(8) + ' ' + fromCurrency;
                amountAfterFee.textContent = amountAfterFeeValue.toFixed(8) + ' ' + fromCurrency;
                
                rateDisplay.style.display = 'block';
                feeDisplay.style.display = 'block';
            } else {
                receivedInput.value = '';
                rateDisplay.style.display = 'none';
                feeDisplay.style.display = 'none';
            }
        }
        
        // Swap currencies (reverse direction)
        function swapCurrencies() {
            const fromSelect = document.querySelector('[name="from_currency"]');
            const toSelect = document.querySelector('[name="to_currency"]');
            
            const fromValue = fromSelect.value;
            const toValue = toSelect.value;
            
            // Swap values
            fromSelect.value = toValue;
            toSelect.value = fromValue;
            
            // Update balances and recalculate
            updateFromBalance();
            updateToBalance();
            calculateSwap();
        }
        
        // Update from currency balance
        function updateFromBalance() {
            const fromSelect = document.querySelector('[name="from_currency"]');
            const selectedCurrency = fromSelect.value;
            const balanceElement = document.getElementById('fromBalance');
            
            // In real app, fetch balance via AJAX
            // For now, we'll just trigger recalculation
            calculateSwap();
        }
        
        // Update to currency balance
        function updateToBalance() {
            const toSelect = document.querySelector('[name="to_currency"]');
            const selectedCurrency = toSelect.value;
            const balanceElement = document.getElementById('toBalance');
            
            // In real app, fetch balance via AJAX
            calculateSwap();
        }
        
        // Filter currencies by type
        function setCurrencyFilter(type) {
            const tabs = document.querySelectorAll('.currency-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            const fromOptions = document.querySelectorAll('[name="from_currency"] option');
            const toOptions = document.querySelectorAll('[name="to_currency"] option');
            
            if (type === 'all') {
                fromOptions.forEach(opt => opt.style.display = '');
                toOptions.forEach(opt => opt.style.display = '');
            } else {
                fromOptions.forEach(opt => {
                    if (opt.dataset.type === type || opt.value === '') {
                        opt.style.display = '';
                    } else {
                        opt.style.display = 'none';
                    }
                });
                toOptions.forEach(opt => {
                    if (opt.dataset.type === type || opt.value === '') {
                        opt.style.display = '';
                    } else {
                        opt.style.display = 'none';
                    }
                });
            }
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
        document.getElementById('swapForm').addEventListener('submit', function(e) {
            const fromCurrency = document.querySelector('[name="from_currency"]').value;
            const toCurrency = document.querySelector('[name="to_currency"]').value;
            const amountInput = document.querySelector('[name="amount"]');
            const amount = parseFloat(amountInput.value) || 0;
            
            if (fromCurrency === toCurrency) {
                e.preventDefault();
                alert('Cannot swap to the same currency.');
                return;
            }
            
            if (amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount.');
                return;
            }
            
            // In real app, check balance via AJAX
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            calculateSwap();
            
            // Show success modal if swap was successful
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