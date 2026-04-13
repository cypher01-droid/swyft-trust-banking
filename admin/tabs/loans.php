<?php
// admin/tabs/loans.php

try {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($status_filter) {
        $where .= " AND l.status = ?";
        $params[] = $status_filter;
    }
    
    if ($search) {
        $where .= " AND (l.loan_type LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR l.tracking_code LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $offset = ($page - 1) * $per_page;
    
    // Get loans
    $sql = "SELECT l.*, u.full_name, u.email 
            FROM loans l 
            LEFT JOIN users u ON l.user_id = u.id 
            $where 
            ORDER BY l.created_at DESC 
            LIMIT $offset, $per_page";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM loans l LEFT JOIN users u ON l.user_id = u.id $where";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_items = $count_stmt->fetchColumn();
    $total_pages = ceil($total_items / $per_page);
    
} catch (Exception $e) {
    $data = [];
    $total_items = 0;
    echo '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
}
?>

<div class="tab-content <?php echo $tab === 'loans' ? 'active' : ''; ?>" id="tab-loans">
    <!-- Filters -->
    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="hidden" name="tab" value="loans">
            <input type="text" name="search" class="filter-select" placeholder="Search by loan type or tracking code..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <select name="status" class="filter-select">
                <option value="">All Status</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="declined" <?php echo $status_filter === 'declined' ? 'selected' : ''; ?>>Declined</option>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="?tab=loans" class="btn btn-outline">Clear</a>
        </form>
    </div>

    <!-- Loans Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Loan Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Tracking Code</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($data)): ?>
                    <?php foreach ($data as $loan): 
                        $status_colors = [
                            'pending' => '#f59e0b',
                            'approved' => '#10b981',
                            'declined' => '#ef4444'
                        ];
                        $status_color = $status_colors[$loan['status']] ?? '#64748b';
                    ?>
                    <tr>
                        <td>#<?php echo $loan['id']; ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; color: white;">
                                    <?php echo strtoupper(substr($loan['full_name'] ?: 'U', 0, 1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($loan['full_name'] ?? 'User #' . $loan['user_id']); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);">
                                        <?php echo htmlspecialchars($loan['email'] ?? ''); ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span style="display: inline-block; padding: 4px 12px; border-radius: 20px; background: <?php 
                                $loan_colors = [
                                    'personal' => '#9d50ff',
                                    'business' => '#10b981', 
                                    'emergency' => '#f59e0b',
                                    'education' => '#3b82f6'
                                ];
                                echo $loan_colors[$loan['loan_type']] ?? '#64748b';
                            ?>20; color: <?php echo $loan_colors[$loan['loan_type']] ?? '#64748b'; ?>; font-size: 0.85rem; font-weight: 600;">
                                <?php echo ucfirst($loan['loan_type']); ?>
                            </span>
                        </td>
                        <td style="font-weight: 700; font-family: 'Courier New';">
                            $<?php echo number_format($loan['requested_amount'], 2); ?>
                        </td>
                        <td>
                            <span class="status-badge" style="background-color: <?php echo $status_color; ?>20; color: <?php echo $status_color; ?>;">
                                <?php echo ucfirst($loan['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($loan['tracking_code'])): ?>
                            <code style="background: rgba(255,255,255,0.1); padding: 4px 8px; border-radius: 4px; font-family: monospace; font-size: 0.85rem;">
                                <?php echo $loan['tracking_code']; ?>
                            </code>
                            <?php else: ?>
                            <span style="color: var(--text-secondary); font-size: 0.85rem;">Pending...</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-size: 0.9rem;"><?php echo date('M d, Y', strtotime($loan['created_at'])); ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo date('H:i', strtotime($loan['created_at'])); ?></div>
                        </td>
                        <td>
                            <div class="action-buttons" style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <?php if ($loan['status'] === 'pending'): ?>
                                <button onclick="showLoanApprovalModal(<?php echo $loan['id']; ?>, 'approve')" 
                                        class="btn btn-success btn-sm">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button onclick="showLoanApprovalModal(<?php echo $loan['id']; ?>, 'decline')" 
                                        class="btn btn-danger btn-sm">
                                    <i class="fas fa-times"></i> Decline
                                </button>
                                <?php endif; ?>
                                <button onclick="viewLoanDetails(<?php echo $loan['id']; ?>)" class="btn btn-outline btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                
                                <?php if ($loan['status'] === 'approved'): ?>
                                <button onclick="viewLoanTracking('<?php echo $loan['tracking_code'] ?? ''; ?>')" 
                                        class="btn btn-info btn-sm" title="View Tracking">
                                    <i class="fas fa-search"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                            <i class="fas fa-hand-holding-usd" style="font-size: 2rem; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                            No loan applications found
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Loan Approval Modal -->
    <div id="approvalModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 700px;">
            <div id="approvalModalContent">