<?php
// admin_login.php
session_start();
require_once 'includes/db.php';

$error = '';
$success = '';

// Redirect if already logged in as admin
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header("Location: admin/index.php?status=welcome");
    exit();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    // Validation
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            // Check if user exists and is admin
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
            $stmt->execute([$email]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin && password_verify($password, $admin['password_hash'])) {
                // Check if admin is active (you might want to add an 'active' column)
                
                // Set session
                $_SESSION['user_id'] = $admin['id'];
                $_SESSION['full_name'] = $admin['full_name'];
                $_SESSION['email'] = $admin['email'];
                $_SESSION['role'] = $admin['role'];
                
                // Log login
                $ip = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO login_history (user_id, ip_address, user_agent, success) 
                    VALUES (?, ?, ?, 1)
                ");
                $stmt->execute([$admin['id'], $ip, $user_agent]);
                
                // Set remember me cookie if requested
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                    
                    // Store token in database
                    $stmt = $pdo->prepare("
                        INSERT INTO admin_sessions (admin_id, token, expires_at) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$admin['id'], $token, date('Y-m-d H:i:s', $expiry)]);
                    
                    // Set cookie
                    setcookie('admin_token', $token, $expiry, '/', '', true, true);
                }
                
                // Redirect to admin dashboard
                header("Location: admin/index.php?status=welcome");
                exit();
                
            } else {
                // Log failed attempt
                $ip = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO login_history (ip_address, user_agent, success) 
                    VALUES (?, ?, 0)
                ");
                $stmt->execute([$ip, $user_agent]);
                
                $error = "Invalid email or password.";
            }
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    }
}

// Handle cookie-based login
if (isset($_COOKIE['admin_token']) && !isset($_SESSION['user_id'])) {
    try {
        $token = $_COOKIE['admin_token'];
        
        $stmt = $pdo->prepare("
            SELECT u.* FROM admin_sessions s
            JOIN users u ON s.admin_id = u.id
            WHERE s.token = ? AND s.expires_at > NOW() AND u.role = 'admin'
        ");
        $stmt->execute([$token]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin) {
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['full_name'] = $admin['full_name'];
            $_SESSION['email'] = $admin['email'];
            $_SESSION['role'] = $admin['role'];
            
            header("Location: admin.php");
            exit();
        }
    } catch (Exception $e) {
        // Invalid token, clear cookie
        setcookie('admin_token', '', time() - 3600, '/');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Login - Zeus Bank</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }
        
        :root {
            --primary: #9d50ff;
            --primary-dark: #6a11cb;
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --dark-bg: #0a0a0c;
            --card-bg: #111113;
            --text: #ffffff;
            --text-secondary: #94a3b8;
            --border: rgba(157, 80, 255, 0.1);
        }
        
        body {
            background: var(--dark-bg);
            font-family: 'Inter', -apple-system, sans-serif;
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(157, 80, 255, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(106, 17, 203, 0.1) 0%, transparent 50%);
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            animation: fadeIn 0.6s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .brand-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 2rem;
            font-weight: 900;
            color: var(--text);
            text-decoration: none;
            margin-bottom: 15px;
        }
        
        .brand-icon {
            color: var(--primary);
            font-size: 2.5rem;
        }
        
        .brand-text {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .login-subtitle {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        
        .login-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 40px;
            border: 1px solid var(--border);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .login-title {
            font-size: 1.5rem;
            font-weight: 900;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--danger);
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--success);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text);
        }
        
        .form-input {
            width: 100%;
            padding: 15px;
            background: var(--dark-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-size: 16px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(157, 80, 255, 0.1);
        }
        
        .form-input::placeholder {
            color: var(--text-secondary);
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
        }
        
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            font-size: 0.9rem;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        
        .checkbox-label {
            color: var(--text-secondary);
            cursor: pointer;
        }
        
        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .forgot-link:hover {
            text-decoration: underline;
        }
        
        .login-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(157, 80, 255, 0.3);
        }
        
        .login-btn:active {
            transform: translateY(0);
        }
        
        .login-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        .back-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .security-notice {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: var(--warning);
            padding: 12px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 0.8rem;
        }
        
        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                max-width: 100%;
            }
            
            .login-card {
                padding: 30px 20px;
            }
            
            .form-options {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }
        
        /* Captcha or 2FA area */
        .extra-security {
            margin-top: 20px;
            padding: 20px;
            background: rgba(157, 80, 255, 0.05);
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        
        .extra-title {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text);
        }
        
        .totp-input {
            width: 100%;
            padding: 15px;
            background: var(--dark-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-size: 24px;
            font-family: 'Courier New', monospace;
            text-align: center;
            letter-spacing: 8px;
        }
        
        .help-text {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="brand-logo">
                <i class="fas fa-crown brand-icon"></i>
                <span class="brand-text">SWYFT TRUST BANK ADMIN</span>
            </div>
            <p class="login-subtitle">Administrator Access Portal</p>
        </div>
        
        <div class="login-card">
            <h1 class="login-title">Admin Login</h1>
            
            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label class="form-label">Admin Email</label>
                    <input type="email" 
                           name="email" 
                           class="form-input" 
                           placeholder="admin@zeusbank.com"
                           required
                           autocomplete="email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="password-wrapper">
                        <input type="password" 
                               name="password" 
                               id="password"
                               class="form-input" 
                               placeholder="Enter your password"
                               required
                               autocomplete="current-password">
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <!-- 2FA Section (if enabled for admin) -->
                <?php 
                // Check if admin has 2FA enabled (you'd query this from DB)
                // $show_2fa = false; // Set based on admin preferences
                ?>
                
                <?php if (isset($show_2fa) && $show_2fa): ?>
                <div class="extra-security">
                    <div class="extra-title">Two-Factor Authentication</div>
                    <input type="text" 
                           name="totp_code"
                           class="totp-input"
                           placeholder="000000"
                           maxlength="6"
                           pattern="\d{6}"
                           inputmode="numeric">
                    <p class="help-text">Enter the 6-digit code from your authenticator app</p>
                </div>
                <?php endif; ?>
                
                <div class="form-options">
                    <div class="checkbox-group">
                        <input type="checkbox" 
                               name="remember" 
                               id="remember"
                               class="checkbox">
                        <label for="remember" class="checkbox-label">Remember me</label>
                    </div>
                    <a href="admin_forgot.php" class="forgot-link">Forgot Password?</a>
                </div>
                
                <button type="submit" class="login-btn" id="loginBtn">
                    <span id="btnText">Sign In</span>
                    <span id="btnSpinner" class="spinner" style="display: none;"></span>
                </button>
            </form>
            
            <div class="security-notice">
                <i class="fas fa-shield-alt"></i>
                <span>This area is restricted to authorized personnel only. All activities are monitored and logged.</span>
            </div>
            
            <div class="login-footer">
                <p>© <?php echo date('Y'); ?> Zeus Bank. All rights reserved.</p>
                <a href="../" class="back-link">← Back to Main Site</a>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.querySelector('.toggle-password i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleBtn.classList.remove('fa-eye');
                toggleBtn.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleBtn.classList.remove('fa-eye-slash');
                toggleBtn.classList.add('fa-eye');
            }
        }
        
        // Form submission handler
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('btnSpinner');
            
            // Show loading state
            btn.disabled = true;
            btnText.style.display = 'none';
            spinner.style.display = 'inline-block';
            
            // Optional: Validate 2FA code if present
            const totpInput = document.querySelector('[name="totp_code"]');
            if (totpInput && totpInput.value) {
                if (!/^\d{6}$/.test(totpInput.value.trim())) {
                    e.preventDefault();
                    alert('Please enter a valid 6-digit 2FA code');
                    resetButton();
                    return;
                }
            }
            
            // Form will submit normally
        });
        
        function resetButton() {
            const btn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('btnSpinner');
            
            btn.disabled = false;
            btnText.style.display = 'inline';
            spinner.style.display = 'none';
        }
        
        // Auto-focus on email field
        document.addEventListener('DOMContentLoaded', function() {
            const emailInput = document.querySelector('input[name="email"]');
            if (emailInput) {
                emailInput.focus();
            }
            
            // Check for browser autofill
            setTimeout(() => {
                if (emailInput.value) {
                    emailInput.dispatchEvent(new Event('input'));
                }
            }, 100);
            
            // Prevent form resubmission on refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+Enter to submit form
                if (e.ctrlKey && e.key === 'Enter') {
                    document.getElementById('loginForm').submit();
                }
                
                // Escape to clear form
                if (e.key === 'Escape') {
                    document.getElementById('loginForm').reset();
                }
            });
            
            // Show password on Alt+P
            let altPressed = false;
            document.addEventListener('keydown', function(e) {
                if (e.altKey) altPressed = true;
                if (altPressed && e.key === 'p') {
                    e.preventDefault();
                    togglePassword();
                }
            });
            document.addEventListener('keyup', function(e) {
                if (e.key === 'Alt') altPressed = false;
            });
        });
        
        // Security: Clear form if page is loaded from cache
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                document.getElementById('loginForm').reset();
            }
        });
    </script>
</body>
</html>