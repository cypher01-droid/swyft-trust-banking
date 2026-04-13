<?php
// admin/tabs/withdrawals.php

try {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($search) {
        $where .= " AND (w.wallet_address LIKE ? OR w.bank_details LIKE ? OR u.full_name LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if ($status_filter) {
        $where .= " AND w.status = ?";
        $params[] = $status_filter;
    }
    
    $offset = ($page - 1) * $per_page;
    
    // Get withdrawals
    $sql = "SELECT w.*, u.full_name 
            FROM withdrawals w 
            LEFT JOIN users u ON w.user_id = u.id 
            $where 
            ORDER BY w.created_at DESC 
            LIMIT $offset, $per_page";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM withdrawals w LEFT JOIN users u ON w.user_id = u.id $where";
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

<div class="tab-content <?php echo $tab === 'withdrawals' ? 'active' : ''; ?>" id="tab-withdrawals">
    <!-- Bulk Actions -->
    <form method="POST" class="bulk-actions" id="bulkFormWithdrawals">
        <input type="hidden" name="tab" value="withdrawals">
        <select name="bulk_action" class="filter-select" style="flex: 1;">
            <option value="">Bulk Actions</option>
            <option value="approve_all">Approve Selected</option>
            <option value="reject_all">Reject Selected</option>
        </select>
        <button type="submit" class="btn btn-primary">Apply</button>
    </form>

    <!-- Filters -->
    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="hidden" name="tab" value="withdrawals">
            <input type="text" name="search" class="filter-select" placeholder="Search by wallet/bank..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <select name="status" class="filter-select">
                <option value="">All Status</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="?tab=withdrawals" class="btn btn-outline">Clear</a>
        </form>
    </div>

    <!-- Withdrawals Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="select-cell"><input type="checkbox" id="selectAllWithdrawals"></th>
                    <th>ID</th>
                    <th>User</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($data)): ?>
                    <?php foreach ($data as $withdrawal): ?>
                    <tr>
                        <td><input type="checkbox" class="checkbox withdrawal-checkbox" name="selected_ids[]" value="<?php echo $withdrawal['id']; ?>"></td>
                        <td>#<?php echo $withdrawal['id']; ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem;">
                                    <?php echo strtoupper(substr($withdrawal['full_name'] ?: 'U', 0, 1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($withdrawal['full_name'] ?? 'User #' . $withdrawal['user_id']); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);">ID: <?php echo $withdrawal['user_id']; ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 700; font-family: 'Courier New'; color: var(--danger);">
                                $<?php echo number_format($withdrawal['amount'], 2); ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);">
                                Net: $<?php echo number_format($withdrawal['net_amount'], 2); ?>
                            </div>
                        </td>
                        <td><?php echo ucfirst($withdrawal['method']); ?></td>
                        <td>
                            <div style="font-size: 0.9rem;">$<?php echo number_format($withdrawal['fee_amount'], 2); ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo $withdrawal['fee_percentage']; ?>%</div>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $withdrawal['status']; ?>">
                                <?php echo ucfirst($withdrawal['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 0.9rem;"><?php echo date('M d, Y', strtotime($withdrawal['created_at'])); ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo date('H:i', strtotime($withdrawal['created_at'])); ?></div>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <?php if ($withdrawal['status'] === 'pending'): ?>
                                <a href="?tab=withdrawals&action=approve&id=<?php echo $withdrawal['id']; ?>" 
                                   class="btn btn-success btn-sm" onclick="return confirm('Approve this withdrawal?')">
                                    <i class="fas fa-check"></i> Approve
                                </a>
                                <a href="?tab=withdrawals&action=reject&id=<?php echo $withdrawal['id']; ?>" 
                                   class="btn btn-danger btn-sm" onclick="return confirm('Reject this withdrawal?')">
                                    <i class="fas fa-times"></i> Reject
                                </a>
                                <?php elseif ($withdrawal['status'] === 'approved' || $withdrawal['status'] === 'processing'): ?>
                                <a href="?tab=withdrawals&action=complete&id=<?php echo $withdrawal['id']; ?>" 
                                   class="btn btn-primary btn-sm" onclick="return confirm('Mark as completed?')">
                                    <i class="fas fa-check-double"></i> Complete
                                </a>
                                <?php endif; ?>
                                <button onclick="viewWithdrawalDetails(<?php echo $withdrawal['id']; ?>)" class="btn btn-outline btn-sm">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                            <i class="fas fa-wallet" style="font-size: 2rem; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                            No withdrawals found
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?tab=withdrawals&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Select all checkboxes for withdrawals
document.getElementById('selectAllWithdrawals')?.addEventListener('change', function(e) {
    const checkboxes = document.querySelectorAll('.withdrawal-checkbox');
    checkboxes.forEach(cb => cb.checked = e.target.checked);
});

// View withdrawal details
function viewWithdrawalDetails(withdrawalId) {
    alert('View withdrawal details for ID: ' + withdrawalId);
}
</script>