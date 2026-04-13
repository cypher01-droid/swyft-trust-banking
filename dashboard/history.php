<?php
// history.php - COMPLETE CORRECTED VERSION
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Get all transactions without pagination for export
    $export_query = "
        SELECT * FROM (
            SELECT 
                d.id,
                'deposit' as type,
                d.amount,
                d.currency_code as currency,
                d.method as details,
                d.transaction_id as reference,
                d.status,
                d.created_at,
                CONCAT('Deposit via ', d.method) as description,
                d.admin_notes as notes,
                NULL as other_party
            FROM deposits d
            WHERE d.user_id = :user_id
            
            UNION ALL
            
            SELECT 
                w.id,
                'withdrawal' as type,
                w.net_amount as amount,
                w.currency_code as currency,
                w.method as details,
                w.wallet_address as reference,
                w.status,
                w.created_at,
                CONCAT('Withdrawal via ', w.method) as description,
                w.admin_notes as notes,
                NULL as other_party
            FROM withdrawals w
            WHERE w.user_id = :user_id
            
            UNION ALL
            
            SELECT 
                l.id,
                'loan' as type,
                l.requested_amount as amount,
                'USD' as currency,
                CONCAT(l.loan_type, ' - ', l.repayment_period, ' months') as details,
                l.tracking_code as reference,
                l.status,
                l.created_at,
                CONCAT('Loan: ', COALESCE(l.purpose, 'N/A')) as description,
                l.admin_notes as notes,
                NULL as other_party
            FROM loans l
            WHERE l.user_id = :user_id
        ) as transactions
        ORDER BY created_at DESC
    ";
    
    $stmt = $pdo->prepare($export_query);
    $stmt->execute([':user_id' => $user_id]);
    $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transactions_' . date('Y-m-d') . '.csv');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Add headers
    fputcsv($output, [
        'Date', 'Time', 'Type', 'Description', 'Amount', 'Currency', 
        'Status', 'Reference', 'Details', 'Notes'
    ]);
    
    // Add data rows
    foreach ($export_data as $row) {
        $date = date('Y-m-d', strtotime($row['created_at']));
        $time = date('H:i:s', strtotime($row['created_at']));
        $amount = number_format($row['amount'], 8);
        $sign = $row['type'] === 'withdrawal' ? '-' : '+';
        
        fputcsv($output, [
            $date,
            $time,
            ucfirst($row['type']),
            $row['description'] ?? '',
            $sign . $amount,
            $row['currency'],
            ucfirst($row['status']),
            $row['reference'] ?? '',
            $row['details'] ?? '',
            $row['notes'] ?? ''
        ]);
    }
    
    fclose($output);
    exit();
}

// Filters
$type_filter = $_GET['type'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get counts for each type
$counts = [
    'all' => 0,
    'deposit' => 0,
    'withdrawal' => 0,
    'loan' => 0
];

try {
    // Count deposits
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM deposits WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $counts['deposit'] = $result['count'] ?? 0;
    
    // Count withdrawals
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM withdrawals WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $counts['withdrawal'] = $result['count'] ?? 0;
    
    // Count loans
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM loans WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $counts['loan'] = $result['count'] ?? 0;
    
    // Total count
    $counts['all'] = $counts['deposit'] + $counts['withdrawal'] + $counts['loan'];
    
} catch (Exception $e) {
    error_log("Count query error: " . $e->getMessage());
}

// Get transactions from all tables
$transactions = [];
$total_transactions = 0;
$total_pages = 1;

try {
    // Build queries for each type
    $queries = [];
    $params = [':user_id' => $user_id];
    
    // Deposits query
    if ($type_filter === 'all' || $type_filter === 'deposit') {
        $deposit_sql = "
            SELECT 
                d.id,
                'deposit' as type,
                d.amount,
                d.currency_code as currency,
                d.method as details,
                d.transaction_id as reference,
                d.status,
                d.created_at,
                CONCAT('Deposit via ', d.method) as description,
                d.admin_notes as notes,
                1 as sort_order
            FROM deposits d
            WHERE d.user_id = :user_id
        ";
        
        if ($status_filter !== 'all') {
            $deposit_sql .= " AND d.status = :deposit_status";
            $params[':deposit_status'] = $status_filter;
        }
        
        if (!empty($search)) {
            $deposit_sql .= " AND (
                d.transaction_id LIKE :deposit_search OR 
                d.method LIKE :deposit_search OR
                d.currency_code LIKE :deposit_search
            )";
            $params[':deposit_search'] = "%$search%";
        }
        
        $queries[] = $deposit_sql;
    }
    
    // Withdrawals query
    if ($type_filter === 'all' || $type_filter === 'withdrawal') {
        $withdrawal_sql = "
            SELECT 
                w.id,
                'withdrawal' as type,
                w.net_amount as amount,
                w.currency_code as currency,
                w.method as details,
                COALESCE(w.wallet_address, 'Bank Transfer') as reference,
                w.status,
                w.created_at,
                CONCAT('Withdrawal via ', w.method) as description,
                w.admin_notes as notes,
                2 as sort_order
            FROM withdrawals w
            WHERE w.user_id = :user_id
        ";
        
        if ($status_filter !== 'all') {
            $withdrawal_sql .= " AND w.status = :withdrawal_status";
            $params[':withdrawal_status'] = $status_filter;
        }
        
        if (!empty($search)) {
            $withdrawal_sql .= " AND (
                w.wallet_address LIKE :withdrawal_search OR 
                w.method LIKE :withdrawal_search OR
                w.currency_code LIKE :withdrawal_search OR
                w.bank_details LIKE :withdrawal_search
            )";
            $params[':withdrawal_search'] = "%$search%";
        }
        
        $queries[] = $withdrawal_sql;
    }
    
    // Loans query
    if ($type_filter === 'all' || $type_filter === 'loan') {
        $loan_sql = "
            SELECT 
                l.id,
                'loan' as type,
                l.requested_amount as amount,
                'USD' as currency,
                CONCAT(l.loan_type, ' - ', l.repayment_period, ' months') as details,
                l.tracking_code as reference,
                l.status,
                l.created_at,
                CONCAT('Loan: ', COALESCE(l.purpose, 'N/A')) as description,
                l.admin_notes as notes,
                3 as sort_order
            FROM loans l
            WHERE l.user_id = :user_id
        ";
        
        if ($status_filter !== 'all') {
            $loan_sql .= " AND l.status = :loan_status";
            $params[':loan_status'] = $status_filter;
        }
        
        if (!empty($search)) {
            $loan_sql .= " AND (
                l.tracking_code LIKE :loan_search OR 
                l.loan_type LIKE :loan_search OR
                l.purpose LIKE :loan_search
            )";
            $params[':loan_search'] = "%$search%";
        }
        
        $queries[] = $loan_sql;
    }
    
    // Combine all queries
    if (!empty($queries)) {
        // Get total count - SIMPLIFIED APPROACH
        $total_transactions = 0;
        
        // Count deposits
        if ($type_filter === 'all' || $type_filter === 'deposit') {
            $count_sql = "SELECT COUNT(*) as cnt FROM deposits WHERE user_id = :user_id";
            if ($status_filter !== 'all') {
                $count_sql .= " AND status = :deposit_status";
            }
            if (!empty($search)) {
                $count_sql .= " AND (transaction_id LIKE :deposit_search OR method LIKE :deposit_search OR currency_code LIKE :deposit_search)";
            }
            $stmt = $pdo->prepare($count_sql);
            $stmt->bindValue(':user_id', $user_id);
            if ($status_filter !== 'all') {
                $stmt->bindValue(':deposit_status', $status_filter);
            }
            if (!empty($search)) {
                $stmt->bindValue(':deposit_search', "%$search%");
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_transactions += $result['cnt'] ?? 0;
        }
        
        // Count withdrawals
        if ($type_filter === 'all' || $type_filter === 'withdrawal') {
            $count_sql = "SELECT COUNT(*) as cnt FROM withdrawals WHERE user_id = :user_id";
            if ($status_filter !== 'all') {
                $count_sql .= " AND status = :withdrawal_status";
            }
            if (!empty($search)) {
                $count_sql .= " AND (wallet_address LIKE :withdrawal_search OR method LIKE :withdrawal_search OR currency_code LIKE :withdrawal_search OR bank_details LIKE :withdrawal_search)";
            }
            $stmt = $pdo->prepare($count_sql);
            $stmt->bindValue(':user_id', $user_id);
            if ($status_filter !== 'all') {
                $stmt->bindValue(':withdrawal_status', $status_filter);
            }
            if (!empty($search)) {
                $stmt->bindValue(':withdrawal_search', "%$search%");
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_transactions += $result['cnt'] ?? 0;
        }
        
        // Count loans
        if ($type_filter === 'all' || $type_filter === 'loan') {
            $count_sql = "SELECT COUNT(*) as cnt FROM loans WHERE user_id = :user_id";
            if ($status_filter !== 'all') {
                $count_sql .= " AND status = :loan_status";
            }
            if (!empty($search)) {
                $count_sql .= " AND (tracking_code LIKE :loan_search OR loan_type LIKE :loan_search OR purpose LIKE :loan_search)";
            }
            $stmt = $pdo->prepare($count_sql);
            $stmt->bindValue(':user_id', $user_id);
            if ($status_filter !== 'all') {
                $stmt->bindValue(':loan_status', $status_filter);
            }
            if (!empty($search)) {
                $stmt->bindValue(':loan_search', "%$search%");
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_transactions += $result['cnt'] ?? 0;
        }
        
        $total_pages = ceil($total_transactions / $limit);
        
        // Now get the paginated data
        if (!empty($queries)) {
            $main_query = "(" . implode(") UNION ALL (", $queries) . ") ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($main_query);
            
            // Bind all parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
} catch (Exception $e) {
    error_log("Transaction query error: " . $e->getMessage());
    error_log("Error details: " . print_r($pdo->errorInfo(), true));
    $transactions = [];
}

// Get summary data
$summary = [
    'total_deposits' => 0,
    'total_withdrawals' => 0,
    'total_loans' => 0
];

try {
    // Get deposit total
    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total
        FROM deposits 
        WHERE user_id = ? 
        AND status IN ('approved', 'completed')
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $summary['total_deposits'] = $result['total'] ?? 0;
    
    // Get withdrawal total
    $stmt = $pdo->prepare("
        SELECT SUM(net_amount) as total
        FROM withdrawals 
        WHERE user_id = ? 
        AND status IN ('completed', 'approved')
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $summary['total_withdrawals'] = $result['total'] ?? 0;
    
    // Get loans total
    $stmt = $pdo->prepare("
        SELECT SUM(requested_amount) as total
        FROM loans 
        WHERE user_id = ? 
        AND status = 'approved'
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $summary['total_loans'] = $result['total'] ?? 0;
    
} catch (Exception $e) {
    error_log("Summary calculation error: " . $e->getMessage());
}

// Add export button functionality
$export_url = "history.php?" . http_build_query([
    'type' => $type_filter,
    'status' => $status_filter,
    'search' => $search,
    'export' => 'csv'
]);

include './dashboard_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transaction History - Swyft Trust Bank</title>
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
        
        .history-container {
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
        
        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: #111113;
            border-radius: 15px;
            padding: 18px;
            border: 1px solid rgba(157, 80, 255, 0.1);
        }
        
        .summary-title {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        
        .summary-value {
            font-size: 1.3rem;
            font-weight: 900;
            color: #fff;
        }
        
        .summary-value.positive {
            color: #10b981;
        }
        
        .summary-value.negative {
            color: #ef4444;
        }
        
        /* Filters */
        .filters-section {
            background: #111113;
            border-radius: 20px;
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
        
        .filter-group {
            margin-bottom: 15px;
        }
        
        .filter-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        
        .filter-select, .filter-input {
            width: 100%;
            padding: 12px;
            background: #0a0a0c;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        
        .filter-input::placeholder {
            color: #64748b;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .filter-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .apply-btn {
            background: linear-gradient(135deg, #9d50ff, #6a11cb);
            color: white;
        }
        
        .reset-btn {
            background: rgba(255, 255, 255, 0.05);
            color: #94a3b8;
        }
        
        .filter-btn:hover {
            transform: translateY(-2px);
        }
        
        /* Type Tabs */
        .type-tabs {
            display: flex;
            overflow-x: auto;
            gap: 8px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            scrollbar-width: none;
        }
        
        .type-tabs::-webkit-scrollbar {
            display: none;
        }
        
        .type-tab {
            flex-shrink: 0;
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }
        
        .type-tab:hover {
            background: rgba(157, 80, 255, 0.1);
        }
        
        .type-tab.active {
            background: #9d50ff;
            color: white;
        }
        
        .tab-count {
            margin-left: 6px;
            padding: 2px 6px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            font-size: 0.7rem;
        }
        
        /* Transactions List */
        .transactions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .transactions-count {
            font-size: 0.9rem;
            color: #94a3b8;
        }
        
        .transactions-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .transaction-item {
            background: #111113;
            border-radius: 15px;
            padding: 18px;
            transition: all 0.3s;
            border-left: 4px solid #9d50ff;
        }
        
        .transaction-item:hover {
            transform: translateY(-2px);
            background: rgba(157, 80, 255, 0.05);
        }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .transaction-type {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .type-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        
        .transaction-amount {
            font-weight: 900;
            font-size: 1.1rem;
        }
        
        .amount-positive {
            color: #10b981;
        }
        
        .amount-negative {
            color: #ef4444;
        }
        
        .transaction-details {
            margin-bottom: 10px;
        }
        
        .transaction-desc {
            font-size: 0.9rem;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .transaction-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        .transaction-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .transaction-status {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        .status-pending { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .status-processing { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .status-completed { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .status-approved { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .status-rejected { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        .status-cancelled { background: rgba(107, 114, 128, 0.1); color: #6b7280; }
        .status-declined { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        
        .transaction-reference {
            font-family: monospace;
            font-size: 0.75rem;
            color: #64748b;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .pagination-btn {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: none;
            border-radius: 8px;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .pagination-btn:hover:not(:disabled) {
            background: rgba(157, 80, 255, 0.2);
        }
        
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-info {
            font-size: 0.9rem;
            color: #94a3b8;
        }
        
        /* Export Section */
        .export-section {
            margin-top: 30px;
            padding: 20px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 15px;
            text-align: center;
        }
        
        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #9d50ff, #6a11cb);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            opacity: 0.95;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }
        
        .empty-icon {
            font-size: 3rem;
            color: #64748b;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-title {
            font-size: 1.2rem;
            color: #94a3b8;
            margin-bottom: 10px;
        }
        
        .empty-desc {
            font-size: 0.9rem;
            color: #64748b;
        }
        
        @media (max-width: 480px) {
            .history-container {
                padding: 60px 0 80px;
            }
            
            .summary-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="history-container">
        <a href="./" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Transaction History</h1>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-title">Total Deposits</div>
                <div class="summary-value positive">$<?php echo number_format($summary['total_deposits'], 2); ?></div>
            </div>
            
            <div class="summary-card">
                <div class="summary-title">Total Withdrawals</div>
                <div class="summary-value negative">$<?php echo number_format($summary['total_withdrawals'], 2); ?></div>
            </div>
            
            <?php if ($summary['total_loans'] > 0): ?>
            <div class="summary-card">
                <div class="summary-title">Total Loans</div>
                <div class="summary-value positive">$<?php echo number_format($summary['total_loans'], 2); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Filters -->
        <div class="filters-section">
            <div class="filter-title">
                <i class="fas fa-filter"></i> Filter Transactions
            </div>
            
            <form method="GET" action="" id="filterForm">
                <div class="filter-group">
                    <label class="filter-label">Type</label>
                    <select name="type" class="filter-select" id="typeSelect">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="deposit" <?php echo $type_filter === 'deposit' ? 'selected' : ''; ?>>Deposits</option>
                        <option value="withdrawal" <?php echo $type_filter === 'withdrawal' ? 'selected' : ''; ?>>Withdrawals</option>
                        <option value="loan" <?php echo $type_filter === 'loan' ? 'selected' : ''; ?>>Loans</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-select" id="statusSelect">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="declined" <?php echo $status_filter === 'declined' ? 'selected' : ''; ?>>Declined</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Search</label>
                    <input type="text" 
                           name="search" 
                           class="filter-input" 
                           placeholder="Search by reference or description..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="filter-btn apply-btn">
                        <i class="fas fa-check"></i> Apply Filters
                    </button>
                    <button type="button" class="filter-btn reset-btn" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Type Tabs -->
        <div class="type-tabs" id="typeTabs">
            <div class="type-tab <?php echo $type_filter === 'all' ? 'active' : ''; ?>" onclick="setTypeFilter('all')">
                All <span class="tab-count"><?php echo $counts['all']; ?></span>
            </div>
            <div class="type-tab <?php echo $type_filter === 'deposit' ? 'active' : ''; ?>" onclick="setTypeFilter('deposit')">
                Deposits <span class="tab-count"><?php echo $counts['deposit']; ?></span>
            </div>
            <div class="type-tab <?php echo $type_filter === 'withdrawal' ? 'active' : ''; ?>" onclick="setTypeFilter('withdrawal')">
                Withdrawals <span class="tab-count"><?php echo $counts['withdrawal']; ?></span>
            </div>
            <div class="type-tab <?php echo $type_filter === 'loan' ? 'active' : ''; ?>" onclick="setTypeFilter('loan')">
                Loans <span class="tab-count"><?php echo $counts['loan']; ?></span>
            </div>
        </div>
        
        <!-- Transactions List -->
        <div class="transactions-header">
            <div class="transactions-count">
                <?php echo count($transactions); ?> transaction<?php echo count($transactions) != 1 ? 's' : ''; ?> found
            </div>
        </div>
        
        <?php if (!empty($transactions)): ?>
        <div class="transactions-list">
            <?php 
            // Helper function for hex to rgb
            function hex2rgb($hex) {
                $hex = str_replace("#", "", $hex);
                if(strlen($hex) == 3) {
                    $r = hexdec(substr($hex,0,1).substr($hex,0,1));
                    $g = hexdec(substr($hex,1,1).substr($hex,1,1));
                    $b = hexdec(substr($hex,2,1).substr($hex,2,1));
                } else {
                    $r = hexdec(substr($hex,0,2));
                    $g = hexdec(substr($hex,2,2));
                    $b = hexdec(substr($hex,4,2));
                }
                return "$r, $g, $b";
            }
            
            foreach ($transactions as $transaction): 
                $type = $transaction['type'];
                $amount = floatval($transaction['amount']);
                $is_positive = $type !== 'withdrawal'; // Deposits and loans are positive
                $amount_class = $is_positive ? 'amount-positive' : 'amount-negative';
                $amount_display = ($is_positive ? '+' : '-') . number_format(abs($amount), 8);
                $status = $transaction['status'] ?? 'pending';
                $currency = $transaction['currency'] ?? 'USD';
                
                // Get type icon and color
                $type_icon = 'fa-exchange-alt';
                $type_color = '#9d50ff';
                $type_display = ucfirst($type);
                
                switch ($type) {
                    case 'deposit':
                        $type_icon = 'fa-plus-circle';
                        $type_color = '#10b981';
                        $type_display = 'Deposit';
                        break;
                    case 'withdrawal':
                        $type_icon = 'fa-minus-circle';
                        $type_color = '#ef4444';
                        $type_display = 'Withdrawal';
                        break;
                    case 'loan':
                        $type_icon = 'fa-hand-holding-usd';
                        $type_color = '#f59e0b';
                        $type_display = 'Loan';
                        break;
                }
                
                // Format reference
                $reference = $transaction['reference'] ?? '';
                $reference_display = $reference;
                if (!empty($reference)) {
                    if (strlen($reference) > 20) {
                        $reference_display = substr($reference, 0, 17) . '...';
                    }
                }
            ?>
            <div class="transaction-item" style="border-left-color: <?php echo $type_color; ?>;">
                <div class="transaction-header">
                    <div class="transaction-type">
                        <div class="type-icon" style="background: rgba(<?php echo hex2rgb($type_color); ?>, 0.1); color: <?php echo $type_color; ?>;">
                            <i class="fas <?php echo $type_icon; ?>"></i>
                        </div>
                        <div class="type-name"><?php echo $type_display; ?></div>
                    </div>
                    <div class="transaction-amount <?php echo $amount_class; ?>">
                        <?php echo $amount_display; ?> <?php echo $currency; ?>
                    </div>
                </div>
                
                <div class="transaction-details">
                    <div class="transaction-desc">
                        <?php echo htmlspecialchars($transaction['description'] ?? 'No description'); ?>
                        <?php if (!empty($transaction['details'])): ?>
                        <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 5px;">
                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($transaction['details']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="transaction-meta">
                        <span>
                            <i class="far fa-calendar"></i> 
                            <?php echo date('M d, Y', strtotime($transaction['created_at'])); ?>
                        </span>
                        <span>
                            <i class="far fa-clock"></i> 
                            <?php echo date('H:i', strtotime($transaction['created_at'])); ?>
                        </span>
                    </div>
                </div>
                
                <div class="transaction-footer">
                    <div class="transaction-status status-<?php echo $status; ?>">
                        <?php echo ucfirst($status); ?>
                    </div>
                    <?php if (!empty($reference)): ?>
                    <div class="transaction-reference" title="<?php echo htmlspecialchars($reference); ?>">
                        <i class="fas fa-hashtag"></i> 
                        <?php if ($type === 'deposit'): ?>
                            TX: <?php echo $reference_display; ?>
                        <?php elseif ($type === 'loan'): ?>
                            Code: <?php echo $reference_display; ?>
                        <?php else: ?>
                            <?php echo $reference_display; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($transaction['notes'])): ?>
                <div style="margin-top: 12px; padding: 10px; background: rgba(0,0,0,0.2); border-radius: 8px;">
                    <div style="font-size: 0.75rem; color: #9d50ff; font-weight: 600;">
                        <i class="fas fa-info-circle"></i> Admin Notes
                    </div>
                    <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 5px;">
                        <?php echo htmlspecialchars($transaction['notes']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <button class="pagination-btn" onclick="changePage(<?php echo $page - 1; ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                <i class="fas fa-chevron-left"></i> Previous
            </button>
            
            <span class="pagination-info">
                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            </span>
            
            <button class="pagination-btn" onclick="changePage(<?php echo $page + 1; ?>)" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Export Section -->
        <div class="export-section">
            <a href="<?php echo htmlspecialchars($export_url); ?>" class="export-btn">
                <i class="fas fa-file-export"></i> Export to CSV
            </a>
            <p style="margin-top: 10px; font-size: 0.8rem; color: #94a3b8;">
                Export includes all transactions matching current filters
            </p>
        </div>
        
        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-receipt"></i>
            </div>
            <h3 class="empty-title">No transactions found</h3>
            <p class="empty-desc">
                <?php if ($type_filter !== 'all' || $status_filter !== 'all' || !empty($search)): ?>
                    Try adjusting your filters or search terms.
                <?php else: ?>
                    You haven't made any transactions yet.
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Change page function
        function changePage(page) {
            if (page < 1) return;
            
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
        
        // Set type filter from tabs
        function setTypeFilter(type) {
            const url = new URL(window.location);
            url.searchParams.set('type', type);
            url.searchParams.set('page', '1'); // Reset to page 1
            window.location.href = url.toString();
        }
        
        // Reset all filters
        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('type');
            url.searchParams.delete('status');
            url.searchParams.delete('search');
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const typeFilter = "<?php echo $type_filter; ?>";
            const typeSelect = document.getElementById('typeSelect');
            if (typeSelect) {
                typeSelect.value = typeFilter;
            }
            
            const tabs = document.querySelectorAll('.type-tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            const activeTab = document.querySelector(`.type-tab[onclick*="${typeFilter}"]`);
            if (activeTab) {
                activeTab.classList.add('active');
            } else {
                const allTab = document.querySelector('.type-tab[onclick*="all"]');
                if (allTab) {
                    allTab.classList.add('active');
                }
            }
        });
    </script>
</body>
</html>