<?php
include 'header.php';

/* ---------------------------------------------------------------------------
 * Pull branches with their current status & primary image (single query).
 * Also pull the global maintenance flag.
 * ------------------------------------------------------------------------- */
$branches = $pdo->query("
    SELECT branch_id, branch_name, location, contact_number,
           opening_hours, image_url, is_available, unavailable_reason
    FROM   v_branches_full
    ORDER BY branch_name
")->fetchAll();

$globalMaintenance = (int)$pdo->query("
    SELECT setting_value FROM system_settings
    WHERE  setting_key = 'all_branches_maintenance'
    LIMIT 1
")->fetchColumn();
?>

<style>
/* ─────────────────────────────────────────────────────────────────
   BRANCHES PAGE — redesigned card grid
   Keeps the existing background and color scheme (--primary, etc.)
   ───────────────────────────────────────────────────────────────── */
.branches-hero {
    text-align: center;
    margin: 1.5rem auto 2rem;
    padding: 0 1rem;
}
.branches-hero h1 {
    display: inline-block;
    padding: 0.9rem 2.5rem;
    background: rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-radius: 100px;          /* full pill */
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.08);
    font-size: 2.4rem;
    color: var(--primary-dark);
    margin: 0;
    font-weight: 700;
}

/* Maintenance banner (when ALL branches are under maintenance) */
.maintenance-banner {
    max-width: 1100px;
    margin: 0 auto 2rem;
    background: linear-gradient(135deg, #fff3cd 0%, #ffe8a3 100%);
    border-left: 5px solid #f39c12;
    padding: 18px 24px;
    border-radius: 14px;
    box-shadow: 0 6px 22px rgba(243,156,18,0.18);
    display: flex;
    align-items: center;
    gap: 16px;
}
.maintenance-banner .mb-icon {
    font-size: 1.8rem;
    color: #b06d00;
    flex-shrink: 0;
}
.maintenance-banner .mb-text strong {
    display: block;
    color: #6b3e00;
    font-size: 1.05rem;
    margin-bottom: 2px;
}
.maintenance-banner .mb-text span {
    color: #8a5a00;
    font-size: 0.9rem;
}

/* Branch grid */
.branches-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.8rem;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1rem 4rem;
}

/* Branch card */
.branch-card {
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 6px 24px rgba(0,0,0,0.08);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    display: flex;
    flex-direction: column;
    position: relative;
}
.branch-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 14px 38px rgba(0, 119, 182, 0.18);
}
.branch-card.is-blocked {
    opacity: 0.92;
}
.branch-card.is-blocked:hover { transform: none; }

/* Image area */
.branch-img-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 10;
    overflow: hidden;
    background: #eef3f8;
}
.branch-img-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease, filter 0.3s ease;
}
.branch-card:hover .branch-img-wrap img {
    transform: scale(1.06);
}
.branch-card.is-blocked .branch-img-wrap img {
    filter: grayscale(60%) brightness(0.85);
}

/* Status pill (top-right of image) */
.branch-status-pill {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 6px 14px;
    border-radius: 50px;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    box-shadow: 0 4px 14px rgba(0,0,0,0.15);
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.branch-status-pill.s-available    { background: rgba(40,167,69,0.92);  color: #fff; }
.branch-status-pill.s-unavailable  { background: rgba(231,76,60,0.92);  color: #fff; }
.branch-status-pill.s-maintenance  { background: rgba(243,156,18,0.95); color: #fff; }

/* Card body */
.branch-card-body {
    padding: 1.4rem 1.4rem 1.6rem;
    display: flex;
    flex-direction: column;
    flex: 1;
    gap: 12px;
}
.branch-card-body h3 {
    margin: 0;
    color: var(--primary-dark);
    font-size: 1.25rem;
    font-weight: 700;
}
.branch-info-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: #555;
    font-size: 0.9rem;
    line-height: 1.45;
}
.branch-info-row i {
    color: var(--primary);
    margin-top: 3px;
    width: 16px;
    flex-shrink: 0;
}

/* Status note (shown when blocked) */
.branch-status-note {
    background: rgba(231,76,60,0.07);
    border-left: 3px solid #e74c3c;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 0.85rem;
    color: #7d2a20;
    line-height: 1.45;
}
.branch-status-note.maintenance {
    background: rgba(243,156,18,0.08);
    border-left-color: #f39c12;
    color: #6b3e00;
}

/* Footer button */
.branch-card-footer {
    margin-top: auto;
    padding-top: 6px;
}
.branch-book-btn {
    display: block;
    text-align: center;
    padding: 12px 16px;
    border-radius: 50px;
    background: var(--primary);
    color: #fff;
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
    box-shadow: 0 4px 14px rgba(0,119,182,0.28);
}
.branch-book-btn:hover {
    filter: brightness(1.1);
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,119,182,0.4);
}
.branch-book-btn.disabled {
    background: #b8c4cf;
    color: #fff;
    box-shadow: none;
    cursor: not-allowed;
    pointer-events: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

/* Empty state */
.branches-empty {
    max-width: 560px;
    margin: 4rem auto;
    text-align: center;
    color: #888;
    padding: 3rem 2rem;
    background: rgba(255,255,255,0.85);
    border-radius: 18px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.08);
}
.branches-empty i { font-size: 3rem; color: #ccc; display: block; margin-bottom: 1rem; }

@media (max-width: 600px) {
    .branches-hero h1 { font-size: 1.8rem; }
    .branches-grid    { grid-template-columns: 1fr; padding: 0 0.6rem 3rem; }
}
</style>

<section class="branches-hero">
    <h1><i style="color:var(--secondary);margin-right:8px;"></i>Branches</h1>
</section>

<?php if ($globalMaintenance === 1): ?>
    <div class="maintenance-banner">
        <div class="mb-icon"><i class="fas fa-tools"></i></div>
        <div class="mb-text">
            <strong>All branches are temporarily under maintenance.</strong>
            <span>Online bookings are paused while we make improvements. Please check back soon &mdash; we appreciate your patience.</span>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($branches)): ?>
    <div class="branches-empty">
        <i class="fas fa-water"></i>
        <h3 style="color:var(--primary-dark);margin:0 0 0.5rem;">No branches to show right now</h3>
        <p style="margin:0;">Please check back later.</p>
    </div>
<?php else: ?>
    <div class="branches-grid">
        <?php foreach ($branches as $b):
            $isAvailable = (int)$b['is_available'] === 1;
            $isBookable  = $isAvailable && $globalMaintenance !== 1;

            // Status-pill class & label
            if ($globalMaintenance === 1) {
                $pillClass = 's-maintenance';
                $pillLabel = '<i class="fas fa-tools"></i> Under Maintenance';
            } elseif ($isAvailable) {
                $pillClass = 's-available';
                $pillLabel = '<i class="fas fa-check-circle"></i> Available';
            } else {
                $pillClass = 's-unavailable';
                $pillLabel = '<i class="fas fa-ban"></i> Unavailable';
            }
        ?>
        <article class="branch-card <?= $isBookable ? '' : 'is-blocked' ?>">
            <div class="branch-img-wrap">
                <img src="<?= htmlspecialchars($b['image_url']) ?>"
                     alt="<?= htmlspecialchars($b['branch_name']) ?>"
                     onerror="this.src='assets/default.jpg'">
                <span class="branch-status-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
            </div>
            <div class="branch-card-body">
                <h3><?= htmlspecialchars($b['branch_name']) ?></h3>

                <div class="branch-info-row">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><?= htmlspecialchars($b['location']) ?></span>
                </div>
                <div class="branch-info-row">
                    <i class="fas fa-clock"></i>
                    <span><?= htmlspecialchars($b['opening_hours'] ?? 'Always Open') ?></span>
                </div>
                <?php if (!empty($b['contact_number'])): ?>
                <div class="branch-info-row">
                    <i class="fas fa-phone-alt"></i>
                    <span><?= htmlspecialchars($b['contact_number']) ?></span>
                </div>
                <?php endif; ?>

                <?php if (!$isBookable): ?>
                    <?php if ($globalMaintenance === 1): ?>
                        <div class="branch-status-note maintenance">
                            <i class="fas fa-tools" style="margin-right:6px;"></i>
                            All branches are temporarily under maintenance. Bookings are paused.
                        </div>
                    <?php else: ?>
                        <div class="branch-status-note">
                            <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                            This branch is currently unavailable for booking.
                            <?php if (!empty($b['unavailable_reason'])): ?>
                                <br><small><?= htmlspecialchars($b['unavailable_reason']) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="branch-card-footer">
                    <?php if ($isBookable): ?>
                        <a href="book.php?branch=<?= (int)$b['branch_id'] ?>" class="branch-book-btn">
                            Book Here &rarr;
                        </a>
                    <?php else: ?>
                        <span class="branch-book-btn disabled">
                            <i class="fas fa-lock"></i> Booking Unavailable
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>