<?php 
include 'header.php'; 
$branches = $pdo->query("SELECT * FROM branches")->fetchAll();
?>

<div class="container" style="padding-top: 2rem;">
    <h2 class="section-title">Our Resorts</h2>
    <div class="grid">
        <?php foreach($branches as $b): ?>
            <div class="card">
                <img src="<?= htmlspecialchars($b['image_url']) ?>" alt="Resort Image">
                <div class="card-content">
                    <h3><?= htmlspecialchars($b['branch_name']) ?></h3>
                    <p style="font-size: 0.9rem; color: #666; margin-bottom: 10px;">
                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($b['location']) ?>
                    </p>
                    <p><?= htmlspecialchars($b['opening_hours'] ?? 'Always Open') ?></p>
                    <a href="book.php?branch=<?= $b['branch_id'] ?>" style="display:block; text-align:center; margin-top:15px; color:var(--primary); font-weight:bold;">Book Here &rarr;</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include 'footer.php'; ?>