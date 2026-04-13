<?php
// stats.php or analytics.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// Date range for stats (default: last 30 days)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get account summary
$account_summary = [
    'total_balance' => 0,
    'available_balance' => 0,
    'pending_balance' => 0,
    'total_deposits' => 0,
    'total_withdrawals' => 0,
    'net_flow' => 0
];

try {
    // Get balances
    $stmt = $pdo->prepare("
        SELECT 
            SUM(available_balance) as available,
            SUM(pending_balance) as pending
        FROM balances 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $balances = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $account_summary['available_balance'] = $balances['available'] ?? 0;
    $account_summary['pending_balance'] = $balances['pending'] ?? 0;
    $account_summary['total_balance'] = $account_summary['available_balance'] + $account_summary['pending_balance'];
    
    // Get deposits total
    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total 
        FROM deposits 
        WHERE user_id = ? 
        AND status IN ('approved', 'completed')
        AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$user_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $deposits = $stmt->fetch(PDO::FETCH_ASSOC);
    $account_summary['total_deposits'] = $deposits['total'] ?? 0;
    
    // Get withdrawals total
    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total 
        FROM withdrawals 
        WHERE user_id = ? 
        AND status IN ('approved', 'completed')
        AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$user_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $withdrawals = $stmt->fetch(PDO::FETCH_ASSOC);
    $account_summary['total_withdrawals'] = $withdrawals['total'] ?? 0;
    
    // Calculate net flow
    $account_summary['net_flow'] = $account_summary['total_deposits'] - $account_summary['total_withdrawals'];
    
} catch (Exception $e) {
    error_log("Stats calculation error: " . $e->getMessage());
}

// Get monthly data for chart
$monthly_data = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as deposits,
            SUM(CASE WHEN type = 'withdrawal' THEN -amount ELSE 0 END) as withdrawals
        FROM (
            SELECT 'deposit' as type, amount, created_at FROM deposits WHERE user_id = ? AND status IN ('approved', 'completed')
            UNION ALL
            SELECT 'withdrawal' as type, amount, created_at FROM withdrawals WHERE user_id = ? AND status IN ('approved', 'completed')
        ) as transactions
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $stmt->execute([$user_id, $user_id]);
    $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Monthly data error: " . $e->getMessage());
}

// Get transaction counts
$transaction_counts = [
    'total' => 0,
    'deposits' => 0,
    'withdrawals' => 0,
    'pending' => 0,
    'completed' => 0
];

try {
    // Total transactions
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM deposits WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $transaction_counts['deposits'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM withdrawals WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $transaction_counts['withdrawals'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $transaction_counts['total'] = $transaction_counts['deposits'] + $transaction_counts['withdrawals'];
    
    // Pending transactions
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM deposits WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    $pending_deposits = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM withdrawals WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    $pending_withdrawals = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $transaction_counts['pending'] = $pending_deposits + $pending_withdrawals;
    $transaction_counts['completed'] = $transaction_counts['total'] - $transaction_counts['pending'];
    
} catch (Exception $e) {
    error_log("Transaction count error: " . $e->getMessage());
}

// Get currency distribution
$currency_distribution = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            currency_code,
            SUM(available_balance + pending_balance) as total_balance,
            COUNT(*) as account_count
        FROM balances 
        WHERE user_id = ? 
        AND (available_balance > 0 OR pending_balance > 0)
        GROUP BY currency_code
        ORDER BY total_balance DESC
    ");
    $stmt->execute([$user_id]);
    $currency_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Currency distribution error: " . $e->getMessage());
}

// Get recent activity
$recent_activity = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM (
            SELECT 
                'deposit' as type,
                id,
                amount,
                currency_code as currency,
                status,
                created_at,
                CONCAT('Deposit: $', amount) as description
            FROM deposits 
            WHERE user_id = ?
            
            UNION ALL
            
            SELECT 
                'withdrawal' as type,
                id,
                amount,
                currency_code as currency,
                status,
                created_at,
                CONCAT('Withdrawal: $', amount) as description
            FROM withdrawals 
            WHERE user_id = ?
        ) as activities
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id, $user_id]);
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Recent activity error: " . $e->getMessage());
}

// Calculate growth percentage
$growth_percentage = 0;
try {
    // Get current month deposits
    $current_month_start = date('Y-m-01');
    $current_month_end = date('Y-m-t');
    
    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total 
        FROM deposits 
        WHERE user_id = ? 
        AND status IN ('approved', 'completed')
        AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$user_id, $current_month_start . ' 00:00:00', $current_month_end . ' 23:59:59']);
    $current_month = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_month_total = $current_month['total'] ?? 0;
    
    // Get last month deposits
    $last_month_start = date('Y-m-01', strtotime('-1 month'));
    $last_month_end = date('Y-m-t', strtotime('-1 month'));
    
    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total 
        FROM deposits 
        WHERE user_id = ? 
        AND status IN ('approved', 'completed')
        AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$user_id, $last_month_start . ' 00:00:00', $last_month_end . ' 23:59:59']);
    $last_month = $stmt->fetch(PDO::FETCH_ASSOC);
    $last_month_total = $last_month['total'] ?? 0;
    
    // Calculate growth percentage
    if ($last_month_total > 0) {
        $growth_percentage = (($current_month_total - $last_month_total) / $last_month_total) * 100;
    } elseif ($current_month_total > 0) {
        $growth_percentage = 100; // First month with deposits
    }
    
} catch (Exception $e) {
    error_log("Growth calculation error: " . $e->getMessage());
}

include './dashboard_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Account Statistics - Zeus Bank</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .stats-container {
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
        
        /* Date Filter */
        .date-filter {
            background: #111113;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .filter-title {
            font-size: 0.9rem;
            color: #9d50ff;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .date-input {
            width: 100%;
            padding: 12px;
            background: #0a0a0c;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        
        .quick-date-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .quick-date-btn {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: none;
            border-radius: 20px;
            color: #94a3b8;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .quick-date-btn:hover, .quick-date-btn.active {
            background: rgba(157, 80, 255, 0.2);
            color: #9d50ff;
        }
        
        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: #111113;
            border-radius: 15px;
            padding: 20px;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .summary-title {
            font-size: 0.8rem;
            color: #94a3b8;
            font-weight: 600;
        }
        
        .summary-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        
        .balance-icon { background: rgba(157, 80, 255, 0.1); color: #9d50ff; }
        .deposit-icon { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .withdrawal-icon { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        .flow-icon { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 900;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .summary-change {
            font-size: 0.8rem;
            font-weight: 700;
        }
        
        .change-positive {
            color: #10b981;
        }
        
        .change-negative {
            color: #ef4444;
        }
        
        /* Charts Section */
        .charts-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #fff;
        }
        
        .chart-container {
            background: #111113;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .chart-title {
            font-size: 0.9rem;
            color: #9d50ff;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-wrapper {
            height: 200px;
            position: relative;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #111113;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 900;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        /* Currency Distribution */
        .currency-distribution {
            background: #111113;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .currency-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .currency-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 12px;
        }
        
        .currency-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .currency-symbol {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(157, 80, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #9d50ff;
        }
        
        .currency-name {
            font-weight: 700;
            color: #fff;
            font-size: 0.95rem;
        }
        
        .currency-balance {
            font-weight: 900;
            color: #fff;
        }
        
        /* Recent Activity */
        .recent-activity {
            background: #111113;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 12px;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-desc {
            font-weight: 600;
            color: #fff;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }
        
        .activity-meta {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        .activity-amount {
            font-weight: 900;
            color: #fff;
        }
        
        .amount-positive {
            color: #10b981;
        }
        
        .amount-negative {
            color: #ef4444;
        }
        
        /* Loading State */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }
        
        @media (max-width: 480px) {
            .stats-container {
                padding: 60px 0 80px;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .date-inputs {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="stats-container">
        <a href="./" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Account Statistics</h1>
        
        <!-- Date Filter -->
        <div class="date-filter">
            <div class="filter-title">
                <i class="fas fa-calendar-alt"></i> Date Range
            </div>
            
            <form method="GET" action="" id="dateForm">
                <div class="date-inputs">
                    <input type="date" 
                           name="start_date" 
                           class="date-input" 
                           value="<?php echo $start_date; ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                    
                    <input type="date" 
                           name="end_date" 
                           class="date-input" 
                           value="<?php echo $end_date; ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="quick-date-buttons">
                    <button type="button" class="quick-date-btn" onclick="setDateRange('today')">Today</button>
                    <button type="button" class="quick-date-btn" onclick="setDateRange('week')">This Week</button>
                    <button type="button" class="quick-date-btn active" onclick="setDateRange('month')">This Month</button>
                    <button type="button" class="quick-date-btn" onclick="setDateRange('quarter')">Last 3 Months</button>
                    <button type="button" class="quick-date-btn" onclick="setDateRange('year')">This Year</button>
                </div>
                
                <button type="submit" style="display: none;"></button>
            </form>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-header">
                    <div class="summary-title">Total Balance</div>
                    <div class="summary-icon balance-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                </div>
                <div class="summary-value">$<?php echo number_format($account_summary['total_balance'], 2); ?></div>
                <div class="summary-change <?php echo $growth_percentage >= 0 ? 'change-positive' : 'change-negative'; ?>">
                    <?php echo $growth_percentage >= 0 ? '↗' : '↘'; ?> 
                    <?php echo number_format(abs($growth_percentage), 1); ?>% from last month
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-header">
                    <div class="summary-title">Net Cash Flow</div>
                    <div class="summary-icon flow-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                </div>
                <div class="summary-value <?php echo $account_summary['net_flow'] >= 0 ? 'change-positive' : 'change-negative'; ?>">
                    <?php echo $account_summary['net_flow'] >= 0 ? '+' : ''; ?>
                    $<?php echo number_format($account_summary['net_flow'], 2); ?>
                </div>
                <div class="summary-change">
                    <?php echo $account_summary['net_flow'] >= 0 ? 'Positive' : 'Negative'; ?> cash flow
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-header">
                    <div class="summary-title">Total Deposits</div>
                    <div class="summary-icon deposit-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                </div>
                <div class="summary-value change-positive">
                    +$<?php echo number_format($account_summary['total_deposits'], 2); ?>
                </div>
                <div class="summary-change">
                    Since <?php echo date('M j', strtotime($start_date)); ?>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="summary-header">
                    <div class="summary-title">Total Withdrawals</div>
                    <div class="summary-icon withdrawal-icon">
                        <i class="fas fa-minus-circle"></i>
                    </div>
                </div>
                <div class="summary-value change-negative">
                    -$<?php echo number_format($account_summary['total_withdrawals'], 2); ?>
                </div>
                <div class="summary-change">
                    Since <?php echo date('M j', strtotime($start_date)); ?>
                </div>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="charts-section">
            <h2 class="section-title">Financial Overview</h2>
            
            <!-- Monthly Activity Chart -->
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-line"></i> Monthly Activity
                </div>
                <div class="chart-wrapper">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
            
            <!-- Transaction Distribution -->
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-pie"></i> Transaction Distribution
                </div>
                <div class="chart-wrapper">
                    <canvas id="distributionChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $transaction_counts['total']; ?></div>
                <div class="stat-label">Total Transactions</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?php echo $transaction_counts['deposits']; ?></div>
                <div class="stat-label">Deposits</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?php echo $transaction_counts['withdrawals']; ?></div>
                <div class="stat-label">Withdrawals</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?php echo $transaction_counts['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        
        <!-- Currency Distribution -->
        <?php if (!empty($currency_distribution)): ?>
        <div class="currency-distribution">
            <div class="chart-title">
                <i class="fas fa-coins"></i> Currency Distribution
            </div>
            
            <div class="currency-list">
                <?php foreach ($currency_distribution as $currency): 
                    $percentage = $account_summary['total_balance'] > 0 ? 
                        ($currency['total_balance'] / $account_summary['total_balance']) * 100 : 0;
                ?>
                <div class="currency-item">
                    <div class="currency-info">
                        <div class="currency-symbol"><?php echo $currency['currency_code']; ?></div>
                        <div>
                            <div class="currency-name"><?php echo $currency['currency_code']; ?></div>
                            <div style="font-size: 0.75rem; color: #64748b;">
                                <?php echo number_format($percentage, 1); ?>% of portfolio
                            </div>
                        </div>
                    </div>
                    <div class="currency-balance">
                        $<?php echo number_format($currency['total_balance'], 2); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recent Activity -->
        <?php if (!empty($recent_activity)): ?>
        <div class="recent-activity">
            <div class="chart-title">
                <i class="fas fa-history"></i> Recent Activity
            </div>
            
            <div class="activity-list">
                <?php foreach ($recent_activity as $activity): 
                    $type = $activity['type'];
                    $amount = floatval($activity['amount']);
                    $is_positive = $type === 'deposit';
                    $amount_class = $is_positive ? 'amount-positive' : 'amount-negative';
                    $amount_display = ($is_positive ? '+' : '-') . '$' . number_format(abs($amount), 2);
                ?>
                <div class="activity-item">
                    <div class="activity-icon <?php echo $type === 'deposit' ? 'deposit-icon' : 'withdrawal-icon'; ?>">
                        <?php if ($type === 'deposit'): ?>
                            <i class="fas fa-plus"></i>
                        <?php else: ?>
                            <i class="fas fa-minus"></i>
                        <?php endif; ?>
                    </div>
                    <div class="activity-details">
                        <div class="activity-desc">
                            <?php echo htmlspecialchars($activity['description']); ?>
                        </div>
                        <div class="activity-meta">
                            <?php echo date('M d, H:i', strtotime($activity['created_at'])); ?> • 
                            <span style="color: <?php echo $activity['status'] === 'completed' ? '#10b981' : '#f59e0b'; ?>;">
                                <?php echo ucfirst($activity['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="activity-amount <?php echo $amount_class; ?>">
                        <?php echo $amount_display; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Monthly Activity Chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyChart = new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: <?php 
                    $labels = [];
                    foreach ($monthly_data as $data) {
                        $labels[] = date('M Y', strtotime($data['month'] . '-01'));
                    }
                    echo json_encode(array_reverse($labels)); 
                ?>,
                datasets: [
                    {
                        label: 'Deposits',
                        data: <?php 
                            $deposits = [];
                            foreach ($monthly_data as $data) {
                                $deposits[] = $data['deposits'] ?? 0;
                            }
                            echo json_encode(array_reverse($deposits));
                        ?>,
                        backgroundColor: 'rgba(16, 185, 129, 0.5)',
                        borderColor: '#10b981',
                        borderWidth: 1
                    },
                    {
                        label: 'Withdrawals',
                        data: <?php 
                            $withdrawals = [];
                            foreach ($monthly_data as $data) {
                                $withdrawals[] = abs($data['withdrawals'] ?? 0);
                            }
                            echo json_encode(array_reverse($withdrawals));
                        ?>,
                        backgroundColor: 'rgba(239, 68, 68, 0.5)',
                        borderColor: '#ef4444',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: '#94a3b8',
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(17, 17, 19, 0.9)',
                        titleColor: '#fff',
                        bodyColor: '#94a3b8',
                        borderColor: '#9d50ff',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        },
                        ticks: {
                            color: '#94a3b8',
                            font: {
                                size: 10
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        },
                        ticks: {
                            color: '#94a3b8',
                            font: {
                                size: 10
                            },
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        // Transaction Distribution Chart
        const distributionCtx = document.getElementById('distributionChart').getContext('2d');
        const distributionChart = new Chart(distributionCtx, {
            type: 'doughnut',
            data: {
                labels: ['Deposits', 'Withdrawals', 'Pending'],
                datasets: [{
                    data: [
                        <?php echo $transaction_counts['deposits']; ?>,
                        <?php echo $transaction_counts['withdrawals']; ?>,
                        <?php echo $transaction_counts['pending']; ?>
                    ],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.7)',
                        'rgba(239, 68, 68, 0.7)',
                        'rgba(245, 158, 11, 0.7)'
                    ],
                    borderColor: [
                        '#10b981',
                        '#ef4444',
                        '#f59e0b'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            color: '#94a3b8',
                            font: {
                                size: 11
                            },
                            padding: 20
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(17, 17, 19, 0.9)',
                        titleColor: '#fff',
                        bodyColor: '#94a3b8',
                        borderColor: '#9d50ff',
                        borderWidth: 1
                    }
                }
            }
        });
        
        // Date Range Functions
        function setDateRange(range) {
            const today = new Date();
            let startDate, endDate;
            
            switch(range) {
                case 'today':
                    startDate = endDate = today.toISOString().split('T')[0];
                    break;
                case 'week':
                    startDate = new Date(today.setDate(today.getDate() - 7)).toISOString().split('T')[0];
                    endDate = new Date().toISOString().split('T')[0];
                    break;
                case 'month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    endDate = new Date().toISOString().split('T')[0];
                    break;
                case 'quarter':
                    startDate = new Date(today.getFullYear(), today.getMonth() - 3, 1).toISOString().split('T')[0];
                    endDate = new Date().toISOString().split('T')[0];
                    break;
                case 'year':
                    startDate = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                    endDate = new Date().toISOString().split('T')[0];
                    break;
            }
            
            document.querySelector('[name="start_date"]').value = startDate;
            document.querySelector('[name="end_date"]').value = endDate;
            document.getElementById('dateForm').submit();
        }
        
        // Update quick date buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Set max date to today
            const today = new Date().toISOString().split('T')[0];
            document.querySelector('[name="end_date"]').max = today;
            document.querySelector('[name="start_date"]').max = today;
            
            // Update button states
            const buttons = document.querySelectorAll('.quick-date-btn');
            buttons.forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Auto-submit form on date change
            document.querySelectorAll('.date-input').forEach(input => {
                input.addEventListener('change', function() {
                    document.getElementById('dateForm').submit();
                });
            });
        });
        
        // Auto-refresh stats every 60 seconds
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>