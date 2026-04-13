<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
session_start();
require_once '../includes/db.php'; // Your database connection

// Helper function for safe output
function safeOutput($value, $default = '') {
    if ($value === null || $value === false) {
        return htmlspecialchars($default);
    }
    return htmlspecialchars((string)$value);
}

// Helper function for safe date display
function safeDate($date, $format = 'M d, Y') {
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    return date($format, strtotime($date));
}

// ==================== AUTHENTICATION & SECURITY ====================
class AdminAuth {
    public static function checkAdmin() {
        global $pdo;
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            header('Location: ../admin-login.php');
            exit();
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user || $user['role'] !== 'admin') {
                session_destroy();
                header('Location: ../login.php');
                exit();
            }
        } catch (Exception $e) {
            error_log("Could not verify admin user: " . $e->getMessage());
        }
    }
    
    public static function logAction($action, $entityType, $entityId = null, $oldData = null, $newData = null) {
        global $pdo;
        
        $adminId = $_SESSION['user_id'] ?? 0;
        
        if (!$adminId) {
            error_log("Warning: No valid admin found for audit log. Action: $action");
            return;
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_logs 
                (admin_id, action, entity_type, entity_id, old_data, new_data, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $adminId,
                $action,
                $entityType,
                $entityId,
                $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
                $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
        }
    }
}

// ==================== ADMIN FUNCTIONS ====================
class AdminFunctions {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // 1. USER MANAGEMENT
    public function getUsers($search = '', $page = 1, $perPage = 20, $statusFilter = null) {
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM users WHERE 1=1";
        $params = [];
        
        if ($search) {
            $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
        
        if ($statusFilter && in_array($statusFilter, ['active', 'suspended', 'under_review', 'locked'])) {
            $sql .= " AND account_status = ?";
            $params[] = $statusFilter;
        }
        
        $sql .= " ORDER BY 
                  CASE account_status 
                    WHEN 'under_review' THEN 1
                    WHEN 'suspended' THEN 2
                    WHEN 'locked' THEN 3
                    WHEN 'active' THEN 4
                  END,
                  created_at DESC 
                  LIMIT " . intval($perPage) . " OFFSET " . intval($offset);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function updateUserBalance($userId, $currencyCode, $newAvailable, $newPending, $notes = '') {
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM balances WHERE user_id = ? AND currency_code = ?");
            $stmt->execute([$userId, $currencyCode]);
            $oldBalance = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($oldBalance) {
                $stmt = $this->pdo->prepare("UPDATE balances SET available_balance = ?, pending_balance = ? 
                                           WHERE user_id = ? AND currency_code = ?");
                $stmt->execute([$newAvailable, $newPending, $userId, $currencyCode]);
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO balances (user_id, currency_code, available_balance, pending_balance) 
                                           VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $currencyCode, $newAvailable, $newPending]);
            }
            
            $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, type, amount, currency_code, description, status) 
                                        VALUES (?, 'admin_adjustment', ?, ?, ?, 'completed')");
            $amountDiff = 0;
            if ($oldBalance) {
                $amountDiff = ($newAvailable - $oldBalance['available_balance']) + ($newPending - $oldBalance['pending_balance']);
            } else {
                $amountDiff = $newAvailable + $newPending;
            }
            $stmt->execute([$userId, $amountDiff, $currencyCode, "Admin balance adjustment: $notes"]);
            
            $stmt = $this->pdo->prepare("INSERT INTO notifications (user_id, type, title, message) 
                                        VALUES (?, 'balance_update', 'Balance Updated', ?)");
            $message = "Your $currencyCode balance has been updated by admin. $notes";
            $stmt->execute([$userId, $message]);
            
            AdminAuth::logAction(
                'update_balance',
                'user',
                $userId,
                $oldBalance,
                ['available_balance' => $newAvailable, 'pending_balance' => $newPending]
            );
            
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Balance update failed: " . $e->getMessage());
            return false;
        }
    }
    
    // NEW: Get user balances with user details
    public function getAllBalances($search = '', $currency = '', $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT 
                    u.id as user_id,
                    u.email,
                    u.full_name,
                    u.account_status,
                    b.currency_code,
                    b.available_balance,
                    b.pending_balance,
                    b.updated_at as balance_updated_at
                FROM users u
                LEFT JOIN balances b ON u.id = b.user_id
                WHERE u.role = 'user'";
        
        $params = [];
        
        if ($search) {
            $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if ($currency) {
            $sql .= " AND b.currency_code = ?";
            $params[] = $currency;
        }
        
        $sql .= " ORDER BY u.email, b.currency_code 
                  LIMIT " . intval($perPage) . " OFFSET " . intval($offset);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // NEW: Get user's complete balance sheet
    public function getUserBalances($userId) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM balances 
            WHERE user_id = ? 
            ORDER BY currency_code
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // NEW: Bulk balance update
    public function bulkUpdateBalances($updates) {
        $success = 0;
        $failed = 0;
        
        $this->pdo->beginTransaction();
        
        try {
            foreach ($updates as $update) {
                $result = $this->updateUserBalance(
                    $update['user_id'],
                    $update['currency_code'],
                    $update['available_balance'],
                    $update['pending_balance'],
                    $update['notes'] ?? 'Bulk update'
                );
                
                if ($result) {
                    $success++;
                } else {
                    $failed++;
                }
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'success_count' => $success,
                'failed_count' => $failed
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Bulk balance update failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // NEW: Get available currencies
    public function getCurrencies() {
        $stmt = $this->pdo->query("
            SELECT DISTINCT currency_code 
            FROM balances 
            UNION 
            SELECT 'USD' 
            UNION 
            SELECT 'EUR' 
            UNION 
            SELECT 'GBP' 
            UNION 
            SELECT 'BTC' 
            UNION 
            SELECT 'ETH'
            ORDER BY currency_code
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function updateKycStatus($userId, $status, $notes = '') {
        $oldStatus = $this->getUserKycStatus($userId);
        
        $stmt = $this->pdo->prepare("UPDATE users SET kyc_status = ? WHERE id = ?");
        $stmt->execute([$status, $userId]);
        
        $statusLabels = [
            'unverified' => 'Not Verified',
            'pending' => 'Under Review',
            'verified' => 'Verified',
            'declined' => 'Declined'
        ];
        
        $stmt = $this->pdo->prepare("INSERT INTO notifications (user_id, type, title, message) 
                                    VALUES (?, 'kyc_update', 'KYC Status Updated', ?)");
        $message = "Your KYC status changed from " . ($statusLabels[$oldStatus] ?? $oldStatus) . 
                   " to " . ($statusLabels[$status] ?? $status) . ". $notes";
        $stmt->execute([$userId, $message]);
        
        AdminAuth::logAction('update_kyc', 'user', $userId, ['kyc_status' => $oldStatus], ['kyc_status' => $status]);
        
        return true;
    }
    
    // 2. DEPOSIT MANAGEMENT
    public function getPendingDeposits() {
        $stmt = $this->pdo->query("SELECT d.*, u.email, u.full_name 
                                  FROM deposits d 
                                  JOIN users u ON d.user_id = u.id 
                                  WHERE d.status = 'pending' 
                                  ORDER BY d.created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function processDeposit($depositId, $action, $adminNotes = '') {
        $actions = ['approved', 'rejected'];
        if (!in_array($action, $actions)) {
            error_log("Invalid deposit action: $action");
            return ['success' => false, 'error' => 'Invalid action'];
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM deposits WHERE id = ? FOR UPDATE");
            $stmt->execute([$depositId]);
            $deposit = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$deposit) {
                throw new Exception("Deposit #$depositId not found");
            }
            
            if ($deposit['status'] !== 'pending') {
                throw new Exception("Deposit #$depositId already processed (status: {$deposit['status']})");
            }
            
            $stmt = $this->pdo->prepare("UPDATE deposits SET 
                status = ?, 
                admin_notes = ?, 
                approved_by = ?, 
                approved_at = NOW() 
                WHERE id = ?");
            
            $stmt->execute([$action, $adminNotes, $_SESSION['user_id'], $depositId]);
            
            if ($action === 'approved') {
                $stmt = $this->pdo->prepare("
                    INSERT INTO balances (user_id, currency_code, available_balance, pending_balance) 
                    VALUES (?, ?, ?, 0)
                    ON DUPLICATE KEY UPDATE 
                    available_balance = available_balance + VALUES(available_balance)
                ");
                
                $stmt->execute([
                    $deposit['user_id'],
                    $deposit['currency_code'],
                    $deposit['amount']
                ]);
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO transactions 
                    (user_id, type, amount, currency_code, description, status, reference_id) 
                    VALUES (?, 'deposit', ?, ?, ?, 'completed', ?)
                ");
                
                $description = "Deposit via " . ucfirst($deposit['method']) . " - Approved by admin";
                $stmt->execute([
                    $deposit['user_id'],
                    $deposit['amount'],
                    $deposit['currency_code'],
                    $description,
                    $depositId
                ]);
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message) 
                    VALUES (?, 'deposit', 'Deposit Approved', ?)
                ");
                
                $message = "Your deposit of {$deposit['amount']} {$deposit['currency_code']} has been approved and added to your balance.";
                $stmt->execute([$deposit['user_id'], $message]);
                
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message) 
                    VALUES (?, 'deposit', 'Deposit Rejected', ?)
                ");
                
                $message = "Your deposit of {$deposit['amount']} {$deposit['currency_code']} has been rejected. ";
                if ($adminNotes) {
                    $message .= "Reason: $adminNotes";
                }
                $stmt->execute([$deposit['user_id'], $message]);
            }
            
            try {
                AdminAuth::logAction(
                    "deposit_$action", 
                    'deposit', 
                    $depositId,
                    ['status' => $deposit['status']], 
                    ['status' => $action]
                );
            } catch (Exception $e) {
                error_log("Audit log failed for deposit: " . $e->getMessage());
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'deposit_id' => $depositId,
                'action' => $action,
                'user_id' => $deposit['user_id'],
                'amount' => $deposit['amount'],
                'currency' => $deposit['currency_code']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Deposit processing failed (ID: $depositId): " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // 3. WITHDRAWAL MANAGEMENT
    public function getPendingWithdrawals() {
        $stmt = $this->pdo->query("SELECT w.*, u.email, u.full_name 
                                  FROM withdrawals w 
                                  JOIN users u ON w.user_id = u.id 
                                  WHERE w.status = 'pending' 
                                  ORDER BY w.created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function processWithdrawal($withdrawalId, $action, $adminNotes = '') {
        $validActions = ['processing', 'completed', 'rejected'];
        if (!in_array($action, $validActions)) {
            error_log("Invalid withdrawal action: $action");
            return ['success' => false, 'error' => 'Invalid action'];
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$withdrawalId]);
            $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$withdrawal) {
                throw new Exception("Withdrawal #$withdrawalId not found");
            }
            
            if ($withdrawal['status'] !== 'pending' && $action === 'processing') {
                throw new Exception("Withdrawal already processed (status: {$withdrawal['status']})");
            }
            
            if ($withdrawal['status'] !== 'processing' && $action === 'completed') {
                throw new Exception("Withdrawal must be in 'processing' status before completing");
            }
            
            if ($action === 'rejected') {
                $stmt = $this->pdo->prepare("
                    INSERT INTO balances (user_id, currency_code, available_balance, pending_balance) 
                    VALUES (?, ?, ?, 0)
                    ON DUPLICATE KEY UPDATE 
                    available_balance = available_balance + VALUES(available_balance)
                ");
                
                $stmt->execute([
                    $withdrawal['user_id'],
                    $withdrawal['currency_code'],
                    $withdrawal['amount']
                ]);
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE withdrawals SET 
                status = ?, 
                admin_notes = ?, 
                processed_by = ?, 
                processed_at = NOW(),
                completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
                WHERE id = ?
            ");
            
            $stmt->execute([
                $action,
                $adminNotes,
                $_SESSION['user_id'],
                $action,
                $withdrawalId
            ]);
            
            if ($action === 'completed') {
                $stmt = $this->pdo->prepare("
                    INSERT INTO transactions 
                    (user_id, type, amount, currency_code, description, status, reference_id) 
                    VALUES (?, 'withdrawal', ?, ?, ?, 'completed', ?)
                ");
                
                $description = "Withdrawal via " . ucfirst($withdrawal['method']) . " - Processed";
                $stmt->execute([
                    $withdrawal['user_id'],
                    $withdrawal['net_amount'],
                    $withdrawal['currency_code'],
                    $description,
                    $withdrawalId
                ]);
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message) 
                VALUES (?, 'withdrawal', ?, ?)
            ");
            
            $title = "Withdrawal " . ucfirst($action);
            $message = "Your withdrawal of {$withdrawal['amount']} {$withdrawal['currency_code']} has been {$action}.";
            
            if ($adminNotes) {
                $message .= " Notes: $adminNotes";
            }
            
            $stmt->execute([$withdrawal['user_id'], $title, $message]);
            
            try {
                AdminAuth::logAction(
                    "withdrawal_$action",
                    'withdrawal',
                    $withdrawalId,
                    ['status' => $withdrawal['status']],
                    ['status' => $action]
                );
            } catch (Exception $e) {
                error_log("Audit log failed for withdrawal: " . $e->getMessage());
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'withdrawal_id' => $withdrawalId,
                'action' => $action,
                'user_id' => $withdrawal['user_id'],
                'amount' => $withdrawal['amount'],
                'currency' => $withdrawal['currency_code'],
                'net_amount' => $withdrawal['net_amount']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Withdrawal processing failed (ID: $withdrawalId): " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // ==================== ACCOUNT SUSPENSION & APPEAL MANAGEMENT ====================
    public function suspendUser($userId, $publicReason, $privateNotes = '') {
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("SELECT account_status, email, full_name FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            if ($user['account_status'] === 'locked') {
                throw new Exception("Cannot suspend a permanently locked account");
            }
            
            $oldStatus = $user['account_status'];
            
            $stmt = $this->pdo->prepare("
                UPDATE users SET 
                account_status = 'suspended',
                suspension_reason = ?,
                admin_notes_private = ?,
                suspended_at = NOW(),
                suspended_by = ?,
                appeal_status = 'none',
                appeal_message = NULL,
                appeal_submitted_at = NULL,
                updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$publicReason, $privateNotes, $_SESSION['user_id'], $userId]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO user_suspension_history 
                (user_id, action, reason_public, reason_private, performed_by, previous_status, new_status) 
                VALUES (?, 'suspended', ?, ?, ?, ?, 'suspended')
            ");
            
            $stmt->execute([$userId, $publicReason, $privateNotes, $_SESSION['user_id'], $oldStatus]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message) 
                VALUES (?, 'account', 'Account Suspended', ?)
            ");
            
            $message = "Your account has been suspended.\n\n";
            $message .= "Reason: $publicReason\n\n";
            $message .= "You can submit an appeal from the profile page to request account restoration.";
            
            $stmt->execute([$userId, $message]);
            
            AdminAuth::logAction(
                'suspend_user',
                'user',
                $userId,
                ['account_status' => $oldStatus],
                ['account_status' => 'suspended', 'public_reason' => $publicReason]
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'user_id' => $userId,
                'email' => $user['email'],
                'public_reason' => $publicReason
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("User suspension failed (User ID: $userId): " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function restoreUser($userId, $adminNotes = '') {
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("SELECT account_status, email, full_name FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            if ($user['account_status'] !== 'suspended' && $user['account_status'] !== 'under_review') {
                throw new Exception("User is not currently suspended");
            }
            
            $oldStatus = $user['account_status'];
            
            $stmt = $this->pdo->prepare("
                UPDATE users SET 
                account_status = 'active',
                suspension_reason = NULL,
                suspended_at = NULL,
                suspended_by = NULL,
                appeal_status = 'none',
                appeal_message = NULL,
                appeal_submitted_at = NULL,
                restored_at = NOW(),
                restored_by = ?,
                updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$_SESSION['user_id'], $userId]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO user_suspension_history 
                (user_id, action, reason, performed_by, previous_status, new_status) 
                VALUES (?, 'restored', ?, ?, ?, 'active')
            ");
            
            $reason = "Account restored by admin. " . ($adminNotes ? "Notes: " . $adminNotes : "");
            $stmt->execute([$userId, $reason, $_SESSION['user_id'], $oldStatus]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message) 
                VALUES (?, 'account', 'Account Restored', ?)
            ");
            
            $message = "Your account has been restored. You can now login and use all features.";
            if ($adminNotes) {
                $message .= " Admin notes: $adminNotes";
            }
            $stmt->execute([$userId, $message]);
            
            AdminAuth::logAction(
                'restore_user',
                'user',
                $userId,
                ['account_status' => $oldStatus],
                ['account_status' => 'active']
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'user_id' => $userId,
                'email' => $user['email']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("User restoration failed (User ID: $userId): " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function lockUser($userId, $reason = '', $adminNotes = '') {
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("SELECT account_status, email, full_name FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            $oldStatus = $user['account_status'];
            
            $stmt = $this->pdo->prepare("
                UPDATE users SET 
                account_status = 'locked',
                locked_at = NOW(),
                appeal_status = 'rejected',
                updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$userId]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO user_suspension_history 
                (user_id, action, reason, performed_by, previous_status, new_status) 
                VALUES (?, 'locked', ?, ?, ?, 'locked')
            ");
            
            $fullReason = $reason ?: "Appeal rejected, account permanently locked." . ($adminNotes ? " Notes: $adminNotes" : "");
            $stmt->execute([$userId, $fullReason, $_SESSION['user_id'], $oldStatus]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message) 
                VALUES (?, 'account', 'Account Permanently Locked', ?)
            ");
            
            $message = "Your appeal has been reviewed and rejected. Your account has been permanently locked.";
            if ($reason) {
                $message .= " Reason: $reason";
            }
            $stmt->execute([$userId, $message]);
            
            AdminAuth::logAction(
                'lock_user',
                'user',
                $userId,
                ['account_status' => $oldStatus],
                ['account_status' => 'locked']
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'user_id' => $userId,
                'email' => $user['email']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("User lock failed (User ID: $userId): " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getPendingAppeals() {
        $stmt = $this->pdo->query("
            SELECT u.id as user_id, u.email, u.full_name, u.account_status, 
                   u.suspension_reason, u.suspended_at, u.appeal_message, 
                   u.appeal_submitted_at, ua.*
            FROM users u
            LEFT JOIN user_appeals ua ON u.id = ua.user_id AND ua.status = 'pending'
            WHERE u.appeal_status = 'pending' 
               OR (u.account_status = 'suspended' AND u.appeal_message IS NOT NULL)
            ORDER BY u.appeal_submitted_at DESC
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getUserSuspensionHistory($userId) {
        $stmt = $this->pdo->prepare("
            SELECT ush.*, 
                   u1.email as user_email, u1.full_name as user_name,
                   u2.email as admin_email, u2.full_name as admin_name
            FROM user_suspension_history ush
            JOIN users u1 ON ush.user_id = u1.id
            LEFT JOIN users u2 ON ush.performed_by = u2.id
            WHERE ush.user_id = ?
            ORDER BY ush.performed_at DESC
        ");
        
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getSuspendedAccounts($status = null) {
        $sql = "SELECT id, email, full_name, account_status, suspension_reason, 
                       suspended_at, locked_at, appeal_status, appeal_submitted_at
                FROM users 
                WHERE account_status IN ('suspended', 'under_review', 'locked')";
        
        $params = [];
        
        if ($status && in_array($status, ['suspended', 'under_review', 'locked'])) {
            $sql .= " AND account_status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY 
                  CASE account_status 
                    WHEN 'under_review' THEN 1
                    WHEN 'suspended' THEN 2
                    WHEN 'locked' THEN 3
                  END,
                  suspended_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function processAppeal($userId, $decision, $publicResponse = '', $privateNotes = '') {
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM user_appeals 
                WHERE user_id = ? AND status = 'pending' 
                ORDER BY submitted_at DESC LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$userId]);
            $appeal = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appeal) {
                throw new Exception("No pending appeal found for this user");
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE user_appeals SET 
                status = ?,
                admin_response_public = ?,
                admin_notes_private = ?,
                reviewed_by = ?,
                reviewed_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $decision === 'approve' ? 'approved' : 'rejected',
                $publicResponse,
                $privateNotes,
                $_SESSION['user_id'],
                $appeal['id']
            ]);
            
            if ($decision === 'approve') {
                $stmt = $this->pdo->prepare("
                    UPDATE users SET 
                    account_status = 'active',
                    appeal_status = 'approved',
                    suspension_reason = NULL,
                    admin_notes_private = NULL,
                    suspended_at = NULL,
                    suspended_by = NULL,
                    appeal_message = NULL,
                    appeal_submitted_at = NULL,
                    restored_at = NOW(),
                    restored_by = ?,
                    updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $userId]);
                
                $notificationTitle = 'Appeal Approved';
                $notificationMessage = "Your appeal has been approved. Your account has been restored.\n\n";
                
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE users SET 
                    account_status = 'locked',
                    appeal_status = 'rejected',
                    locked_at = NOW(),
                    updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$userId]);
                
                $notificationTitle = 'Appeal Rejected';
                $notificationMessage = "Your appeal has been reviewed and rejected. Your account has been permanently locked.\n\n";
            }
            
            if ($publicResponse) {
                $notificationMessage .= "Admin Response: $publicResponse\n\n";
            }
            
            if ($decision === 'rejected' && !$publicResponse) {
                $notificationMessage .= "No specific reason was provided for the rejection.";
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message) 
                VALUES (?, 'appeal', ?, ?)
            ");
            $stmt->execute([$userId, $notificationTitle, $notificationMessage]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO user_suspension_history 
                (user_id, action, reason_public, reason_private, performed_by, previous_status, new_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $action = $decision === 'approve' ? 'restored' : 'locked';
            $publicReason = $decision === 'approve' 
                ? "Appeal approved: " . ($publicResponse ?: "Account restored")
                : "Appeal rejected: " . ($publicResponse ?: "Permanently locked due to policy violation");
            
            $stmt->execute([
                $userId, 
                $action, 
                $publicReason,
                $privateNotes,
                $_SESSION['user_id'],
                'suspended',
                $decision === 'approve' ? 'active' : 'locked'
            ]);
            
            AdminAuth::logAction(
                'process_appeal_' . $decision,
                'appeal',
                $appeal['id'],
                ['status' => 'pending'],
                ['status' => $decision === 'approve' ? 'approved' : 'rejected']
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'user_id' => $userId,
                'decision' => $decision,
                'appeal_id' => $appeal['id'],
                'public_response' => $publicResponse
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Appeal processing failed (User ID: $userId): " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // 4. LOAN MANAGEMENT
    public function getPendingLoans() {
        $stmt = $this->pdo->query("SELECT l.*, u.email, u.full_name, u.kyc_status 
                                  FROM loans l 
                                  JOIN users u ON l.user_id = u.id 
                                  WHERE l.status = 'pending' 
                                  ORDER BY l.created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function generateLoanTrackingCode() {
        $prefix = 'LN';
        $unique = false;
        $code = '';
        
        while (!$unique) {
            $random = strtoupper(substr(md5(uniqid()), 0, 8));
            $code = $prefix . $random;
            
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM loans WHERE tracking_code = ?");
            $stmt->execute([$code]);
            $unique = $stmt->fetchColumn() == 0;
        }
        
        return $code;
    }
    
    public function processLoan($loanId, $action, $adminNotes = '', $adminMessage = '') {
        $validActions = ['approved', 'declined'];
        if (!in_array($action, $validActions)) {
            error_log("Invalid loan action: $action");
            return ['success' => false, 'error' => 'Invalid action'];
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM loans WHERE id = ? FOR UPDATE");
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan) {
                throw new Exception("Loan #$loanId not found");
            }
            
            if ($loan['status'] !== 'pending') {
                throw new Exception("Loan already processed (status: {$loan['status']})");
            }
            
            $trackingCode = $loan['tracking_code'];
            if ($action === 'approved' && empty($trackingCode)) {
                $trackingCode = $this->generateLoanTrackingCode();
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE loans SET 
                status = ?, 
                tracking_code = ?, 
                admin_notes = ?, 
                admin_message = ?, 
                processed_by = ?, 
                processed_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([
                $action,
                $trackingCode,
                $adminNotes,
                $adminMessage,
                $_SESSION['user_id'],
                $loanId
            ]);
            
            if ($action === 'approved') {
                $stmt = $this->pdo->prepare("
                    INSERT INTO balances (user_id, currency_code, available_balance, pending_balance) 
                    VALUES (?, 'USD', ?, 0)
                    ON DUPLICATE KEY UPDATE 
                    available_balance = available_balance + VALUES(available_balance)
                ");
                
                $stmt->execute([
                    $loan['user_id'],
                    $loan['requested_amount']
                ]);
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO transactions 
                    (user_id, type, amount, currency_code, description, status, tracking_code, reference_id) 
                    VALUES (?, 'loan', ?, 'USD', ?, 'completed', ?, ?)
                ");
                
                $description = "Loan approved - {$loan['loan_type']}";
                $stmt->execute([
                    $loan['user_id'],
                    $loan['requested_amount'],
                    $description,
                    $trackingCode,
                    $loanId
                ]);
                
                $message = "🎉 Your loan request of \${$loan['requested_amount']} has been APPROVED!<br>";
                $message .= "Tracking Code: <strong>{$trackingCode}</strong><br>";
                if ($adminMessage) {
                    $message .= "Message: {$adminMessage}";
                }
                
            } else {
                $message = "Your loan request of \${$loan['requested_amount']} has been DECLINED.<br>";
                if ($adminNotes) {
                    $message .= "Reason: {$adminNotes}";
                }
                if ($adminMessage) {
                    $message .= "<br>Additional notes: {$adminMessage}";
                }
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message) 
                VALUES (?, 'loan', ?, ?)
            ");
            
            $title = "Loan " . ucfirst($action);
            $stmt->execute([$loan['user_id'], $title, strip_tags($message)]);
            
            try {
                AdminAuth::logAction(
                    "loan_$action",
                    'loan',
                    $loanId,
                    ['status' => $loan['status']],
                    ['status' => $action]
                );
            } catch (Exception $e) {
                error_log("Audit log failed for loan: " . $e->getMessage());
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'loan_id' => $loanId,
                'action' => $action,
                'tracking_code' => $trackingCode,
                'user_id' => $loan['user_id'],
                'amount' => $loan['requested_amount'],
                'loan_type' => $loan['loan_type']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Loan processing failed (ID: $loanId): " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // 5. KYC DOCUMENT MANAGEMENT
    public function getPendingKycDocuments() {
        $stmt = $this->pdo->query("SELECT k.*, u.email, u.full_name, u.kyc_status 
                                  FROM kyc_documents k 
                                  JOIN users u ON k.user_id = u.id 
                                  WHERE k.status = 'pending' 
                                  ORDER BY k.created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function verifyKycDocument($documentId, $action, $adminNotes = '') {
        $validActions = ['verified', 'rejected'];
        if (!in_array($action, $validActions)) {
            error_log("Invalid KYC document action: $action");
            return ['success' => false, 'error' => 'Invalid action'];
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT k.*, u.email, u.full_name, u.kyc_status 
                FROM kyc_documents k 
                JOIN users u ON k.user_id = u.id 
                WHERE k.id = ? FOR UPDATE
            ");
            
            $stmt->execute([$documentId]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$document) {
                throw new Exception("KYC document #$documentId not found");
            }
            
            if ($document['status'] !== 'pending') {
                throw new Exception("Document already processed (status: {$document['status']})");
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE kyc_documents SET 
                status = ?, 
                admin_notes = ?, 
                verified_by = ?, 
                verified_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([$action, $adminNotes, $_SESSION['user_id'], $documentId]);
            
            $docTypeNames = [
                'id_card' => 'ID Card',
                'passport' => 'Passport',
                'drivers_license' => "Driver's License",
                'proof_of_address' => 'Proof of Address',
                'selfie' => 'Selfie Photo'
            ];
            
            $docTypeName = $docTypeNames[$document['document_type']] ?? $document['document_type'];
            
            if ($action === 'verified') {
                $requiredDocs = ['id_card', 'passport', 'proof_of_address', 'selfie'];
                
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) as verified_count 
                    FROM kyc_documents 
                    WHERE user_id = ? 
                    AND document_type IN ('" . implode("','", $requiredDocs) . "')
                    AND status = 'verified'
                ");
                
                $stmt->execute([$document['user_id']]);
                $verifiedCount = $stmt->fetchColumn();
                
                if ($verifiedCount >= 3) {
                    $this->updateKycStatus($document['user_id'], 'verified', 
                        'All required documents verified successfully');
                }
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message) 
                VALUES (?, 'kyc', ?, ?)
            ");
            
            $title = "Document " . ucfirst($action);
            $message = "Your {$docTypeName} has been {$action}.";
            
            if ($adminNotes) {
                $message .= " Notes: {$adminNotes}";
            }
            
            $stmt->execute([$document['user_id'], $title, $message]);
            
            try {
                AdminAuth::logAction(
                    "kyc_document_$action",
                    'kyc_document',
                    $documentId,
                    ['status' => $document['status']],
                    ['status' => $action]
                );
            } catch (Exception $e) {
                error_log("Audit log failed for KYC document: " . $e->getMessage());
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'document_id' => $documentId,
                'action' => $action,
                'user_id' => $document['user_id'],
                'document_type' => $document['document_type'],
                'doc_type_name' => $docTypeName
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("KYC document verification failed (ID: $documentId): " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // 6. DASHBOARD STATISTICS
    public function getDashboardStats() {
        $stats = [];
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as total_users FROM users WHERE role = 'user'");
        $stats['total_users'] = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COUNT(DISTINCT user_id) as active_users FROM transactions 
                                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stats['active_users'] = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as pending_deposits FROM deposits WHERE status = 'pending'");
        $stats['pending_deposits'] = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as pending_withdrawals FROM withdrawals WHERE status = 'pending'");
        $stats['pending_withdrawals'] = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as pending_loans FROM loans WHERE status = 'pending'");
        $stats['pending_loans'] = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COUNT(*) as pending_kyc FROM kyc_documents WHERE status = 'pending'");
        $stats['pending_kyc'] = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(CASE WHEN account_status = 'active' THEN 1 END) as active_accounts,
                COUNT(CASE WHEN account_status = 'suspended' THEN 1 END) as suspended_accounts,
                COUNT(CASE WHEN account_status = 'under_review' THEN 1 END) as under_review_accounts,
                COUNT(CASE WHEN account_status = 'locked' THEN 1 END) as locked_accounts,
                COUNT(CASE WHEN appeal_status = 'pending' THEN 1 END) as pending_appeals
            FROM users 
            WHERE role = 'user'
        ");
        
        $accountStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats = array_merge($stats, $accountStats);
        
        $stmt = $this->pdo->query("SELECT currency_code, SUM(available_balance) as total 
                                  FROM balances GROUP BY currency_code");
        $stats['total_balances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $this->pdo->query("SELECT t.*, u.email FROM transactions t 
                                  JOIN users u ON t.user_id = u.id 
                                  ORDER BY t.created_at DESC LIMIT 10");
        $stats['recent_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
    }
    
    // Helper methods
    private function getUserKycStatus($userId) {
        $stmt = $this->pdo->prepare("SELECT kyc_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result ?: 'unverified';
    }
}

// ==================== INITIALIZE ====================
AdminAuth::checkAdmin();
$admin = new AdminFunctions($pdo);

// ==================== HANDLE ACTIONS ====================
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'update_balance':
            $success = $admin->updateUserBalance(
                $_POST['user_id'],
                $_POST['currency_code'],
                $_POST['available_balance'],
                $_POST['pending_balance'],
                $_POST['notes'] ?? ''
            );
            if ($success) {
                $message = 'Balance updated successfully';
            } else {
                $error = 'Failed to update balance';
            }
            break;
            
        case 'bulk_update_balances':
            if (isset($_POST['bulk_updates']) && is_array($_POST['bulk_updates'])) {
                $result = $admin->bulkUpdateBalances($_POST['bulk_updates']);
                if ($result['success']) {
                    $message = "Bulk update completed: {$result['success_count']} updated, {$result['failed_count']} failed";
                } else {
                    $error = "Bulk update failed: " . $result['error'];
                }
            }
            break;
            
        case 'update_kyc_status':
            $success = $admin->updateKycStatus(
                $_POST['user_id'],
                $_POST['status'],
                $_POST['notes'] ?? ''
            );
            if ($success) {
                $message = 'KYC status updated';
            } else {
                $error = 'Failed to update KYC status';
            }
            break;
            
        case 'process_deposit':
            $result = $admin->processDeposit(
                $_POST['deposit_id'],
                $_POST['status'],
                $_POST['admin_notes'] ?? ''
            );
            
            if (is_array($result) && isset($result['success']) && $result['success']) {
                $message = "Deposit #{$result['deposit_id']} {$result['action']} successfully.";
                header("Location: ?section=deposits&success=" . urlencode($message));
                exit;
            } else {
                $error = "Failed to process deposit: " . ($result['error'] ?? 'Unknown error');
            }
            break;
            
        case 'process_withdrawal':
            $result = $admin->processWithdrawal(
                $_POST['withdrawal_id'],
                $_POST['status'],
                $_POST['admin_notes'] ?? ''
            );
            
            if (is_array($result) && isset($result['success']) && $result['success']) {
                $message = "Withdrawal #{$result['withdrawal_id']} {$result['action']} successfully.";
                header("Location: ?section=withdrawals&success=" . urlencode($message));
                exit;
            } else {
                $error = "Failed to process withdrawal: " . ($result['error'] ?? 'Unknown error');
            }
            break;
            
        case 'process_loan':
            $result = $admin->processLoan(
                $_POST['loan_id'],
                $_POST['status'],
                $_POST['admin_notes'] ?? '',
                $_POST['admin_message'] ?? ''
            );
            
            if (is_array($result) && isset($result['success']) && $result['success']) {
                $message = "Loan #{$result['loan_id']} {$result['action']} successfully.";
                if (!empty($result['tracking_code'])) {
                    $message .= " Tracking Code: {$result['tracking_code']}";
                }
                header("Location: ?section=loans&success=" . urlencode($message));
                exit;
            } else {
                $error = "Failed to process loan: " . ($result['error'] ?? 'Unknown error');
            }
            break;
            
        case 'verify_kyc_document':
            $result = $admin->verifyKycDocument(
                $_POST['document_id'],
                $_POST['status'],
                $_POST['admin_notes'] ?? ''
            );
            
            if (is_array($result) && isset($result['success']) && $result['success']) {
                $message = "Document #{$result['document_id']} ({$result['doc_type_name']}) {$result['action']}.";
                header("Location: ?section=kyc&success=" . urlencode($message));
                exit;
            } else {
                $error = "Failed to verify document: " . ($result['error'] ?? 'Unknown error');
            }
            break;
            
        case 'suspend_user':
            $result = $admin->suspendUser(
                $_POST['user_id'],
                $_POST['suspension_reason'],
                $_POST['admin_notes_private'] ?? ''
            );
            
            if ($result['success']) {
                $message = "User #{$result['user_id']} ({$result['email']}) has been suspended.";
                header("Location: ?section=users&success=" . urlencode($message));
                exit;
            } else {
                $error = "Failed to suspend user: " . ($result['error'] ?? 'Unknown error');
            }
            break;
            
        case 'restore_user':
            $result = $admin->restoreUser(
                $_POST['user_id'],
                $_POST['admin_notes'] ?? ''
            );
            
            if ($result['success']) {
                $message = "User #{$result['user_id']} ({$result['email']}) has been restored.";
                header("Location: ?section=users&success=" . urlencode($message));
                exit;
            } else {
                $error = "Failed to restore user: " . ($result['error'] ?? 'Unknown error');
            }
            break;
            
        case 'lock_user':
            $result = $admin->lockUser(
                $_POST['user_id'],
                $_POST['lock_reason'] ?? '',
                $_POST['admin_notes'] ?? ''
            );
            
            if ($result['success']) {
                $message = "User #{$result['user_id']} ({$result['email']}) has been permanently locked.";
                header("Location: ?section=users&success=" . urlencode($message));
                exit;
            } else {
                $error = "Failed to lock user: " . ($result['error'] ?? 'Unknown error');
            }
            break;
            
        case 'process_appeal':
            $result = $admin->processAppeal(
                $_POST['user_id'],
                $_POST['decision'],
                $_POST['admin_response_public'] ?? '',
                $_POST['admin_notes_private'] ?? ''
            );
            
            if ($result['success']) {
                $message = "Appeal for user #{$result['user_id']} has been {$result['decision']}d.";
                header("Location: ?section=appeals&success=" . urlencode($message));
                exit;
            } else {
                $error = "Failed to process appeal: " . ($result['error'] ?? 'Unknown error');
            }
            break;
    }
}

function timeAgo($datetime) {
    if (!$datetime) return 'N/A';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}

// ==================== GET DATA FOR DISPLAY ====================
$stats = $admin->getDashboardStats();
$pendingDeposits = $admin->getPendingDeposits();
$pendingWithdrawals = $admin->getPendingWithdrawals();
$pendingLoans = $admin->getPendingLoans();
$pendingKycDocs = $admin->getPendingKycDocuments();
$pendingAppeals = $admin->getPendingAppeals();
$currencies = $admin->getCurrencies();

// Get users for user management
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$users = $admin->getUsers($search, $page, 20);

// Get balances for balance management
$balanceSearch = $_GET['balance_search'] ?? '';
$balanceCurrency = $_GET['balance_currency'] ?? '';
$balancePage = isset($_GET['balance_page']) ? (int)$_GET['balance_page'] : 1;
$balances = $admin->getAllBalances($balanceSearch, $balanceCurrency, $balancePage, 50);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Zeus Finance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ADMIN CSS - SAME AS YOUR ORIGINAL STYLES */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
        }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background: #1e293b;
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .logo {
            padding: 0 20px 20px;
            border-bottom: 1px solid #334155;
            margin-bottom: 20px;
        }
        
        .logo h2 {
            color: #9d50ff;
            font-size: 1.5rem;
        }
        
        .logo .subtitle {
            color: #64748b;
            font-size: 0.8rem;
        }
        
        .nav-menu {
            list-style: none;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: block;
            padding: 12px 20px;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(157, 80, 255, 0.1);
            color: #9d50ff;
            border-left-color: #9d50ff;
        }
        
        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #334155;
        }
        
        .header h1 {
            color: #fff;
            font-size: 1.8rem;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logout-btn {
            background: #dc2626;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            text-decoration: none;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #1e293b;
            border-radius: 10px;
            padding: 20px;
            border-top: 4px solid;
        }
        
        .stat-card.total-users { border-color: #9d50ff; }
        .stat-card.active-users { border-color: #10b981; }
        .stat-card.pending-deposits { border-color: #f59e0b; }
        .stat-card.pending-withdrawals { border-color: #3b82f6; }
        .stat-card.pending-loans { border-color: #8b5cf6; }
        .stat-card.pending-kyc { border-color: #ec4899; }
        .stat-card.pending-appeals { border-color: #f59e0b; }
        .stat-card.locked-accounts { border-color: #ef4444; }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #94a3b8;
            font-size: 0.9rem;
        }
        
        .card {
            background: #1e293b;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .card-title {
            color: #fff;
            font-size: 1.2rem;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #0f172a;
            padding: 12px 15px;
            text-align: left;
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #334155;
        }
        
        tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-block;
            text-decoration: none;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 0.8rem;
        }
        
        .btn-primary {
            background: #9d50ff;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-info {
            background: #3b82f6;
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid #94a3b8;
            color: #e2e8f0;
        }
        
        .btn-outline:hover {
            background: #334155;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #cbd5e1;
            font-size: 0.9rem;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 8px 12px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 4px;
            color: #e2e8f0;
            font-size: 0.9rem;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #9d50ff;
        }
        
        textarea {
            min-height: 80px;
            resize: vertical;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: #1e293b;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-lg .modal-content {
            max-width: 800px;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #334155;
            text-align: right;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: #fff;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-processing { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-verified { background: #d1fae5; color: #065f46; }
        .status-declined { background: #fee2e2; color: #991b1b; }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-suspended { background: #fef3c7; color: #92400e; }
        .status-under_review { background: #dbeafe; color: #1e40af; }
        .status-locked { background: #fee2e2; color: #991b1b; }
        .status-unverified { background: #f1f5f9; color: #475569; }
        
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        .d-flex { display: flex; }
        .align-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-10 { gap: 10px; }
        .mb-20 { margin-bottom: 20px; }
        .mt-20 { margin-top: 20px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #64748b;
            margin-bottom: 20px;
        }
        
        .empty-state h4 {
            color: #e2e8f0;
            margin-bottom: 10px;
        }
        
        .search-input {
            padding: 8px 12px;
            border: 1px solid #334155;
            border-radius: 4px;
            background: #0f172a;
            color: #e2e8f0;
            width: 250px;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #334155;
            border-radius: 4px;
            background: #0f172a;
            color: #e2e8f0;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .user-info {
            line-height: 1.4;
        }
        
        .user-info small {
            color: #94a3b8;
            font-size: 11px;
        }
        
        .document-previews {
            display: flex;
            gap: 5px;
        }
        
        .preview-link {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 4px 8px;
            background: #0f172a;
            color: #9d50ff;
            border-radius: 4px;
            text-decoration: none;
            font-size: 11px;
        }
        
        .document-review-card {
            background: #0f172a;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .review-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            background: #9d50ff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .user-details h4 {
            margin: 0 0 5px 0;
            color: #fff;
        }
        
        .user-email {
            margin: 0 0 10px 0;
            color: #94a3b8;
        }
        
        .document-details {
            display: grid;
            gap: 10px;
        }
        
        .detail-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #334155;
        }
        
        .detail-label {
            width: 200px;
            color: #94a3b8;
            font-weight: 500;
        }
        
        .detail-value {
            flex: 1;
        }
        
        .progress-container {
            width: 100%;
        }
        
        .progress-bar {
            height: 8px;
            background: #334155;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #9d50ff, #6a11cb);
            border-radius: 4px;
        }
        
        .progress-text {
            font-size: 12px;
            color: #94a3b8;
        }
        
        .verification-checklist {
            background: rgba(245, 158, 11, 0.1);
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
        }
        
        .verification-checklist h4 {
            margin: 0 0 15px 0;
            color: #f59e0b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .verification-checklist ul {
            margin: 0;
            padding-left: 20px;
            color: #e2e8f0;
        }
        
        .verification-checklist li {
            margin-bottom: 8px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        
        .image-preview {
            text-align: center;
        }
        
        .document-image {
            max-width: 100%;
            max-height: 500px;
            border: 1px solid #334155;
            border-radius: 4px;
        }
        
        .pdf-viewer {
            width: 100%;
            height: 500px;
            border: 1px solid #334155;
            border-radius: 4px;
        }
        
        .preview-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .document-card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .document-card.status-verified { border-left: 4px solid #10b981; }
        .document-card.status-rejected { border-left: 4px solid #ef4444; }
        .document-card.status-pending { border-left: 4px solid #f59e0b; }
        
        .document-header {
            padding: 15px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .document-header h5 {
            margin: 0;
            color: #fff;
        }
        
        .doc-status {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            text-transform: uppercase;
        }
        
        .document-body {
            padding: 15px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .info-row span:first-child {
            color: #94a3b8;
            font-weight: 500;
        }
        
        .doc-actions {
            display: flex;
            gap: 5px;
            margin-top: 15px;
        }
        
        .required {
            color: #ef4444;
        }
        
        .form-help {
            display: block;
            margin-top: 5px;
            font-size: 11px;
            color: #94a3b8;
        }
        
        [data-tooltip] {
            position: relative;
        }
        
        [data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #e2e8f0;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
            border: 1px solid #334155;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 60px;
            }
            
            .sidebar .logo h2, .sidebar .subtitle, .sidebar .nav-link span {
                display: none;
            }
            
            .main-content {
                margin-left: 60px;
            }
            
            .nav-link {
                text-align: center;
                padding: 12px 0;
            }
            
            .nav-link i {
                margin: 0;
                font-size: 1.2rem;
            }
            
            .form-row {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .documents-grid {
                grid-template-columns: 1fr;
            }
            
            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .detail-label {
                width: 100%;
            }
        }

        /* Balance Management Specific Styles */
        .balance-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        .balance-positive {
            color: #10b981;
        }
        
        .balance-negative {
            color: #ef4444;
        }
        
        .bulk-edit-row {
            background: rgba(157, 80, 255, 0.05);
            border-left: 4px solid #9d50ff;
        }
        
        .filter-bar {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .currency-badge {
            background: #0f172a;
            color: #9d50ff;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid #9d50ff;
        }
        
        .total-row {
            background: #0f172a;
            font-weight: 600;
        }
        
        .total-row td {
            border-top: 2px solid #334155;
            border-bottom: 2px solid #334155;
        }
        
        .editable-field {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 4px;
            padding: 4px 8px;
            width: 100px;
            color: #e2e8f0;
            font-family: 'Courier New', monospace;
        }
        
        .editable-field:focus {
            border-color: #9d50ff;
            outline: none;
        }
        
        .bulk-select {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .bulk-actions-bar {
            background: #0f172a;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .bulk-actions-bar.hidden {
            display: none;
        }
        
        .summary-card {
            background: #0f172a;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #9d50ff;
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-item .label {
            color: #94a3b8;
            font-size: 0.8rem;
            margin-bottom: 5px;
        }
        
        .stat-item .value {
            font-size: 1.2rem;
            font-weight: 600;
            color: #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- SIDEBAR -->
        <div class="sidebar">
            <div class="logo">
                <h2>Zeus Finance</h2>
                <div class="subtitle">Admin Dashboard</div>
            </div>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="?section=dashboard" class="nav-link <?php echo (!isset($_GET['section']) || $_GET['section'] == 'dashboard') ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?section=balances" class="nav-link <?php echo ($_GET['section'] ?? '') == 'balances' ? 'active' : ''; ?>">
                        <i class="fas fa-wallet"></i> <span>Balances</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?section=users" class="nav-link <?php echo ($_GET['section'] ?? '') == 'users' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i> <span>Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?section=deposits" class="nav-link <?php echo ($_GET['section'] ?? '') == 'deposits' ? 'active' : ''; ?>">
                        <i class="fas fa-arrow-down"></i> <span>Deposits</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?section=withdrawals" class="nav-link <?php echo ($_GET['section'] ?? '') == 'withdrawals' ? 'active' : ''; ?>">
                        <i class="fas fa-arrow-up"></i> <span>Withdrawals</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?section=loans" class="nav-link <?php echo ($_GET['section'] ?? '') == 'loans' ? 'active' : ''; ?>">
                        <i class="fas fa-hand-holding-usd"></i> <span>Loans</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?section=kyc" class="nav-link <?php echo ($_GET['section'] ?? '') == 'kyc' ? 'active' : ''; ?>">
                        <i class="fas fa-id-card"></i> <span>KYC Verification</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?section=appeals" class="nav-link <?php echo ($_GET['section'] ?? '') == 'appeals' ? 'active' : ''; ?>">
                        <i class="fas fa-gavel"></i> <span>Appeals</span>
                        <span id="appealCount" class="badge" style="background: #f59e0b; color: white; margin-left: 8px; display: <?php echo count($pendingAppeals) > 0 ? 'inline-block' : 'none'; ?>;">
                            <?php echo count($pendingAppeals); ?>
                        </span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?section=transactions" class="nav-link <?php echo ($_GET['section'] ?? '') == 'transactions' ? 'active' : ''; ?>">
                        <i class="fas fa-exchange-alt"></i> <span>Transactions</span>
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- MAIN CONTENT -->
        <div class="main-content">
            <!-- HEADER -->
            <div class="header">
                <h1>
                    <?php
                    $section = $_GET['section'] ?? 'dashboard';
                    $titles = [
                        'dashboard' => 'Dashboard Overview',
                        'balances' => 'Balance Management',
                        'users' => 'User Management',
                        'deposits' => 'Deposit Management',
                        'withdrawals' => 'Withdrawal Management',
                        'loans' => 'Loan Management',
                        'kyc' => 'KYC Verification',
                        'transactions' => 'Transaction Monitoring',
                        'appeals' => 'Appeal Management'
                    ];
                    echo $titles[$section] ?? 'Admin Dashboard';
                    ?>
                </h1>
                
                <div class="user-menu">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <!-- MESSAGES -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- DASHBOARD SECTION -->
            <?php if (!isset($_GET['section']) || $_GET['section'] == 'dashboard'): ?>
                <!-- STATS -->
                <div class="stats-grid">
                    <div class="stat-card total-users">
                        <div class="stat-label">Total Users</div>
                        <div class="stat-value"><?php echo number_format($stats['total_users'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card active-users">
                        <div class="stat-label">Active Users (30d)</div>
                        <div class="stat-value"><?php echo number_format($stats['active_users'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card pending-deposits">
                        <div class="stat-label">Pending Deposits</div>
                        <div class="stat-value"><?php echo number_format($stats['pending_deposits'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card pending-withdrawals">
                        <div class="stat-label">Pending Withdrawals</div>
                        <div class="stat-value"><?php echo number_format($stats['pending_withdrawals'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card pending-loans">
                        <div class="stat-label">Pending Loans</div>
                        <div class="stat-value"><?php echo number_format($stats['pending_loans'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card pending-kyc">
                        <div class="stat-label">Pending KYC</div>
                        <div class="stat-value"><?php echo number_format($stats['pending_kyc'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card pending-appeals">
                        <div class="stat-label">Pending Appeals</div>
                        <div class="stat-value"><?php echo number_format($stats['pending_appeals'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card locked-accounts">
                        <div class="stat-label">Locked Accounts</div>
                        <div class="stat-value"><?php echo number_format($stats['locked_accounts'] ?? 0); ?></div>
                    </div>
                </div>
                
                <!-- TOTAL BALANCES -->
                <div class="card mb-20">
                    <div class="card-header">
                        <h3 class="card-title">Total Platform Balances</h3>
                        <a href="?section=balances" class="btn btn-sm btn-outline">Manage Balances</a>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Currency</th>
                                    <th>Total Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($stats['total_balances'])): ?>
                                    <?php foreach ($stats['total_balances'] as $balance): ?>
                                    <tr>
                                        <td><span class="currency-badge"><?php echo htmlspecialchars($balance['currency_code']); ?></span></td>
                                        <td><strong class="balance-positive"><?php echo number_format($balance['total'], 8); ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" class="text-center">No balance data available</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- RECENT TRANSACTIONS -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Recent Transactions</h3>
                        <a href="?section=transactions" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($stats['recent_transactions'])): ?>
                                    <?php foreach ($stats['recent_transactions'] as $tx): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($tx['email']); ?></td>
                                        <td><?php echo htmlspecialchars($tx['type']); ?></td>
                                        <td class="balance-cell <?php echo $tx['amount'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                            <?php echo number_format($tx['amount'], 8); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($tx['currency_code']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $tx['status']; ?>">
                                                <?php echo ucfirst($tx['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y H:i', strtotime($tx['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No recent transactions</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            
            <!-- BALANCES SECTION (NEW) -->
            <?php elseif ($_GET['section'] == 'balances'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">User Balance Management</h3>
                        <div class="filter-bar">
                            <form method="GET" class="d-flex gap-10">
                                <input type="hidden" name="section" value="balances">
                                <input type="text" name="balance_search" class="search-input" 
                                       placeholder="Search by name or email..." 
                                       value="<?php echo htmlspecialchars($balanceSearch); ?>">
                                <select name="balance_currency" class="filter-select">
                                    <option value="">All Currencies</option>
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?php echo htmlspecialchars($currency); ?>" 
                                                <?php echo $balanceCurrency == $currency ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($currency); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="?section=balances" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </form>
                            <button class="btn btn-success" onclick="enableBulkEdit()" id="bulkEditBtn">
                                <i class="fas fa-pencil-alt"></i> Bulk Edit
                            </button>
                        </div>
                    </div>
                    
                    <!-- Bulk Actions Bar -->
                    <div id="bulkActionsBar" class="bulk-actions-bar hidden">
                        <span>
                            <i class="fas fa-check-square"></i>
                            <span id="selectedCount">0</span> items selected
                        </span>
                        <div class="d-flex gap-10">
                            <select id="bulkCurrency" class="filter-select">
                                <option value="">Select Currency</option>
                                <?php foreach ($currencies as $currency): ?>
                                    <option value="<?php echo htmlspecialchars($currency); ?>">
                                        <?php echo htmlspecialchars($currency); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="bulkAddAmount" class="search-input" 
                                   placeholder="Amount to add (+/-)" style="width: 120px;">
                            <input type="text" id="bulkSetAmount" class="search-input" 
                                   placeholder="Set exact amount" style="width: 120px;">
                            <button class="btn btn-primary" onclick="applyBulkAdd()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                            <button class="btn btn-warning" onclick="applyBulkSet()">
                                <i class="fas fa-equals"></i> Set
                            </button>
                            <button class="btn btn-success" onclick="saveBulkChanges()">
                                <i class="fas fa-save"></i> Save All
                            </button>
                            <button class="btn btn-outline" onclick="cancelBulkEdit()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </div>
                    
                    <!-- Summary Stats -->
                    <?php
                    $totalBalance = 0;
                    $totalUsers = count(array_unique(array_column($balances, 'user_id')));
                    $totalEntries = count($balances);
                    ?>
                    <div class="summary-card">
                        <div class="summary-stats">
                            <div class="stat-item">
                                <div class="label">Total Users</div>
                                <div class="value"><?php echo $totalUsers; ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="label">Balance Entries</div>
                                <div class="value"><?php echo $totalEntries; ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="label">Active Currencies</div>
                                <div class="value"><?php echo count($currencies); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <form id="balanceForm" method="POST">
                        <input type="hidden" name="action" value="bulk_update_balances">
                        <div class="table-container">
                            <table id="balanceTable">
                                <thead>
                                    <tr>
                                        <th width="30px">
                                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                                        </th>
                                        <th>User</th>
                                        <th>Status</th>
                                        <th>Currency</th>
                                        <th>Available Balance</th>
                                        <th>Pending Balance</th>
                                        <th>Total Balance</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($balances)): ?>
                                        <?php foreach ($balances as $balance): 
                                            $total = ($balance['available_balance'] ?? 0) + ($balance['pending_balance'] ?? 0);
                                        ?>
                                        <tr data-user-id="<?php echo $balance['user_id']; ?>" 
                                            data-currency="<?php echo $balance['currency_code']; ?>"
                                            data-available="<?php echo $balance['available_balance'] ?? 0; ?>"
                                            data-pending="<?php echo $balance['pending_balance'] ?? 0; ?>">
                                            <td>
                                                <input type="checkbox" class="row-select" 
                                                       onclick="updateSelectedCount()">
                                            </td>
                                            <td>
                                                <div class="user-info">
                                                    <strong><?php echo htmlspecialchars($balance['full_name'] ?? 'N/A'); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($balance['email'] ?? ''); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $balance['account_status'] ?? 'active'; ?>">
                                                    <?php echo ucfirst($balance['account_status'] ?? 'active'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="currency-badge"><?php echo htmlspecialchars($balance['currency_code'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td>
                                                <span class="editable-field-wrapper">
                                                    <input type="text" 
                                                           class="editable-field available-field" 
                                                           value="<?php echo number_format($balance['available_balance'] ?? 0, 8); ?>"
                                                           data-original="<?php echo $balance['available_balance'] ?? 0; ?>"
                                                           onchange="markAsEdited(this)"
                                                           data-user-id="<?php echo $balance['user_id']; ?>"
                                                           data-currency="<?php echo $balance['currency_code']; ?>">
                                                </span>
                                            </td>
                                            <td>
                                                <span class="editable-field-wrapper">
                                                    <input type="text" 
                                                           class="editable-field pending-field" 
                                                           value="<?php echo number_format($balance['pending_balance'] ?? 0, 8); ?>"
                                                           data-original="<?php echo $balance['pending_balance'] ?? 0; ?>"
                                                           onchange="markAsEdited(this)"
                                                           data-user-id="<?php echo $balance['user_id']; ?>"
                                                           data-currency="<?php echo $balance['currency_code']; ?>">
                                                </span>
                                            </td>
                                            <td class="balance-cell <?php echo $total >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                <?php echo number_format($total, 8); ?>
                                            </td>
                                            <td>
                                                <?php echo $balance['balance_updated_at'] ? date('M d, Y H:i', strtotime($balance['balance_updated_at'])) : 'Never'; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" class="btn btn-sm btn-primary" 
                                                            onclick="quickEditUser(<?php echo $balance['user_id']; ?>)"
                                                            data-tooltip="Edit All Currencies">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewUserTransactions(<?php echo $balance['user_id']; ?>)"
                                                            data-tooltip="View Transactions">
                                                        <i class="fas fa-history"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No balance data found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="modal-footer" style="margin-top: 20px;">
                            <button type="submit" class="btn btn-success" id="saveChangesBtn">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="button" class="btn btn-outline" onclick="resetChanges()">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Quick Edit User Modal -->
                <div id="quickEditModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3><i class="fas fa-edit"></i> Edit User Balances</h3>
                            <button class="close-modal" onclick="closeModal('quickEditModal')">&times;</button>
                        </div>
                        <div class="modal-body" id="quickEditContent">
                            <!-- Dynamic content -->
                        </div>
                        <div class="modal-footer">
                            <button class="btn" onclick="closeModal('quickEditModal')">Cancel</button>
                            <button class="btn btn-success" onclick="saveQuickEdit()">Save Changes</button>
                        </div>
                    </div>
                </div>
                
            <!-- USERS SECTION -->
            <?php elseif ($_GET['section'] == 'users'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">User Management</h3>
                        <div class="d-flex align-center gap-10">
                            <form method="GET" class="d-flex">
                                <input type="hidden" name="section" value="users">
                                <input type="text" name="search" class="search-input" placeholder="Search users..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                                <button type="submit" class="btn btn-primary" style="margin-left: 5px;">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>KYC Status</th>
                                    <th>Account Status</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>#<?php echo $user['id']; ?></td>
                                        <td><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['kyc_status'] ?? 'unverified'; ?>">
                                                <?php echo ucfirst($user['kyc_status'] ?? 'unverified'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $user['account_status'] ?? 'active';
                                            $statusLabels = [
                                                'active' => 'Active',
                                                'suspended' => 'Suspended',
                                                'under_review' => 'Under Review',
                                                'locked' => 'Locked'
                                            ];
                                            ?>
                                            <span class="status-badge status-<?php echo $status; ?>">
                                                <?php echo $statusLabels[$status] ?? ucfirst($status); ?>
                                            </span>
                                            <?php if ($status === 'suspended' && !empty($user['suspension_reason'])): ?>
                                                <br><small style="color: #94a3b8;" data-tooltip="<?php echo htmlspecialchars($user['suspension_reason']); ?>">
                                                    <?php echo htmlspecialchars(substr($user['suspension_reason'], 0, 20)); ?>...
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo ucfirst($user['role'] ?? 'user'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($user['created_at'] ?? 'now')); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-primary" onclick="openUserModal(<?php echo $user['id']; ?>)" data-tooltip="Manage User">
                                                    <i class="fas fa-cog"></i>
                                                </button>
                                                
                                                <?php if ($user['role'] !== 'admin'): ?>
                                                    <?php if (($user['account_status'] ?? '') === 'active'): ?>
                                                        <button class="btn btn-sm btn-warning" onclick="suspendUser(<?php echo $user['id']; ?>)" data-tooltip="Suspend User">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    <?php elseif (in_array($user['account_status'] ?? '', ['suspended', 'under_review'])): ?>
                                                        <button class="btn btn-sm btn-success" onclick="restoreUser(<?php echo $user['id']; ?>)" data-tooltip="Restore User">
                                                            <i class="fas fa-undo"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <button class="btn btn-sm btn-info" onclick="viewSuspensionHistory(<?php echo $user['id']; ?>)" data-tooltip="View History">
                                                    <i class="fas fa-history"></i>
                                                </button>
                                                
                                                <?php if (($user['appeal_status'] ?? '') === 'pending'): ?>
                                                    <button class="btn btn-sm" style="background: #f59e0b; color: white;" onclick="reviewAppeal(<?php echo $user['id']; ?>, 'approve')" data-tooltip="Review Appeal">
                                                        <i class="fas fa-gavel"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center">No users found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <!-- DEPOSITS SECTION -->
            <?php elseif ($_GET['section'] == 'deposits'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Pending Deposits (<?php echo count($pendingDeposits); ?>)</h3>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Method</th>
                                    <th>Transaction ID</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pendingDeposits)): ?>
                                    <?php foreach ($pendingDeposits as $deposit): ?>
                                    <tr>
                                        <td>#<?php echo $deposit['id']; ?></td>
                                        <td>
                                            <div class="user-info">
                                                <strong><?php echo htmlspecialchars($deposit['full_name'] ?? ''); ?></strong><br>
                                                <small><?php echo htmlspecialchars($deposit['email']); ?></small>
                                            </div>
                                        </td>
                                        <td><strong><?php echo number_format($deposit['amount'], 8); ?></strong></td>
                                        <td><?php echo htmlspecialchars($deposit['currency_code']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $deposit['method'])); ?></td>
                                        <td><?php echo htmlspecialchars($deposit['transaction_id'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($deposit['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-success" onclick="openDepositModal(<?php echo $deposit['id']; ?>, 'approved')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="openDepositModal(<?php echo $deposit['id']; ?>, 'rejected')">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No pending deposits</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <!-- WITHDRAWALS SECTION -->
            <?php elseif ($_GET['section'] == 'withdrawals'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Pending Withdrawals (<?php echo count($pendingWithdrawals); ?>)</h3>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Amount</th>
                                    <th>Currency</th>
                                    <th>Method</th>
                                    <th>Net Amount</th>
                                    <th>Fee</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pendingWithdrawals)): ?>
                                    <?php foreach ($pendingWithdrawals as $withdrawal): ?>
                                    <tr>
                                        <td>#<?php echo $withdrawal['id']; ?></td>
                                        <td>
                                            <div class="user-info">
                                                <strong><?php echo htmlspecialchars($withdrawal['full_name'] ?? ''); ?></strong><br>
                                                <small><?php echo htmlspecialchars($withdrawal['email']); ?></small>
                                            </div>
                                        </td>
                                        <td><strong><?php echo number_format($withdrawal['amount'], 8); ?></strong></td>
                                        <td><?php echo htmlspecialchars($withdrawal['currency_code']); ?></td>
                                        <td><?php echo ucfirst($withdrawal['method']); ?></td>
                                        <td><?php echo number_format($withdrawal['net_amount'], 8); ?></td>
                                        <td><?php echo number_format($withdrawal['fee_amount'], 8); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($withdrawal['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-info" onclick="openWithdrawalModal(<?php echo $withdrawal['id']; ?>, 'processing')">
                                                    <i class="fas fa-play"></i> Process
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="openWithdrawalModal(<?php echo $withdrawal['id']; ?>, 'rejected')">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center">No pending withdrawals</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <!-- LOANS SECTION -->
            <?php elseif ($_GET['section'] == 'loans'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Pending Loan Applications (<?php echo count($pendingLoans); ?>)</h3>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>KYC Status</th>
                                    <th>Loan Type</th>
                                    <th>Amount</th>
                                    <th>Purpose</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pendingLoans)): ?>
                                    <?php foreach ($pendingLoans as $loan): ?>
                                    <tr>
                                        <td>#<?php echo $loan['id']; ?></td>
                                        <td>
                                            <div class="user-info">
                                                <strong><?php echo htmlspecialchars($loan['full_name'] ?? ''); ?></strong><br>
                                                <small><?php echo htmlspecialchars($loan['email']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $loan['kyc_status'] ?? 'unverified'; ?>">
                                                <?php echo ucfirst($loan['kyc_status'] ?? 'unverified'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($loan['loan_type'] ?? 'Personal'); ?></td>
                                        <td><strong>$<?php echo number_format($loan['requested_amount'], 2); ?></strong></td>
                                        <td><?php echo htmlspecialchars(substr($loan['purpose'] ?? '', 0, 30)) . '...'; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($loan['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-success" onclick="openLoanModal(<?php echo $loan['id']; ?>, 'approved')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="openLoanModal(<?php echo $loan['id']; ?>, 'declined')">
                                                    <i class="fas fa-times"></i> Decline
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No pending loan applications</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <!-- KYC SECTION -->
            <?php elseif ($_GET['section'] == 'kyc'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Pending KYC Documents (<?php echo count($pendingKycDocs); ?>)</h3>
                        <div class="card-actions d-flex gap-10">
                            <select id="documentFilter" class="filter-select" onchange="filterKycDocuments()">
                                <option value="all">All Document Types</option>
                                <option value="id_card">ID Card</option>
                                <option value="passport">Passport</option>
                                <option value="drivers_license">Driver's License</option>
                                <option value="proof_of_address">Proof of Address</option>
                                <option value="selfie">Selfie</option>
                            </select>
                            <input type="text" id="userSearch" class="search-input" placeholder="Search by email or name..." onkeyup="searchKycUsers()">
                        </div>
                    </div>
                    
                    <?php if (!empty($pendingKycDocs)): ?>
                        <div class="table-container">
                            <table id="kycTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Document Type</th>
                                        <th>Document Preview</th>
                                        <th>User KYC Status</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingKycDocs as $doc): ?>
                                    <tr data-doc-type="<?php echo $doc['document_type']; ?>" 
                                        data-email="<?php echo strtolower($doc['email']); ?>" 
                                        data-name="<?php echo strtolower($doc['full_name']); ?>">
                                        <td>#<?php echo $doc['id']; ?></td>
                                        <td>
                                            <div class="user-info">
                                                <strong><?php echo htmlspecialchars($doc['full_name']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($doc['email']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $docTypes = [
                                                'id_card' => '<i class="fas fa-id-card"></i> ID Card',
                                                'passport' => '<i class="fas fa-passport"></i> Passport',
                                                'drivers_license' => '<i class="fas fa-car"></i> Driver\'s License',
                                                'proof_of_address' => '<i class="fas fa-home"></i> Proof of Address',
                                                'selfie' => '<i class="fas fa-camera"></i> Selfie'
                                            ];
                                            echo $docTypes[$doc['document_type']] ?? $doc['document_type'];
                                            ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($doc['document_front'])): ?>
                                                <div class="document-previews">
                                                    <a href="#" onclick="previewDocument('<?php echo addslashes($doc['document_front']); ?>', '<?php echo $doc['document_type']; ?>', 'front'); return false;" class="preview-link">
                                                        <i class="fas fa-eye"></i> Front
                                                    </a>
                                                    <?php if (!empty($doc['document_back'])): ?>
                                                        <a href="#" onclick="previewDocument('<?php echo addslashes($doc['document_back']); ?>', '<?php echo $doc['document_type']; ?>', 'back'); return false;" class="preview-link">
                                                            <i class="fas fa-eye"></i> Back
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #94a3b8;">No preview</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $doc['kyc_status'] ?? 'unverified'; ?>">
                                                <?php echo ucfirst($doc['kyc_status'] ?? 'unverified'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($doc['created_at'])); ?><br>
                                            <small style="color: #94a3b8;">
                                                <?php echo timeAgo($doc['created_at']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-success" onclick="openKycModal(<?php echo $doc['id']; ?>, 'verified')" data-tooltip="Verify Document">
                                                    <i class="fas fa-check"></i> Verify
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="openKycModal(<?php echo $doc['id']; ?>, 'rejected')" data-tooltip="Reject Document">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                                <button class="btn btn-sm btn-info" onclick="viewUserDocuments(<?php echo $doc['user_id']; ?>)" data-tooltip="View All Documents">
                                                    <i class="fas fa-folder-open"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h4>No Pending KYC Documents</h4>
                            <p>All documents have been reviewed. Check back later for new submissions.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
            <!-- APPEALS SECTION -->
            <?php elseif ($_GET['section'] == 'appeals'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Pending Appeals (<?php echo count($pendingAppeals); ?>)</h3>
                    </div>
                    
                    <?php if (!empty($pendingAppeals)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Suspension Reason</th>
                                        <th>Appeal Message</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingAppeals as $appeal): ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                <strong><?php echo htmlspecialchars($appeal['full_name'] ?? ''); ?></strong><br>
                                                <small><?php echo htmlspecialchars($appeal['email']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-suspended">Suspended</span><br>
                                            <small><?php echo htmlspecialchars(substr($appeal['suspension_reason'] ?? 'No reason', 0, 30)); ?>...</small>
                                        </td>
                                        <td>
                                            <div style="max-width: 250px;">
                                                <i class="fas fa-quote-left" style="color: #94a3b8; font-size: 12px;"></i>
                                                <?php echo htmlspecialchars(substr($appeal['appeal_message'] ?? 'No message', 0, 50)); ?>...
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($appeal['appeal_submitted_at'] ?? $appeal['submitted_at'] ?? 'now')); ?><br>
                                            <small style="color: #94a3b8;">
                                                <?php echo timeAgo($appeal['appeal_submitted_at'] ?? $appeal['submitted_at'] ?? ''); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-success" onclick="reviewAppeal(<?php echo $appeal['user_id']; ?>, 'approve')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="reviewAppeal(<?php echo $appeal['user_id']; ?>, 'reject')">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h4>No Pending Appeals</h4>
                            <p>All appeals have been processed.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
            <!-- TRANSACTIONS SECTION -->
            <?php elseif ($_GET['section'] == 'transactions'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">All Transactions</h3>
                    </div>
                    <div class="empty-state">
                        <i class="fas fa-exchange-alt"></i>
                        <h4>Transaction History</h4>
                        <p>Transaction monitoring feature coming soon.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- ==================== MODALS ==================== -->
    
    <!-- USER MANAGEMENT MODAL -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-cog"></i> Manage User</h3>
                <button class="close-modal" onclick="closeModal('userModal')">&times;</button>
            </div>
            <form id="userForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_balance">
                    <input type="hidden" id="user_id" name="user_id">
                    
                    <div class="form-group">
                        <label>User Information</label>
                        <div id="userInfo" class="mb-20" style="background: #0f172a; padding: 12px; border-radius: 6px;"></div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Currency</label>
                            <select id="currency_code" name="currency_code" required>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                                <option value="GBP">GBP</option>
                                <option value="BTC">BTC</option>
                                <option value="ETH">ETH</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Available Balance</label>
                            <input type="number" step="0.00000001" id="available_balance" name="available_balance" required>
                        </div>
                        <div class="form-group">
                            <label>Pending Balance</label>
                            <input type="number" step="0.00000001" id="pending_balance" name="pending_balance" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>KYC Status</label>
                        <select id="kyc_status" name="status">
                            <option value="unverified">Unverified</option>
                            <option value="pending">Pending</option>
                            <option value="verified">Verified</option>
                            <option value="declined">Declined</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes <span style="color: #94a3b8; font-size: 11px;">(Optional)</span></label>
                        <textarea id="notes" name="notes" placeholder="Reason for changes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('userModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- DEPOSIT MODAL -->
    <div id="depositModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="depositModalTitle">Process Deposit</h3>
                <button class="close-modal" onclick="closeModal('depositModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="process_deposit">
                    <input type="hidden" id="deposit_id" name="deposit_id">
                    <input type="hidden" id="deposit_status" name="status">
                    
                    <div class="form-group">
                        <label>Deposit Details</label>
                        <div id="depositInfo" style="background: #0f172a; padding: 15px; border-radius: 6px;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Admin Notes <span class="required">*</span></label>
                        <textarea id="deposit_admin_notes" name="admin_notes" rows="4" placeholder="Add notes for user or internal use..." required></textarea>
                        <small class="form-help">These notes will be visible to the user.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('depositModal')">Cancel</button>
                    <button type="submit" id="depositSubmitBtn" class="btn btn-success">Confirm</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- WITHDRAWAL MODAL -->
    <div id="withdrawalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="withdrawalModalTitle">Process Withdrawal</h3>
                <button class="close-modal" onclick="closeModal('withdrawalModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="process_withdrawal">
                    <input type="hidden" id="withdrawal_id" name="withdrawal_id">
                    <input type="hidden" id="withdrawal_status" name="status">
                    
                    <div class="form-group">
                        <label>Withdrawal Details</label>
                        <div id="withdrawalInfo" style="background: #0f172a; padding: 15px; border-radius: 6px;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Admin Notes <span class="required">*</span></label>
                        <textarea id="withdrawal_admin_notes" name="admin_notes" rows="4" placeholder="Add notes for user or internal use..." required></textarea>
                        <small class="form-help">These notes will be visible to the user.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('withdrawalModal')">Cancel</button>
                    <button type="submit" id="withdrawalSubmitBtn" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- LOAN MODAL -->
    <div id="loanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="loanModalTitle">Process Loan Application</h3>
                <button class="close-modal" onclick="closeModal('loanModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="process_loan">
                    <input type="hidden" id="loan_id" name="loan_id">
                    <input type="hidden" id="loan_status" name="status">
                    
                    <div class="form-group">
                        <label>Loan Details</label>
                        <div id="loanInfo" style="background: #0f172a; padding: 15px; border-radius: 6px;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Internal Notes <span style="color: #94a3b8; font-size: 11px;">(Not shown to user)</span></label>
                        <textarea id="loan_admin_notes" name="admin_notes" rows="3" placeholder="Internal notes for admin team..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Message to User <span style="color: #94a3b8; font-size: 11px;">(Shown in notification)</span></label>
                        <textarea id="loan_admin_message" name="admin_message" rows="3" placeholder="This message will be sent to the user..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('loanModal')">Cancel</button>
                    <button type="submit" id="loanSubmitBtn" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- KYC MODAL -->
    <div id="kycModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3 id="kycModalTitle">Verify KYC Document</h3>
                <button class="close-modal" onclick="closeModal('kycModal')">&times;</button>
            </div>
            <form id="kycForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="verify_kyc_document">
                    <input type="hidden" id="document_id" name="document_id">
                    <input type="hidden" id="kyc_doc_status" name="status">
                    
                    <div id="kycInfo"></div>
                    
                    <div class="form-group">
                        <label for="kyc_admin_notes">
                            <i class="fas fa-comment"></i> Admin Notes <span class="required">*</span>
                        </label>
                        <textarea name="admin_notes" id="kyc_admin_notes" rows="4" placeholder="Enter verification notes or rejection reason..." required></textarea>
                        <small class="form-help">Notes will be visible to the user in their notification.</small>
                    </div>
                    
                    <div id="verificationReqs"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('kycModal')">Cancel</button>
                    <button type="submit" id="submitKycBtn" class="btn btn-success">
                        <i class="fas fa-check"></i> Verify Document
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- DOCUMENT PREVIEW MODAL -->
    <div id="documentPreviewModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3 id="previewTitle">Document Preview</h3>
                <button class="close-modal" onclick="closeModal('documentPreviewModal')">&times;</button>
            </div>
            <div class="modal-body" id="previewContent">
                <!-- Document preview will be loaded here -->
            </div>
        </div>
    </div>
    
    <!-- USER DOCUMENTS MODAL -->
    <div id="userDocumentsModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>User KYC Documents</h3>
                <button class="close-modal" onclick="closeModal('userDocumentsModal')">&times;</button>
            </div>
            <div class="modal-body" id="userDocumentsContent">
                <!-- User documents will be loaded here -->
            </div>
        </div>
    </div>
    
    <!-- SUSPEND USER MODAL -->
    <div id="suspendUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-ban" style="color: #f59e0b;"></i> Suspend User Account</h3>
                <button class="close-modal" onclick="closeModal('suspendUserModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="suspend_user">
                    <input type="hidden" id="suspend_user_id" name="user_id">
                    
                    <div class="form-group">
                        <label>User</label>
                        <div id="suspendUserInfo" style="background: #0f172a; padding: 12px; border-radius: 6px;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="suspension_reason_select" style="color: #f59e0b;">
                            <i class="fas fa-exclamation-triangle"></i> Public Suspension Reason <span class="required">*</span>
                            <span style="font-size: 11px; color: #94a3b8; margin-left: 10px;">(Visible to user)</span>
                        </label>
                        <select id="suspension_reason_select" onchange="toggleCustomReason()" required>
                            <option value="">Select a reason...</option>
                            <option value="Violation of Terms of Service - Your account has been suspended for violating our terms of service. Please review our terms and submit an appeal if you believe this was in error.">
                                ⚠️ Violation of Terms of Service
                            </option>
                            <option value="Suspicious Activity Detected - Unusual activity was detected on your account. For security reasons, your account has been temporarily suspended. Please contact support to verify your identity.">
                                🔒 Suspicious Activity Detected
                            </option>
                            <option value="Failed KYC Verification - Your identity verification documents were not approved. Please resubmit valid documents and submit an appeal.">
                                📋 Failed KYC Verification
                            </option>
                            <option value="Chargeback or Payment Dispute - A chargeback or payment dispute was filed against your account. Your account has been suspended until this matter is resolved.">
                                💳 Chargeback / Payment Dispute
                            </option>
                            <option value="Inappropriate Behavior - Your account has been suspended due to inappropriate behavior or harassment of other users.">
                                🚫 Inappropriate Behavior
                            </option>
                            <option value="Multiple Account Violations - Operating multiple accounts is against our terms of service.">
                                👥 Multiple Account Violations
                            </option>
                            <option value="custom">✏️ Custom Reason (Write your own)</option>
                        </select>
                        <textarea id="custom_suspension_reason" name="custom_suspension_reason" 
                                  style="display: none; margin-top: 10px;" rows="4" 
                                  placeholder="Enter a clear, professional reason that will be shown to the user..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="suspend_admin_notes_private">
                            <i class="fas fa-lock" style="color: #64748b;"></i> Private Admin Notes
                            <span style="font-size: 11px; color: #94a3b8; margin-left: 10px;">(Not visible to user)</span>
                        </label>
                        <textarea id="suspend_admin_notes_private" name="admin_notes_private" 
                                  rows="3" placeholder="Internal notes for admin reference..."></textarea>
                    </div>
                    
                    <div style="background: #0f172a; padding: 15px; border-radius: 6px; margin-top: 20px;">
                        <strong style="color: #94a3b8; display: block; margin-bottom: 10px;">
                            <i class="fas fa-eye"></i> Preview - User Will See:
                        </strong>
                        <div id="userVisiblePreview" style="background: #1e293b; padding: 12px; border-radius: 4px; border-left: 4px solid #f59e0b;">
                            Select a reason to preview...
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('suspendUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-ban"></i> Suspend Account
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- RESTORE USER MODAL -->
    <div id="restoreUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo" style="color: #10b981;"></i> Restore User Account</h3>
                <button class="close-modal" onclick="closeModal('restoreUserModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="restore_user">
                    <input type="hidden" id="restore_user_id" name="user_id">
                    
                    <div class="form-group">
                        <label>User</label>
                        <div id="restoreUserInfo" style="background: #0f172a; padding: 12px; border-radius: 6px;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="restore_admin_notes">
                            <i class="fas fa-clipboard"></i> Restoration Notes
                        </label>
                        <textarea id="restore_admin_notes" name="admin_notes" 
                                  rows="3" placeholder="Reason for restoring account..."></textarea>
                        <small class="form-help">This message will be sent to the user.</small>
                    </div>
                    
                    <div class="alert alert-success" style="margin-top: 15px;">
                        <i class="fas fa-check-circle"></i> 
                        The user will be able to login and use all features again.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('restoreUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Restore Account</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- APPEAL REVIEW MODAL -->
    <div id="appealReviewModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3 id="appealModalTitle">Review User Appeal</h3>
                <button class="close-modal" onclick="closeModal('appealReviewModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="process_appeal">
                    <input type="hidden" id="appeal_user_id" name="user_id">
                    <input type="hidden" id="appeal_decision" name="decision">
                    
                    <div id="appealDetails"></div>
                    
                    <div class="form-group">
                        <label for="admin_response_public">
                            <i class="fas fa-reply" style="color: #9d50ff;"></i> Public Response to User
                            <span class="required">*</span>
                            <span style="font-size: 11px; color: #94a3b8; margin-left: 10px;">(Visible to user)</span>
                        </label>
                        <textarea id="admin_response_public" name="admin_response_public" 
                                  rows="5" placeholder="Write a clear, professional response that will be sent to the user..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="appeal_admin_notes_private">
                            <i class="fas fa-lock" style="color: #64748b;"></i> Private Admin Notes
                            <span style="font-size: 11px; color: #94a3b8; margin-left: 10px;">(Not visible to user)</span>
                        </label>
                        <textarea id="appeal_admin_notes_private" name="admin_notes_private" 
                                  rows="3" placeholder="Internal notes about this appeal..."></textarea>
                    </div>
                    
                    <div id="appealDecisionInfo" class="alert" style="margin-top: 20px;"></div>
                    
                    <div id="responsePreview" style="background: #0f172a; padding: 15px; border-radius: 6px; margin-top: 20px;">
                        <strong style="color: #94a3b8; display: block; margin-bottom: 10px;">
                            <i class="fas fa-eye"></i> Preview - User Will See:
                        </strong>
                        <div id="responsePreviewContent" style="background: #1e293b; padding: 15px; border-radius: 4px; border-left: 4px solid #9d50ff;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('appealReviewModal')">Cancel</button>
                    <button type="submit" id="appealSubmitBtn" class="btn btn-primary">Process Appeal</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- SUSPENSION HISTORY MODAL -->
    <div id="historyModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3><i class="fas fa-history"></i> Account Suspension History</h3>
                <button class="close-modal" onclick="closeModal('historyModal')">&times;</button>
            </div>
            <div class="modal-body" id="historyContent">
                <!-- History will be populated via JS -->
            </div>
        </div>
    </div>
    
   <script>
// ==================== GLOBAL MODAL FUNCTIONS ====================
// Explicitly attach to window object
window.openModal = function(modalId) {
    console.log('openModal called:', modalId);
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    } else {
        console.error('Modal not found:', modalId);
    }
};

window.closeModal = function(modalId) {
    console.log('closeModal called:', modalId);
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
};

// ==================== BALANCE MANAGEMENT FUNCTIONS ====================
let editedRows = new Set();
let selectedRows = new Set();
let bulkEditEnabled = false;

window.enableBulkEdit = function() {
    bulkEditEnabled = true;
    document.getElementById('bulkEditBtn').style.display = 'none';
    document.getElementById('bulkActionsBar').classList.remove('hidden');
    document.getElementById('selectAll').style.display = 'inline-block';
    
    // Show all checkboxes
    document.querySelectorAll('.row-select').forEach(cb => {
        cb.style.display = 'inline-block';
    });
};

window.cancelBulkEdit = function() {
    bulkEditEnabled = false;
    document.getElementById('bulkEditBtn').style.display = 'inline-block';
    document.getElementById('bulkActionsBar').classList.add('hidden');
    document.getElementById('selectAll').style.display = 'none';
    
    // Hide all checkboxes
    document.querySelectorAll('.row-select').forEach(cb => {
        cb.style.display = 'none';
        cb.checked = false;
    });
    
    selectedRows.clear();
    updateSelectedCount();
};

window.toggleSelectAll = function() {
    const selectAll = document.getElementById('selectAll');
    document.querySelectorAll('.row-select').forEach(cb => {
        cb.checked = selectAll.checked;
        if (selectAll.checked) {
            const row = cb.closest('tr');
            const userId = row.dataset.userId;
            const currency = row.dataset.currency;
            selectedRows.add(`${userId}:${currency}`);
        } else {
            selectedRows.clear();
        }
    });
    updateSelectedCount();
};

window.updateSelectedCount = function() {
    selectedRows.clear();
    document.querySelectorAll('.row-select:checked').forEach(cb => {
        const row = cb.closest('tr');
        const userId = row.dataset.userId;
        const currency = row.dataset.currency;
        selectedRows.add(`${userId}:${currency}`);
    });
    document.getElementById('selectedCount').textContent = selectedRows.size;
};

window.markAsEdited = function(field) {
    const row = field.closest('tr');
    const userId = row.dataset.userId;
    const currency = row.dataset.currency;
    const key = `${userId}:${currency}`;
    
    editedRows.add(key);
    
    // Add visual indicator
    row.classList.add('bulk-edit-row');
};

window.applyBulkAdd = function() {
    const amount = parseFloat(document.getElementById('bulkAddAmount').value);
    if (isNaN(amount) || amount === 0) {
        alert('Please enter a valid amount to add');
        return;
    }
    
    const currency = document.getElementById('bulkCurrency').value;
    
    selectedRows.forEach(key => {
        const [userId, cur] = key.split(':');
        if (currency && cur !== currency) return;
        
        const row = document.querySelector(`tr[data-user-id="${userId}"][data-currency="${cur}"]`);
        if (row) {
            const availableField = row.querySelector('.available-field');
            const currentValue = parseFloat(availableField.value) || 0;
            availableField.value = (currentValue + amount).toFixed(8);
            markAsEdited(availableField);
        }
    });
};

window.applyBulkSet = function() {
    const amount = parseFloat(document.getElementById('bulkSetAmount').value);
    if (isNaN(amount)) {
        alert('Please enter a valid amount');
        return;
    }
    
    const currency = document.getElementById('bulkCurrency').value;
    
    selectedRows.forEach(key => {
        const [userId, cur] = key.split(':');
        if (currency && cur !== currency) return;
        
        const row = document.querySelector(`tr[data-user-id="${userId}"][data-currency="${cur}"]`);
        if (row) {
            const availableField = row.querySelector('.available-field');
            availableField.value = amount.toFixed(8);
            markAsEdited(availableField);
        }
    });
};

window.saveBulkChanges = function() {
    if (editedRows.size === 0) {
        alert('No changes to save');
        return;
    }
    
    const form = document.getElementById('balanceForm');
    const existingInputs = form.querySelectorAll('input[name="bulk_updates[]"]');
    existingInputs.forEach(input => input.remove());
    
    editedRows.forEach(key => {
        const [userId, currency] = key.split(':');
        const row = document.querySelector(`tr[data-user-id="${userId}"][data-currency="${currency}"]`);
        
        if (row) {
            const available = row.querySelector('.available-field').value;
            const pending = row.querySelector('.pending-field').value;
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bulk_updates[]';
            input.value = JSON.stringify({
                user_id: parseInt(userId),
                currency_code: currency,
                available_balance: parseFloat(available),
                pending_balance: parseFloat(pending),
                notes: 'Bulk balance update'
            });
            
            form.appendChild(input);
        }
    });
    
    form.submit();
};

window.resetChanges = function() {
    if (confirm('Reset all unsaved changes?')) {
        location.reload();
    }
};

window.quickEditUser = async function(userId) {
    try {
        const response = await fetch(`ajax/get_user_balances.php?user_id=${userId}`);
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error);
        
        const user = result.user;
        const balances = result.balances;
        
        let html = `
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0; color: #fff;">${escapeHTML(user.full_name)}</h4>
                <p style="margin: 5px 0 0 0; color: #94a3b8;">${escapeHTML(user.email)}</p>
            </div>
        `;
        
        if (balances.length > 0) {
            html += '<div class="table-container"><table><thead><tr><th>Currency</th><th>Available</th><th>Pending</th><th>Actions</th></tr></thead><tbody>';
            
            balances.forEach(balance => {
                html += `
                    <tr>
                        <td><span class="currency-badge">${balance.currency_code}</span></td>
                        <td>
                            <input type="text" class="editable-field" 
                                   value="${parseFloat(balance.available_balance).toFixed(8)}"
                                   data-user-id="${userId}"
                                   data-currency="${balance.currency_code}"
                                   data-field="available">
                        </td>
                        <td>
                            <input type="text" class="editable-field" 
                                   value="${parseFloat(balance.pending_balance).toFixed(8)}"
                                   data-user-id="${userId}"
                                   data-currency="${balance.currency_code}"
                                   data-field="pending">
                        </td>
                        <td>
                            <button class="btn btn-sm btn-success" onclick="saveQuickEditRow('${userId}', '${balance.currency_code}')">
                                <i class="fas fa-save"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-center">No balances found for this user.</p>';
        }
        
        document.getElementById('quickEditContent').innerHTML = html;
        openModal('quickEditModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

window.saveQuickEditRow = async function(userId, currency) {
    const row = event.target.closest('tr');
    const availableField = row.querySelector('input[data-field="available"]');
    const pendingField = row.querySelector('input[data-field="pending"]');
    
    const formData = new FormData();
    formData.append('action', 'update_balance');
    formData.append('user_id', userId);
    formData.append('currency_code', currency);
    formData.append('available_balance', parseFloat(availableField.value));
    formData.append('pending_balance', parseFloat(pendingField.value));
    formData.append('notes', 'Quick edit from balance management');
    
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.text();
        alert('Balance updated successfully');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

window.viewUserTransactions = function(userId) {
    window.location.href = `?section=transactions&user_id=${userId}`;
};

// ==================== USER MANAGEMENT ====================
window.openUserModal = async function(userId) {
    console.log('Loading user ID:', userId);
    
    try {
        const response = await fetch(`ajax/get_user.php?id=${userId}`, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to load user data');
        }
        
        populateUserModal(result.user, result.balances);
        openModal('userModal');
        
    } catch (error) {
        console.error('Error loading user:', error);
        alert('Error loading user: ' + error.message);
    }
};

window.populateUserModal = function(user, balances) {
    const userIdField = document.getElementById('user_id');
    if (userIdField) userIdField.value = user.id;
    
    const kycStatusField = document.getElementById('kyc_status');
    if (kycStatusField) kycStatusField.value = user.kyc_status || 'unverified';
    
    const userInfoDiv = document.getElementById('userInfo');
    if (userInfoDiv) {
        userInfoDiv.innerHTML = `
            <strong style="font-size: 16px;">${escapeHTML(user.full_name || 'N/A')}</strong><br>
            <span style="color: #94a3b8;">${escapeHTML(user.email || 'N/A')}</span><br>
            <span style="color: #94a3b8;">Phone: ${escapeHTML(user.phone || 'N/A')}</span><br>
            <span style="color: #94a3b8;">Joined: ${user.created_at ? new Date(user.created_at).toLocaleDateString() : 'N/A'}</span><br>
            Status: <span class="status-badge status-${user.account_status || 'active'}">${getStatusLabel(user.account_status)}</span>
        `;
    }
    
    if (balances && balances.length > 0) {
        const firstBalance = balances[0];
        const currencyField = document.getElementById('currency_code');
        if (currencyField) currencyField.value = firstBalance.currency_code || 'USD';
        
        const availField = document.getElementById('available_balance');
        if (availField) availField.value = parseFloat(firstBalance.available_balance || 0).toFixed(8);
        
        const pendField = document.getElementById('pending_balance');
        if (pendField) pendField.value = parseFloat(firstBalance.pending_balance || 0).toFixed(8);
    } else {
        const currencyField = document.getElementById('currency_code');
        if (currencyField) currencyField.value = 'USD';
        
        const availField = document.getElementById('available_balance');
        if (availField) availField.value = '0.00000000';
        
        const pendField = document.getElementById('pending_balance');
        if (pendField) pendField.value = '0.00000000';
    }
};

// ==================== DEPOSIT MANAGEMENT ====================
window.openDepositModal = async function(depositId, status) {
    console.log('Opening deposit:', depositId, status);
    
    try {
        const response = await fetch(`ajax/get_deposit.php?id=${depositId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load deposit');
        
        const deposit = result.data;
        
        document.getElementById('deposit_id').value = depositId;
        document.getElementById('deposit_status').value = status;
        
        document.getElementById('depositInfo').innerHTML = `
            <table style="width: 100%;">
                <tr><td style="padding: 5px 0; color: #94a3b8;">User:</td>
                    <td style="padding: 5px 0;">${escapeHTML(deposit.email)} (${escapeHTML(deposit.full_name)})</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Amount:</td>
                    <td style="padding: 5px 0; font-weight: bold;">${parseFloat(deposit.amount).toFixed(8)} ${deposit.currency_code}</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Method:</td>
                    <td style="padding: 5px 0;">${deposit.method.replace('_', ' ').toUpperCase()}</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Transaction ID:</td>
                    <td style="padding: 5px 0; font-family: monospace;">${escapeHTML(deposit.transaction_id || 'N/A')}</td></tr>
            </table>
        `;
        
        const title = document.getElementById('depositModalTitle');
        const submitBtn = document.getElementById('depositSubmitBtn');
        const notesField = document.getElementById('deposit_admin_notes');
        
        if (status === 'approved') {
            title.textContent = 'Approve Deposit';
            submitBtn.textContent = 'Approve Deposit';
            submitBtn.className = 'btn btn-success';
            notesField.value = 'Deposit verified and approved.';
        } else {
            title.textContent = 'Reject Deposit';
            submitBtn.textContent = 'Reject Deposit';
            submitBtn.className = 'btn btn-danger';
            notesField.value = 'Deposit rejected. ';
        }
        
        openModal('depositModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

// ==================== WITHDRAWAL MANAGEMENT ====================
window.openWithdrawalModal = async function(withdrawalId, status) {
    console.log('Opening withdrawal:', withdrawalId, status);
    
    try {
        const response = await fetch(`ajax/get_withdrawal.php?id=${withdrawalId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load withdrawal');
        
        const withdrawal = result.data;
        
        document.getElementById('withdrawal_id').value = withdrawalId;
        document.getElementById('withdrawal_status').value = status;
        
        const feeAmount = parseFloat(withdrawal.fee_amount || 0);
        const netAmount = parseFloat(withdrawal.net_amount || withdrawal.amount);
        const totalAmount = parseFloat(withdrawal.amount);
        
        document.getElementById('withdrawalInfo').innerHTML = `
            <table style="width: 100%;">
                <tr><td style="padding: 5px 0; color: #94a3b8;">User:</td>
                    <td style="padding: 5px 0;">${escapeHTML(withdrawal.email)} (${escapeHTML(withdrawal.full_name)})</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Amount:</td>
                    <td style="padding: 5px 0; font-weight: bold;">${totalAmount.toFixed(8)} ${withdrawal.currency_code}</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Fee (${withdrawal.fee_percentage || 0}%):</td>
                    <td style="padding: 5px 0; color: #ef4444;">${feeAmount.toFixed(8)} ${withdrawal.currency_code}</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Net Amount:</td>
                    <td style="padding: 5px 0; font-weight: bold; color: #10b981;">${netAmount.toFixed(8)} ${withdrawal.currency_code}</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Method:</td>
                    <td style="padding: 5px 0;">${withdrawal.method.toUpperCase()}</td></tr>
            </table>
        `;
        
        const title = document.getElementById('withdrawalModalTitle');
        const submitBtn = document.getElementById('withdrawalSubmitBtn');
        const notesField = document.getElementById('withdrawal_admin_notes');
        
        const titles = {
            'processing': 'Process Withdrawal',
            'completed': 'Complete Withdrawal',
            'rejected': 'Reject Withdrawal'
        };
        
        const buttonClasses = {
            'processing': 'btn-info',
            'completed': 'btn-success',
            'rejected': 'btn-danger'
        };
        
        title.textContent = titles[status] || 'Process Withdrawal';
        submitBtn.textContent = titles[status] || 'Confirm';
        submitBtn.className = `btn ${buttonClasses[status] || 'btn-primary'}`;
        
        notesField.value = status === 'processing' ? 'Withdrawal processing initiated.' :
                          status === 'completed' ? 'Withdrawal processed and completed.' :
                          'Withdrawal rejected. Reason: ';
        
        openModal('withdrawalModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

// ==================== LOAN MANAGEMENT ====================
window.openLoanModal = async function(loanId, status) {
    console.log('Opening loan:', loanId, status);
    
    try {
        const response = await fetch(`ajax/get_loan.php?id=${loanId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load loan');
        
        const loan = result.data;
        
        document.getElementById('loan_id').value = loanId;
        document.getElementById('loan_status').value = status;
        
        document.getElementById('loanInfo').innerHTML = `
            <table style="width: 100%;">
                <tr><td style="padding: 5px 0; color: #94a3b8;">Applicant:</td>
                    <td style="padding: 5px 0;">${escapeHTML(loan.email)} (${escapeHTML(loan.full_name)})</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Loan Type:</td>
                    <td style="padding: 5px 0;">${escapeHTML(loan.loan_type || 'Personal Loan')}</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Amount:</td>
                    <td style="padding: 5px 0; font-weight: bold; color: #f59e0b;">$${parseFloat(loan.requested_amount).toFixed(2)}</td></tr>
                <tr><td style="padding: 5px 0; color: #94a3b8;">Purpose:</td>
                    <td style="padding: 5px 0;">${escapeHTML(loan.purpose || 'Not specified')}</td></tr>
            </table>
        `;
        
        const title = document.getElementById('loanModalTitle');
        const submitBtn = document.getElementById('loanSubmitBtn');
        const adminNotes = document.getElementById('loan_admin_notes');
        const adminMessage = document.getElementById('loan_admin_message');
        
        if (status === 'approved') {
            title.textContent = 'Approve Loan Application';
            submitBtn.textContent = 'Approve Loan';
            submitBtn.className = 'btn btn-success';
            adminMessage.value = 'Congratulations! Your loan has been approved. The funds have been credited to your account.';
            adminNotes.value = '';
        } else {
            title.textContent = 'Decline Loan Application';
            submitBtn.textContent = 'Decline Loan';
            submitBtn.className = 'btn btn-danger';
            adminNotes.value = 'Loan application declined after review.';
            adminMessage.value = 'We regret to inform you that your loan application has been declined at this time.';
        }
        
        openModal('loanModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

// ==================== KYC MANAGEMENT ====================
window.openKycModal = async function(documentId, status) {
    console.log('Opening KYC document:', documentId, status);
    
    try {
        const response = await fetch(`ajax/get_kyc_document.php?id=${documentId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load document');
        
        const doc = result.data;
        
        const docTypeNames = {
            'id_card': 'ID Card',
            'passport': 'Passport',
            'drivers_license': "Driver's License",
            'proof_of_address': 'Proof of Address',
            'selfie': 'Selfie Photo'
        };
        
        const docTypeName = docTypeNames[doc.document_type] || doc.document_type;
        
        document.getElementById('document_id').value = documentId;
        document.getElementById('kyc_doc_status').value = status;
        
        document.getElementById('kycInfo').innerHTML = `
            <div class="document-review-card">
                <div class="review-header">
                    <div class="user-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="user-details">
                        <h4>${escapeHTML(doc.full_name || 'User')}</h4>
                        <p class="user-email">${escapeHTML(doc.email)}</p>
                        <span class="status-badge status-${doc.kyc_status || 'unverified'}">
                            ${(doc.kyc_status || 'UNVERIFIED').toUpperCase()}
                        </span>
                    </div>
                </div>
                
                <div class="document-details">
                    <div class="detail-row">
                        <div class="detail-label">Document Type:</div>
                        <div class="detail-value"><strong>${docTypeName}</strong></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Submitted:</div>
                        <div class="detail-value">${new Date(doc.created_at).toLocaleString()}</div>
                    </div>
                </div>
                
                <div class="document-previews" style="margin-top: 15px;">
                    ${doc.document_front ? `
                        <button onclick="previewDocument('${escapeHTML(doc.document_front)}', '${doc.document_type}', 'front')" 
                                class="btn btn-sm btn-outline">
                            <i class="fas fa-eye"></i> View Front
                        </button>
                    ` : ''}
                    ${doc.document_back ? `
                        <button onclick="previewDocument('${escapeHTML(doc.document_back)}', '${doc.document_type}', 'back')" 
                                class="btn btn-sm btn-outline">
                            <i class="fas fa-eye"></i> View Back
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
        
        document.getElementById('verificationReqs').innerHTML = getVerificationRequirements(doc.document_type, status);
        
        const title = document.getElementById('kycModalTitle');
        const submitBtn = document.getElementById('submitKycBtn');
        const notesField = document.getElementById('kyc_admin_notes');
        
        if (status === 'verified') {
            title.textContent = `Verify ${docTypeName}`;
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Verify Document';
            submitBtn.className = 'btn btn-success';
            notesField.value = `${docTypeName} verified successfully.`;
        } else {
            title.textContent = `Reject ${docTypeName}`;
            submitBtn.innerHTML = '<i class="fas fa-times"></i> Reject Document';
            submitBtn.className = 'btn btn-danger';
            notesField.value = `${docTypeName} rejected. Reason: `;
        }
        
        openModal('kycModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

window.getVerificationRequirements = function(docType, action) {
    const checklists = {
        'id_card': {
            verify: ['Clear and readable text', 'All corners visible', 'Not expired', 'Photo matches user', 'Security features visible'],
            reject: ['Blurry or unclear', 'Expired document', 'Information mismatch', 'Suspicious alterations', 'Poor quality']
        },
        'passport': {
            verify: ['Clear and readable', 'All pages visible', 'Not expired', 'Signature present', 'Security features intact'],
            reject: ['Expired passport', 'Damaged pages', 'Missing information', 'Signature mismatch', 'Suspicious document']
        },
        'drivers_license': {
            verify: ['Front and back visible', 'Clear expiration date', 'Address matches', 'Photo matches user', 'Issuing authority clear'],
            reject: ['Expired license', 'Information unclear', 'Address mismatch', 'Suspicious alterations', 'Poor quality']
        },
        'proof_of_address': {
            verify: ['Issued within last 3 months', 'Name and address clear', 'Official document', 'Matches user details', 'No alterations'],
            reject: ['Older than 3 months', 'Address mismatch', 'Unofficial document', 'Altered information', 'Poor quality']
        },
        'selfie': {
            verify: ['Face clearly visible', 'Good lighting', 'No filters/editing', 'Matches ID photo', 'ID visible in hand'],
            reject: ['Face not visible', 'Poor lighting', 'Filters or editing', "Doesn't match ID", 'ID not visible']
        }
    };
    
    const checklist = checklists[docType] || {
        verify: ['Document is clear and readable', 'All information is visible'],
        reject: ['Document is unclear', 'Information missing or altered']
    };
    
    const items = action === 'verified' ? checklist.verify : checklist.reject;
    
    return `
        <div class="verification-checklist">
            <h4><i class="fas fa-clipboard-check"></i> ${action === 'verified' ? 'Verification' : 'Rejection'} Checklist</h4>
            <ul>
                ${items.map(item => `<li><i class="fas fa-check-circle"></i> ${item}</li>`).join('')}
            </ul>
        </div>
    `;
};

window.previewDocument = async function(fileUrl, docType, side) {
    const docTypeNames = {
        'id_card': 'ID Card',
        'passport': 'Passport',
        'drivers_license': "Driver's License",
        'proof_of_address': 'Proof of Address',
        'selfie': 'Selfie'
    };
    
    const sideText = side === 'front' ? 'Front Side' : 'Back Side';
    const title = `${docTypeNames[docType] || docType} - ${sideText}`;
    
    document.getElementById('previewTitle').textContent = title;
    
    const extension = fileUrl.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp'].includes(extension);
    const isPDF = extension === 'pdf';
    
    let content = '';
    
    if (isImage) {
        content = `
            <div class="image-preview">
                <img src="${escapeHTML(fileUrl)}" alt="${escapeHTML(title)}" class="document-image">
                <div class="preview-actions">
                    <a href="${escapeHTML(fileUrl)}" target="_blank" class="btn btn-outline">
                        <i class="fas fa-external-link-alt"></i> Open in New Tab
                    </a>
                </div>
            </div>
        `;
    } else if (isPDF) {
        content = `
            <div class="pdf-preview-container">
                <iframe src="${escapeHTML(fileUrl)}" frameborder="0" class="pdf-viewer"></iframe>
                <div class="preview-actions">
                    <a href="${escapeHTML(fileUrl)}" target="_blank" class="btn btn-outline">
                        <i class="fas fa-external-link-alt"></i> Open PDF
                    </a>
                    <a href="${escapeHTML(fileUrl)}" download class="btn btn-outline">
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
            </div>
        `;
    } else {
        content = `
            <div class="file-preview">
                <div class="file-icon"><i class="fas fa-file"></i></div>
                <p>Preview not available for this file type</p>
                <a href="${escapeHTML(fileUrl)}" target="_blank" class="btn btn-outline">
                    <i class="fas fa-external-link-alt"></i> Open File
                </a>
            </div>
        `;
    }
    
    document.getElementById('previewContent').innerHTML = content;
    openModal('documentPreviewModal');
};

window.viewUserDocuments = async function(userId) {
    try {
        const response = await fetch(`ajax/get_user_documents.php?user_id=${userId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load documents');
        
        const docs = result.data || [];
        const user = result.user || {};
        
        let content = `
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0 0 5px 0; color: #fff;">${escapeHTML(user.full_name || 'User')}</h4>
                <p style="margin: 0 0 15px 0; color: #94a3b8;">${escapeHTML(user.email || '')}</p>
                <span class="status-badge status-${user.kyc_status || 'unverified'}">
                    ${(user.kyc_status || 'UNVERIFIED').toUpperCase()}
                </span>
            </div>
        `;
        
        if (docs.length > 0) {
            content += `<div class="documents-grid">`;
            
            docs.forEach(doc => {
                const docTypeNames = {
                    'id_card': 'ID Card',
                    'passport': 'Passport',
                    'drivers_license': "Driver's License",
                    'proof_of_address': 'Proof of Address',
                    'selfie': 'Selfie'
                };
                
                const docTypeName = docTypeNames[doc.document_type] || doc.document_type;
                
                content += `
                    <div class="document-card status-${doc.status}">
                        <div class="document-header">
                            <h5>${docTypeName}</h5>
                            <span class="doc-status status-${doc.status}">${doc.status}</span>
                        </div>
                        <div class="document-body">
                            <div class="info-row">
                                <span>Submitted:</span>
                                <span>${new Date(doc.created_at).toLocaleDateString()}</span>
                            </div>
                            ${doc.verified_at ? `
                                <div class="info-row">
                                    <span>Verified:</span>
                                    <span>${new Date(doc.verified_at).toLocaleDateString()}</span>
                                </div>
                            ` : ''}
                            ${doc.admin_notes ? `
                                <div class="info-row">
                                    <span>Notes:</span>
                                    <span class="notes">${escapeHTML(doc.admin_notes)}</span>
                                </div>
                            ` : ''}
                            <div class="doc-actions">
                                ${doc.document_front ? `
                                    <button onclick="previewDocument('${escapeHTML(doc.document_front)}', '${doc.document_type}', 'front')" 
                                            class="btn btn-sm btn-outline">
                                        <i class="fas fa-eye"></i> Front
                                    </button>
                                ` : ''}
                                ${doc.document_back ? `
                                    <button onclick="previewDocument('${escapeHTML(doc.document_back)}', '${doc.document_type}', 'back')" 
                                            class="btn btn-sm btn-outline">
                                        <i class="fas fa-eye"></i> Back
                                    </button>
                                ` : ''}
                                ${doc.status === 'pending' ? `
                                    <button onclick="openKycModal(${doc.id}, 'verified')" class="btn btn-sm btn-success">
                                        <i class="fas fa-check"></i> Verify
                                    </button>
                                    <button onclick="openKycModal(${doc.id}, 'rejected')" class="btn btn-sm btn-danger">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            content += `</div>`;
        } else {
            content += `
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h5>No Documents Found</h5>
                    <p>This user hasn't uploaded any KYC documents yet.</p>
                </div>
            `;
        }
        
        document.getElementById('userDocumentsContent').innerHTML = content;
        openModal('userDocumentsModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

// ==================== SUSPEND USER FUNCTIONS ====================
window.suspendUser = async function(userId) {
    console.log('Suspending user:', userId);
    
    try {
        const response = await fetch(`ajax/get_user.php?id=${userId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load user');
        
        const user = result.user;
        
        document.getElementById('suspend_user_id').value = userId;
        document.getElementById('suspendUserInfo').innerHTML = `
            <strong style="font-size: 16px;">${escapeHTML(user.full_name || 'N/A')}</strong><br>
            <span style="color: #94a3b8;">${escapeHTML(user.email)}</span><br>
            Current Status: <span class="status-badge status-${user.account_status || 'active'}">${getStatusLabel(user.account_status)}</span>
        `;
        
        const reasonSelect = document.getElementById('suspension_reason_select');
        if (reasonSelect) reasonSelect.value = '';
        
        const customReason = document.getElementById('custom_suspension_reason');
        if (customReason) {
            customReason.style.display = 'none';
            customReason.value = '';
        }
        
        const privateNotes = document.getElementById('suspend_admin_notes_private');
        if (privateNotes) privateNotes.value = '';
        
        document.getElementById('userVisiblePreview').innerHTML = 'Select a reason to preview...';
        
        openModal('suspendUserModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

window.toggleCustomReason = function() {
    const select = document.getElementById('suspension_reason_select');
    const customField = document.getElementById('custom_suspension_reason');
    const previewDiv = document.getElementById('userVisiblePreview');
    
    if (!select || !customField || !previewDiv) return;
    
    if (select.value === 'custom') {
        customField.style.display = 'block';
        customField.required = true;
        select.name = '';
        customField.name = 'suspension_reason';
        
        if (customField.value) {
            previewDiv.innerHTML = `<strong style="color: #f59e0b;">Suspension Reason:</strong><br>${escapeHTML(customField.value)}`;
        } else {
            previewDiv.innerHTML = 'Enter a custom reason to preview...';
        }
    } else {
        customField.style.display = 'none';
        customField.required = false;
        select.name = 'suspension_reason';
        customField.name = 'custom_suspension_reason';
        
        if (select.value) {
            const selectedText = select.options[select.selectedIndex]?.text || '';
            previewDiv.innerHTML = `<strong style="color: #f59e0b;">Suspension Reason:</strong><br>${escapeHTML(selectedText.replace(/[⚠️🔒📋💳🚫👥✏️]/g, ''))}<br><br>${escapeHTML(select.value)}`;
        } else {
            previewDiv.innerHTML = 'Select a reason to preview...';
        }
    }
};

// ==================== RESTORE USER FUNCTIONS ====================
window.restoreUser = async function(userId) {
    console.log('Restoring user:', userId);
    
    try {
        const response = await fetch(`ajax/get_user.php?id=${userId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load user');
        
        const user = result.user;
        
        document.getElementById('restore_user_id').value = userId;
        document.getElementById('restoreUserInfo').innerHTML = `
            <strong>${escapeHTML(user.full_name || 'N/A')}</strong><br>
            Email: ${escapeHTML(user.email)}<br>
            ${user.suspension_reason ? 'Suspended Reason: ' + escapeHTML(user.suspension_reason) + '<br>' : ''}
            Since: ${user.suspended_at ? new Date(user.suspended_at).toLocaleDateString() : 'N/A'}
        `;
        document.getElementById('restore_admin_notes').value = 'Account restored by admin.';
        
        openModal('restoreUserModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

// ==================== APPEAL REVIEW FUNCTIONS ====================
window.reviewAppeal = async function(userId, decision) {
    console.log('Reviewing appeal:', userId, decision);
    
    try {
        const response = await fetch(`ajax/get_user.php?id=${userId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load appeal');
        
        const user = result.user;
        const appeal = result.pendingAppeal || {
            appeal_message: user.appeal_message || 'No appeal message found',
            appeal_submitted_at: user.appeal_submitted_at
        };
        
        document.getElementById('appeal_user_id').value = userId;
        document.getElementById('appeal_decision').value = decision;
        
        document.getElementById('appealDetails').innerHTML = `
            <div style="background: #0f172a; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                    <div>
                        <h4 style="margin: 0; color: #fff;">
                            <i class="fas fa-user"></i> ${escapeHTML(user.full_name || 'User')}
                        </h4>
                        <p style="margin: 5px 0 0 0; color: #94a3b8;">${escapeHTML(user.email)}</p>
                    </div>
                    <span class="status-badge status-${user.account_status || 'suspended'}">
                        ${getStatusLabel(user.account_status)}
                    </span>
                </div>
                
                <div style="background: #1e293b; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                    <strong style="color: #ef4444;"><i class="fas fa-ban"></i> SUSPENSION REASON:</strong>
                    <p style="margin: 10px 0 0 0; padding: 10px; background: #0f172a; border-radius: 4px;">
                        ${escapeHTML(user.suspension_reason || 'No reason provided')}
                    </p>
                </div>
                
                <div style="background: #1e293b; padding: 15px; border-radius: 6px;">
                    <strong style="color: #9d50ff;"><i class="fas fa-comment"></i> USER APPEAL MESSAGE:</strong>
                    <p style="margin: 10px 0 0 0; padding: 15px; background: #0f172a; border-radius: 4px; border-left: 4px solid #9d50ff;">
                        "${escapeHTML(appeal.appeal_message || 'No appeal message found')}"
                    </p>
                    <small style="display: block; margin-top: 8px; color: #64748b;">
                        Submitted: ${appeal.appeal_submitted_at ? new Date(appeal.appeal_submitted_at).toLocaleString() : 'N/A'}
                    </small>
                </div>
            </div>
        `;
        
        const responseField = document.getElementById('admin_response_public');
        const previewContent = document.getElementById('responsePreviewContent');
        const decisionInfo = document.getElementById('appealDecisionInfo');
        const submitBtn = document.getElementById('appealSubmitBtn');
        const modalTitle = document.getElementById('appealModalTitle');
        
        if (decision === 'approve') {
            modalTitle.textContent = 'Approve Appeal - Restore Account';
            responseField.value = 'Dear user,\n\nYour appeal has been reviewed and approved. We have restored your account access. Please ensure you review our terms of service to avoid future issues.\n\nThank you for your understanding.';
            decisionInfo.className = 'alert alert-success';
            decisionInfo.innerHTML = '<i class="fas fa-check-circle"></i> <strong>Approving this appeal will:</strong> Restore the user account to active status.';
            submitBtn.className = 'btn btn-success';
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Approve & Restore Account';
            previewContent.innerHTML = `
                <strong style="color: #10b981; display: block; margin-bottom: 10px;">✅ APPEAL APPROVED</strong>
                ${escapeHTML(responseField.value).replace(/\n/g, '<br>')}
            `;
        } else {
            modalTitle.textContent = 'Reject Appeal - Lock Account';
            responseField.value = 'Dear user,\n\nWe have carefully reviewed your appeal. After thorough consideration, we regret to inform you that your appeal has been rejected and your account will remain permanently locked.\n\nThis decision is final.\n\nThank you for your understanding.';
            decisionInfo.className = 'alert alert-error';
            decisionInfo.innerHTML = '<i class="fas fa-times-circle"></i> <strong>Rejecting this appeal will:</strong> Permanently lock the user account. <span style="color: #ef4444; font-weight: bold;">This action cannot be undone.</span>';
            submitBtn.className = 'btn btn-danger';
            submitBtn.innerHTML = '<i class="fas fa-ban"></i> Reject & Lock Permanently';
            previewContent.innerHTML = `
                <strong style="color: #ef4444; display: block; margin-bottom: 10px;">❌ APPEAL REJECTED - ACCOUNT LOCKED</strong>
                ${escapeHTML(responseField.value).replace(/\n/g, '<br>')}
            `;
        }
        
        document.getElementById('appeal_admin_notes_private').value = '';
        
        openModal('appealReviewModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

// ==================== SUSPENSION HISTORY ====================
window.viewSuspensionHistory = async function(userId) {
    console.log('Viewing history:', userId);
    
    try {
        const response = await fetch(`ajax/get_user.php?id=${userId}`, {
            credentials: 'include'
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const result = await response.json();
        
        if (!result.success) throw new Error(result.error || 'Failed to load history');
        
        const user = result.user;
        const history = result.suspensionHistory || [];
        
        let historyHtml = `
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0; color: #fff;">${escapeHTML(user.full_name || 'User')}</h4>
                <p style="margin: 0 0 15px 0; color: #94a3b8;">${escapeHTML(user.email)}</p>
                <span class="status-badge status-${user.account_status || 'active'}" style="margin-right: 10px;">
                    Current: ${getStatusLabel(user.account_status)}
                </span>
                <span class="status-badge" style="background: #64748b; color: white;">
                    Total Actions: ${history.length}
                </span>
            </div>
        `;
        
        if (history.length > 0) {
            historyHtml += `
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #0f172a;">
                                <th style="padding: 12px; text-align: left; color: #94a3b8;">Date</th>
                                <th style="padding: 12px; text-align: left; color: #94a3b8;">Action</th>
                                <th style="padding: 12px; text-align: left; color: #94a3b8;">Admin</th>
                                <th style="padding: 12px; text-align: left; color: #94a3b8;">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            history.forEach(entry => {
                const actionClass = entry.action === 'suspended' ? 'warning' : 
                                   entry.action === 'restored' ? 'success' : 'danger';
                const actionLabel = entry.action ? entry.action.toUpperCase() : 'UNKNOWN';
                
                historyHtml += `
                    <tr>
                        <td style="padding: 12px; border-bottom: 1px solid #334155; color: #e2e8f0;">
                            ${entry.performed_at ? new Date(entry.performed_at).toLocaleString() : 'N/A'}
                        </td>
                        <td style="padding: 12px; border-bottom: 1px solid #334155;">
                            <span class="status-badge status-${actionClass}">
                                ${actionLabel}
                            </span>
                            ${entry.previous_status && entry.new_status ? 
                                `<br><small style="color: #94a3b8;">${entry.previous_status} → ${entry.new_status}</small>` : ''}
                        </td>
                        <td style="padding: 12px; border-bottom: 1px solid #334155; color: #e2e8f0;">
                            ${escapeHTML(entry.admin_name || 'Admin')}
                            ${entry.admin_email ? `<br><small style="color: #94a3b8;">${escapeHTML(entry.admin_email)}</small>` : ''}
                        </td>
                        <td style="padding: 12px; border-bottom: 1px solid #334155; color: #e2e8f0;">
                            ${escapeHTML(entry.reason_public || entry.reason || 'No reason provided')}
                        </td>
                    </tr>
                `;
            });
            
            historyHtml += '</tbody></table></div>';
        } else {
            historyHtml += `
                <div class="empty-state">
                    <i class="fas fa-history" style="font-size: 48px; color: #64748b; margin-bottom: 20px;"></i>
                    <h5 style="margin: 0 0 10px 0; color: #e2e8f0;">No Suspension History</h5>
                    <p style="margin: 0; color: #94a3b8;">This user has never been suspended.</p>
                </div>
            `;
        }
        
        document.getElementById('historyContent').innerHTML = historyHtml;
        openModal('historyModal');
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    }
};

// ==================== FILTER FUNCTIONS ====================
window.filterKycDocuments = function() {
    const filter = document.getElementById('documentFilter')?.value || 'all';
    const rows = document.querySelectorAll('#kycTable tbody tr');
    
    rows.forEach(row => {
        if (filter === 'all' || row.getAttribute('data-doc-type') === filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
};

window.searchKycUsers = function() {
    const search = document.getElementById('userSearch')?.value.toLowerCase() || '';
    const rows = document.querySelectorAll('#kycTable tbody tr');
    
    rows.forEach(row => {
        const email = row.getAttribute('data-email') || '';
        const name = row.getAttribute('data-name') || '';
        
        if (email.includes(search) || name.includes(search)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
};

// ==================== HELPER FUNCTIONS ====================
window.escapeHTML = function(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

window.getStatusLabel = function(status) {
    const labels = {
        'active': 'Active',
        'suspended': 'Suspended',
        'under_review': 'Under Review',
        'locked': 'Locked'
    };
    return labels[status] || (status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown');
};

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Admin dashboard loaded');
    
    // Verify all functions are attached to window
    console.log('openModal defined:', typeof window.openModal === 'function');
    console.log('openUserModal defined:', typeof window.openUserModal === 'function');
    console.log('openDepositModal defined:', typeof window.openDepositModal === 'function');
    console.log('openWithdrawalModal defined:', typeof window.openWithdrawalModal === 'function');
    console.log('openLoanModal defined:', typeof window.openLoanModal === 'function');
    console.log('openKycModal defined:', typeof window.openKycModal === 'function');
    console.log('suspendUser defined:', typeof window.suspendUser === 'function');
    console.log('restoreUser defined:', typeof window.restoreUser === 'function');
    console.log('reviewAppeal defined:', typeof window.reviewAppeal === 'function');
    
    // Initialize balance table if on balances section
    if (window.location.search.includes('section=balances')) {
        // Hide checkboxes initially
        document.querySelectorAll('.row-select').forEach(cb => {
            cb.style.display = 'none';
        });
        document.getElementById('selectAll').style.display = 'none';
    }
    
    // Add custom reason input listener
    const customReasonField = document.getElementById('custom_suspension_reason');
    if (customReasonField) {
        customReasonField.addEventListener('input', function() {
            const select = document.getElementById('suspension_reason_select');
            const previewDiv = document.getElementById('userVisiblePreview');
            if (select && select.value === 'custom' && previewDiv) {
                previewDiv.innerHTML = `<strong style="color: #f59e0b;">Suspension Reason:</strong><br>${escapeHTML(this.value || 'Enter a reason...')}`;
            }
        });
    }
    
    // Add public response preview listener
    const responseField = document.getElementById('admin_response_public');
    if (responseField) {
        responseField.addEventListener('input', function() {
            const decision = document.getElementById('appeal_decision')?.value;
            const previewContent = document.getElementById('responsePreviewContent');
            if (previewContent) {
                const color = decision === 'approve' ? '#10b981' : '#ef4444';
                const title = decision === 'approve' ? '✅ APPEAL APPROVED' : '❌ APPEAL REJECTED';
                previewContent.innerHTML = `
                    <strong style="color: ${color}; display: block; margin-bottom: 10px;">${title}</strong>
                    ${escapeHTML(this.value).replace(/\n/g, '<br>')}
                `;
            }
        });
    }
    
    // Close modals on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active').forEach(modal => {
                modal.classList.remove('active');
            });
            document.body.style.overflow = 'auto';
        }
    });
    
    // Close modals when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
    });
});
</script>
    <script>
// Debug function to check if buttons are properly rendered
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded');
    
    // Check if modal functions are defined
    console.log('openUserModal defined:', typeof window.openUserModal === 'function');
    console.log('openDepositModal defined:', typeof window.openDepositModal === 'function');
    console.log('openWithdrawalModal defined:', typeof window.openWithdrawalModal === 'function');
    console.log('openLoanModal defined:', typeof window.openLoanModal === 'function');
    console.log('openKycModal defined:', typeof window.openKycModal === 'function');
    console.log('suspendUser defined:', typeof window.suspendUser === 'function');
    console.log('restoreUser defined:', typeof window.restoreUser === 'function');
    console.log('reviewAppeal defined:', typeof window.reviewAppeal === 'function');
    
    // Check if modal elements exist
    console.log('userModal exists:', !!document.getElementById('userModal'));
    console.log('depositModal exists:', !!document.getElementById('depositModal'));
    console.log('withdrawalModal exists:', !!document.getElementById('withdrawalModal'));
    console.log('loanModal exists:', !!document.getElementById('loanModal'));
    console.log('kycModal exists:', !!document.getElementById('kycModal'));
    console.log('suspendUserModal exists:', !!document.getElementById('suspendUserModal'));
    console.log('restoreUserModal exists:', !!document.getElementById('restoreUserModal'));
    console.log('appealReviewModal exists:', !!document.getElementById('appealReviewModal'));
    
    // Test a button click
    const firstUserButton = document.querySelector('button[onclick*="openUserModal"]');
    if (firstUserButton) {
        console.log('First user button found:', firstUserButton);
        console.log('Button onclick:', firstUserButton.getAttribute('onclick'));
    } else {
        console.log('No user buttons found');
    }
});

// Make sure functions are globally accessible
window.openModal = openModal;
window.closeModal = closeModal;
window.openUserModal = openUserModal;
window.openDepositModal = openDepositModal;
window.openWithdrawalModal = openWithdrawalModal;
window.openLoanModal = openLoanModal;
window.openKycModal = openKycModal;
window.suspendUser = suspendUser;
window.restoreUser = restoreUser;
window.reviewAppeal = reviewAppeal;
window.viewSuspensionHistory = viewSuspensionHistory;
</script>
</body>
</html>