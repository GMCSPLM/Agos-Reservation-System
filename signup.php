<?php 
include 'header.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO customers (full_name, email, contact_number) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['email'], $_POST['contact']]);
        $cust_id = $pdo->lastInsertId();

        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, customer_id) VALUES (?, ?, 'Customer', ?)");
        $stmt->execute([$_POST['email'], $hash, $cust_id]);

        $pdo->commit();
        echo "<script>alert('Success! Please login.'); window.location='login.php';</script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: Email likely already registered.');</script>";
    }
}
?>
<div class="auth-box">
    <h2>Create Account</h2>
    <form method="POST">
        <div class="form-group"><input type="text" name="name" placeholder="Full Name" required></div>
        <div class="form-group"><input type="email" name="email" placeholder="Email Address" required></div>
        <div class="form-group"><input type="text" name="contact" placeholder="Contact Number" required></div>
        <div class="form-group"><input type="password" name="password" placeholder="Password" required></div>
        <button type="submit">SIGN UP</button>
    </form>
</div>
<?php include 'footer.php'; ?>