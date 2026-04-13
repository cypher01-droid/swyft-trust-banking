<?php
// admin/ajax/get_user_edit.php
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die('Unauthorized');
}

$user_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo '<div class="alert alert-danger">User not found</div>';
    exit;
}
?>

<form id="editUserForm">
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
        </div>
        
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user['email']); ?>" required>
        </div>
        
        <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" class="form-input" value="<?php echo htmlspecialchars($user['phone']); ?>">
        </div>
        
        <div class="form-group">
            <label>Country</label>
            <input type="text" name="country" class="form-input" value="<?php echo htmlspecialchars($user['country']); ?>">
        </div>
        
        <div class="form-group">
            <label>Date of Birth</label>
            <input type="date" name="date_of_birth" class="form-input" value="<?php echo $user['date_of_birth']; ?>">
        </div>
        
        <div class="form-group">
            <label>Role</label>
            <select name="role" class="form-input">
                <option value="user" <?php echo $user['role'] == 'user' ? 'selected' : ''; ?>>User</option>
                <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
            </select>
        </div>
    </div>
    
    <div class="form-group">
        <label>Address</label>
        <textarea name="address" class="form-input" rows="3"><?php echo htmlspecialchars($user['address']); ?></textarea>
    </div>
    
    <div class="form-group">
        <label>Wallet PIN (leave empty to keep current)</label>
        <input type="text" name="wallet_pin" class="form-input" placeholder="Enter new 4-digit PIN" maxlength="4">
    </div>
    
    <div class="form-group">
        <label>Account Status</label>
        <div style="display: flex; gap: 15px; margin-top: 10px;">
            <label style="display: flex; align-items: center; gap: 5px;">
                <input type="radio" name="account_status" value="active" <?php echo $user['two_factor_enabled'] ? 'checked' : ''; ?>>
                Active
            </label>
            <label style="display: flex; align-items: center; gap: 5px;">
                <input type="radio" name="account_status" value="suspended" <?php echo !$user['two_factor_enabled'] ? 'checked' : ''; ?>>
                Suspended
            </label>
        </div>
    </div>
    
    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
</form>

<script>
function updateUser(userId) {
    const formData = new FormData(document.getElementById('editUserForm'));
    
    fetch('/admin/ajax/update_user.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('User updated successfully!');
            closeModal('editUserModal');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error updating user');
        console.error(error);
    });
}
</script>