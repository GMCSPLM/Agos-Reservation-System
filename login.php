<?php 
include 'header.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'], $user['password_hash'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['customer_id'] = $user['customer_id'];
        
        $redirect = ($user['role'] == 'Admin') ? "admin/dashboard.php" : "book.php";
        echo "<script>window.location.href='$redirect';</script>";
        exit;
    } else {
        $error = "Invalid credentials.";
    }
}
?>
<div class="auth-box">
    <h2>Welcome Back</h2>
    <?php if(isset($error)) echo "<p style='color:red; text-align:center;'>$error</p>"; ?>
    <form method="POST">
        <div class="form-group">
            <input type="email" name="email" placeholder="Email Address" required>
        </div>
        <div class="form-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button type="submit">LOG IN</button>
    </form>
    <p style="text-align: center; margin-top: 15px;">New here? <a href="signup.php" style="color: var(--primary);">Create Account</a></p>
</div>
<?php include 'footer.php'; ?>