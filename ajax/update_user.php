<?php
// admin/ajax/update_user.php
require_once '../includes/db.php';
require_once '../includes/functions.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user_id = $_POST['user_id'] ?? 0;
$full_name = $_POST['full_name'] ?? '';
$email = $_POST['email'] ?? '';
$phone = $_POST['phone'] ?? '';
$country = $_POST['country'] ?? '';
$date_of_birth = $_POST['date_of_birth'] ?? '';
$role = $_POST['role'] ?? 'user';
$address = $_POST['address'] ?? '';
$wallet_pin = $_POST['wallet_pin'] ?? '';
$account_status = $_POST['account_status'] ?? 'active';

if (!$user_id) {
    die(json_encode(['success' => false, 'message' => 'User ID required']));
}

try {
    $pdo->beginTransaction();
    
    // Prepare update data
    $update_data = [
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'country' => $country,
        'date_of_birth' => $date_of_birth,
        'role' => $role,
        'address' => $address,
        'two_factor_enabled' => $account_status == 'active' ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Add wallet pin if provided
    if (!empty($wallet_pin)) {
        $update_data['wallet_pin'] = password_hash($wallet_pin, PASSWORD_DEFAULT);
    }
    
    // Build update query
    $set_parts = [];
    $params = [];
    foreach ($update_data as $key => $value) {
        $set_parts[] = "$key = ?";
        $params[] = $value;
    }
    $params[] = $user_id;
    
    $sql = "UPDATE users SET " . implode(', ', $set_parts) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Add notification
    $admin_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
    
    $notification_message = "Your account has been updated by admin " . $admin['full_name'];
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'system', 'Account Updated', ?, NOW())");
    $stmt->execute([$user_id, $notification_message]);
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>