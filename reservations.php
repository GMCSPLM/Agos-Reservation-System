<?php
include 'header.php';

// Require login
if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Please log in to view your reservations.'); window.location='login.php?redirect=reservations';</script>";
    exit;
}

$user_id = $_SESSION['user_id'];

// Get the customer_id linked to this user account
$ustmt = $pdo->prepare("SELECT customer_id FROM users WHERE user_id = ?");
$ustmt->execute([$user_id]);
$userRow = $ustmt->fetch(PDO::FETCH_ASSOC);

if (!$userRow || !$userRow['customer_id']) {
    // Logged in but no customer profile linked
    $reservations = [];
    $customer = ['full_name' => 'Unknown'];
    $upcoming = $past = [];
    $totalCount = $upcomingCount = 0;
    $totalSpent = 0;
} else {
    $customer_id = $userRow['customer_id'];

    // Fetch reservations
    $stmt = $pdo->prepare("
        SELECT
            r.reservation_id,
            r.reservation_date,
            r.check_out_date,
            r.reservation_type,
            r.total_amount,
            r.payment_status,
            r.notes,
            r.status,
            r.created_at,
            b.branch_name,
            b.location
        FROM reservations r
        JOIN branches b ON r.branch_id = b.branch_id
        WHERE r.customer_id = ?
        ORDER BY r.reservation_date DESC
    ");
    $stmt->execute([$customer_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch customer name
    $cstmt = $pdo->prepare("SELECT full_name FROM customers WHERE customer_id = ?");
    $cstmt->execute([$customer_id]);
    $customer = $cstmt->fetch(PDO::FETCH_ASSOC);
}

    $upcoming = array_filter($reservations, fn($r) => strtotime($r['reservation_date']) >= strtotime('today') && strtolower($r['status']) !== 'cancelled');
    $past     = array_filter($reservations, fn($r) => strtotime($r['reservation_date']) <  strtotime('today'));

    $totalCount    = count($reservations);
    $upcomingCount = count($upcoming);
    $totalSpent    = array_sum(array_column(array_filter($reservations, fn($r) => strtolower($r['status']) !== 'cancelled'), 'total_amount'));

// Always ensure these vars exist
if (!isset($upcoming))     $upcoming     = [];
if (!isset($past))         $past         = [];
if (!isset($totalCount))   $totalCount   = 0;
if (!isset($upcomingCount)) $upcomingCount = 0;
if (!isset($totalSpent))   $totalSpent   = 0;
?>

<style>
.reservations-page {
    min-height: 80vh;
    padding: 3rem 0 5rem;
    background: linear-gradient(rgba(255,255,255,0.3), rgba(255,255,255,0.3)), url('Ripple-Effect.png');
    background-size: cover;
    background-position: center;
    background-attachment: scroll;
    font-family: 'Poppins', sans-serif;
}
.page-hero {
    background: linear-gradient(135deg, #1a3a6e 0%, #0077b6 100%);
    color: white;
    padding: 3rem 2rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.page-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url('Ripple-Effect.png') center/cover no-repeat;
    opacity: 0.08;
}
.page-hero-content { position: relative; z-index: 1; }
.page-hero h1 { font-size: clamp(1.8rem, 4vw, 2.6rem); font-weight: 800; margin-bottom: 0.5rem; }
.page-hero p { font-size: 1.05rem; opacity: 0.88; }
.customer-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    padding: 8px 20px;
    border-radius: 50px;
    font-size: 0.95rem;
    font-weight: 600;
    margin-top: 1rem;
}
.summary-strip {
    max-width: 860px;
    margin: 1.5rem auto 0;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    padding: 0 1rem;
}
.summary-card {
    background: white;
    border-radius: 12px;
    padding: 1.2rem 1.5rem;
    text-align: center;
    box-shadow: 0 3px 12px rgba(0,0,0,0.06);
    border: 1px solid #e5eaf3;
}
.summary-card .s-value { font-size: 2rem; font-weight: 800; color: #0077b6; display: block; }
.summary-card .s-label { font-size: 0.8rem; color: #888; font-weight: 500; margin-top: 4px; display: block; }
.tab-bar {
    display: flex;
    max-width: 860px;
    margin: 2rem auto 0;
    background: white;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0,0,0,0.08);
    overflow: hidden;
    border: 1px solid #e5eaf3;
}
.tab-btn {
    flex: 1;
    padding: 1rem;
    border: none;
    background: transparent;
    font-family: 'Poppins', sans-serif;
    font-size: 0.95rem;
    font-weight: 600;
    color: #888;
    cursor: pointer;
    transition: 0.25s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-bottom: 3px solid transparent;
}
.tab-btn:hover { background: #f0f6ff; color: #0077b6; }
.tab-btn.active { color: #0077b6; background: #f0f6ff; border-bottom-color: #0077b6; }
.tab-count {
    background: #0077b6;
    color: white;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    min-width: 22px;
    text-align: center;
}
.tab-btn:not(.active) .tab-count { background: #ccc; }
.tab-content { display: none; }
.tab-content.active { display: block; }
.reservations-grid {
    max-width: 860px;
    margin: 1.8rem auto 0;
    display: flex;
    flex-direction: column;
    gap: 1.2rem;
    padding: 0 1rem;
}
.res-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 3px 16px rgba(0,0,0,0.07);
    overflow: hidden;
    display: flex;
    border: 1px solid #e5eaf3;
    transition: transform 0.25s, box-shadow 0.25s;
    animation: cardIn 0.4s ease both;
}
.res-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,119,182,0.13); }
@keyframes cardIn {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}
.res-card-img { width: 160px; min-height: 160px; object-fit: cover; flex-shrink: 0; }
.res-card-body { flex: 1; padding: 1.3rem 1.5rem; display: flex; flex-direction: column; gap: 0.35rem; }
.res-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
.res-branch { font-size: 1.15rem; font-weight: 700; color: #1a3a6e; }
.res-location { font-size: 0.82rem; color: #888; display: flex; align-items: center; gap: 5px; margin-top: 2px; }
.res-meta { display: flex; flex-wrap: wrap; gap: 1.2rem; margin-top: 0.6rem; }
.res-meta-item { display: flex; align-items: center; gap: 7px; font-size: 0.88rem; color: #444; font-weight: 500; }
.res-meta-item i { color: #0077b6; width: 16px; text-align: center; }
.res-requests {
    background: #f8faff;
    border-left: 3px solid #0077b6;
    border-radius: 0 6px 6px 0;
    padding: 6px 12px;
    font-size: 0.82rem;
    color: #555;
    margin-top: 4px;
    font-style: italic;
}
.res-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: auto;
    padding-top: 0.8rem;
    border-top: 1px solid #f0f4f8;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.res-total { font-size: 1.15rem; font-weight: 700; color: #1a3a6e; }
.res-total span { font-size: 0.8rem; font-weight: 500; color: #888; display: block; }
.res-id { font-size: 0.78rem; color: #aaa; font-weight: 500; }
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 14px;
    border-radius: 50px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    white-space: nowrap;
}
.status-badge::before {
    content: '';
    width: 7px; height: 7px;
    border-radius: 50%;
    background: currentColor;
    display: inline-block;
    animation: pulse 1.8s infinite;
}
.status-confirmed  { background: #e8f5e9; color: #2e7d32; }
.status-pending    { background: #fff8e1; color: #e65100; }
.status-cancelled  { background: #ffebee; color: #c62828; }
.status-completed  { background: #e3f2fd; color: #1565c0; }
.status-confirmed::before, .status-cancelled::before, .status-completed::before { animation: none; }
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.4; transform: scale(0.7); }
}
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #aaa;
    max-width: 860px;
    margin: 1.8rem auto 0;
    background: white;
    border-radius: 16px;
    border: 2px dashed #e0e8f4;
}
.empty-state i { font-size: 3.5rem; color: #cdd8ea; display: block; margin-bottom: 1rem; }
.empty-state p { font-size: 1rem; margin-bottom: 1.5rem; }
.btn-book-now {
    display: inline-block;
    background: linear-gradient(135deg, #1a3a6e, #0077b6);
    color: white;
    font-weight: 700;
    font-size: 0.95rem;
    padding: 12px 30px;
    border-radius: 50px;
    text-decoration: none;
    transition: 0.3s;
    box-shadow: 0 4px 14px rgba(0,119,182,0.25);
}
.btn-book-now:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,119,182,0.35); }
.btn-print-receipt {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #1a3a6e, #0077b6);
    color: white;
    font-family: 'Poppins', sans-serif;
    font-weight: 600;
    font-size: 0.82rem;
    padding: 7px 16px;
    border-radius: 50px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: 0.25s;
    box-shadow: 0 3px 10px rgba(0,119,182,0.22);
    white-space: nowrap;
}
.btn-print-receipt:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,119,182,0.35);
    color: white;
    text-decoration: none;
}
.btn-print-receipt i { font-size: 0.85rem; }
/* Tablet and below */
@media (max-width: 768px) {
    .summary-strip  { max-width: 100%; padding: 0 0.75rem; }
    .tab-bar        { margin: 1.5rem 1rem 0; }
    .reservations-grid { padding: 0 0.75rem; }
    .empty-state    { margin: 1.5rem 0.75rem 0; }
}

/* Large phones */
@media (max-width: 640px) {
    .res-card           { flex-direction: column; }
    .res-card-img       { width: 100%; height: 180px; min-height: unset; }
    .res-card-body      { padding: 1rem 1.1rem; }
    .summary-strip      { gap: 0.6rem; }
    .summary-card       { padding: 0.9rem 0.6rem; }
    .summary-card .s-value { font-size: 1.5rem; }
    .tab-btn            { font-size: 0.82rem; padding: 0.85rem 0.5rem; gap: 5px; }
    .res-meta           { gap: 0.7rem; }
    .res-meta-item      { font-size: 0.82rem; }
}

/* Small phones (375px) */
@media (max-width: 420px) {
    .page-hero          { padding: 2rem 1rem; }
    .page-hero h1       { font-size: 1.5rem; }
    .page-hero p        { font-size: 0.9rem; }
    .customer-badge     { font-size: 0.82rem; padding: 7px 14px; }
    .summary-strip      { grid-template-columns: repeat(3, 1fr); gap: 0.4rem; padding: 0 0.5rem; }
    .summary-card       { padding: 0.75rem 0.4rem; }
    .summary-card .s-value { font-size: 1.2rem; }
    .summary-card .s-label { font-size: 0.7rem; }
    .tab-btn            { font-size: 0.75rem; padding: 0.75rem 0.35rem; gap: 4px; }
    .tab-count          { font-size: 0.65rem; padding: 2px 6px; min-width: 18px; }
    .res-branch         { font-size: 1rem; }
    .res-card-body      { padding: 0.9rem; }
    .res-total          { font-size: 1rem; }
    .res-footer         { gap: 0.75rem; }
}

/* Minimum (320px) */
@media (max-width: 330px) {
    .summary-strip      { grid-template-columns: 1fr; }
    .tab-btn            { font-size: 0.7rem; padding: 0.7rem 0.25rem; }
    .res-card-body      { padding: 0.75rem; }
    .res-branch         { font-size: 0.95rem; }
    .btn-print-receipt  { font-size: 0.75rem; padding: 6px 12px; }
}
</style>

<!-- PAGE HERO -->
<div class="page-hero">
    <div class="page-hero-content">
        <h1>My Reservations</h1>
        <p>View and track all your resort bookings in one place.</p>
        <div class="customer-badge">
            <i class="fas fa-user-circle"></i>
            <?= htmlspecialchars($customer['full_name'] ?? 'Guest') ?>
        </div>
    </div>
</div>

<div class="reservations-page">

    <!-- SUMMARY STRIP -->
    <div class="summary-strip">
        <div class="summary-card">
            <span class="s-value"><?= $totalCount ?></span>
            <span class="s-label">Total Bookings</span>
        </div>
        <div class="summary-card">
            <span class="s-value"><?= $upcomingCount ?></span>
            <span class="s-label">Upcoming</span>
        </div>
        <div class="summary-card">
            <span class="s-value">&#8369;<?= number_format($totalSpent, 0) ?></span>
            <span class="s-label">Total Spent</span>
        </div>
    </div>

    <!-- TABS -->
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab('upcoming', this)">
            <i class="fas fa-clock"></i> Upcoming
            <span class="tab-count"><?= count($upcoming) ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab('past', this)">
            <i class="fas fa-history"></i> Past
            <span class="tab-count"><?= count($past) ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab('all', this)">
            <i class="fas fa-list"></i> All
            <span class="tab-count"><?= $totalCount ?></span>
        </button>
    </div>

    <!-- TAB: UPCOMING -->
    <div id="tab-upcoming" class="tab-content active">
        <?php if (empty($upcoming)): ?>
            <div class="empty-state">
                <i class="fas fa-umbrella-beach"></i>
                <p>You have no upcoming reservations yet.</p>
                <a href="index.php#calendar" class="btn-book-now">Book a Date</a>
            </div>
        <?php else: ?>
            <div class="reservations-grid">
            <?php foreach ($upcoming as $res):
                $sc = match(strtolower($res['status'])) {
                    'confirmed' => 'status-confirmed',
                    'cancelled' => 'status-cancelled',
                    'completed' => 'status-completed',
                    default     => 'status-pending',
                };
            ?>
                <div class="res-card">
                    <?php if (!empty($res['image_url'])): ?>
                    <img class="res-card-img" src="<?= htmlspecialchars($res['image_url']) ?>" alt="<?= htmlspecialchars($res['branch_name']) ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="res-card-body">
                        <div class="res-card-top">
                            <div>
                                <div class="res-branch"><?= htmlspecialchars($res['branch_name']) ?></div>
                                <div class="res-location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($res['location']) ?></div>
                            </div>
                            <span class="status-badge <?= $sc ?>"><?= htmlspecialchars($res['status']) ?></span>
                        </div>
                        <div class="res-meta">
                            <div class="res-meta-item"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y', strtotime($res['reservation_date'])) ?></div>
                            <div class="res-meta-item"><i class="fas fa-moon"></i> <?= htmlspecialchars($res['reservation_type']) ?></div>
                            <div class="res-meta-item"><i class="fas fa-credit-card"></i> <?= htmlspecialchars($res['payment_status']) ?></div>
                            <div class="res-meta-item"><i class="fas fa-clock"></i> Booked on <?= date('M j, Y', strtotime($res['created_at'])) ?></div>
                        </div>
                        <?php if (!empty($res['notes'])): ?>
                        <div class="res-requests"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($res['notes']) ?></div>
                        <?php endif; ?>
                        <div class="res-footer">
                            <div class="res-total">&#8369;<?= number_format($res['total_amount'], 2) ?><span>Total Amount</span></div>
                            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                                <div class="res-id">Reservation #RES-<?= (int)$res['reservation_id'] ?></div>
                                <?php if (strtolower($res['status']) === 'confirmed'): ?>
                                <a href="print_receipt.php?id=<?= (int)$res['reservation_id'] ?>" target="_blank" class="btn-print-receipt">
                                    <i class="fas fa-print"></i> Print Receipt
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB: PAST -->
    <div id="tab-past" class="tab-content">
        <?php if (empty($past)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <p>No past reservations found.</p>
            </div>
        <?php else: ?>
            <div class="reservations-grid">
            <?php foreach ($past as $res):
                $sc = match(strtolower($res['status'])) {
                    'confirmed' => 'status-confirmed',
                    'cancelled' => 'status-cancelled',
                    'completed' => 'status-completed',
                    default     => 'status-pending',
                };
            ?>
                <div class="res-card" style="opacity:0.75;">
                    <?php if (!empty($res['image_url'])): ?>
                    <img class="res-card-img" src="<?= htmlspecialchars($res['image_url']) ?>" alt="<?= htmlspecialchars($res['branch_name']) ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="res-card-body">
                        <div class="res-card-top">
                            <div>
                                <div class="res-branch"><?= htmlspecialchars($res['branch_name']) ?></div>
                                <div class="res-location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($res['location']) ?></div>
                            </div>
                            <span class="status-badge <?= $sc ?>"><?= htmlspecialchars($res['status']) ?></span>
                        </div>
                        <div class="res-meta">
                            <div class="res-meta-item"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y', strtotime($res['reservation_date'])) ?></div>
                            <div class="res-meta-item"><i class="fas fa-moon"></i> <?= htmlspecialchars($res['reservation_type']) ?></div>
                            <div class="res-meta-item"><i class="fas fa-credit-card"></i> <?= htmlspecialchars($res['payment_status']) ?></div>
                            <div class="res-meta-item"><i class="fas fa-clock"></i> Booked on <?= date('M j, Y', strtotime($res['created_at'])) ?></div>
                        </div>
                        <?php if (!empty($res['notes'])): ?>
                        <div class="res-requests"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($res['notes']) ?></div>
                        <?php endif; ?>
                        <div class="res-footer">
                            <div class="res-total">&#8369;<?= number_format($res['total_amount'], 2) ?><span>Total Amount</span></div>
                            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                                <div class="res-id">Reservation #RES-<?= (int)$res['reservation_id'] ?></div>
                                <?php if (strtolower($res['status']) === 'confirmed'): ?>
                                <a href="print_receipt.php?id=<?= (int)$res['reservation_id'] ?>" target="_blank" class="btn-print-receipt">
                                    <i class="fas fa-print"></i> Print Receipt
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB: ALL -->
    <div id="tab-all" class="tab-content">
        <?php if (empty($reservations)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <p>You haven't made any reservations yet.</p>
                <a href="index.php#calendar" class="btn-book-now"><i class="fas fa-calendar-plus"></i> Book a Date</a>
            </div>
        <?php else: ?>
            <div class="reservations-grid">
            <?php foreach ($reservations as $res):
                $isPast = strtotime($res['reservation_date']) < strtotime('today');
                $sc = match(strtolower($res['status'])) {
                    'confirmed' => 'status-confirmed',
                    'cancelled' => 'status-cancelled',
                    'completed' => 'status-completed',
                    default     => 'status-pending',
                };
            ?>
                <div class="res-card" <?= $isPast ? 'style="opacity:0.75;"' : '' ?>>
                    <?php if (!empty($res['image_url'])): ?>
                    <img class="res-card-img" src="<?= htmlspecialchars($res['image_url']) ?>" alt="<?= htmlspecialchars($res['branch_name']) ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="res-card-body">
                        <div class="res-card-top">
                            <div>
                                <div class="res-branch"><?= htmlspecialchars($res['branch_name']) ?></div>
                                <div class="res-location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($res['location']) ?></div>
                            </div>
                            <span class="status-badge <?= $sc ?>"><?= htmlspecialchars($res['status']) ?></span>
                        </div>
                        <div class="res-meta">
                            <div class="res-meta-item"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y', strtotime($res['reservation_date'])) ?></div>
                            <div class="res-meta-item"><i class="fas fa-moon"></i> <?= htmlspecialchars($res['reservation_type']) ?></div>
                            <div class="res-meta-item"><i class="fas fa-credit-card"></i> <?= htmlspecialchars($res['payment_status']) ?></div>
                            <div class="res-meta-item"><i class="fas fa-clock"></i> Booked on <?= date('M j, Y', strtotime($res['created_at'])) ?></div>
                        </div>
                        <?php if (!empty($res['notes'])): ?>
                        <div class="res-requests"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($res['notes']) ?></div>
                        <?php endif; ?>
                        <div class="res-footer">
                            <div class="res-total">&#8369;<?= number_format($res['total_amount'], 2) ?><span>Total Amount</span></div>
                            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                                <div class="res-id">Reservation #RES-<?= (int)$res['reservation_id'] ?></div>
                                <?php if (strtolower($res['status']) === 'confirmed'): ?>
                                <a href="print_receipt.php?id=<?= (int)$res['reservation_id'] ?>" target="_blank" class="btn-print-receipt">
                                    <i class="fas fa-print"></i> Print Receipt
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.tab-content').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
}
</script>

<?php include 'footer.php'; ?>