<?php
/**
 * print_receipt.php
 * Generates a printable / PDF-saveable booking receipt for confirmed reservations.
 * Opens in a new tab and auto-triggers the browser print dialog so the user
 * can Save as PDF or send to a physical printer.
 *
 * Usage:  print_receipt.php?id=<reservation_id>
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// We only need the DB connection and session — NOT the full page chrome.
// If your project bootstraps $pdo inside header.php you can swap the line below
// to:  require_once 'db.php';  (or whatever your connection file is called).
require_once 'db.php';           // ← adjust path/filename if needed

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:2rem;">Please <a href="login.php">log in</a> to access your receipt.</p>';
    exit;
}

$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($reservation_id <= 0) {
    http_response_code(400);
    echo '<p style="font-family:sans-serif;padding:2rem;">Invalid reservation ID.</p>';
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// ── Resolve customer_id for this user ─────────────────────────────────────────
$ustmt = $pdo->prepare("SELECT customer_id FROM users WHERE user_id = ?");
$ustmt->execute([$user_id]);
$userRow = $ustmt->fetch(PDO::FETCH_ASSOC);

if (!$userRow || empty($userRow['customer_id'])) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:2rem;">No customer profile linked to your account.</p>';
    exit;
}

$customer_id = (int)$userRow['customer_id'];

// ── Fetch reservation (must belong to this customer) ──────────────────────────
// ── Fetch reservation (must belong to this customer) ──────────────────────────
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
        b.location       AS branch_location,
        c.full_name      AS customer_name,
        c.email          AS customer_email,
        c.contact_number AS customer_phone  /* <-- Changed this line */
    FROM reservations r
    JOIN branches  b ON r.branch_id  = b.branch_id
    JOIN customers c ON r.customer_id = c.customer_id
    WHERE r.reservation_id = ?
      AND r.customer_id    = ?
    LIMIT 1
");
$stmt->execute([$reservation_id, $customer_id]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Guards ────────────────────────────────────────────────────────────────────
if (!$res) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;padding:2rem;">Reservation not found.</p>';
    exit;
}

if (strtolower(trim($res['status'])) !== 'confirmed') {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:2rem;">Receipts are only available for <strong>Confirmed</strong> reservations.</p>';
    exit;
}

// ── Format helpers ────────────────────────────────────────────────────────────
$fmtDate   = fn($d) => $d ? date('F j, Y', strtotime($d)) : '—';
$fmtMoney  = fn($v) => '₱' . number_format((float)$v, 2);
$receiptNo = 'RES-' . str_pad($res['reservation_id'], 6, '0', STR_PAD_LEFT);
$printedAt = date('F j, Y \a\t g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Receipt – <?= htmlspecialchars($receiptNo) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ── Reset & base ─────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 14px; }
body {
    font-family: 'Poppins', sans-serif;
    background: #eef2f7;
    color: #1a1a2e;
    min-height: 100vh;
    padding: 2rem 1rem 4rem;
}

/* ── Wrapper ──────────────────────────────────────────────────────────────── */
.receipt-wrapper {
    max-width: 760px;
    margin: 0 auto;
}

/* ── Action bar (hidden when printing) ───────────────────────────────────── */
.action-bar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.action-bar p {
    font-size: 0.82rem;
    color: #666;
    margin-right: auto;
}
.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 22px;
    border-radius: 50px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: .25s;
}
.btn-primary {
    background: linear-gradient(135deg, #1a3a6e, #0077b6);
    color: white;
    box-shadow: 0 4px 14px rgba(0,119,182,.28);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 7px 20px rgba(0,119,182,.38); }
.btn-secondary {
    background: white;
    color: #1a3a6e;
    border: 1.5px solid #c5d4e8;
}
.btn-secondary:hover { background: #f0f6ff; }

/* ── Receipt card ─────────────────────────────────────────────────────────── */
.receipt {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 8px 40px rgba(0,0,0,0.10);
}

/* ── Header band ──────────────────────────────────────────────────────────── */
.receipt-header {
    background: linear-gradient(135deg, #1a3a6e 0%, #0077b6 60%, #00b4d8 100%);
    padding: 2.5rem 2.5rem 2rem;
    color: white;
    position: relative;
    overflow: hidden;
}
.receipt-header::after {
    content: '';
    position: absolute;
    right: -60px;
    top: -60px;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    background: rgba(255,255,255,0.07);
    pointer-events: none;
}
.receipt-header::before {
    content: '';
    position: absolute;
    right: 60px;
    bottom: -80px;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    pointer-events: none;
}
.header-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    position: relative;
    z-index: 1;
}
.brand-block .brand-name {
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: -0.3px;
    line-height: 1;
}
.brand-block .brand-sub {
    font-size: 0.78rem;
    opacity: 0.75;
    font-weight: 500;
    margin-top: 3px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}
.confirmed-stamp {
    display: flex;
    flex-direction: column;
    align-items: center;
    background: rgba(255,255,255,0.15);
    border: 1.5px solid rgba(255,255,255,0.4);
    border-radius: 12px;
    padding: 10px 18px;
    text-align: center;
    backdrop-filter: blur(4px);
}
.confirmed-stamp .stamp-label {
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    opacity: 0.8;
}
.confirmed-stamp .stamp-value {
    font-size: 1.05rem;
    font-weight: 800;
    letter-spacing: 0.5px;
    margin-top: 2px;
}
.confirmed-stamp .stamp-icon {
    font-size: 1.4rem;
    margin-bottom: 4px;
}
.header-bottom {
    margin-top: 1.8rem;
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.receipt-title {
    font-size: 1.9rem;
    font-weight: 800;
    letter-spacing: -0.5px;
    line-height: 1.1;
}
.receipt-title span { opacity: 0.6; font-weight: 500; font-size: 1rem; display: block; margin-bottom: 3px; font-style: normal; }
.receipt-meta {
    text-align: right;
    font-size: 0.8rem;
    opacity: 0.82;
    line-height: 1.7;
}

/* ── Divider with notches ─────────────────────────────────────────────────── */
.notch-divider {
    position: relative;
    height: 32px;
    background: white;
    overflow: visible;
    z-index: 1;
}
.notch-divider::before,
.notch-divider::after {
    content: '';
    position: absolute;
    top: -16px;
    width: 32px;
    height: 32px;
    background: #eef2f7;
    border-radius: 50%;
}
.notch-divider::before { left: -16px; }
.notch-divider::after  { right: -16px; }
.notch-line {
    position: absolute;
    top: 50%;
    left: 16px;
    right: 16px;
    border-top: 2px dashed #d8e4f0;
    transform: translateY(-50%);
}

/* ── Body ─────────────────────────────────────────────────────────────────── */
.receipt-body {
    padding: 0 2.5rem 2.5rem;
}

/* ── Section layout ───────────────────────────────────────────────────────── */
.section {
    margin-top: 1.8rem;
}
.section-title {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #0077b6;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-title::after {
    content: '';
    flex: 1;
    height: 1.5px;
    background: linear-gradient(to right, #d0e8f5, transparent);
    border-radius: 2px;
}

/* ── Info grid ────────────────────────────────────────────────────────────── */
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.9rem 2rem;
}
.info-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
.info-item .label {
    font-size: 0.72rem;
    color: #8a9ab5;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-bottom: 3px;
}
.info-item .value {
    font-size: 0.92rem;
    font-weight: 600;
    color: #1a2a4a;
    line-height: 1.4;
}
.info-item .value.mono {
    font-family: 'Courier New', monospace;
    font-size: 0.88rem;
    color: #0077b6;
    font-weight: 700;
}

/* ── Notes box ────────────────────────────────────────────────────────────── */
.notes-box {
    background: #f8faff;
    border-left: 3px solid #0077b6;
    border-radius: 0 8px 8px 0;
    padding: 10px 14px;
    font-size: 0.88rem;
    color: #4a5568;
    font-style: italic;
    line-height: 1.6;
}

/* ── Amount summary ───────────────────────────────────────────────────────── */
.amount-summary {
    background: linear-gradient(135deg, #f0f6ff, #e8f4fc);
    border: 1.5px solid #c5dff0;
    border-radius: 14px;
    padding: 1.5rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 1.8rem;
}
.amount-label {
    font-size: 0.78rem;
    color: #5a7a9a;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.amount-value {
    font-size: 2.2rem;
    font-weight: 800;
    color: #1a3a6e;
    line-height: 1;
    margin-top: 4px;
}
.payment-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: white;
    border: 1.5px solid #c5dff0;
    border-radius: 50px;
    padding: 8px 18px;
    font-size: 0.82rem;
    font-weight: 700;
    color: #1a3a6e;
}
.payment-badge.paid   { background: #e8f5e9; border-color: #a5d6a7; color: #2e7d32; }
.payment-badge.unpaid { background: #fff8e1; border-color: #ffe082; color: #e65100; }
.payment-badge .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}

/* ── Receipt footer ───────────────────────────────────────────────────────── */
.receipt-footer {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1.5px dashed #dce8f2;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.receipt-footer .footer-left {
    font-size: 0.78rem;
    color: #8a9ab5;
    line-height: 1.7;
}
.receipt-footer .footer-left strong { color: #1a3a6e; }
.thankyou {
    font-size: 0.85rem;
    font-weight: 700;
    color: #0077b6;
    text-align: right;
}
.thankyou span { display: block; font-weight: 400; font-size: 0.75rem; color: #8a9ab5; margin-top: 2px; }

/* ── Print styles ─────────────────────────────────────────────────────────── */
@media print {
    html { font-size: 12px; }
    body { background: white !important; padding: 0; margin: 0; }
    .action-bar { display: none !important; }
    .receipt {
        border-radius: 0;
        box-shadow: none;
    }
    .receipt-wrapper { max-width: 100%; }
    .notch-divider::before,
    .notch-divider::after { background: white; }
    @page {
        size: A4;
        margin: 12mm 14mm;
    }
}

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 560px) {
    .receipt-header  { padding: 1.8rem 1.5rem 1.5rem; }
    .receipt-body    { padding: 0 1.5rem 1.8rem; }
    .info-grid       { grid-template-columns: 1fr; }
    .info-grid.cols-3{ grid-template-columns: 1fr 1fr; }
    .amount-summary  { padding: 1.2rem; }
    .amount-value    { font-size: 1.8rem; }
    .action-bar      { flex-direction: column; align-items: stretch; }
    .btn-action      { justify-content: center; }
}
</style>
</head>
<body>

<div class="receipt-wrapper">

    <!-- Action bar -->
    <div class="action-bar">
        <p>📄 Save this page as <strong>PDF</strong> using your browser's print dialog.</p>
        <a href="reservations.php" class="btn-action btn-secondary">← Back</a>
        <button onclick="window.print()" class="btn-action btn-primary">🖨️ Print / Save PDF</button>
    </div>

    <!-- Receipt card -->
    <div class="receipt">

        <!-- Header -->
        <div class="receipt-header">
            <div class="header-top">
                <div class="brand-block">
                    <div class="brand-name">🌊 Resort Booking</div>
                    <div class="brand-sub">Official Booking Receipt</div>
                </div>
                <div class="confirmed-stamp">
                    <div class="stamp-icon">✅</div>
                    <div class="stamp-label">Status</div>
                    <div class="stamp-value">Confirmed</div>
                </div>
            </div>
            <div class="header-bottom">
                <div class="receipt-title">
                    <span>Booking Receipt</span>
                    #<?= htmlspecialchars($receiptNo) ?>
                </div>
                <div class="receipt-meta">
                    Issued: <?= htmlspecialchars($printedAt) ?><br>
                    Branch: <?= htmlspecialchars($res['branch_name']) ?>
                </div>
            </div>
        </div>

        <!-- Notch divider -->
        <div class="notch-divider"><div class="notch-line"></div></div>

        <!-- Body -->
        <div class="receipt-body">

            <!-- Customer Info -->
            <div class="section">
                <div class="section-title">Customer Information</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="label">Full Name</div>
                        <div class="value"><?= htmlspecialchars($res['customer_name']) ?></div>
                    </div>
                    <?php if (!empty($res['customer_email'])): ?>
                    <div class="info-item">
                        <div class="label">Email Address</div>
                        <div class="value"><?= htmlspecialchars($res['customer_email']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($res['customer_phone'])): ?>
                    <div class="info-item">
                        <div class="label">Phone Number</div>
                        <div class="value"><?= htmlspecialchars($res['customer_phone']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="label">Reservation ID</div>
                        <div class="value mono"><?= htmlspecialchars($receiptNo) ?></div>
                    </div>
                </div>
            </div>

            <!-- Booking Details -->
            <div class="section">
                <div class="section-title">Booking Details</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="label">Resort / Branch</div>
                        <div class="value"><?= htmlspecialchars($res['branch_name']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Location</div>
                        <div class="value"><?= htmlspecialchars($res['branch_location']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Package / Type</div>
                        <div class="value"><?= htmlspecialchars($res['reservation_type']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Booking Date</div>
                        <div class="value"><?= $fmtDate($res['created_at']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Check-in Date</div>
                        <div class="value"><?= $fmtDate($res['reservation_date']) ?></div>
                    </div>
                    <?php if (!empty($res['check_out_date'])): ?>
                    <div class="info-item">
                        <div class="label">Check-out Date</div>
                        <div class="value"><?= $fmtDate($res['check_out_date']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($res['notes'])): ?>
            <!-- Special Requests -->
            <div class="section">
                <div class="section-title">Special Requests / Notes</div>
                <div class="notes-box"><?= nl2br(htmlspecialchars($res['notes'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- Payment Summary -->
            <div class="amount-summary">
                <div>
                    <div class="amount-label">Total Amount Due</div>
                    <div class="amount-value"><?= $fmtMoney($res['total_amount']) ?></div>
                </div>
                <?php
                    $ps    = strtolower(trim($res['payment_status']));
                    $pClass = ($ps === 'paid') ? 'paid' : (($ps === 'unpaid' || $ps === 'pending') ? 'unpaid' : '');
                    $pLabel = ucfirst($res['payment_status']);
                ?>
                <div class="payment-badge <?= $pClass ?>">
                    <span class="dot"></span>
                    <?= htmlspecialchars($pLabel) ?>
                </div>
            </div>

            <!-- Receipt footer -->
            <div class="receipt-footer">
                <div class="footer-left">
                    <strong>Reservation Status:</strong> Confirmed<br>
                    <strong>Printed on:</strong> <?= htmlspecialchars($printedAt) ?><br>
                    This is an official booking confirmation receipt.
                </div>
                <div class="thankyou">
                    Thank you for choosing us! 🌊
                    <span>We look forward to your visit.</span>
                </div>
            </div>

        </div><!-- /.receipt-body -->
    </div><!-- /.receipt -->

</div><!-- /.receipt-wrapper -->

<script>
// Auto-open print dialog after fonts load
window.addEventListener('load', function () {
    // Small delay to let fonts & layout settle before print dialog
    setTimeout(() => window.print(), 600);
});
</script>

</body>
</html>