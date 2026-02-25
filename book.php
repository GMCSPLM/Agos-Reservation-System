<?php 
include 'header.php';
if (!isset($_SESSION['user_id'])) echo "<script>window.location='login.php';</script>";

$branches = $pdo->query("SELECT * FROM branches")->fetchAll();

// Pre-fill from calendar click (branch & date passed via URL)
$preselectedBranch = $_GET['branch'] ?? null;
$preselectedDate   = $_GET['date']   ?? null;

// Only use branch if it's a valid numeric ID (ignore 'all')
$preselectedBranch = (is_numeric($preselectedBranch)) ? intval($preselectedBranch) : null;
?>

<div class="container">
    <div style="display: flex; gap: 40px; margin-top: 40px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 300px;">
            <h1 style="font-size: 3rem; color: var(--primary); line-height: 1.2;">Ready to unwind?<br>Secure your spot.</h1>
            <p style="margin-top: 20px; color: #666;">Choose your preferred resort branch and date. We handle the rest.</p>
        </div>
        
        <div class="auth-box" style="margin: 0; flex: 1;">
            <h3>Reservation Details</h3>
            <form action="paymongo_api.php" method="POST">
                <div class="form-group">
                    <label>Select Resort</label>
                    <select name="branch_id" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?= $b['branch_id'] ?>" 
                                <?= ($preselectedBranch === intval($b['branch_id'])) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Check-in Date</label>
                    <input type="date" name="check_in" required 
                           min="<?= date('Y-m-d') ?>"
                           value="<?= htmlspecialchars($preselectedDate ?? '') ?>">
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