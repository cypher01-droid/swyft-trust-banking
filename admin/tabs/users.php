<?php
// admin/tabs/users.php

// Get users data
$offset = ($page - 1) * $per_page;

try {
    $where = "WHERE u.role != 'admin'";
    $params = [];
    
    if ($search) {
        $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if ($status_filter) {
        $where .= " AND u.kyc_status = ?";
        $params[] = $status_filter;
    }
    
    // Get users
    $sql = "SELECT u.id as user_id, u.* FROM users u $where ORDER BY u.created_at DESC LIMIT $offset, $per_page";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM users u $where";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_users = $count_stmt->fetchColumn();
    $total_pages = ceil($total_users / $per_page);
    
} catch (Exception $e) {
    $data = [];
    $total_users = 0;
    echo '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
}
?>

<!-- CSRF Token -->
<script>
    window.csrfToken = "<?php 
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); 
    ?>";
</script>

<!-- Filters -->
<div class="filters">
    <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
        <input type="hidden" name="tab" value="users">
        <input type="text" name="search" class="filter-select" placeholder="Search by name, email, phone" 
               value="<?php echo htmlspecialchars($search); ?>">
        <select name="status" class="filter-select">
            <option value="">All KYC Status</option>
            <option value="unverified" <?php echo $status_filter === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="verified" <?php echo $status_filter === 'verified' ? 'selected' : ''; ?>>Verified</option>
            <option value="declined" <?php echo $status_filter === 'declined' ? 'selected' : ''; ?>>Declined</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="?tab=users" class="btn btn-outline">Clear</a>
    </form>
</div>

<!-- Users Table (Include your users table HTML from original file here) -->
<?php include 'includes/users-table.php'; ?>

<!-- Modals -->
<?php include 'modals/user_details.php'; ?>
<?php include 'modals/edit_user.php'; ?>
<?php include 'modals/adjust_balance.php'; ?>
<?php include 'modals/kyc_modal.php'; ?>
<?php include 'modals/password_modal.php'; ?>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?tab=users&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="display: none;">
    <div class="loading-spinner"></div>
</div>