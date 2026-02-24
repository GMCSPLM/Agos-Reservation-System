<?php 
include 'header.php';
if (!isset($_SESSION['user_id'])) echo "<script>window.location='login.php';</script>";

$branches = $pdo->query("SELECT * FROM branches")->fetchAll();
?>

<div class="container">
    <div style="display: flex; gap: 40px; margin-top: 40px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 300px;">
            <h1 style="font-size: 3rem; color: white; line-height: 1.2; text-shadow: 2px 2px 8px rgba(0,0,0,0.6);">Ready to unwind?<br>Secure your spot.</h1>
            <p style="margin-top: 20px; color: white; text-shadow: 1px 1px 6px rgba(0,0,0,0.6);">Choose your preferred resort branch and date. We handle the rest.</p>
        </div>
        
        <div class="auth-box" style="margin: 0; flex: 1;">
            <h3>Reservation Details</h3>
            <form action="paymongo_api.php" method="POST">
                <div class="form-group">
                    <label>Select Resort</label>
                    <select name="branch_id" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?= $b['branch_id'] ?>"><?= $b['branch_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Check-in Date</label>
                    <input type="date" name="check_in" required min="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Tour Type</label>
                    <select name="type">
                        <option value="Day">Day Tour (₱9,000)</option>
                        <option value="Overnight">Overnight (₱10,000)</option>
                    </select>
                </div>
                <button type="submit">PROCEED TO PAYMENT</button>
            </form>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>