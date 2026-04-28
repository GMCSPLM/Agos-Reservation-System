<?php ob_start(); require_once 'db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CheckMates | Private Resorts</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ══════════════════════════════════════════
           RESERVATIONS BUTTON
        ══════════════════════════════════════════ */
        .btn-nav-reservations {
            border: 2px solid #0077b6 !important;
            color: #0077b6 !important;
            border-radius: 50px !important;
            padding: 7px 18px !important;
            font-weight: 700 !important;
            transition: background 0.25s, color 0.25s !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }
        .btn-nav-reservations:hover,
        .btn-nav-reservations.active {
            background: #0077b6 !important;
            color: white !important;
        }

        /* ══════════════════════════════════════════
           HIDE MOBILE ELEMENTS BY DEFAULT
        ══════════════════════════════════════════ */
        .nav-overlay { display: none; }
        .mobile-nav  { display: none; }
        .hamburger   { display: none; }

        /* ══════════════════════════════════════════
           TABLET FIX (769px – 1024px)
           Shrinks desktop nav so it fits — no overflow
        ══════════════════════════════════════════ */
        @media (min-width: 769px) and (max-width: 1024px) {
            nav         { padding: 1rem 3%; }
            nav ul      { gap: 0.7rem; }
            nav ul li:last-child a { min-width: 36px; text-align: center; }
            nav a       { font-size: 0.8rem; }
            .logo       { font-size: 1.2rem; flex-shrink: 0; }
            .logo span  { display: none; }
            .btn-nav    { padding: 0.55rem 1rem !important; font-size: 0.8rem; }
            .btn-nav-reservations { padding: 5px 10px !important; font-size: 0.8rem !important; }
            .mobile-close-btn { display: none !important; }
        }

        /* ══════════════════════════════════════════
           MOBILE (≤ 768px) — Hamburger menu
        ══════════════════════════════════════════ */
        @media (max-width: 768px) {

            /* — Hamburger button — */
            .hamburger {
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                gap: 5px;
                width: 38px;
                height: 38px;
                background: none;
                border: none;
                cursor: pointer;
                padding: 6px;
                border-radius: 8px;
                flex-shrink: 0;
            }
            .hamburger:hover { background: rgba(0,119,182,0.08); box-shadow: none; transform: none; }
            .hamburger span  { display: block; width: 20px; height: 2px; background: var(--primary-dark); border-radius: 2px; transition: all 0.3s ease; }
            .hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
            .hamburger.open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
            .hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

            /* — Logo shrink — */
            .logo      { font-size: 1.25rem; }
            .logo span { font-size: 0.85rem; }

            /* — Hide desktop nav links — */
            nav ul { display: none !important; }

            /* — Dark overlay behind menu — */
            .nav-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.45);
                z-index: 1998;
                cursor: pointer;
            }
            .nav-overlay.open { display: block; }

            /* — Slide-in mobile menu — */
            .mobile-nav {
                flex-direction: column;
                position: fixed;
                top: 0;
                right: 0;
                width: 75%;
                max-width: 280px;
                height: 100vh;
                background: #ffffff;
                padding: 80px 22px 40px;
                gap: 4px;
                box-shadow: -6px 0 30px rgba(0,0,0,0.15);
                z-index: 1999;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                list-style: none;
            }
            .mobile-nav.open { display: flex; }

            .mobile-nav li        { width: 100%; }
            .mobile-nav li a      { display: block; padding: 13px 16px; border-radius: 10px; font-size: 0.95rem; font-weight: 600; color: var(--text-dark); text-decoration: none; width: 100%; }
            .mobile-nav li a:hover { background: rgba(0,119,182,0.08); color: var(--primary); transform: none; }
            .mobile-nav li a.btn-nav {
                text-align: center;
                margin-top: 8px;
                padding: 13px 16px;
                border-radius: 12px;
                background: linear-gradient(135deg, var(--primary), var(--primary-dark));
                color: white !important;
            }
            .mobile-nav li a.btn-nav-reservations { justify-content: center; margin-top: 4px; }
            .mobile-nav li a.active {
    background: rgba(0,119,182,0.10);
    color: #0077b6;
}
        }

        /* ══════════════════════════════════════════
           EXTRA SMALL PHONES (≤ 360px)
        ══════════════════════════════════════════ */
        @media (max-width: 360px) {
            .logo      { font-size: 1.1rem; }
            .logo span { font-size: 0.78rem; }
            nav        { padding: 0.9rem 4%; }
        }
    </style>
</head>
<body>

<!-- Outside nav so position:fixed works correctly -->
<div class="nav-overlay" id="navOverlay"></div>
<ul class="mobile-nav" id="mobileNav">
    <li style="display: flex; justify-content: flex-end; padding-bottom: 8px;">
    <button onclick="closeMenu()" aria-label="Close menu" style="
        background: none;
        border: 2px solid rgba(2,62,138,0.15);
        font-size: 1.2rem;
        cursor: pointer;
        color: #023e8a;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s, color 0.2s, border-color 0.2s;
    "
    onmouseover="this.style.background='#023e8a';this.style.color='white';this.style.borderColor='#023e8a';"
    onmouseout="this.style.background='none';this.style.color='#023e8a';this.style.borderColor='rgba(2,62,138,0.15)';"
    >&#10005;</button>
</li>
    <li><a href="index.php">HOME</a></li>
    <li><a href="branches.php">BRANCHES</a></li>
    <li><a href="amenities.php">AMENITIES</a></li>
    <li><a href="feedbacks.php">FEEDBACKS</a></li>
    <?php if(isset($_SESSION['user_id'])): ?>
        <?php if($_SESSION['role'] == 'Admin'): ?>
            <li><a href="admin/dashboard.php">DASHBOARD</a></li>
        <?php else: ?>
            <li><a href="reservations.php" class="btn-nav-reservations <?= basename($_SERVER['PHP_SELF']) === 'reservations.php' ? 'active' : '' ?>">RESERVATIONS</a></li>
            <li><a href="book.php" class="btn-nav">BOOK NOW</a></li>
        <?php endif; ?>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> LOGOUT</a></li>
    <?php else: ?>
        <li><a href="login.php">LOGIN</a></li>
        <li><a href="signup.php" class="btn-nav">SIGN UP</a></li>
    <?php endif; ?>
</ul>

<nav>
    <div class="logo">
        <i class="fas fa-water"></i> CheckMates <span>AGOS</span>
    </div>
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
        <span></span><span></span><span></span>
    </button>
    <ul>
        <li><a href="index.php">HOME</a></li>
        <li><a href="branches.php">BRANCHES</a></li>
        <li><a href="amenities.php">AMENITIES</a></li>
        <li><a href="feedbacks.php">FEEDBACKS</a></li>
        <?php if(isset($_SESSION['user_id'])): ?>
            <?php if($_SESSION['role'] == 'Admin'): ?>
                <li><a href="admin/dashboard.php">DASHBOARD</a></li>
            <?php else: ?>
                <li><a href="reservations.php" class="btn-nav-reservations <?= basename($_SERVER['PHP_SELF']) === 'reservations.php' ? 'active' : '' ?>">
                    RESERVATIONS
                </a></li>
                <li><a href="book.php" class="btn-nav">BOOK NOW</a></li>
            <?php endif; ?>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i></a></li>
        <?php else: ?>
            <li><a href="login.php">LOGIN</a></li>
            <li><a href="signup.php" class="btn-nav">SIGN UP</a></li>
        <?php endif; ?>
    </ul>
</nav>

<script>
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const mobileNav    = document.getElementById('mobileNav');
    const navOverlay   = document.getElementById('navOverlay');

    function openMenu()  {
        hamburgerBtn.classList.add('open');
        mobileNav.classList.add('open');
        navOverlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeMenu() {
        hamburgerBtn.classList.remove('open');
        mobileNav.classList.remove('open');
        navOverlay.classList.remove('open');
        document.body.style.overflow = '';
    }

    hamburgerBtn.addEventListener('click', () => mobileNav.classList.contains('open') ? closeMenu() : openMenu());
    navOverlay.addEventListener('click', closeMenu);
    mobileNav.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
</script>
