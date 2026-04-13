<?php
session_start();
require_once 'includes/db.php'; // Assuming you have a PDO database connection in $pdo

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Collect, Sanitize, and Validate ALL Input
    $full_name = filter_var($_POST['full_name'], FILTER_SANITIZE_STRING);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $wallet_pin = $_POST['wallet_pin']; 
    $base_currency = $_POST['base_currency']; 

    // Server-side validation for the PIN length
    if (strlen($wallet_pin) !== 6 || !is_numeric($wallet_pin)) {
        die("Error: Invalid PIN format. Must be 6 digits.");
    }

    try {
        // 2. Check if Email Exists
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->rowCount() > 0) {
            header("Location: register.php?error=exists");
            exit();
        }

        // 3. Hash BOTH Password and PIN (using strong Bcrypt algorithm)
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        $hashed_pin = password_hash($wallet_pin, PASSWORD_BCRYPT); // Hash the 6-digit PIN

        // 4. Insert User into MySQL (Now including 'wallet_pin')
        $user_sql = "INSERT INTO users (full_name, email, password_hash, wallet_pin, kyc_status) 
                     VALUES (?, ?, ?, ?, 'unverified')";
        $stmt = $pdo->prepare($user_sql);
        
        // Execute with 4 parameters now
        $stmt->execute([$full_name, $email, $hashed_password, $hashed_pin]);

        // Get the ID of the new user to link the balance
        $user_id = $pdo->lastInsertId();

        // 5. Initialize the User's Wallet in the 'balances' table
        $balance_sql = "INSERT INTO balances (user_id, currency_code, available_balance, pending_balance) 
                        VALUES (?, ?, 0.00000000, 0.00000000)";
        $bal_stmt = $pdo->prepare($balance_sql);
        $bal_stmt->execute([$user_id, $base_currency]);

        // 6. Auto-Login & Redirect to Dashboard (or login page as per plan)
        $_SESSION['user_id'] = $user_id;
        $_SESSION['full_name'] = $full_name;
        
        // Redirect to the login page as we still need the two-step verification on the way in
        header("Location: dashboard/index.php?status=welcome");
        exit();

    } catch (Exception $e) {
        die("System Error: " . $e->getMessage());
    }
} else {
    // If someone tries to access this page directly, send them to the form
    header("Location: register.php");
    exit();
}
?>
