<?php
// cards.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];

// Get user's current balance for virtual cards
try {
    $stmt = $pdo->prepare("
        SELECT SUM(available_balance) as total_balance 
        FROM balances 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $balance = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_balance = $balance['total_balance'] ?? 0;
    
} catch (Exception $e) {
    error_log("Balance fetch error: " . $e->getMessage());
    $total_balance = 0;
}

include './dashboard_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Virtual Cards - Swyft Trust Bank</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            background: #0a0a0c;
            font-family: 'Inter', -apple-system, sans-serif;
            color: #fff;
            padding: 20px;
            min-height: 100vh;
        }
        
        .cards-container {
            max-width: 480px;
            margin: 0 auto;
            padding: 80px 0 100px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #9d50ff;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 25px;
            padding: 10px 0;
        }
        
        .page-title {
            font-size: 1.8rem;
            font-weight: 900;
            margin-bottom: 30px;
            color: #fff;
        }
        
        /* Coming Soon Banner */
        .coming-soon-banner {
            background: linear-gradient(135deg, rgba(157, 80, 255, 0.1), rgba(106, 17, 203, 0.1));
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(157, 80, 255, 0.2);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .banner-icon {
            font-size: 3rem;
            color: #9d50ff;
            margin-bottom: 15px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .banner-title {
            font-size: 1.5rem;
            font-weight: 900;
            color: #fff;
            margin-bottom: 10px;
        }
        
        .banner-subtitle {
            font-size: 0.9rem;
            color: #94a3b8;
            line-height: 1.5;
        }
        
        .banner-badge {
            display: inline-block;
            padding: 6px 16px;
            background: #9d50ff;
            color: white;
            font-size: 0.8rem;
            font-weight: 800;
            border-radius: 20px;
            margin-top: 15px;
            animation: glow 2s infinite;
        }
        
        @keyframes glow {
            0% { box-shadow: 0 0 10px rgba(157, 80, 255, 0.5); }
            50% { box-shadow: 0 0 20px rgba(157, 80, 255, 0.8); }
            100% { box-shadow: 0 0 10px rgba(157, 80, 255, 0.5); }
        }
        
        /* Feature Cards */
        .features-section {
            margin-bottom: 40px;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .feature-card {
            background: #111113;
            border-radius: 18px;
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(157, 80, 255, 0.1);
            transition: all 0.3s;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
            border-color: #9d50ff;
            background: rgba(157, 80, 255, 0.05);
        }
        
        .feature-icon {
            font-size: 2rem;
            color: #9d50ff;
            margin-bottom: 12px;
        }
        
        .feature-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .feature-desc {
            font-size: 0.75rem;
            color: #94a3b8;
        }
        
        /* Virtual Card Preview */
        .card-preview-section {
            margin-bottom: 40px;
        }
        
        .virtual-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 25px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            margin-bottom: 20px;
        }
        
        .card-chip {
            width: 50px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            margin-bottom: 25px;
            position: relative;
        }
        
        .card-chip::before {
            content: '';
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            height: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }
        
        .card-number {
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 2px;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 20px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .card-details {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        
        .card-holder {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .card-expiry {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .card-logo {
            position: absolute;
            top: 25px;
            right: 25px;
            font-size: 1.5rem;
            font-weight: 900;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .card-back {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border-radius: 20px;
            padding: 25px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            margin-top: 20px;
        }
        
        .card-stripe {
            height: 40px;
            background: rgba(0, 0, 0, 0.3);
            margin-bottom: 20px;
        }
        
        .card-cvv {
            background: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: #333;
            width: 60px;
            text-align: center;
        }
        
        /* Timeline */
        .timeline-section {
            margin-bottom: 40px;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: rgba(157, 80, 255, 0.3);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 25px;
        }
        
        .timeline-dot {
            position: absolute;
            left: -30px;
            top: 5px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #9d50ff;
            border: 3px solid #0a0a0c;
        }
        
        .timeline-dot.completed {
            background: #10b981;
        }
        
        .timeline-dot.in-progress {
            background: #f59e0b;
            animation: pulse 2s infinite;
        }
        
        .timeline-dot.pending {
            background: #64748b;
        }
        
        .timeline-content {
            background: #111113;
            border-radius: 15px;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .timeline-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .timeline-desc {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        
        /* Notify Me Form */
        .notify-section {
            background: #111113;
            border-radius: 20px;
            padding: 25px;
            border: 1px solid rgba(157, 80, 255, 0.1);
            text-align: center;
        }
        
        .notify-icon {
            font-size: 2.5rem;
            color: #9d50ff;
            margin-bottom: 15px;
        }
        
        .notify-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 10px;
        }
        
        .notify-desc {
            font-size: 0.9rem;
            color: #94a3b8;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .notify-form {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .notify-input {
            flex: 1;
            padding: 14px;
            background: #0a0a0c;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #fff;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        
        .notify-input::placeholder {
            color: #64748b;
        }
        
        .notify-btn {
            padding: 14px 24px;
            background: linear-gradient(135deg, #9d50ff, #6a11cb);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .notify-btn:hover {
            transform: translateY(-2px);
            opacity: 0.95;
        }
        
        .notify-success {
            padding: 15px;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
            border-radius: 12px;
            font-weight: 600;
            display: none;
        }
        
        .notify-note {
            font-size: 0.75rem;
            color: #64748b;
            line-height: 1.5;
        }
        
        /* FAQ Section */
        .faq-section {
            margin-top: 40px;
        }
        
        .faq-item {
            background: #111113;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .faq-question {
            font-size: 0.95rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .faq-answer {
            font-size: 0.85rem;
            color: #94a3b8;
            line-height: 1.5;
            display: none;
        }
        
        .faq-icon {
            color: #9d50ff;
            transition: transform 0.3s;
        }
        
        .faq-item.active .faq-icon {
            transform: rotate(45deg);
        }
        
        .faq-item.active .faq-answer {
            display: block;
        }
        
        @media (max-width: 480px) {
            .cards-container {
                padding: 60px 0 80px;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
            }
            
            .notify-form {
                flex-direction: column;
            }
            
            .card-number {
                font-size: 1rem;
                letter-spacing: 1px;
            }
        }
    </style>
</head>
<body>
    <div class="cards-container">
        <a href="./" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1 class="page-title">Virtual Cards</h1>
        
        <!-- Coming Soon Banner -->
        <div class="coming-soon-banner">
            <div class="banner-icon">
                <i class="fas fa-credit-card"></i>
            </div>
            <h2 class="banner-title">Coming Soon</h2>
            <p class="banner-subtitle">
                We're launching virtual cards soon! Get ready to experience seamless online payments, 
                enhanced security, and complete control over your spending.
            </p>
            <div class="banner-badge">LAUNCHING Q3 2026</div>
        </div>
        
        <!-- Features Grid -->
        <div class="features-section">
            <h2 class="section-title">
                <i class="fas fa-star"></i> Features You'll Love
            </h2>
            
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="feature-title">Enhanced Security</div>
                    <div class="feature-desc">
                        Generate virtual cards for one-time use or specific merchants
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <div class="feature-title">Instant Issuance</div>
                    <div class="feature-desc">
                        Get virtual cards instantly, ready to use within seconds
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <div class="feature-title">Spending Controls</div>
                    <div class="feature-desc">
                        Set spending limits, merchant locks, and expiration dates
                    </div>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div class="feature-title">Global Acceptance</div>
                    <div class="feature-desc">
                        Works anywhere Visa/Mastercard is accepted worldwide
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Virtual Card Preview -->
        <div class="card-preview-section">
            <h2 class="section-title">
                <i class="fas fa-eye"></i> Preview Your Future Card
            </h2>
            
            <div class="virtual-card">
                <div class="card-logo">SWYT</div>
                <div class="card-chip"></div>
                <div class="card-number">**** **** **** 1234</div>
                <div class="card-details">
                    <div class="card-holder">
                        <div style="font-size: 0.7rem; color: rgba(255, 255, 255, 0.6);">CARD HOLDER</div>
                        <?php echo strtoupper(substr($fullName, 0, 20)); ?>
                    </div>
                    <div class="card-expiry">
                        <div style="font-size: 0.7rem; color: rgba(255, 255, 255, 0.6);">VALID THRU</div>
                        12/26
                    </div>
                </div>
            </div>
            
            <div class="card-back">
                <div class="card-stripe"></div>
                <div style="text-align: right;">
                    <div style="font-size: 0.7rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 5px;">CVV</div>
                    <div class="card-cvv">***</div>
                </div>
            </div>
        </div>
        
        <!-- Development Timeline -->
        <div class="timeline-section">
            <h2 class="section-title">
                <i class="fas fa-road"></i> Development Timeline
            </h2>
            
            <div class="timeline">
                <div class="timeline-item">
                    <div class="timeline-dot completed"></div>
                    <div class="timeline-content">
                        <div class="timeline-title">Research & Planning</div>
                        <div class="timeline-desc">Completed market research and feature planning</div>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-dot in-progress"></div>
                    <div class="timeline-content">
                        <div class="timeline-title">Development Phase</div>
                        <div class="timeline-desc">Currently building the virtual cards infrastructure</div>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-dot pending"></div>
                    <div class="timeline-content">
                        <div class="timeline-title">Security Testing</div>
                        <div class="timeline-desc">Rigorous security audits and penetration testing</div>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-dot pending"></div>
                    <div class="timeline-content">
                        <div class="timeline-title">Beta Launch</div>
                        <div class="timeline-desc">Limited beta release for selected users</div>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-dot pending"></div>
                    <div class="timeline-content">
                        <div class="timeline-title">Full Launch</div>
                        <div class="timeline-desc">Available to all Zeus Bank customers</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Notify Me Form -->
        <div class="notify-section">
            <div class="notify-icon">
                <i class="fas fa-bell"></i>
            </div>
            <h3 class="notify-title">Get Notified at Launch</h3>
            <p class="notify-desc">
                Be the first to know when virtual cards are available. 
                Enter your email below and we'll notify you immediately.
            </p>
            
            <form class="notify-form" id="notifyForm">
                <input type="email" 
                       class="notify-input" 
                       placeholder="Enter your email"
                       required>
                <button type="submit" class="notify-btn">
                    Notify Me
                </button>
            </form>
            
            <div class="notify-success" id="successMessage">
                <i class="fas fa-check-circle"></i> Success! We'll notify you when virtual cards launch.
            </div>
            
            <p class="notify-note">
                Your email will only be used to notify you about virtual cards launch. 
                No spam, ever.
            </p>
        </div>
        
        <!-- FAQ Section -->
        <div class="faq-section">
            <h2 class="section-title">
                <i class="fas fa-question-circle"></i> Frequently Asked Questions
            </h2>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    What are virtual cards?
                    <span class="faq-icon">+</span>
                </div>
                <div class="faq-answer">
                    Virtual cards are digital versions of debit/credit cards that exist only in digital form. 
                    They have unique card numbers for secure online payments without exposing your main card details.
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    How much will virtual cards cost?
                    <span class="faq-icon">+</span>
                </div>
                <div class="faq-answer">
                    Virtual cards will be free for all Zeus Bank customers. There are no monthly fees, 
                    issuance fees, or maintenance fees. You only pay for your actual transactions.
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    Can I use virtual cards for physical purchases?
                    <span class="faq-icon">+</span>
                </div>
                <div class="faq-answer">
                    Virtual cards are primarily designed for online payments. However, you can add them to 
                    digital wallets like Apple Pay or Google Pay to use them for in-store contactless payments.
                </div>
            </div>
            
            <div class="faq-item">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    How do I fund my virtual cards?
                    <span class="faq-icon">+</span>
                </div>
                <div class="faq-answer">
                    Virtual cards will be linked to your Zeus Bank account balance. You can set spending limits 
                    for each card, and transactions will be deducted from your available balance.
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Notify Form Submission
        document.getElementById('notifyForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const email = this.querySelector('input').value;
            const successMessage = document.getElementById('successMessage');
            
            // Show loading state
            const submitBtn = this.querySelector('button');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            submitBtn.disabled = true;
            
            // Simulate API call
            setTimeout(() => {
                // Show success message
                successMessage.style.display = 'block';
                this.reset();
                
                // Restore button
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                
                // Hide success message after 5 seconds
                setTimeout(() => {
                    successMessage.style.display = 'none';
                }, 5000);
                
                // Log to console (in production, this would be an API call)
                console.log('Notification request saved for email:', email);
                
                // Show toast notification
                showNotification('We\'ll notify you at launch!', 'success');
                
            }, 1500);
        });
        
        // FAQ Toggle
        function toggleFAQ(element) {
            const faqItem = element.parentElement;
            faqItem.classList.toggle('active');
        }
        
        // Show notification
        function showNotification(message, type = 'info') {
            // Remove existing notification
            const existing = document.querySelector('.notification');
            if (existing) existing.remove();
            
            // Create notification
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                background: ${type === 'success' ? '#10b981' : '#9d50ff'};
                color: white;
                border-radius: 8px;
                font-weight: 600;
                font-size: 0.9rem;
                z-index: 1000;
                animation: slideIn 0.3s ease;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                max-width: 300px;
            `;
            
            // Add animation style
            if (!document.querySelector('#notificationStyle')) {
                const style = document.createElement('style');
                style.id = 'notificationStyle';
                style.textContent = `
                    @keyframes slideIn {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                `;
                document.head.appendChild(style);
            }
            
            notification.textContent = message;
            document.body.appendChild(notification);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                notification.style.transform = 'translateX(100%)';
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        // Card number animation
        document.addEventListener('DOMContentLoaded', function() {
            const cardNumber = document.querySelector('.card-number');
            let isOriginal = true;
            
            // Change card number on click
            cardNumber.addEventListener('click', function() {
                if (isOriginal) {
                    this.textContent = '**** **** **** 5678';
                    this.style.color = '#ff6b6b';
                } else {
                    this.textContent = '**** **** **** 1234';
                    this.style.color = 'rgba(255, 255, 255, 0.9)';
                }
                isOriginal = !isOriginal;
            });
            
            // Auto-rotate card preview every 5 seconds
            let cardRotation = 0;
            setInterval(() => {
                const cards = document.querySelectorAll('.virtual-card, .card-back');
                cardRotation = cardRotation === 0 ? 180 : 0;
                
                cards.forEach(card => {
                    card.style.transform = `rotateY(${cardRotation}deg)`;
                    card.style.transition = 'transform 0.6s ease';
                });
            }, 5000);
            
            // Add scroll animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            // Observe all feature cards
            document.querySelectorAll('.feature-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(card);
            });
            
            // Observe timeline items
            document.querySelectorAll('.timeline-item').forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                item.style.transition = `opacity 0.5s ease ${index * 0.1}s, transform 0.5s ease ${index * 0.1}s`;
                observer.observe(item);
            });
        });
    </script>
</body>
</html>