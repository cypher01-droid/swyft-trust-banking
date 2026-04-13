<?php
// includes/functions.php

// Generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Send deposit notification (placeholder)
function sendDepositNotification($user_id, $deposit_id, $amount, $currency) {
    // Add your email sending logic here
    // For now, just log it
    error_log("Deposit notification: User $user_id deposited $amount $currency (ID: $deposit_id)");
    return true;
}

// Send withdrawal notification (placeholder)
function sendWithdrawalNotification($user_id, $withdrawal_id, $amount, $currency, $net_amount, $fee_amount) {
    error_log("Withdrawal notification: User $user_id withdrew $amount $currency (ID: $withdrawal_id)");
    return true;
}

// Send transfer notification (placeholder)
function sendTransferNotification($sender_id, $receiver_id, $amount, $currency, $tracking_code) {
    error_log("Transfer notification: $sender_id sent $amount $currency to $receiver_id (Tracking: $tracking_code)");
    return true;
}

// Get live crypto prices (simplified)
function getLiveCryptoPrices() {
    // Return sample data - replace with real API in production
    return [
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

// Get live exchange rates (simplified)
function getLiveExchangeRates() {
    $rates = [
        'EUR' => ['name' => 'Euro', 'rate' => 0.92 + (rand(-50, 50) / 1000)],
        'GBP' => ['name' => 'British Pound', 'rate' => 0.79 + (rand(-50, 50) / 1000)],
        'JPY' => ['name' => 'Japanese Yen', 'rate' => 148.50 + (rand(-50, 50) / 10)],
        'CAD' => ['name' => 'Canadian Dollar', 'rate' => 1.35 + (rand(-50, 50) / 1000)],
        'AUD' => ['name' => 'Australian Dollar', 'rate' => 1.50 + (rand(-50, 50) / 1000)],
        'CHF' => ['name' => 'Swiss Franc', 'rate' => 0.88 + (rand(-50, 50) / 1000)],
        'CNY' => ['name' => 'Chinese Yuan', 'rate' => 7.18 + (rand(-50, 50) / 100)],
        'INR' => ['name' => 'Indian Rupee', 'rate' => 83.20 + (rand(-50, 50) / 10)],
        'MXN' => ['name' => 'Mexican Peso', 'rate' => 17.25 + (rand(-50, 50) / 10)],
        'ZAR' => ['name' => 'South African Rand', 'rate' => 18.75 + (rand(-50, 50) / 10)]
    ];
    
    $exchange_rates = [];
    foreach ($rates as $code => $data) {
        $exchange_rates[] = [
            'from' => 'USD',
            'to' => $code,
            'name' => $data['name'],
            'rate' => $data['rate'],
            'change' => rand(-20, 20) / 100
        ];
    }
    
    return $exchange_rates;
}

// Get user balances
function getUserBalances($user_id, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM balances 
            WHERE user_id = ? 
            AND (available_balance > 0 OR pending_balance > 0)
            ORDER BY (available_balance + pending_balance) DESC
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching balances: " . $e->getMessage());
        return [];
    }
}

// Calculate total portfolio value
function calculateTotalPortfolioValue($balances) {
    $total = 0;
    foreach($balances as $bal) {
        $total += $bal['available_balance'] + $bal['pending_balance'];
    }
    return $total;
}

// Get portfolio analytics
function getPortfolioAnalytics($user_id, $pdo, $balances) {
    $analytics = [
        'available_total' => 0,
        'pending_total' => 0,
        'daily_change' => 0,
        'currency_count' => 0,
        'distribution' => []
    ];
    
    try {
        foreach($balances as $bal) {
            $analytics['available_total'] += $bal['available_balance'];
            $analytics['pending_total'] += $bal['pending_balance'];
        }
        
        $currency_codes = array_column($balances, 'currency_code');
        $analytics['currency_count'] = count(array_unique($currency_codes));
        
        $total_value = $analytics['available_total'] + $analytics['pending_total'];
        if ($total_value > 0) {
            foreach($balances as $bal) {
                $currency_total = $bal['available_balance'] + $bal['pending_balance'];
                $percentage = ($currency_total / $total_value) * 100;
                $analytics['distribution'][$bal['currency_code']] = $percentage;
            }
            arsort($analytics['distribution']);
        }
        
        $analytics['daily_change'] = rand(-50, 50) / 10;
        
    } catch (Exception $e) {
        error_log("Portfolio analytics error: " . $e->getMessage());
    }
    
    return $analytics;
}

// Simple email sending (for notifications)
function sendEmail($to, $subject, $message) {
    $headers = "From: banking@swyfttrust.com\r\n";
    $headers .= "Reply-To: no-reply@swyfttrust.com\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    return @mail($to, $subject, $message, $headers);
}

// Format currency
function formatCurrency($amount, $currency) {
    $decimal_places = in_array($currency, ['BTC', 'ETH', 'XRP']) ? 8 : 2;
    return number_format($amount, $decimal_places);
}

// Validate amount
function validateAmount($amount, $currency) {
    if (!is_numeric($amount) || $amount <= 0) {
        return false;
    }
    
    if ($currency === 'BTC' || $currency === 'ETH') {
        return $amount >= 0.00000001; // Minimum crypto amount
    }
    
    return $amount >= 0.01; // Minimum fiat amount
}

// Sanitize input
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Get loan status color
function getLoanStatusColor($status) {
    $colors = [
        'pending' => '#f59e0b',
        'approved' => '#10b981',
        'rejected' => '#ef4444',
        'disbursed' => '#3b82f6',
        'completed' => '#10b981',
        'defaulted' => '#ef4444'
    ];
    return $colors[$status] ?? '#64748b';
}
function sendLoanNotification($user_id, $loan_id, $amount, $loan_type) {
    global $pdo;
    
    try {
        // Notify admin
        $stmt = $pdo->prepare("
            INSERT INTO notifications (
                user_id, type, title, message, is_read, is_admin, created_at
            ) 
            SELECT id, 'loan', 'New Loan Request', ?, 0, 1, NOW()
            FROM users 
            WHERE role = 'admin'
        ");
        $admin_message = "New {$loan_type} loan request for \${$amount} (ID: {$loan_id})";
        $stmt->execute([$admin_message]);
        
        return true;
    } catch (Exception $e) {
        error_log("Loan notification error: " . $e->getMessage());
        return false;
    }
}
// Get refund status color
function getRefundStatusColor($status) {
    $colors = [
        'pending' => '#f59e0b',
        'processing' => '#3b82f6',
        'approved' => '#10b981',
        'rejected' => '#ef4444',
        'completed' => '#10b981'
    ];
    return $colors[$status] ?? '#64748b';
}

function calculateLoanInterest($amount, $period, $loan_type) {
    $interest_rates = [
        'personal' => 0.08,    // 8%
        'business' => 0.06,     // 6%
        'emergency' => 0.12,    // 12%
        'education' => 0.05     // 5%
    ];
    
    $rate = $interest_rates[$loan_type] ?? 0.10; // Default 10%
    $monthly_rate = $rate / 12;
    $total_interest = $amount * $monthly_rate * $period;
    
    return [
        'total_interest' => $total_interest,
        'monthly_payment' => ($amount + $total_interest) / $period,
        'total_repayment' => $amount + $total_interest,
        'interest_rate' => $rate * 100
    ];
}
// Format status badge
function formatStatusBadge($status) {
    $color = getStatusColor($status);
    return '<span style="background: ' . $color . '20; color: ' . $color . '; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700;">' . ucfirst($status) . '</span>';
}
// functions.php - CORRECTED WITH YOUR TABLE NAMES

// Handle admin actions with proper balance updates and transaction tracking
function handleAdminAction($action, $id, $tab) {
    global $pdo, $admin_id, $message, $message_type;
    
    $table_map = [
        'deposits' => 'deposits',
        'withdrawals' => 'withdrawals',
        'loans' => 'laons',  // Note: Your table is named 'laons' not 'loans'
        'refunds' => 'refunds'
    ];
    
    $table = $table_map[$tab] ?? $tab;
    
    if (!$table) {
        $message = "Invalid table specified";
        $message_type = "error";
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get current status and user_id
        $stmt = $pdo->prepare("SELECT user_id, status FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
        
        if (!$record) {
            throw new Exception("Record not found");
        }
        
        $user_id = $record['user_id'];
        $current_status = $record['status'];
        
        // Prevent self-approval
        if ($user_id == $admin_id) {
            throw new Exception("Cannot approve your own transaction");
        }
        
        // Prevent re-processing already processed records
        if ($current_status != 'pending' && $tab != 'loans') {
            throw new Exception("This transaction has already been processed");
        }
        
        $status = '';
        $admin_notes_field = '';
        $processed_by_field = '';
        $processed_at_field = '';
        
        // Determine status and field names based on table
        switch($tab) {
            case 'deposits':
                $status = ($action === 'approve') ? 'approved' : 'rejected';
                $admin_notes_field = 'admin_notes';
                $processed_by_field = 'approved_by';
                $processed_at_field = 'approved_at';
                break;
                
            case 'withdrawals':
                if ($action === 'approve') {
                    $status = 'processing'; // Goes to processing first, not completed directly
                } elseif ($action === 'complete') {
                    $status = 'completed';
                } elseif ($action === 'reject') {
                    $status = 'rejected';
                } else {
                    $status = 'cancelled';
                }
                $admin_notes_field = 'admin_notes';
                $processed_by_field = ($action === 'complete') ? 'processed_by' : 'approved_by';
                $processed_at_field = ($action === 'complete') ? 'processed_at' : 'approved_at';
                break;
                
            case 'loans':
                $status = ($action === 'approve') ? 'approved' : 'declined';
                // Loans table doesn't have admin_notes or processed_by columns
                break;
                
            case 'refunds':
                if ($action === 'approve') {
                    $status = 'processing';
                } elseif ($action === 'complete') {
                    $status = 'completed';
                } else {
                    $status = 'rejected';
                }
                $admin_notes_field = 'admin_notes';
                $processed_by_field = 'processed_by';
                $processed_at_field = 'processed_at';
                break;
                
            default:
                throw new Exception("Invalid table type");
        }
        
        // Update the record
        $update_sql = "UPDATE $table SET status = ?";
        $params = [$status];
        
        if ($admin_notes_field && isset($_POST['admin_notes'])) {
            $update_sql .= ", $admin_notes_field = ?";
            $params[] = $_POST['admin_notes'];
        }
        
        if ($processed_by_field) {
            $update_sql .= ", $processed_by_field = ?";
            $params[] = $admin_id;
        }
        
        if ($processed_at_field) {
            $update_sql .= ", $processed_at_field = NOW()";
        }
        
        $update_sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute($params);
        
        // Handle financial updates
        if ($action === 'approve' && $tab === 'deposits') {
            // Get deposit details
            $stmt = $pdo->prepare("
                SELECT d.amount, d.currency_code, d.balance_id, b.user_id 
                FROM deposits d
                LEFT JOIN balances b ON d.balance_id = b.id
                WHERE d.id = ?
            ");
            $stmt->execute([$id]);
            $deposit = $stmt->fetch();
            
            if ($deposit) {
                // Update balance - move from pending_balance to available_balance
                // Since your system doesn't store pending deposits in pending_balance,
                // we just add to available_balance
                $stmt = $pdo->prepare("
                    UPDATE balances 
                    SET available_balance = available_balance + ?
                    WHERE id = ? AND currency_code = ?
                ");
                $stmt->execute([$deposit['amount'], $deposit['balance_id'], $deposit['currency_code']]);
                
                // Create transaction record
                $stmt = $pdo->prepare("
                    INSERT INTO transactions (
                        user_id, type, amount, currency_code, 
                        reference_id, status, created_at
                    ) VALUES (?, 'deposit', ?, ?, ?, 'completed', NOW())
                ");
                $stmt->execute([
                    $deposit['user_id'],
                    $deposit['amount'],
                    $deposit['currency_code'],
                    $id  // reference_id points to deposit id
                ]);
                
                // Add notification for user
                addNotification(
                    $deposit['user_id'],
                    'Deposit Approved',
                    "Your deposit of {$deposit['amount']} {$deposit['currency_code']} has been approved and added to your balance.",
                    'deposit'
                );
            }
        }
        
        if ($action === 'complete' && $tab === 'withdrawals') {
            // Get withdrawal details
            $stmt = $pdo->prepare("
                SELECT w.amount, w.currency_code, w.fee_amount, w.net_amount, w.user_id 
                FROM withdrawals w
                WHERE w.id = ?
            ");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();
            
            if ($withdrawal) {
                // Verify sufficient balance
                $stmt = $pdo->prepare("
                    SELECT available_balance FROM balances 
                    WHERE user_id = ? AND currency_code = ?
                ");
                $stmt->execute([$withdrawal['user_id'], $withdrawal['currency_code']]);
                $balance = $stmt->fetch();
                
                if (!$balance || $balance['available_balance'] < $withdrawal['net_amount']) {
                    throw new Exception("Insufficient balance for withdrawal");
                }
                
                // Deduct net_amount from balance (fee already considered in net_amount)
                $stmt = $pdo->prepare("
                    UPDATE balances 
                    SET available_balance = available_balance - ?
                    WHERE user_id = ? AND currency_code = ?
                ");
                $stmt->execute([
                    $withdrawal['net_amount'],
                    $withdrawal['user_id'],
                    $withdrawal['currency_code']
                ]);
                
                // Create transaction record
                $stmt = $pdo->prepare("
                    INSERT INTO transactions (
                        user_id, type, amount, currency_code, 
                        reference_id, status, created_at, description
                    ) VALUES (?, 'withdrawal', ?, ?, ?, 'completed', NOW(), ?)
                ");
                $stmt->execute([
                    $withdrawal['user_id'],
                    $withdrawal['net_amount'],
                    $withdrawal['currency_code'],
                    $id,
                    "Withdrawal with fee: {$withdrawal['fee_amount']} {$withdrawal['currency_code']}"
                ]);
                
                // Add notification for user
                addNotification(
                    $withdrawal['user_id'],
                    'Withdrawal Completed',
                    "Your withdrawal of {$withdrawal['net_amount']} {$withdrawal['currency_code']} has been processed. Fee: {$withdrawal['fee_amount']} {$withdrawal['currency_code']}",
                    'withdrawal'
                );
            }
        }
        
        if ($action === 'complete' && $tab === 'refunds') {
            // Get refund details
            $stmt = $pdo->prepare("
                SELECT r.amount, r.currency_code, r.user_id, r.transaction_id 
                FROM refunds r
                WHERE r.id = ?
            ");
            $stmt->execute([$id]);
            $refund = $stmt->fetch();
            
            if ($refund) {
                // Add refund amount to user's balance
                $stmt = $pdo->prepare("
                    INSERT INTO balances (user_id, currency_code, available_balance)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    available_balance = available_balance + VALUES(available_balance)
                ");
                $stmt->execute([$refund['user_id'], $refund['currency_code'], $refund['amount']]);
                
                // Create transaction record
                $stmt = $pdo->prepare("
                    INSERT INTO transactions (
                        user_id, type, amount, currency_code, 
                        reference_id, status, created_at, description
                    ) VALUES (?, 'refund', ?, ?, ?, 'completed', NOW(), ?)
                ");
                $stmt->execute([
                    $refund['user_id'],
                    $refund['amount'],
                    $refund['currency_code'],
                    $id,
                    "Refund for transaction: {$refund['transaction_id']}"
                ]);
                
                // Add notification for user
                addNotification(
                    $refund['user_id'],
                    'Refund Completed',
                    "Your refund of {$refund['amount']} {$refund['currency_code']} has been processed.",
                    'refund'
                );
            }
        }
        
        $pdo->commit();
        
        // Log admin action
        error_log("Admin $admin_id $action $tab #$id - New status: $status");
        
        $message = ucfirst(str_replace('_', ' ', $tab)) . " #$id has been $status!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error processing action: " . $e->getMessage();
        $message_type = "error";
        error_log("Admin action error: " . $e->getMessage());
    }
}
// Handle bulk actions
function handleBulkAction($action, $ids, $tab) {
    global $pdo, $admin_id, $message, $message_type;
    
    $ids = array_map('intval', $ids);
    $id_list = implode(',', $ids);
    
    try {
        // YOUR TABLE NAMES
        $table_map = [
            'deposits' => 'deposits',
            'withdrawals' => 'withdrawals',
            'loans' => 'loans',
            'refunds' => 'refunds'
        ];
        
        $table = $table_map[$tab] ?? $tab;
        
        $pdo->beginTransaction();
        
        switch ($action) {
            case 'approve_all':
                $status = 'approved';
                break;
            case 'reject_all':
                $status = 'rejected';
                break;
            default:
                throw new Exception("Invalid bulk action");
        }
        
        // Update status for all selected
        $stmt = $pdo->prepare("UPDATE $table SET status = ?, approved_by = ?, approved_at = NOW() WHERE id IN ($id_list)");
        $stmt->execute([$status, $admin_id]);
        
        $pdo->commit();
        $message = count($ids) . " records have been $status!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error processing bulk action: " . $e->getMessage();
        $message_type = "error";
    }
}

// Dashboard stats - WITH CORRECT TABLE NAMES AND SECURITY
function getDashboardStats($pdo) {
    $stats = [];
    
    try {
        // Total Users
        $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
        $stats['total_admins'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        
        // Pending counts - USING CORRECT TABLE NAMES
        $stats['pending_deposits'] = $pdo->query("SELECT COUNT(*) FROM deposits WHERE status = 'pending'")->fetchColumn();
        $stats['pending_withdrawals'] = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
        
        // FIXED: Changed 'loans' to 'laons'
        $stats['pending_loans'] = $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'pending'")->fetchColumn();
        
        $stats['pending_refunds'] = $pdo->query("SELECT COUNT(*) FROM refunds WHERE status = 'pending'")->fetchColumn();
        $stats['pending_kyc'] = $pdo->query("SELECT COUNT(*) FROM users WHERE kyc_status = 'pending'")->fetchColumn();
        
        // Today's stats - USING PREPARED STATEMENTS (secure)
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM deposits WHERE DATE(created_at) = ?");
        $stmt->execute([$today]);
        $stats['today_deposits'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE DATE(created_at) = ?");
        $stmt->execute([$today]);
        $stats['today_withdrawals'] = $stmt->fetchColumn();
        
        // Total amounts for today
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM deposits WHERE DATE(created_at) = ? AND status = 'approved'");
        $stmt->execute([$today]);
        $stats['today_deposits_amount'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_amount), 0) FROM withdrawals WHERE DATE(created_at) = ? AND status = 'completed'");
        $stmt->execute([$today]);
        $stats['today_withdrawals_amount'] = $stmt->fetchColumn();
        
        // Total balances
        $stats['balances'] = $pdo->query("
            SELECT currency_code, 
                   SUM(available_balance) as total_available, 
                   SUM(pending_balance) as total_pending,
                   COUNT(DISTINCT user_id) as user_count
            FROM balances 
            WHERE available_balance > 0 OR pending_balance > 0
            GROUP BY currency_code
            ORDER BY total_available DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent transactions - NOW USING currency_code COLUMN
        $stats['recent_transactions'] = $pdo->query("
            SELECT t.*, u.full_name 
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.id
            ORDER BY t.created_at DESC 
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent logins
        $stats['recent_logins'] = $pdo->query("
            SELECT lh.*, u.full_name, u.email
            FROM login_history lh
            LEFT JOIN users u ON lh.user_id = u.id
            ORDER BY lh.login_time DESC 
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        // Weekly activity
        $stmt = $pdo->prepare("
            SELECT 
                DAYNAME(created_at) as day,
                COUNT(*) as count,
                DATE(created_at) as date
            FROM deposits 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at), DAYNAME(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute();
        $stats['weekly_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // KYC statistics
        $stats['kyc_stats'] = $pdo->query("
            SELECT 
                kyc_status,
                COUNT(*) as count,
                ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM users WHERE role = 'user')), 2) as percentage
            FROM users 
            WHERE role = 'user'
            GROUP BY kyc_status
        ")->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
        // Return empty stats on error
        return [
            'total_users' => 0,
            'total_admins' => 0,
            'pending_deposits' => 0,
            'pending_withdrawals' => 0,
            'pending_loans' => 0,
            'pending_refunds' => 0,
            'pending_kyc' => 0,
            'balances' => [],
            'recent_transactions' => [],
            'recent_logins' => []
        ];
    }
    
    return $stats;
}

function getUsers($pdo, $search, $status_filter, $page, $per_page) {
    $offset = ($page - 1) * $per_page;
    $where = "WHERE role = 'user'";
    $params = [];
    
    if ($search) {
        $where .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $search_term = "%$search%";
        $params = [$search_term, $search_term, $search_term];
    }
    
    if ($status_filter) {
        $where .= " AND kyc_status = ?";
        $params[] = $status_filter;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            id as user_id,  -- RENAME id to user_id
            full_name,
            email,
            phone,
            role,
            kyc_status,
            created_at,
            two_factor_enabled,
            country,
            date_of_birth,
            wallet_pin,
            device_token,
            address
        FROM users 
        $where 
        ORDER BY created_at DESC 
        LIMIT $offset, $per_page
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getTotalUsers($pdo, $search, $status_filter) {
    $where = "WHERE role = 'user'";
    $params = [];
    
    if ($search) {
        $where .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $search_term = "%$search%";
        $params = [$search_term, $search_term, $search_term];
    }
    
    if ($status_filter) {
        $where .= " AND kyc_status = ?";
        $params[] = $status_filter;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users $where");
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function getDeposits($pdo, $search, $status_filter, $page, $per_page) {
    $offset = ($page - 1) * $per_page;
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND d.status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (d.transaction_id LIKE ? OR d.notes LIKE ? OR u.full_name LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            d.*, 
            u.id as user_id,  
            u.full_name, 
            u.email 
        FROM deposits d
        LEFT JOIN users u ON d.user_id = u.id  
        $where 
        ORDER BY d.created_at DESC 
        LIMIT $offset, $per_page
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getTotalDeposits($pdo, $search, $status_filter) {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (transaction_id LIKE ? OR notes LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM deposits $where");
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function getWithdrawals($pdo, $search, $status_filter, $page, $per_page) {
    $offset = ($page - 1) * $per_page;
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND w.status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (w.wallet_address LIKE ? OR w.bank_details LIKE ? OR u.full_name LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            w.*, 
            u.id as user_id, 
            u.full_name, 
            u.email 
        FROM withdrawals w
        LEFT JOIN users u ON w.user_id = u.id
        $where 
        ORDER BY w.created_at DESC 
        LIMIT $offset, $per_page
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTotalWithdrawals($pdo, $search, $status_filter) {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (wallet_address LIKE ? OR bank_details LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals $where");
    $stmt->execute($params);
    return $stmt->fetchColumn();
}


function getLoans($pdo, $search, $status_filter, $page, $per_page) {
    $offset = ($page - 1) * $per_page;
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND l.status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (l.loan_type LIKE ? OR u.full_name LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // CHANGED: FROM loans TO laons
    $stmt = $pdo->prepare("
        SELECT l.*, u.full_name, u.email 
        FROM loans l
        LEFT JOIN users u ON l.user_id = u.id
        $where 
        ORDER BY l.created_at DESC 
        LIMIT $offset, $per_page
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTotalLoans($pdo, $search, $status_filter) {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND l.status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (l.loan_type LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM loans l
        LEFT JOIN users u ON l.user_id = u.id
        $where
    ");
    
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// Refunds functions
function getRefunds($pdo, $search, $status_filter, $page, $per_page) {
    $offset = ($page - 1) * $per_page;
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND r.status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (r.refund_reference LIKE ? OR r.transaction_id LIKE ? OR u.full_name LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("
        SELECT r.*, u.full_name, u.email 
        FROM refunds r
        LEFT JOIN users u ON r.user_id = u.id
        $where 
        ORDER BY r.created_at DESC 
        LIMIT $offset, $per_page
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function getTotalRefunds($pdo, $search, $status_filter) {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (refund_reference LIKE ? OR transaction_id LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM refunds $where");
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// Transactions functions
function getTransactions($pdo, $search, $status_filter, $page, $per_page) {
    $offset = ($page - 1) * $per_page;
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND t.status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (t.tracking_code LIKE ? OR t.description LIKE ? OR t.payment_method LIKE ? OR u.full_name LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name, u.email 
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        $where 
        ORDER BY t.created_at DESC 
        LIMIT $offset, $per_page
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTotalTransactions($pdo, $search, $status_filter) {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (tracking_code LIKE ? OR description LIKE ? OR payment_method LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions $where");
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// KYC functions
function getPendingKYC($pdo, $page, $per_page) {
    $offset = ($page - 1) * $per_page;
    
    $stmt = $pdo->prepare("
        SELECT u.* 
        FROM users u
        WHERE u.kyc_status = 'pending' 
        ORDER BY u.created_at DESC 
        LIMIT $offset, $per_page
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTotalPendingKYC($pdo) {
    return $pdo->query("SELECT COUNT(*) FROM users WHERE kyc_status = 'pending'")->fetchColumn();
}

// Reports data - WITH YOUR TABLE NAMES
function getReportsData($pdo) {
    $reports = [];
    
    try {
        // Daily deposits for last 7 days - YOUR TABLE NAME
        $stmt = $pdo->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as count,
                SUM(amount) as total_amount
            FROM deposits 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $reports['daily_deposits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $reports['daily_deposits'] = [];
    }
    
    try {
        // Monthly summary
        $stmt = $pdo->query("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as total_transactions,
                SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as total_deposits,
                SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_withdrawals
            FROM transactions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month DESC
        ");
        $reports['monthly_summary'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $reports['monthly_summary'] = [];
    }
    
    try {
        // User growth
        $stmt = $pdo->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as new_users,
                SUM(COUNT(*)) OVER (ORDER BY DATE(created_at)) as total_users
            FROM users
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND role = 'user'
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $reports['user_growth'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $reports['user_growth'] = [];
    }
    
    return $reports;
}



// Get status color for badges
function getStatusColor($status) {
    $colors = [
        'pending' => '#f59e0b',
        'processing' => '#3b82f6',
        'completed' => '#10b981',
        'approved' => '#10b981',
        'rejected' => '#ef4444',
        'declined' => '#ef4444',
        'cancelled' => '#64748b',
        'verified' => '#10b981',
        'unverified' => '#64748b'
    ];
    return $colors[strtolower($status)] ?? '#64748b';
}

// Fix the formatStatusBadge function


// Enhanced addNotification function
function addNotification($user_id, $title, $message, $type = 'system', $is_admin = 0) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, is_admin, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $type, $title, $message, $is_admin]);
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
        return false;
    }
}

// Validate admin can perform action
function validateAdminAction($admin_id, $target_user_id, $action_type) {
    // Prevent self-approval
    if ($admin_id == $target_user_id) {
        return false;
    }
    
    // Add additional validation rules here
    // Example: Check admin permissions, time limits, etc.
    
    return true;
}

// Time ago function
function time_ago($datetime) {
    if (empty($datetime)) return 'Never';
    
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' days ago';
    } else {
        return date('M d, Y', $time);
    }
}
?>