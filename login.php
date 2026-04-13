<?php
session_start();
require_once 'includes/db.php';

$error = "";
$login_success = false; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $pin = $_POST['wallet_pin'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if (empty($pin)) {
            $login_success = true; 
        } else {
            if (password_verify($pin, $user['wallet_pin'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                header("Location: dashboard/index.php?status=welcome");
                exit();
            } else {
                $error = "Secure PIN mismatch. Access Denied.";
                $login_success = true; 
            }
        }
    } else {
        $error = "Invalid credentials.";
    }
}

include 'includes/header.php'; 
?>

<style>
/* Elite Zeus Security Gateway Styles */
.auth-section {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: radial-gradient(circle at top right, rgba(157, 80, 255, 0.1), transparent),
                radial-gradient(circle at bottom left, rgba(110, 44, 242, 0.05), transparent);
    padding: 100px 20px;
}

.auth-container {
    width: 100%;
    max-width: 480px;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1px solid rgba(157, 80, 255, 0.3);
    border-radius: 35px;
    padding: 40px;
    box-shadow: 0 40px 100px rgba(0, 0, 0, 0.7);
    color: white;
}

.purple-text { color: #9d50ff; font-weight: 800; }

/* Neomorphic Inputs */
.input-group { margin-bottom: 25px; }
.input-group label {
    display: block;
    margin-bottom: 10px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.neo-input {
    width: 100% !important;
    background: #0a0a0c !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
    padding: 18px !important;
    border-radius: 16px !important;
    color: white !important;
    font-size: 1rem !important;
    box-shadow: inset 4px 4px 10px rgba(0, 0, 0, 0.5) !important;
    outline: none;
    transition: 0.3s;
}

/* Buttons */
.btn-primary {
    background: #9d50ff !important;
    border: none;
    padding: 18px;
    border-radius: 16px;
    color: white !important;
    font-weight: 800;
    width: 100%;
    cursor: pointer;
    box-shadow: 0 10px 20px rgba(157, 80, 255, 0.3);
}

/* PIN Interface */
.pin-display { margin-bottom: 25px; text-align: center; }
.pin-field {
    text-align: center !important;
    font-size: 2.2rem !important;
    letter-spacing: 12px;
    background: rgba(0, 0, 0, 0.4) !important;
}

.pin-pad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.pin-btn {
    height: 65px;
    background: #1e293b;
    border: none;
    border-radius: 18px;
    color: white;
    font-size: 1.4rem;
    font-weight: 700;
    box-shadow: 5px 5px 10px rgba(0,0,0,0.3);
    cursor: pointer;
}

.submit-pin { background: #9d50ff !important; }

.hidden { display: none; }
.fade-in { animation: fadeInUp 0.5s ease forwards; }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<section class="auth-section">
    <div class="auth-container">
        
        <!-- LOGIN STEP -->
        <div id="loginStep" class="<?php echo $login_success ? 'hidden' : 'fade-in'; ?>">
            <h2>Vault <span class="purple-text">Access</span></h2>
            <p>Enter credentials to unlock.</p>
            <?php if($error && !$login_success): ?>
                <div style="color:#ff5050; margin-bottom:15px;"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="input-group">
                    <label>Email</label>
                    <input type="email" name="email" class="neo-input" value="<?php echo $_POST['email'] ?? ''; ?>" required>
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="password" class="neo-input" required>
                </div>
                <button type="submit" class="btn-primary">Verify Credentials</button>
            </form>
        </div>

        <!-- PIN STEP -->
        <div id="pinStep" class="<?php echo $login_success ? 'fade-in' : 'hidden'; ?>">
            <h2>Wallet <span class="purple-text">PIN</span></h2>
            <p>Confirm identity for <strong><?php echo htmlspecialchars($email ?? ''); ?></strong></p>
            
            <?php if($error && $login_success): ?>
                <div style="color:#ff00ff; margin-bottom:15px;"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <input type="hidden" name="password" value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>">
                
                <div class="pin-display">
                    <input type="password" id="pinInput" name="wallet_pin" maxlength="6" class="neo-input pin-field" readonly>
                </div>
                <div class="pin-pad">
                    <?php for($i=1; $i<=9; $i++): ?>
                        <button type="button" class="pin-btn" onclick="pressPin('<?php echo $i; ?>')"><?php echo $i; ?></button>
                    <?php endfor; ?>
                    <button type="button" class="pin-btn" onclick="pressPin('clear')">C</button>
                    <button type="button" class="pin-btn" onclick="pressPin('0')">0</button>
                    <button type="submit" class="pin-btn submit-pin">✓</button>
                </div>
            </form>
        </div>

    </div>
</section>

<script>
const pinField = document.getElementById('pinInput');
function pressPin(value) {
    if (value === 'clear') pinField.value = '';
    else if (pinField.value.length < 6) pinField.value += value;
}
</script>
<script id="chatway" async="true" src="https://cdn.chatway.app/widget.js?id=NvLLTntumZ8H"></script>

<?php include 'includes/footer.php'; ?>
