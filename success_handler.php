<?php
// ─── success_handler.php ──────────────────────────────────────────────────────
// PayMongo redirects the user here after a completed checkout session.
//
// IMPORTANT: The redirect alone is NOT proof of payment — a user could navigate
// here manually. We therefore call PayMongo's API to confirm the session status
// before writing anything to the database.
//
// Flow:
//   1. Read paymongo_session_id and booking_intent from $_SESSION.
//   2. Fetch the session object from PayMongo → check payment_status.
//   3. If paid → INSERT reservation as 'Pending' (awaiting admin approval).
//   4. Clear booking session data and redirect to a confirmation page.
// ─────────────────────────────────────────────────────────────────────────────
require_once 'db.php';
require_once 'config.php';
include    'header.php';

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location='login.php';</script>";
    exit;
}

$sessionId = $_SESSION['paymongo_session_id']  ?? null;
$intent    = $_SESSION['booking_intent']        ?? null;

// ── Guard: must arrive with session context ───────────────────────────────────
if (!$sessionId || !$intent) {
    echo '<div class="container" style="padding:60px 20px; color:white; text-align:center;">
            <h2>No booking in progress.</h2>
            <p><a href="book.php" style="color:#ffffffcc;">← Back to booking</a></p>
          </div>';
    include 'footer.php';
    exit;
}

// ── Verify payment status with PayMongo (with retry) ─────────────────────────
// PayMongo redirects to success_url almost instantly after the user pays, but
// its own API may not have flipped payment_status to 'paid' yet. We retry up
// to 4 times with a 2-second pause between each attempt before giving up.
// ─────────────────────────────────────────────────────────────────────────────
$verifyUrl     = "https://api.paymongo.com/v1/checkout_sessions/" . urlencode($sessionId);
$maxAttempts   = 4;
$paymentStatus = null;
$curlError     = null;
$json          = null;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $ch = curl_init($verifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
    ]);
    $result    = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $json          = json_decode($result, true);
    $paymentStatus = $json['data']['attributes']['payment_status'] ?? null;

    // Also accept a non-empty payments array as proof — PayMongo sometimes
    // populates 'payments' before it updates 'payment_status'.
    $hasPayments = !empty($json['data']['attributes']['payments']);

    if ($paymentStatus === 'paid' || $hasPayments) {
        $paymentStatus = 'paid'; // normalise
        break;
    }

    // Not confirmed yet — wait before retrying (skip sleep on last attempt)
    if ($attempt < $maxAttempts) sleep(2);
}

// ── Only proceed if PayMongo confirms the payment ─────────────────────────────
if ($paymentStatus !== 'paid') {
    // Still not confirmed after all retries — do NOT wipe the session yet so
    // the user can refresh and try again without losing their booking intent.
    $debugHint = $curlError
        ? "cURL error: " . htmlspecialchars($curlError)
        : "Last status returned: " . htmlspecialchars($paymentStatus ?? 'null');

    echo '<div class="container" style="padding:60px 20px; color:white; text-align:center;">
            <h2 style="color:#e57373;">Payment not verified yet.</h2>
            <p>Your payment may still be processing. Please wait a moment and
               <a href="success_handler.php" style="color:#fff; font-weight:700;">refresh this page</a>
               to check again.</p>
            <p style="font-size:0.8rem; opacity:0.6; margin-top:16px;">' . $debugHint . '</p>
            <p style="margin-top:20px;"><a href="book.php" style="color:#ffffffcc;">← Back to booking</a></p>
          </div>';
    include 'footer.php';
    exit;
}

// ── Payment confirmed — insert the reservation ────────────────────────────────
// Check one last time for a race condition (another user may have confirmed
// the same slot between the time this user started paying and now).
$raceStmt = $pdo->prepare("
    SELECT COUNT(*) FROM reservations
    WHERE  branch_id        = ?
      AND  reservation_date = ?
      AND  reservation_type = ?
      AND  status IN ('Confirmed', 'Pending')
");
$raceStmt->execute([
    $intent['branch_id'],
    $intent['reservation_date'],
    $intent['reservation_type'],
]);
$slotTaken = (int) $raceStmt->fetchColumn() > 0;

if ($slotTaken) {
    // Extremely rare race condition — slot was confirmed by someone else.
    // The user paid but we cannot honour the booking for this slot.
    // In production you would trigger a refund here via PayMongo's Refunds API.
    unset($_SESSION['booking_intent'], $_SESSION['paymongo_session_id']);

    echo '<div class="container" style="padding:60px 20px; color:white; text-align:center;">
            <h2 style="color:#e57373;">Slot no longer available.</h2>
            <p>Someone else confirmed this slot while your payment was processing.
               Please contact us — your payment will be refunded.</p>
            <p><a href="book.php" style="color:#ffffffcc;">← Choose another date</a></p>
          </div>';
    include 'footer.php';
    exit;
}

// All clear — write the reservation.
// payment_status must be 'Paid' so the admin dashboard does not filter it out.
// (The dashboard hides rows where status='Pending' AND payment_status='Unpaid'.
$stmt = $pdo->prepare("
    INSERT INTO reservations
        (customer_id, branch_id, reservation_date, reservation_type, total_amount, status, payment_status)
    VALUES (?, ?, ?, ?, ?, 'Pending', 'Paid')
");
$stmt->execute([
    $intent['customer_id'],
    $intent['branch_id'],
    $intent['reservation_date'],
    $intent['reservation_type'],
    $intent['total_amount'],
]);
$newReservationId = $pdo->lastInsertId();

// Clear booking session data — no longer needed
unset($_SESSION['booking_intent'], $_SESSION['paymongo_session_id']);

$tourTypeLabel = ($intent['reservation_type'] === 'Day') ? 'Day Tour' : 'Overnight Stay';
$tourIcon      = ($intent['reservation_type'] === 'Day') ? 'fa-sun' : 'fa-moon';
$formattedDate = date('F j, Y', strtotime($intent['reservation_date']));
$formattedAmt  = '₱' . number_format($intent['total_amount'], 2);
?>

<style>
/* ── Success page styles ────────────────────────────────────────────────────── */
.success-wrapper {
    min-height: calc(100vh - 80px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
}

.success-card {
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-radius: 24px;
    box-shadow: 0 24px 64px rgba(0, 30, 80, 0.22);
    max-width: 560px;
    width: 100%;
    overflow: hidden;
    animation: successSlideUp 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes successSlideUp {
    from { opacity: 0; transform: translateY(40px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Card header band ── */
.success-header {
    background: linear-gradient(135deg, #1a3a6e 0%, #0077b6 100%);
    padding: 40px 40px 36px;
    text-align: center;
    position: relative;
}

.success-icon-ring {
    width: 84px;
    height: 84px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    border: 3px solid rgba(255,255,255,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    animation: popIn 0.5s 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}

@keyframes popIn {
    from { opacity: 0; transform: scale(0.5); }
    to   { opacity: 1; transform: scale(1); }
}

.success-icon-ring i {
    font-size: 2.4rem;
    color: #ffffff;
}

.success-header h1 {
    color: #ffffff;
    font-size: 1.75rem;
    font-weight: 800;
    margin: 0 0 8px;
    letter-spacing: -0.3px;
    text-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.success-header p {
    color: rgba(255,255,255,0.82);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.5;
}

/* ── Summary rows ── */
.success-body {
    padding: 32px 40px;
}

.summary-title {
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #0077b6;
    margin: 0 0 16px;
}

.summary-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid #f0f4f8;
}

.summary-row:last-of-type { border-bottom: none; }

.summary-row-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #e8f4fd;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.summary-row-icon i {
    color: #0077b6;
    font-size: 0.95rem;
}

.summary-row-label {
    font-size: 0.78rem;
    color: #8fa3b1;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}

.summary-row-value {
    font-size: 1rem;
    font-weight: 700;
    color: #1a2d44;
}

/* ── Status badge ── */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fff7e6;
    color: #e07b00;
    border: 1.5px solid #ffd280;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 0.82rem;
    font-weight: 700;
}

.status-badge i { font-size: 0.75rem; }

/* ── What's next steps ── */
.next-steps {
    background: #f4f8fd;
    border-radius: 14px;
    padding: 20px 22px;
    margin-top: 8px;
}

.next-steps-title {
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #0077b6;
    margin: 0 0 14px;
}

.step {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 10px;
}

.step:last-child { margin-bottom: 0; }

.step-num {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #0077b6;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 1px;
}

.step-text {
    font-size: 0.875rem;
    color: #3d5166;
    line-height: 1.45;
}

/* ── Action buttons ── */
.success-actions {
    padding: 0 40px 36px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-primary-action {
    flex: 1;
    background: linear-gradient(135deg, #1a3a6e 0%, #0077b6 100%);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 14px 20px;
    font-size: 0.9rem;
    font-weight: 700;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-primary-action:hover { opacity: 0.88; transform: translateY(-1px); }

.btn-secondary-action {
    flex: 1;
    background: transparent;
    color: #0077b6;
    border: 2px solid #0077b6;
    border-radius: 12px;
    padding: 13px 20px;
    font-size: 0.9rem;
    font-weight: 700;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s, color 0.2s, transform 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-secondary-action:hover {
    background: #e8f4fd;
    transform: translateY(-1px);
}

@media (max-width: 480px) {
    .success-header, .success-body { padding-left: 24px; padding-right: 24px; }
    .success-actions { flex-direction: column; padding-left: 24px; padding-right: 24px; }
}
</style>

<div class="success-wrapper">
    <div class="success-card">

        <!-- Header -->
        <div class="success-header">
            <div class="success-icon-ring">
                <i class="fas fa-check"></i>
            </div>
            <h1>Booking Received!</h1>
            <p>Your reservation is now <strong style="color:#fff;">pending admin approval</strong>.<br>
               You'll be notified once it's confirmed.</p>
        </div>

        <!-- Booking Summary -->
        <div class="success-body">
            <div class="summary-title">Booking Summary</div>

            <div class="summary-row">
                <div class="summary-row-icon"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <div class="summary-row-label">Check-in Date</div>
                    <div class="summary-row-value"><?= htmlspecialchars($formattedDate) ?></div>
                </div>
            </div>

            <div class="summary-row">
                <div class="summary-row-icon"><i class="fas <?= $tourIcon ?>"></i></div>
                <div>
                    <div class="summary-row-label">Tour Type</div>
                    <div class="summary-row-value"><?= htmlspecialchars($tourTypeLabel) ?></div>
                </div>
            </div>

            <div class="summary-row">
                <div class="summary-row-icon"><i class="fas fa-peso-sign"></i></div>
                <div>
                    <div class="summary-row-label">Amount Paid</div>
                    <div class="summary-row-value"><?= $formattedAmt ?></div>
                </div>
            </div>

            <div class="summary-row">
                <div class="summary-row-icon"><i class="fas fa-circle-dot"></i></div>
                <div>
                    <div class="summary-row-label">Status</div>
                    <div class="summary-row-value">
                        <span class="status-badge">
                            <i class="fas fa-clock"></i> Pending Approval
                        </span>
                    </div>
                </div>
            </div>

            <!-- What's Next -->
            <div class="next-steps">
                <div class="next-steps-title">What happens next?</div>
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-text">Our admin will review your reservation and confirm it within 24 hours.</div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-text">You can track your booking status anytime in <strong>My Reservations</strong>.</div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-text">Arrive on your check-in date and enjoy your stay!</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="success-actions">
            <a href="reservations.php" class="btn-primary-action">
                <i class="fas fa-list-check"></i> My Reservations
            </a>
            <a href="book.php" class="btn-secondary-action">
                <i class="fas fa-plus"></i> Book Another
            </a>
        </div>

    </div>
</div>

<?php include 'footer.php'; ?>