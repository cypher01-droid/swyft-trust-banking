
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">

    <title>SwyftTrust | Secure Managed Banking</title>
    <!-- CSS Dependencies -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">
<style>
    /* Completely hide the Google Translate top bar and tools */
    .goog-te-banner-frame.skiptranslate, 
    .goog-te-gadget-icon, 
    #goog-gt-tt, 
    .goog-te-balloon-frame { display: none !important; }
    body { top: 0px !important; }
    .goog-text-highlight { background-color: transparent !important; box-shadow: none !important; }

    /* Custom Floating Dropdown */
    .custom-lang-box {
        position: fixed; bottom: 90px; right: 20px; z-index: 999999;
        background: #fff; padding: 10px 15px; border-radius: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; border: 1px solid #ddd;
        font-family: Arial, sans-serif; font-size: 14px;
    }
    .lang-list {
        display: none; position: absolute; bottom: 50px; right: 0;
        background: white; border-radius: 12px; width: 160px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15); overflow: hidden;
    }
    .custom-lang-box:hover .lang-list { display: block; }
    .lang-list a { display: block; padding: 12px; text-decoration: none; color: #333; transition: 0.2s; }
    .lang-list a:hover { background: #f4f4f4; color: #4285f4; }
</style>
</head>
<body>

<header id="main-header" class="header">
  <div class="header-container">
    <!-- Logo Section -->
    <div class="logo" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
      <div class="logo-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <span class="logo-text">Swyft<span>Trust</span></span>
    </div>

    <!-- Desktop Menu -->
    <nav class="nav-desktop">
      <a href="/" class="nav-link">Home</a>
      <a href="#features" class="nav-link">Features</a>
      <a href="#faq" class="nav-link">FAQ</a>
      <a href="/check_status.php" class="nav-link">Check Status</a>
    </nav>

    <!-- Auth & Hamburger -->
    <div class="header-auth">
      <a href="/login.php" class="login-btn">Login</a>
      <a href="/register.php" class="signup-btn">Get Started</a>
      
      <button class="hamburger" id="menuBtn">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
      </button>
    </div>
  </div>

  <!-- Mobile Dropdown -->
  <nav class="nav-mobile" id="mobileMenu">
    <a href="/" class="mobile-link">Home</a>
    <a href="#features" class="mobile-link">Features</a>
    <a href="/check_status.php" class="mobile-link">Check Status</a>
    <hr class="nav-divider">
    <a href="/login.php" class="mobile-link">Login</a>
    <a href="/register.php" class="mobile-signup">Sign Up</a>
  </nav>
</header>
<!-- Global JS Load -->
<div class="gtranslate_wrapper"></div>
<script>window.gtranslateSettings = {"default_language":"en","native_language_names":true,"detect_browser_language":true,"languages":["en","fr","it","es","de"],"wrapper_selector":".gtranslate_wrapper","flag_size":24,"flag_style":"3d","alt_flags":{"en":"usa","es":"mexico"}}</script>
<script src="https://cdn.gtranslate.net/widgets/latest/dwf.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuBtn = document.getElementById('menuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    const mainHeader = document.getElementById('main-header');

    if (menuBtn && mobileMenu) {
        // Handle both 'click' and 'touchstart' for mobile responsiveness
        const handleToggle = function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const isOpen = mobileMenu.classList.toggle('open');
            console.log("Menu State:", isOpen); // Test this in mobile inspect
            
            // Zeus Icon Toggle
            menuBtn.innerHTML = isOpen 
                ? '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>'
                : '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>';
        };

        menuBtn.addEventListener('click', handleToggle);
        menuBtn.addEventListener('touchstart', handleToggle, { passive: false });
    }

    window.onscroll = function() {
        if (window.scrollY > 20) {
            mainHeader.classList.add('scrolled');
        } else {
            mainHeader.classList.remove('scrolled');
        }
    };
});
</script>
<script id="chatway" async="true" src="https://cdn.chatway.app/widget.js?id=veJnUJPNcIOX"></script>