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
    </style>
</head>
<body>
<nav>
    <div class="logo">
        <i class="fas fa-water"></i> CheckMates <span>AGOS</span>
    </div>
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