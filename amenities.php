<?php
include 'header.php';

/* ─────────────────────────────────────────────────────────────────────────
 * Pull every amenity together with its branch. Available amenities surface
 * first (sorted by branch, then name) so the most useful info is up top.
 * Unavailable ones still appear, dimmed and clearly labeled — see UX note
 * below for the rationale.
 * ───────────────────────────────────────────────────────────────────────── */
$amenities = $pdo->query("
    SELECT a.amenity_id, a.amenity_name, a.description, a.availability,
           b.branch_id, b.branch_name, b.location
    FROM   amenities a
    JOIN   branches  b ON a.branch_id = b.branch_id
    ORDER  BY (a.availability = 'Available') DESC, b.branch_name, a.amenity_name
")->fetchAll(PDO::FETCH_ASSOC);

/* Group by branch for the section layout. */
$amenitiesByBranch = [];
foreach ($amenities as $a) {
    $bid = (int)$a['branch_id'];
    if (!isset($amenitiesByBranch[$bid])) {
        $amenitiesByBranch[$bid] = [
            'branch_name' => $a['branch_name'],
            'location'    => $a['location'],
            'items'       => [],
        ];
    }
    $amenitiesByBranch[$bid]['items'][] = $a;
}

$total_count   = count($amenities);
$avail_count   = count(array_filter($amenities, fn($a) => $a['availability'] === 'Available'));
$unavail_count = $total_count - $avail_count;

/* Pick a representative icon for common amenity types. Falls back to a
 * generic "star" icon for anything we don't recognize. Pure presentation —
 * keeps the data model schema-clean (no icon column needed). */
function amenity_icon(string $name): string {
    $n = strtolower($name);
    if (str_contains($n, 'pool') || str_contains($n, 'swim'))  return 'fa-swimming-pool';
    if (str_contains($n, 'wifi') || str_contains($n, 'inter')) return 'fa-wifi';
    if (str_contains($n, 'parking') || str_contains($n, 'car'))return 'fa-square-parking';
    if (str_contains($n, 'gym') || str_contains($n, 'fitness'))return 'fa-dumbbell';
    if (str_contains($n, 'spa') || str_contains($n, 'massage'))return 'fa-spa';
    if (str_contains($n, 'restaurant') || str_contains($n, 'dining') || str_contains($n, 'food')) return 'fa-utensils';
    if (str_contains($n, 'bar') || str_contains($n, 'drink'))  return 'fa-martini-glass';
    if (str_contains($n, 'basketball'))                        return 'fa-basketball';
    if (str_contains($n, 'billiard') || str_contains($n, 'pool table')) return 'fa-circle';
    if (str_contains($n, 'video') || str_contains($n, 'karao') || str_contains($n, 'song')) return 'fa-microphone';
    if (str_contains($n, 'play') || str_contains($n, 'kid'))   return 'fa-child';
    if (str_contains($n, 'beach'))                             return 'fa-umbrella-beach';
    if (str_contains($n, 'garden') || str_contains($n, 'park'))return 'fa-tree';
    if (str_contains($n, 'air') || str_contains($n, 'aircon')) return 'fa-snowflake';
    if (str_contains($n, 'tv') || str_contains($n, 'cable'))   return 'fa-tv';
    return 'fa-star';
}
?>

<style>
/* ─────────────────────────────────────────────────────────────────────────
   Customer-facing Amenities page — uses the system's primary/secondary
   colors and matches the rounded-card aesthetic from index.php.
   ───────────────────────────────────────────────────────────────────────── */
.amenities-hero {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    padding: 4rem 1.5rem 5rem;
    text-align: center;
    position: relative;
    overflow: hidden;
    margin-bottom: -3rem;   /* lets the next section overlap and "lift" */
}
.amenities-hero::before {
    content: ''; position: absolute; inset: 0;
    background-image:
        radial-gradient(circle at 20% 30%, rgba(255,255,255,0.10) 0, transparent 40%),
        radial-gradient(circle at 80% 70%, rgba(255,255,255,0.08) 0, transparent 45%);
    pointer-events: none;
}
.amenities-hero h1 {
    font-size: 2.4rem;
    margin: 0 0 0.6rem;
    font-weight: 800;
    text-shadow: 0 2px 12px rgba(0,0,0,0.18);
    position: relative;
}
.amenities-hero p {
    font-size: 1.05rem;
    max-width: 640px;
    margin: 0 auto;
    opacity: 0.92;
    position: relative;
}
.amenities-hero .am-stats {
    display: inline-flex; flex-wrap: wrap; gap: 1rem; justify-content: center;
    margin-top: 1.6rem; position: relative;
}
.amenities-hero .am-stat {
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    padding: 0.6rem 1.2rem; border-radius: 50px;
    font-size: 0.92rem; font-weight: 600;
    display: inline-flex; align-items: center; gap: 8px;
    border: 1px solid rgba(255,255,255,0.22);
}
.amenities-hero .am-stat i { font-size: 0.85rem; }

/* Wrapper for each branch's section (a "lifted" card). */
.am-page-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1.5rem 4rem;
    position: relative;
    z-index: 2;
}
.am-branch-block {
    background: white;
    border-radius: 22px;
    padding: 2rem 2rem 2.4rem;
    margin-bottom: 2rem;
    box-shadow: 0 12px 40px rgba(0,0,0,0.08);
    border: 1px solid rgba(0,119,182,0.07);
}

.am-branch-title {
    display: flex; align-items: center; gap: 14px;
    padding-bottom: 1.2rem; margin-bottom: 1.6rem;
    border-bottom: 2px dashed rgba(0,119,182,0.18);
}
.am-branch-title .am-branch-badge {
    width: 50px; height: 50px; border-radius: 14px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
    box-shadow: 0 6px 16px rgba(0,119,182,0.25);
    flex-shrink: 0;
}
.am-branch-title .am-branch-info h2 {
    margin: 0; font-size: 1.4rem; color: var(--primary-dark); font-weight: 700;
}
.am-branch-title .am-branch-info p {
    margin: 4px 0 0; color: #777; font-size: 0.88rem;
    display: flex; align-items: center; gap: 6px;
}
.am-branch-title .am-branch-count {
    margin-left: auto;
    font-size: 0.78rem; font-weight: 700;
    padding: 6px 14px; border-radius: 50px;
    background: rgba(0,119,182,0.1); color: var(--primary-dark);
    white-space: nowrap;
}

/* The grid of amenity tiles. */
.am-tile-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 1.2rem;
}

.am-tile {
    background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
    border: 1px solid rgba(0,119,182,0.12);
    border-radius: 18px;
    padding: 1.4rem 1.3rem 1.5rem;
    display: flex; flex-direction: column; gap: 12px;
    position: relative;
    transition: transform 0.25s, box-shadow 0.25s, border-color 0.25s;
    overflow: hidden;
}
.am-tile::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
    background: linear-gradient(180deg, var(--secondary) 0%, var(--primary) 100%);
    transition: width 0.25s;
}
.am-tile:hover {
    transform: translateY(-5px);
    box-shadow: 0 14px 32px rgba(0,119,182,0.15);
    border-color: rgba(0,119,182,0.28);
}
.am-tile:hover::before { width: 6px; }

/* Icon + status header row. */
.am-tile-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 10px;
}
.am-tile-icon {
    width: 48px; height: 48px; border-radius: 14px;
    background: linear-gradient(135deg, rgba(0,119,182,0.14) 0%, rgba(0,180,216,0.14) 100%);
    color: var(--primary);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
    transition: transform 0.25s, background 0.25s;
}
.am-tile:hover .am-tile-icon {
    transform: scale(1.08) rotate(-4deg);
}
.am-tile-status {
    font-size: 0.7rem; font-weight: 700;
    padding: 4px 12px; border-radius: 50px;
    text-transform: uppercase; letter-spacing: 0.05em;
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap;
}
.am-tile-status.s-on  { background: rgba(40,167,69,0.15); color: #1e7d36; }
.am-tile-status.s-off { background: rgba(231,76,60,0.15); color: #b03a2e; }
.am-tile-status i { font-size: 0.65rem; }

.am-tile h3 {
    margin: 0; font-size: 1.08rem; color: var(--primary-dark);
    font-weight: 700; line-height: 1.3;
}
.am-tile p {
    margin: 0; font-size: 0.88rem; color: #555; line-height: 1.55;
    flex: 1;
}
.am-tile .am-tile-empty {
    color: #aaa; font-style: italic; font-size: 0.85rem;
}

/* Slightly subdue unavailable amenities so the eye lands on the open ones
 * first, while still keeping them visible (better UX than silently hiding
 * them — guests can plan around what's offline rather than be surprised). */
.am-tile.is-unavail {
    background: linear-gradient(135deg, #fafafa 0%, #f3f4f7 100%);
    border-color: rgba(231,76,60,0.18);
}
.am-tile.is-unavail::before {
    background: linear-gradient(180deg, #e74c3c 0%, #c0392b 100%);
}
.am-tile.is-unavail .am-tile-icon {
    background: linear-gradient(135deg, rgba(231,76,60,0.14) 0%, rgba(231,76,60,0.07) 100%);
    color: #c0392b;
    filter: grayscale(20%);
}
.am-tile.is-unavail h3 { color: #6b6b6b; }
.am-tile.is-unavail p  { color: #888; }

/* Empty / fallback state. */
.am-empty-state {
    background: white; border-radius: 22px;
    padding: 3rem 2rem; text-align: center;
    box-shadow: 0 12px 40px rgba(0,0,0,0.08);
    color: #888;
}
.am-empty-state i {
    font-size: 3rem; color: var(--primary); margin-bottom: 1rem;
    opacity: 0.6;
}
.am-empty-state h3 {
    color: var(--primary-dark); font-size: 1.2rem; margin: 0 0 0.4rem;
}
.am-empty-state p { margin: 0; font-size: 0.95rem; }

@media (max-width: 720px) {
    .amenities-hero { padding: 3rem 1.2rem 4.5rem; }
    .amenities-hero h1 { font-size: 1.85rem; }
    .am-branch-block { padding: 1.6rem 1.3rem 1.8rem; }
    .am-branch-title { flex-wrap: wrap; }
    .am-branch-title .am-branch-count { margin-left: 0; margin-top: 6px; }
    .am-tile-grid { grid-template-columns: 1fr 1fr; gap: 0.9rem; }
    .am-tile { padding: 1.1rem 1rem 1.2rem; }
}
@media (max-width: 460px) {
    .am-tile-grid { grid-template-columns: 1fr; }
}
</style>

<!-- Hero band -->
<header class="amenities-hero">
    <h1><i></i> Resort Amenities</h1>
    <p>Everything our guests can enjoy across our branches — from pools and courts to in-room essentials. Availability is updated in real time by branch managers.</p>
    <?php if ($total_count > 0): ?>
    <div class="am-stats">
        <span class="am-stat"><i></i> <?= $total_count ?> Total</span>
        <span class="am-stat"><i></i> <?= $avail_count ?> Available</span>
        <?php if ($unavail_count > 0): ?>
            <span class="am-stat"><i></i> <?= $unavail_count ?> Currently Unavailable</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</header>

<div class="am-page-container">
    <?php if (empty($amenitiesByBranch)): ?>
        <div class="am-empty-state">
            <i class="fas fa-umbrella-beach"></i>
            <h3>No amenities listed yet</h3>
            <p>Our team is still setting up. Please check back soon!</p>
        </div>
    <?php else: ?>
        <?php foreach ($amenitiesByBranch as $branch): ?>
            <section class="am-branch-block">
                <div class="am-branch-title">
                    <div class="am-branch-badge">
                        <i class="fas fa-water"></i>
                    </div>
                    <div class="am-branch-info">
                        <h2><?= htmlspecialchars($branch['branch_name']) ?></h2>
                        <?php if (!empty($branch['location'])): ?>
                            <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($branch['location']) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="am-branch-count">
                        <?= count($branch['items']) ?> amenit<?= count($branch['items']) !== 1 ? 'ies' : 'y' ?>
                    </span>
                </div>

                <div class="am-tile-grid">
                    <?php foreach ($branch['items'] as $a):
                        $isAvail = ($a['availability'] === 'Available');
                        $iconClass = amenity_icon($a['amenity_name']);
                    ?>
                        <article class="am-tile <?= $isAvail ? '' : 'is-unavail' ?>">
                            <div class="am-tile-head">
                                <div class="am-tile-icon">
                                    <i class="fas <?= $iconClass ?>"></i>
                                </div>
                                <span class="am-tile-status <?= $isAvail ? 's-on' : 's-off' ?>">
                                    <i class="fas <?= $isAvail ? 'fa-check-circle' : 'fa-tools' ?>"></i>
                                    <?= $isAvail ? 'Available' : 'Unavailable' ?>
                                </span>
                            </div>
                            <h3><?= htmlspecialchars($a['amenity_name']) ?></h3>
                            <?php if (!empty($a['description'])): ?>
                                <p><?= htmlspecialchars($a['description']) ?></p>
                            <?php else: ?>
                                <p class="am-tile-empty">
                                    <?= $isAvail
                                        ? 'Available for all guests during your stay.'
                                        : 'Currently undergoing maintenance.' ?>
                                </p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>