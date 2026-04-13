<?php
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die('Unauthorized');
}

$loan_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT l.*, u.full_name, u.email, u.phone, u.kyc_status
    FROM loans l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.id = ?
");
$stmt->execute([$loan_id]);
$loan = $stmt->fetch();

if (!$loan) {
    echo '<div class="alert alert-danger">Loan not found</div>';
    exit;
}
?>

<div class="loan-review-details">
    <div class="row" style="display: flex; gap: 20px; margin-bottom: 15px;">
        <div style="flex: 1;">
            <h4 style="margin-top: 0; color: #333;">Loan Details</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; color: #666;">Loan ID:</td>
                    <td style="padding: 5px 0; font-weight: 600;">#<?php echo $loan['id']; ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; color: #666;">Type:</td>
                    <td style="padding: 5px 0;">
                        <?php echo ucfirst($loan['loan_type']); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; color: #666;">Amount:</td>
                    <td style="padding: 5px 0; font-weight: 700;">
                        $<?php echo number_format($loan['requested_amount'], 2); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; color: #666;">Status:</td>
                    <td style="padding: 5px 0;">
                        <span style="padding: 3px 10px; border-radius: 15px; background: #f59e0b20; color: #f59e0b; font-size: 0.9rem;">
                            <?php echo ucfirst($loan['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php if (!empty($loan['purpose'])): ?>
                <tr>
                    <td style="padding: 5px 0; color: #666; vertical-align: top;">Purpose:</td>
                    <td style="padding: 5px 0;"><?php echo nl2br(htmlspecialchars($loan['purpose'])); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <div style="flex: 1;">
            <h4 style="margin-top: 0; color: #333;">User Details</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; color: #666;">Name:</td>
                    <td style="padding: 5px 0;"><?php echo htmlspecialchars($loan['full_name']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; color: #666;">Email:</td>
                    <td style="padding: 5px 0;"><?php echo htmlspecialchars($loan['email']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; color: #666;">Phone:</td>
                    <td style="padding: 5px 0;"><?php echo htmlspecialchars($loan['phone']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; color: #666;">KYC Status:</td>
                    <td style="padding: 5px 0;">
                        <span style="padding: 3px 10px; border-radius: 15px; background: <?php echo $loan['kyc_status'] == 'verified' ? '#10b98120' : '#f59e0b20'; ?>; color: <?php echo $loan['kyc_status'] == 'verified' ? '#10b981' : '#f59e0b'; ?>; font-size: 0.9rem;">
                            <?php echo ucfirst($loan['kyc_status']); ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
    <?php if (!empty($loan['tracking_code'])): ?>
    <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px;">
        <strong>Tracking Code:</strong> <?php echo $loan['tracking_code']; ?>
    </div>
    <?php endif; ?>
</div>