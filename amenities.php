<?php 
include 'header.php';
$amenities = $pdo->query("SELECT a.*, b.branch_name FROM amenities a JOIN branches b ON a.branch_id = b.branch_id")->fetchAll();
?>

<div class="container">
    <h2 class="section-title">Resort Amenities</h2>
    <div class="grid">
        <?php foreach($amenities as $a): ?>
            <div class="card" style="border-left: 4px solid var(--secondary);">
                <div class="card-content">
                    <h3><?= htmlspecialchars($a['amenity_name']) ?></h3>
                    <small style="color: #888; text-transform: uppercase;"><?= htmlspecialchars($a['branch_name']) ?></small>
                    <p style="margin-top: 10px;"><?= htmlspecialchars($a['description']) ?></p>
                    <span style="background: #e0f7fa; color: #006064; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem;"><?= $a['availability'] ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include 'footer.php'; ?>