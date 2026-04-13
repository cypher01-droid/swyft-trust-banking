<?php
// admin/ajax/get_user_balance.php
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die('<div class="alert alert-danger">Unauthorized access</div>');
}

$user_id = $_GET['id'] ?? 0;

// Validate user_id
if (!is_numeric($user_id) || $user_id <= 0) {
    die('<div class="alert alert-danger">Invalid user ID</div>');
}

// Get user info first
$stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    die('<div class="alert alert-danger">User not found</div>');
}

// Get user balances
$stmt = $pdo->prepare("SELECT currency_code, available_balance FROM balances WHERE user_id = ? ORDER BY currency_code");
$stmt->execute([$user_id]);
$balances = $stmt->fetchAll();

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get available currencies from your system
$currency_stmt = $pdo->query("SELECT DISTINCT currency_code FROM balances WHERE currency_code IS NOT NULL ORDER BY currency_code");
$available_currencies = $currency_stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($available_currencies)) {
    $available_currencies = ['USD', 'EUR', 'GBP', 'BTC', 'ETH'];
}
?>

<form id="balanceForm">
    <!-- CSRF Protection -->
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    
    <div class="alert alert-info" style="margin-bottom: 20px;">
        <i class="fas fa-info-circle"></i> 
        Adjust balances for <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
        <div style="font-size: 0.85rem; margin-top: 5px;">
            User ID: <?php echo (int)$user['id']; ?> | Email: <?php echo htmlspecialchars($user['email']); ?>
        </div>
    </div>
    
    <div id="balanceFields">
        <?php if (empty($balances)): ?>
        <div class="balance-section" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid var(--primary);">
            <h5 style="margin-top: 0; color: #333; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-plus-circle" style="color: var(--primary);"></i>
                New Currency
            </h5>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Currency *</label>
                    <select name="currency[0]" class="form-input" required>
                        <option value="">Select currency</option>
                        <?php foreach ($available_currencies as $currency): ?>
                            <option value="<?php echo htmlspecialchars($currency); ?>"><?php echo htmlspecialchars($currency); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Action *</label>
                    <select name="action[0]" class="form-input" required onchange="updateAmountPlaceholder(this, 0)">
                        <option value="add">Add Funds (+)</option>
                        <option value="subtract">Subtract Funds (-)</option>
                        <option value="set">Set Specific Amount (=)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount *</label>
                    <input type="number" name="amount[0]" class="form-input" 
                           step="0.00000001" min="0.00000001" required
                           placeholder="Enter amount" id="amount_0">
                    <small class="form-text text-muted">Positive numbers only</small>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description[0]" class="form-input" 
                           placeholder="Reason for adjustment (optional)">
                </div>
            </div>
            <input type="hidden" name="current_balance[0]" value="0">
        </div>
        <?php else: ?>
            <?php foreach ($balances as $index => $balance): 
                $current_balance = (float)$balance['available_balance'];
                $currency_code = htmlspecialchars($balance['currency_code']);
            ?>
            <div class="balance-section" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid var(--info);">
                <h5 style="margin-top: 0; color: #333; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-coins" style="color: var(--warning);"></i>
                    <?php echo $currency_code; ?> Balance
                    <span style="font-size: 0.85rem; color: #666; margin-left: auto;">
                        Current: <strong><?php echo number_format($current_balance, 8); ?></strong>
                    </span>
                </h5>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Action *</label>
                        <select name="action[<?php echo $index; ?>]" class="form-input" required 
                                onchange="updateAmountPlaceholder(this, <?php echo $index; ?>)">
                            <option value="add">Add Funds (+)</option>
                            <option value="subtract">Subtract Funds (-)</option>
                            <option value="set">Set Specific Amount (=)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount *</label>
                        <input type="number" name="amount[<?php echo $index; ?>]" class="form-input" 
                               step="0.00000001" min="0.00000001" required
                               placeholder="Enter amount" id="amount_<?php echo $index; ?>">
                        <small class="form-text text-muted">
                            <?php if ($balance['currency_code'] === 'USD' || $balance['currency_code'] === 'EUR' || $balance['currency_code'] === 'GBP'): ?>
                                Enter amount in <?php echo $currency_code; ?>
                            <?php else: ?>
                                Enter amount (8 decimal places)
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description[<?php echo $index; ?>]" class="form-input" 
                               placeholder="Reason for adjustment (optional)">
                    </div>
                    <div class="form-group">
                        <label>Preview</label>
                        <div class="preview-box" style="padding: 8px 12px; background: #e9ecef; border-radius: 4px; font-family: 'Courier New'; font-size: 0.9rem;">
                            <span id="preview_<?php echo $index; ?>">
                                <?php echo $currency_code; ?>: <?php echo number_format($current_balance, 8); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="currency[<?php echo $index; ?>]" value="<?php echo $currency_code; ?>">
                <input type="hidden" name="current_balance[<?php echo $index; ?>]" value="<?php echo $current_balance; ?>">
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div style="margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap;">
        <button type="button" class="btn btn-outline" onclick="addCurrencyField()">
            <i class="fas fa-plus"></i> Add Another Currency
        </button>
        <button type="button" class="btn btn-outline" onclick="validateForm()">
            <i class="fas fa-check-circle"></i> Validate
        </button>
        <button type="button" class="btn btn-outline" onclick="clearAllFields()">
            <i class="fas fa-broom"></i> Clear All
        </button>
    </div>
    
    <div class="form-group">
        <label>Admin Notes</label>
        <textarea name="admin_notes" class="form-input" rows="3" 
                  placeholder="Internal notes about this adjustment (visible only to admins)"></textarea>
    </div>
    
    <input type="hidden" name="user_id" value="<?php echo (int)$user_id; ?>">
    
    <div class="alert alert-warning" style="margin-top: 20px;">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Important:</strong> All balance adjustments are permanent and will be logged in the transaction history.
    </div>
</form>

<script>
let fieldCounter = <?php echo count($balances); ?>;

function addCurrencyField() {
    const html = `
        <div class="balance-section" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid var(--success);">
            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()" style="float: right;">
                <i class="fas fa-times"></i>
            </button>
            <h5 style="margin-top: 0; color: #333; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-plus-circle" style="color: var(--success);"></i>
                Additional Currency
            </h5>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Currency *</label>
                    <select name="currency[${fieldCounter}]" class="form-input" required>
                        <option value="">Select currency</option>
                        <?php foreach ($available_currencies as $currency): ?>
                            <option value="<?php echo htmlspecialchars($currency); ?>"><?php echo htmlspecialchars($currency); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Action *</label>
                    <select name="action[${fieldCounter}]" class="form-input" required onchange="updateAmountPlaceholder(this, ${fieldCounter})">
                        <option value="add">Add Funds (+)</option>
                        <option value="subtract">Subtract Funds (-)</option>
                        <option value="set">Set Specific Amount (=)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount *</label>
                    <input type="number" name="amount[${fieldCounter}]" class="form-input" 
                           step="0.00000001" min="0.00000001" required
                           placeholder="Enter amount" id="amount_${fieldCounter}">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description[${fieldCounter}]" class="form-input" 
                           placeholder="Reason for adjustment (optional)">
                </div>
            </div>
            <input type="hidden" name="current_balance[${fieldCounter}]" value="0">
        </div>
    `;
    
    document.getElementById('balanceFields').insertAdjacentHTML('beforeend', html);
    fieldCounter++;
}

function updateAmountPlaceholder(selectElement, index) {
    const amountInput = document.getElementById(`amount_${index}`);
    const action = selectElement.value;
    
    switch(action) {
        case 'add':
            amountInput.placeholder = 'Amount to add';
            break;
        case 'subtract':
            amountInput.placeholder = 'Amount to subtract';
            break;
        case 'set':
            amountInput.placeholder = 'New balance amount';
            break;
    }
    
    // Update preview if exists
    updatePreview(index);
}

function updatePreview(index) {
    const previewElement = document.getElementById(`preview_${index}`);
    if (!previewElement) return;
    
    const currency = document.querySelector(`input[name="currency[${index}]"]`)?.value || 
                    document.querySelector(`select[name="currency[${index}]"]`)?.value;
    const currentBalance = parseFloat(document.querySelector(`input[name="current_balance[${index}]"]`)?.value || 0);
    const action = document.querySelector(`select[name="action[${index}]"]`)?.value;
    const amount = parseFloat(document.querySelector(`input[name="amount[${index}]"]`)?.value || 0);
    
    if (!currency || !action || isNaN(amount) || amount <= 0) {
        previewElement.innerHTML = `${currency || 'Currency'}: ${currentBalance.toFixed(8)}`;
        return;
    }
    
    let newBalance;
    switch(action) {
        case 'add':
            newBalance = currentBalance + amount;
            previewElement.innerHTML = `${currency}: ${currentBalance.toFixed(8)} + ${amount.toFixed(8)} = <strong>${newBalance.toFixed(8)}</strong>`;
            break;
        case 'subtract':
            newBalance = currentBalance - amount;
            previewElement.innerHTML = `${currency}: ${currentBalance.toFixed(8)} - ${amount.toFixed(8)} = <strong>${newBalance.toFixed(8)}</strong>`;
            break;
        case 'set':
            newBalance = amount;
            previewElement.innerHTML = `${currency}: ${currentBalance.toFixed(8)} → <strong>${amount.toFixed(8)}</strong>`;
            break;
    }
    
    // Highlight if balance would go negative
    if (newBalance < 0) {
        previewElement.style.color = 'var(--danger)';
        previewElement.innerHTML += ' <i class="fas fa-exclamation-triangle" title="Negative balance!"></i>';
    }
}

function validateForm() {
    const form = document.getElementById('balanceForm');
    let isValid = true;
    let message = '';
    
    // Check each currency section
    const currencySections = form.querySelectorAll('.balance-section');
    
    currencySections.forEach((section, index) => {
        const currency = section.querySelector('select[name^="currency["], input[name^="currency["]')?.value;
        const action = section.querySelector('select[name^="action["]')?.value;
        const amount = parseFloat(section.querySelector('input[name^="amount["]')?.value || 0);
        const currentBalance = parseFloat(section.querySelector('input[name^="current_balance["]')?.value || 0);
        
        if (!currency || !action || isNaN(amount) || amount <= 0) {
            isValid = false;
            message += `• Section ${index + 1}: Incomplete or invalid data\n`;
        }
        
        // Check for negative balance after subtraction
        if (action === 'subtract' && amount > currentBalance) {
            isValid = false;
            message += `• ${currency}: Cannot subtract ${amount} (current: ${currentBalance})\n`;
        }
    });
    
    if (!isValid) {
        alert('Form validation failed:\n\n' + message);
        return false;
    }
    
    alert('✓ Form validation passed! Ready to submit.');
    return true;
}

function clearAllFields() {
    if (!confirm('Clear all form fields? This cannot be undone.')) {
        return;
    }
    
    const form = document.getElementById('balanceForm');
    form.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(input => {
        if (input.name !== 'csrf_token' && input.name !== 'user_id') {
            input.value = '';
        }
    });
    
    form.querySelectorAll('select').forEach(select => {
        select.selectedIndex = 0;
    });
    
    // Reset previews
    for (let i = 0; i < fieldCounter; i++) {
        updatePreview(i);
    }
}

// Initialize previews and event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Add input event listeners to all amount fields
    for (let i = 0; i < fieldCounter; i++) {
        const amountInput = document.getElementById(`amount_${i}`);
        const actionSelect = document.querySelector(`select[name="action[${i}]"]`);
        
        if (amountInput) {
            amountInput.addEventListener('input', () => updatePreview(i));
        }
        if (actionSelect) {
            actionSelect.addEventListener('change', () => updatePreview(i));
        }
        
        // Initial preview
        updatePreview(i);
    }
});
</script>