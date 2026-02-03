<?php require_once 'db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CheckMates | Private Resorts</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                <li><a href="book.php" class="btn-nav">BOOK NOW</a></li>
            <?php endif; ?>
            <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i></a></li>
        <?php else: ?>
            <li><a href="login.php">LOGIN</a></li>
            <li><a href="signup.php" class="btn-nav">SIGN UP</a></li>
        <?php endif; ?>
    </ul>
</nav>