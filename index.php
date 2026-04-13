<?php  
include 'includes/header.php'; 
?>

<!-- Section Specific CSS (Loaded only here for speed) -->
<link rel="stylesheet" href="/assets/css/hero.css">
<link rel="stylesheet" href="/assets/css/partners.css">
<link rel="stylesheet" href="/assets/css/features.css">
<link rel="stylesheet" href="/assets/css/reviews.css">
<link rel="stylesheet" href="/assets/css/faq.css">
<link rel="stylesheet" href="/assets/css/tracker.css">

<!-- Hero Section -->
<section class="hero-section" id="hero">
  <div class="hero-container">
    
    <!-- Left Column: Text & CTA -->
    <div class="hero-text">
      <div class="security-tag">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span>Regulated Global Banking</span>
      </div>
      <h1>
        The Future of <br />
        <span>Digital Finance</span>
      </h1>
      <p>
        Swyft Trust Union Bank is your global hub for multi-currency management, 
        instant loans, and secure asset tracking. Experience banking without borders.
      </p>
      <div class="hero-buttons">
        <a href="register.php" class="btn-primary">
          Get Started 
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
        <a href="#features" class="btn-secondary">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9d50ff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
          View Features
        </a>
      </div>
    </div>

    <!-- Right Column: The Asset Card -->
    <div class="hero-card-wrapper">
      <div class="hero-card">
        <div class="card-header">
          <div class="card-brand">
            <div class="brand-dot"></div>
            <span>Swyft Trust</span>
          </div>
          <div class="card-chip"></div>
        </div>
        <div class="card-body">
          <span class="label">Total Balance</span>
          <div class="card-balance">$14,500.75</div>
        </div>
        <div class="card-footer">
          <div class="footer-item">
            <span>Currency</span>
            <strong>USD / BTC</strong>
          </div>
          <div class="footer-item">
            <span>Status</span>
            <strong class="active-text">Verified</strong>
          </div>
        </div>
        <div class="card-glow"></div>
      </div>
      
      <!-- Interactive Stats Bubble -->
      <div class="stats-bubble">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9d50ff" stroke-width="3"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
        <span>Instant Swap Active</span>
      </div>
    </div>

  </div>
</section>

<?php
// ... above the <section id="partners"> tag ...
$partners = [
  ['name' => "PayPal", 'icon' => "PP"],
  ['name' => "CashApp", 'icon' => "$"],
  ['name' => "Monzo", 'icon' => "M"],
  ['name' => "Revolut", 'icon' => "R"],
  ['name' => "Payoneer", 'icon' => "P"],
  ['name' => "Stripe", 'icon' => "S"],
  ['name' => "Zelle", 'icon' => "Z"],
  ['name' => "Coinbase", 'icon' => "C"],
];
?>

<section className="partners-section" id="partners">
    <div className="partners-container">
        <!-- Header (Simple Fade-In Animation) -->
        <div class="partners-header animate__animated animate__fadeInUp">
          <div class="status-badge">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 0 0 20M2 12h20M2 12a10 10 0 0 1 20 0M12 2v20"/></svg>
            <span>Global Network Active</span>
          </div>
          <h2>Trusted by Industry Leaders</h2>
          <p>We bridge the gap between traditional banking and the digital future.</p>
        </div>

        <!-- INFINITE MARQUEE ROW 1 -->
        <div class="marquee-wrapper">
          <div class="marquee-track marquee-normal">
            <!-- Repeat partners 3 times for a seamless loop -->
            <?php foreach (array_merge($partners, $partners, $partners) as $p): ?>
              <div class="partner-logo-box">
                <div class="logo-symbol"><?php echo $p['icon']; ?></div>
                <span><?php echo $p['name']; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- REVERSE MARQUEE ROW 2 -->
        <div class="marquee-wrapper">
          <div class="marquee-track marquee-reverse">
            <!-- Repeat partners 3 times -->
            <?php foreach (array_merge($partners, $partners, $partners) as $p): ?>
              <div class="partner-logo-box">
                <div class="logo-symbol alt"><?php echo $p['icon']; ?></div>
                <span><?php echo $p['name']; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
</section>
<section class="features-section" id="features">
  <div class="features-container">
    <!-- Header -->
    <div class="features-header animate-on-scroll">
      <div class="status-badge-feat">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>
        <span>Core Platform Features</span>
      </div>
      <h2>A Suite of Tools for Modern Finance</h2>
      <p>Everything you need to manage your assets securely and efficiently in one dashboard.</p>
    </div>
    
    <!-- Features Grid -->
    <div class="features-grid">
      <!-- Feature 1: Deposits & Withdrawals -->
      <div class="feature-card" style="--accent: #10B981;">
        <div class="feature-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </div>
        <h3>Deposits & Withdrawals</h3>
        <p>Submit requests with admin approval and real-time balance updates.</p>
      </div>

      <!-- Feature 2: Multi-Currency Wallet -->
      <div class="feature-card" style="--accent: #F59E0B;">
        <div class="feature-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
        </div>
        <h3>Multi-Currency Wallet</h3>
        <p>Hold, convert, and transact in multiple currencies seamlessly.</p>
      </div>

      <!-- Feature 3: Loans & Finance -->
      <div class="feature-card" style="--accent: #EF4444;">
        <div class="feature-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        </div>
        <h3>Loans & Finance</h3>
        <p>Apply for loans, track repayment schedules, and manage interest.</p>
      </div>

      <!-- Feature 4: Refund Tracking -->
      <div class="feature-card" style="--accent: #8B5CF6;">
        <div class="feature-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
        </div>
        <h3>Refund Tracking</h3>
        <p>Track refunds instantly with full visibility into the approval stages.</p>
      </div>

      <!-- Feature 5: KYC Verification -->
      <div class="feature-card" style="--accent: #6366F1;">
        <div class="feature-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
        </div>
        <h3>KYC Verification</h3>
        <p>Secure account creation with ID and address verification for safety.</p>
      </div>

      <!-- Feature 6: Transaction History -->
      <div class="feature-card" style="--accent: #FACC15;">
        <div class="feature-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <h3>Transaction History</h3>
        <p>Detailed logs of all deposits, withdrawals, and loan transactions.</p>
      </div>
    </div>
  </div>
</section>
<section id="tracking" class="tracker-section">
  <div class="tracker-container">
    <div class="tracker-header animate__animated animate__fadeInUp">
      <div class="status-badge-tracker">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span>Real-Time Audit Protocol</span>
      </div>
      <h2>Monitor Your <span>Wealth Flow</span></h2>
      <p>Instant transparency for your loans, refunds, and high-value transfers.</p>
    </div>

    <!-- Glassmorphic Search Terminal -->
    <div class="glass-terminal animate__animated animate__zoomIn">
      <form action="check_status.php" method="GET" class="tracker-form">
        <div class="input-wrapper">
           <input type="text" name="code" placeholder="Enter Tracking Hash (e.g. ST-7729)" required autocomplete="off">
        </div>
        <button type="submit" class="neo-track-btn">
           <span>Initiate Tracking</span>
           <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </form>
    </div>

    <!-- Live Status Indicators -->
    <div class="tracker-info">
        <div class="info-item">
            <span class="dot pulse"></span> 
            <small>Active Scans: 1,204</small>
        </div>
        <div class="info-item">
            <small>Avg. Audit Time: 12.4s</small>
        </div>
    </div>
  </div>
</section>
<?php
$reviews = [
  ['name' => "Emma J.", 'role' => "Entrepreneur", 'message' => "Deposits and refunds are instant, and their support is top-notch.", 'rating' => 5],
  ['name' => "David S.", 'role' => "Freelancer", 'message' => "I got a loan within 24 hours, completely transparent. Truly modern banking.", 'rating' => 5],
  ['name' => "Sophia L.", 'role' => "Business Owner", 'message' => "Multi-currency management is a game-changer. I handle clients globally.", 'rating' => 5],
  ['name' => "Liam B.", 'role' => "Software Developer", 'message' => "The refund process is fast and reliable. Finances are in good hands.", 'rating' => 4],
  ['name' => "Olivia W.", 'role' => "Investor", 'message' => "User-friendly interface and amazing features. Managing funds is easy.", 'rating' => 5],
  ['name' => "Noah D.", 'role' => "Consultant", 'message' => "Swyft Trust Union Bank brings trust and innovation together. Highly recommend.", 'rating' => 5]
];
?>
<section id="reviews" class="reviews-section">
  <div class="reviews-container">
    <div class="reviews-header">
      <div class="status-badge-rev">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        <span>Social Proof & Trust</span>
      </div>
      <h2>What Our Clients Say Globally</h2>
    </div>

    <div class="reviews-marquee-wrapper">
      <div class="reviews-track">
        <?php 
        // Triple the array for a seamless infinite loop
        $loopingReviews = array_merge($reviews, $reviews, $reviews);
        foreach ($loopingReviews as $review): 
        ?>
          <div class="review-card">
            <div class="review-top">
              <div class="review-meta">
                <p class="review-name"><?php echo $review['name']; ?></p>
                <p class="review-role"><?php echo $review['role']; ?></p>
              </div>
              <div class="rating">
                <?php for($i=0; $i < $review['rating']; $i++): ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="#fcd34d" stroke="#fcd34d"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                <?php endfor; ?>
              </div>
            </div>
            <p class="review-message">"<?php echo $review['message']; ?>"</p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<section id="faq" class="faq-section">
    <div class="faq-container">
        <!-- Header -->
        <div class="faq-header animate-on-scroll">
            <div class="status-badge-faq">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>Support Center</span>
            </div>
            <h2>Common Inquiries</h2>
            <p>Everything you need to know about the Swyft Trust ecosystem.</p>
        </div>

        <!-- FAQ List -->
        <div class="faq-list">
            <?php
            $faqs = [
                ["How do I open a new account?", "Simply click 'Get Started', complete the secure multi-step registration, and upload your KYC documents. Your account is typically activated within 12-24 hours of approval."],
                ["How can I deposit or withdraw funds?", "Initiate a request from your dashboard. Our admin team manually reviews and verifies each transaction for maximum security. Once confirmed, your Available Balance updates instantly."],
                ["How do refunds work?", "You can track refunds via your unique tracking code. Once the 4-stage verification process is complete, funds are moved from Pending to your Available Balance."],
                ["Can I manage multiple currencies?", "Yes. Swyft Trust supports both Fiat and Crypto assets. You can hold, swap, and transfer between different currencies directly from your global wallet."],
                ["How do I apply for a loan?", "Navigate to 'Request Loan' in the Financial Services menu. Select your loan type, submit the form, and track the audit progress in real-time."],
                ["Is my data secure?", "We utilize AES-256 bank-grade encryption and strict KYC/AML compliance protocols to ensure your data and assets are protected against unauthorized access."]
            ];

            foreach ($faqs as $idx => $faq): ?>
                <div class="faq-wrapper" id="faq-<?php echo $idx; ?>">
                    <button class="faq-question-btn" onclick="toggleFaq(<?php echo $idx; ?>)">
                        <span><?php echo $faq[0]; ?></span>
                        <div class="chevron">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                    </button>
                    <div class="faq-answer-container">
                        <div class="faq-answer-content">
                            <p><?php echo $faq[1]; ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Secure Footer Note -->
        <div class="faq-security-note">
           <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#00f294" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
           <span>Still need help? Our 24/7 Secure Support is available in the dashboard.</span>
        </div>
    </div>
</section>
<!-- Your other scripts -->
<script>
// FAQ function
function toggleFaq(index) {
    const wrappers = document.querySelectorAll('.faq-wrapper');
    wrappers.forEach((w, i) => {
        if (i === index) w.classList.toggle('active');
        else w.classList.remove('active');
    });
}

// Google Translate
let googleTranslateLoaded = false;


</script>
<?php 
// Close with the Foundation
include 'includes/footer.php'; 
?>
