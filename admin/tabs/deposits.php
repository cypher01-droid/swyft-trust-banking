<?php
// admin/tabs/deposits.php

try {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($search) {
        $where .= " AND (d.transaction_id LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if ($status_filter) {
        $where .= " AND d.status = ?";
        $params[] = $status_filter;
    }
    
    $offset = ($page - 1) * $per_page;
    
    // Get deposits
    $sql = "SELECT d.*, u.full_name 
            FROM deposits d 
            LEFT JOIN users u ON d.user_id = u.id 
            $where 
            ORDER BY d.created_at DESC 
            LIMIT $offset, $per_page";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM deposits d LEFT JOIN users u ON d.user_id = u.id $where";
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

<div class="tab-content <?php echo $tab === 'deposits' ? 'active' : ''; ?>" id="tab-deposits">
    <!-- Bulk Actions -->
    <form method="POST" class="bulk-actions" id="bulkForm">
        <input type="hidden" name="tab" value="deposits">
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
            <input type="hidden" name="tab" value="deposits">
            <input type="text" name="search" class="filter-select" placeholder="Search by transaction ID..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <select name="status" class="filter-select">
                <option value="">All Status</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="?tab=deposits" class="btn btn-outline">Clear</a>
        </form>
    </div>

    <!-- Deposits Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="select-cell"><input type="checkbox" id="selectAllDeposits"></th>
                    <th>ID</th>
                    <th>User</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($data)): ?>
                    <?php foreach ($data as $deposit): ?>
                    <tr>
                        <td><input type="checkbox" class="checkbox deposit-checkbox" name="selected_ids[]" value="<?php echo $deposit['id']; ?>"></td>
                        <td>#<?php echo $deposit['id']; ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem;">
                                    <?php echo strtoupper(substr($deposit['full_name'] ?: 'U', 0, 1)); ?>
                                </div>
                                <div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($deposit['full_name'] ?? 'User #' . $deposit['user_id']); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);">ID: <?php echo $deposit['user_id']; ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-weight: 700; font-family: 'Courier New'; color: var(--success);">
                            $<?php echo number_format($deposit['amount'], 2); ?>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo $deposit['currency_code']; ?></div>
                        </td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $deposit['method'])); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $deposit['status']; ?>">
                                <?php echo ucfirst($deposit['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 0.9rem;"><?php echo date('M d, Y', strtotime($deposit['created_at'])); ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo date('H:i', strtotime($deposit['created_at'])); ?></div>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <?php if ($deposit['status'] === 'pending'): ?>
                                <a href="?tab=deposits&action=approve&id=<?php echo $deposit['id']; ?>" 
                                   class="btn btn-success btn-sm" onclick="return confirm('Approve this deposit?')">
                                    <i class="fas fa-check"></i> Approve
                                </a>
                                <a href="?tab=deposits&action=reject&id=<?php echo $deposit['id']; ?>" 
                                   class="btn btn-danger btn-sm" onclick="return confirm('Reject this deposit?')">
                                    <i class="fas fa-times"></i> Reject
                                </a>
                                <?php endif; ?>
                                <button onclick="viewDepositDetails(<?php echo $deposit['id']; ?>)" class="btn btn-outline btn-sm">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                            <i class="fas fa-money-bill-wave" style="font-size: 2rem; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                            No deposits found
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
            <a href="?tab=deposits&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Select all checkboxes for deposits
document.getElementById('selectAllDeposits')?.addEventListener('change', function(e) {
    const checkboxes = document.querySelectorAll('.deposit-checkbox');
    checkboxes.forEach(cb => cb.checked = e.target.checked);
});

// View deposit details
function viewDepositDetails(depositId) {
    alert('View deposit details for ID: ' + depositId);
}
</script>