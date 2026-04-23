<?php require_once 'db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CheckMates | Private Resorts</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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

        /* ── ADDED: Hamburger button (hidden on desktop) ── */
        .hamburger {
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 5px;
            width: 40px;
            height: 40px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            transition: background 0.2s;
            z-index: 1100;
        }
        .hamburger:hover {
            background: rgba(0, 119, 182, 0.08);
            box-shadow: none;
            transform: none;
        }
        .hamburger span{
            display: block;
            width: 22px;
            height: 2px;
            background: var(--primary-dark);
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        /* Animate to X when open */
        .hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .hamburger.open span:nth-child(2) { opacity: 0;  transform: scaleX(0); }
        .hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* Added: MOBILE nav styles */
        @media (max-width: 768px) {
            .hamburger { display: flex; }

            nav ul {
                display: none;
                flex-direction: column;
                position: fixed;
                top: 0;
                right: 0;
                width: 75%;
                height: 100vh;
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                padding:80px 24px 40px;
                gap: 4px;
                box-shadow: -6px 0 30px rgba(0, 0, 0, 0.12);
                z-index: 1050;
                overflow-y: auto;
            }
            nav ul.open {
                display: flex;
            }
             nav ul li a {
                display: block;
                padding: 12px 16px;
                border-radius: 10px;
                font-size: 0.92rem;
                font-weight: 600;
            }
             nav ul li a:hover {
                background: rgba(0, 119, 182, 0.08);
                transform: none;
            }
            nav ul li a.btn-nav {
                text-align: center;
                margin-top: 8px;
                padding: 12px 16px;
                border-radius: 12px;
            }
            nav ul li a.btn-nav-reservations {
                justify-content: center;
                margin-top: 4px;
            }

            /*Overlay behind menu */
            .nav-overlay {
                display: none;
                position: fixed;
                isnet: 0;
                background: rgba(0, 0, 0, 0.3);
                z-index: 1040;
            }
            .nav-overlay.open {
                display: block;
            }
        }
    </style>
</head>
<body>
    <! -- Added: Overlay (close menu when tapped outside) -->
    <div class="nav-overlay" id="navOverlay"></div>
<nav>
    <div class="logo">
        <i class="fas fa-water"></i> CheckMates <span>AGOS</span>
    </div>
        <!-- Added: Hamburger button -->
        <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
        <span></span>
        <span></span>
        <span></span>
    </button>
    <ul id="navMenu">
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

<!-- ADDED: Hamburger toggle script -->
<script>
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const navMenu      = document.getElementById('navMenu');
    const navOverlay   = document.getElementById('navOverlay');
 
    function openMenu() {
        hamburgerBtn.classList.add('open');
        navMenu.classList.add('open');
        navOverlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeMenu() {
        hamburgerBtn.classList.remove('open');
        navMenu.classList.remove('open');
        navOverlay.classList.remove('open');
        document.body.style.overflow = '';
    }
 
    hamburgerBtn.addEventListener('click', () => {
        navMenu.classList.contains('open') ? closeMenu() : openMenu();
    });
    navOverlay.addEventListener('click', closeMenu);
    navMenu.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
</script>
