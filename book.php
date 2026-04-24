<?php 
include 'header.php';
if (!isset($_SESSION['user_id'])) echo "<script>window.location='login.php';</script>";

$bookingError = null;

if (isset($_GET['cancelled'])) {
    unset($_SESSION['booking_intent'], $_SESSION['paymongo_session_id']);
    $bookingError = "Your payment was cancelled. The slot is still available — feel free to try again.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postBranchId = isset($_POST['branch_id']) && is_numeric($_POST['branch_id'])
        ? intval($_POST['branch_id']) : null;
    $postDate     = $_POST['check_in'] ?? null;
    $postType     = $_POST['type']     ?? null;

    if ($postBranchId && $postDate && $postType) {
        $guardStmt = $pdo->prepare("
            SELECT COUNT(*) FROM reservations
            WHERE  branch_id        = ?
              AND  reservation_date = ?
              AND  reservation_type = ?
              AND  status IN ('Confirmed', 'Pending')
        ");
        $guardStmt->execute([$postBranchId, $postDate, $postType]);
        $slotTaken = (int) $guardStmt->fetchColumn() > 0;

        if ($slotTaken) {
            $typeLabel    = ($postType === 'Day') ? 'Day Tour' : 'Overnight';
            $bookingError = "Sorry, the <strong>{$typeLabel}</strong> slot on "
                          . htmlspecialchars(date('F j, Y', strtotime($postDate)))
                          . " is no longer available. Please choose a different date or tour type.";
        } else {
            echo '<form id="fwd" action="paymongo_api.php" method="POST" style="display:none">';
            foreach ($_POST as $k => $v) {
                echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
            }
            echo '</form>';
            echo '<script>document.getElementById("fwd").submit();</script>';
            exit;
        }
    } else {
        $bookingError = "Invalid booking data. Please fill in all fields and try again.";
    }
}

$branches          = $pdo->query("SELECT * FROM branches")->fetchAll();
$preselectedBranch = $_GET['branch'] ?? null;
$preselectedDate   = $_GET['date']   ?? null;
$preselectedBranch = (is_numeric($preselectedBranch)) ? intval($preselectedBranch) : null;

$bookedSlots = [];
if ($preselectedBranch && $preselectedDate) {
    $slotStmt = $pdo->prepare("
        SELECT reservation_type
        FROM   reservations
        WHERE  branch_id         = ?
          AND  reservation_date  = ?
          AND  status IN ('Confirmed', 'Pending')
        GROUP BY reservation_type
    ");
    $slotStmt->execute([$preselectedBranch, $preselectedDate]);
    foreach ($slotStmt->fetchAll(PDO::FETCH_COLUMN) as $type) {
        $bookedSlots[$type] = true;
    }
}

$allSlotsTaken = isset($bookedSlots['Day']) && isset($bookedSlots['Overnight']);
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600;700&display=swap');

/* ── Page wrapper: full-height split layout ─────────────────────────────── */
.book-page-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: stretch;
    font-family: 'Poppins', sans-serif;
    background: #f0f4f8;
}

/* ════════════════════════════════════════════════════════════════════════════
   LEFT  —  Decorative hero panel
   ════════════════════════════════════════════════════════════════════════════ */
.book-hero-panel {
    flex: 1.1;
    background: linear-gradient(155deg, #011f4b 0%, #023e8a 48%, #0077b6 100%);
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 60px 56px;
    position: relative;
    overflow: hidden;
    min-height: 100vh;
}

/* Large translucent circle — top-right */
.book-hero-panel::before {
    content: '';
    position: absolute;
    width: 460px; height: 460px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    top: -140px; right: -130px;
    pointer-events: none;
}
/* Small circle — bottom-left */
.book-hero-panel::after {
    content: '';
    position: absolute;
    width: 280px; height: 280px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    bottom: -70px; left: -60px;
    pointer-events: none;
}

.book-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,0.10);
    border: 1px solid rgba(255,255,255,0.18);
    color: #90e0ef;
    font-size: 0.73rem;
    font-weight: 600;
    letter-spacing: 1.6px;
    text-transform: uppercase;
    padding: 7px 18px;
    border-radius: 30px;
    margin-bottom: 30px;
    width: fit-content;
}

.book-hero-title {
    font-family: 'Playfair Display', serif;
    font-size: clamp(2rem, 3vw, 2.8rem);
    font-weight: 700;
    color: #fff;
    line-height: 1.22;
    margin-bottom: 18px;
}
.book-hero-title em {
    font-style: normal;
    color: #90e0ef;
}

.book-hero-subtitle {
    font-size: 0.93rem;
    color: rgba(255,255,255,0.68);
    line-height: 1.75;
    max-width: 380px;
    margin-bottom: 48px;
    font-weight: 400;
}

/* Feature bullet list */
.book-feature-list {
    list-style: none;
    padding: 0; margin: 0;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.book-feature-item {
    display: flex;
    align-items: center;
    gap: 14px;
    color: rgba(255,255,255,0.82);
    font-size: 0.875rem;
    font-weight: 500;
}
.book-feature-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: rgba(255,255,255,0.09);
    border: 1px solid rgba(255,255,255,0.14);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #90e0ef;
    font-size: 0.88rem;
}

/* Bottom wave */
.book-wave {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 72px;
    opacity: 0.07;
}
.book-wave svg { width: 100%; height: 100%; }

/* ════════════════════════════════════════════════════════════════════════════
   RIGHT  —  Form panel
   ════════════════════════════════════════════════════════════════════════════ */
.book-form-panel {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 40px;
    background: #f0f4f8;
    min-height: 100vh;
}

.book-form-card {
    background: #fff;
    border-radius: 24px;
    box-shadow: 0 8px 40px rgba(2,62,138,0.09);
    padding: 44px 40px;
    width: 100%;
    max-width: 490px;
    position: relative;
}
/* Gradient accent bar at top */
.book-form-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, #011f4b, #0077b6, #90e0ef);
    border-radius: 24px 24px 0 0;
}

.book-form-heading {
    font-family: 'Playfair Display', serif;
    font-size: 1.65rem;
    font-weight: 700;
    color: #011f4b;
    margin-bottom: 3px;
}
.book-form-subheading {
    font-size: 0.84rem;
    color: #7a8fa6;
    font-weight: 400;
    margin-bottom: 30px;
}

/* ── Step Indicators ──────────────────────────────────────────────────────── */
.book-steps {
    display: flex;
    align-items: flex-start;
    margin-bottom: 32px;
}
.book-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    flex: 1;
    position: relative;
}
.book-step:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 15px; left: 50%;
    width: 100%; height: 2px;
    background: #e2eaf2;
    z-index: 0;
}
.book-step-num {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: #e2eaf2;
    color: #94a3b8;
    font-size: 0.78rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 1;
    transition: all 0.3s;
}
.book-step.active .book-step-num {
    background: linear-gradient(135deg, #023e8a, #0077b6);
    color: #fff;
    box-shadow: 0 4px 12px rgba(0,119,182,0.32);
}
.book-step-label {
    font-size: 0.66rem;
    font-weight: 600;
    color: #b0bec5;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.book-step.active .book-step-label { color: #023e8a; }

/* ── Error / Warning Banners ──────────────────────────────────────────────── */
.booking-error-banner {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    background: #fff5f5;
    border: 1px solid #fca5a5;
    border-left: 4px solid #ef4444;
    border-radius: 10px;
    padding: 13px 16px;
    margin-bottom: 22px;
    color: #b91c1c;
    font-size: 0.86rem;
    font-weight: 500;
    line-height: 1.55;
}
.booking-error-banner i { flex-shrink:0; margin-top:1px; color:#ef4444; }

.booking-warning-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-left: 4px solid #f59e0b;
    border-radius: 10px;
    padding: 11px 15px;
    margin-bottom: 14px;
    color: #92400e;
    font-size: 0.82rem;
    font-weight: 500;
}

/* ── Field Groups ─────────────────────────────────────────────────────────── */
.bk-field-group { margin-bottom: 20px; }

.bk-field-label {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 0.775rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.65px;
    margin-bottom: 9px;
}
.bk-field-label i { color: #0077b6; font-size: 0.82rem; width: 15px; }

.bk-input {
    width: 100%;
    padding: 13px 16px;
    border: 1.5px solid #e2eaf2;
    border-radius: 10px;
    background: #f8fafc;
    font-family: 'Poppins', sans-serif;
    font-size: 0.92rem;
    color: #1e293b;
    outline: none;
    transition: border-color 0.22s, box-shadow 0.22s, background 0.22s;
    box-sizing: border-box;
    appearance: none;
    -webkit-appearance: none;
}
.bk-input:focus {
    border-color: #0077b6;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(0,119,182,0.11);
}

/* Custom select arrow */
.bk-select-wrap { position: relative; }
.bk-select-wrap::after {
    content: '\f078';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    font-size: 0.68rem;
    color: #0077b6;
    position: absolute;
    right: 14px; top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
}

/* ── Tour Type Radio Cards ────────────────────────────────────────────────── */
.tour-type-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.tour-type-card { position: relative; }

.tour-type-card input[type="radio"] {
    position: absolute;
    opacity: 0; width: 0; height: 0;
}

.tour-type-lbl {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    padding: 20px 12px 16px;
    border: 1.5px solid #e2eaf2;
    border-radius: 12px;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.22s;
    text-align: center;
    user-select: none;
}
.tour-type-lbl:hover {
    border-color: #0077b6;
    background: #f0f7ff;
    transform: translateY(-2px);
}
.tour-type-card input[type="radio"]:checked + .tour-type-lbl {
    border-color: #023e8a;
    background: linear-gradient(140deg, #eef4ff 0%, #ddeeff 100%);
    box-shadow: 0 6px 20px rgba(2,62,138,0.13);
    transform: translateY(-2px);
}
.tour-type-card input[type="radio"]:checked + .tour-type-lbl .tt-icon {
    background: linear-gradient(135deg, #023e8a, #0077b6);
    color: #fff;
    box-shadow: 0 4px 12px rgba(0,119,182,0.3);
}
.tour-type-card input[type="radio"]:checked + .tour-type-lbl .tt-check {
    opacity: 1;
    transform: scale(1);
}

.tt-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    background: #e2eaf2;
    color: #0077b6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    transition: all 0.22s;
    margin-bottom: 4px;
}
.tt-name {
    font-size: 0.875rem;
    font-weight: 700;
    color: #1e293b;
}
.tt-price {
    font-size: 1.1rem;
    font-weight: 700;
    color: #023e8a;
}
.tt-time {
    font-size: 0.7rem;
    color: #7a8fa6;
}
.tt-check {
    position: absolute;
    top: 9px; right: 9px;
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #023e8a;
    color: #fff;
    font-size: 0.58rem;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transform: scale(0.4);
    transition: all 0.2s;
}

/* Booked card */
.tour-type-lbl.is-booked {
    opacity: 0.38;
    cursor: not-allowed;
    background: #f1f5f9;
    border-style: dashed;
    transform: none !important;
}
.tt-booked-tag {
    font-size: 0.67rem;
    font-weight: 700;
    color: #ef4444;
    background: #fff0f0;
    border: 1px solid #fca5a5;
    border-radius: 20px;
    padding: 2px 8px;
}

/* Fully booked notice */
.fully-booked-notice {
    text-align: center;
    padding: 22px 20px;
    background: #fff5f5;
    border: 1.5px dashed #fca5a5;
    border-radius: 12px;
    margin-bottom: 18px;
}
.fully-booked-notice i {
    font-size: 1.9rem;
    color: #ef4444;
    margin-bottom: 8px;
    display: block;
}
.fully-booked-notice p {
    font-size: 0.875rem;
    color: #b91c1c;
    font-weight: 600;
    margin: 0;
    line-height: 1.5;
}

/* ── Price Summary Box ────────────────────────────────────────────────────── */
.book-price-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #f0f7ff, #e6f0fa);
    border: 1px solid rgba(0,119,182,0.14);
    border-radius: 12px;
    padding: 16px 20px;
    margin: 22px 0;
}
.book-price-left { display: flex; flex-direction: column; gap: 2px; }
.book-price-label {
    font-size: 0.76rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.55px;
}
.book-price-note { font-size: 0.7rem; color: #94a3b8; }
.book-price-amount {
    font-family: 'Playfair Display', serif;
    font-size: 1.6rem;
    font-weight: 700;
    color: #023e8a;
}

/* ── Submit Button ────────────────────────────────────────────────────────── */
.book-submit-btn {
    width: 100%;
    padding: 15px 24px;
    background: linear-gradient(135deg, #023e8a 0%, #0077b6 100%);
    color: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 0.93rem;
    font-weight: 700;
    letter-spacing: 0.4px;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.28s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 6px 20px rgba(0,119,182,0.34);
    position: relative;
    overflow: hidden;
}
.book-submit-btn::after {
    content: '';
    position: absolute;
    top: 0; left: -120%;
    width: 80%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.18), transparent);
    transform: skewX(-20deg);
    transition: left 0.5s;
}
.book-submit-btn:hover::after { left: 140%; }
.book-submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(0,119,182,0.44);
}
.book-submit-btn:active { transform: translateY(0); }
.book-submit-btn:disabled {
    background: #cbd5e1;
    color: #94a3b8;
    box-shadow: none;
    cursor: not-allowed;
    transform: none;
}
.book-submit-btn:disabled::after { display: none; }

.btn-arrow { transition: transform 0.25s; }
.book-submit-btn:hover:not(:disabled) .btn-arrow { transform: translateX(5px); }

/* ── Trust Strip ──────────────────────────────────────────────────────────── */
.book-trust-strip {
    display: flex;
    justify-content: center;
    gap: 18px;
    margin-top: 20px;
    flex-wrap: wrap;
}
.book-trust-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.7rem;
    color: #94a3b8;
    font-weight: 500;
}
.book-trust-item i { color: #0077b6; font-size: 0.72rem; }

/* ── Entrance Animations ──────────────────────────────────────────────────── */
@keyframes fadeSlideUp   { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeSlideLeft { from { opacity:0; transform:translateX(28px); } to { opacity:1; transform:translateX(0); } }
@keyframes pricePop      { 0%{transform:scale(1)} 40%{transform:scale(1.12); color:#0077b6;} 100%{transform:scale(1);} }

.book-hero-badge    { animation: fadeSlideUp 0.55s ease 0.05s both; }
.book-hero-title    { animation: fadeSlideUp 0.55s ease 0.14s both; }
.book-hero-subtitle { animation: fadeSlideUp 0.55s ease 0.22s both; }
.book-feature-list  { animation: fadeSlideUp 0.55s ease 0.30s both; }
.book-form-card     { animation: fadeSlideLeft 0.55s ease 0.10s both; }

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 920px) {
    .book-page-wrapper   { flex-direction: column; }
    .book-hero-panel     { min-height: auto; padding: 44px 32px 38px; }
    .book-hero-title     { font-size: 1.85rem; }
    .book-feature-list   { flex-direction: row; flex-wrap: wrap; gap: 12px; }
    .book-feature-item   { flex: 1; min-width: 150px; }
    .book-form-panel     { min-height: auto; padding: 30px 20px 50px; }
    .book-form-card      { padding: 32px 26px; }
}
@media (max-width: 500px) {
    .book-hero-panel  { padding: 36px 20px 30px; }
    .book-form-card   { padding: 26px 18px; border-radius: 18px; }
    .tour-type-grid   { grid-template-columns: 1fr; }
    .book-step-label  { display: none; }
}
/* ── ADDED: 320px–375px screens ── */
@media (max-width: 375px) {
    .book-hero-panel     { padding: 28px 16px 24px; background-attachment: scroll; }
    .book-hero-title     { font-size: 1.55rem; }
    .book-hero-subtitle  { font-size: 0.82rem; margin-bottom: 24px; }
    .book-hero-badge     { font-size: 0.67rem; padding: 5px 14px; }
    .book-form-panel     { padding: 20px 14px 40px; }
    .book-form-card      { padding: 22px 14px; border-radius: 16px; max-width: 100%; }
    .book-form-heading   { font-size: 1.35rem; }
    .book-steps          { margin-bottom: 20px; }
    .book-step-num       { width: 26px; height: 26px; font-size: 0.7rem; }
    .bk-input            { padding: 11px 13px; font-size: 0.88rem; }
    .tour-type-grid      { gap: 10px; }
    .tour-type-lbl       { padding: 16px 10px 13px; }
    .tt-price            { font-size: 1rem; }
    .book-price-summary  { padding: 13px 14px; }
    .book-price-amount   { font-size: 1.35rem; }
    .book-submit-btn     { padding: 13px 18px; font-size: 0.88rem; }
    .book-feature-item   { min-width: 120px; font-size: 0.8rem; }
    .book-feature-icon   { width: 32px; height: 32px; font-size: 0.78rem; }
}

/* ── ADDED: 320px absolute minimum ── */
@media (max-width: 330px) {
    .book-hero-title     { font-size: 1.35rem; }
    .book-form-card      { padding: 18px 12px; }
    .book-form-heading   { font-size: 1.2rem; }
    .bk-input            { padding: 10px 11px; font-size: 0.84rem; }
    .tour-type-grid      { grid-template-columns: 1fr; }
    .tt-price            { font-size: 0.95rem; }
    .book-price-amount   { font-size: 1.2rem; }
    .book-submit-btn     { padding: 11px 14px; font-size: 0.84rem; }
    .book-step-label     { display: none; }
    .book-hero-badge     { display: none; }
}
</style>

<div class="book-page-wrapper">

    <!-- ╔══════════════════════════════════════╗
         ║         LEFT  –  Hero Panel          ║
         ╚══════════════════════════════════════╝ -->
    <div class="book-hero-panel">

        <span class="book-hero-badge">
            Emiart Private Resorts
        </span>

        <h1 class="book-hero-title">
            Ready to<br><em>unwind</em> and<br>escape?
        </h1>

        <p class="book-hero-subtitle">
            Reserve your spot at one of our exclusive resort branches. Breathe in the calm, feel the breeze, and let every moment be unforgettable.
        </p>

        <ul class="book-feature-list">
            <li class="book-feature-item">
                <div class="book-feature-icon"><i class="fas fa-shield-alt"></i></div>
                Secure payment via PayMongo
            </li>
            <li class="book-feature-item">
                <div class="book-feature-icon"><i class="fas fa-calendar-check"></i></div>
                Real-time slot availability
            </li>
        </ul>

        <div class="book-wave">
            <svg viewBox="0 0 1200 72" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0,36 C200,72 400,0 600,36 C800,72 1000,0 1200,36 L1200,72 L0,72 Z" fill="white"/>
            </svg>
        </div>

    </div>

    <!-- ╔══════════════════════════════════════╗
         ║         RIGHT  –  Form Panel         ║
         ╚══════════════════════════════════════╝ -->
    <div class="book-form-panel">
        <div class="book-form-card">

            <h2 class="book-form-heading">Book Your Stay</h2>
            <p class="book-form-subheading">Complete the steps below to secure your reservation.</p>

            <!-- Step bar -->
            <div class="book-steps">
                <div class="book-step active">
                    <div class="book-step-num">1</div>
                    <span class="book-step-label">Resort</span>
                </div>
                <div class="book-step active">
                    <div class="book-step-num">2</div>
                    <span class="book-step-label">Date</span>
                </div>
                <div class="book-step active">
                    <div class="book-step-num">3</div>
                    <span class="book-step-label">Tour Type</span>
                </div>
                <div class="book-step active">
                    <div class="book-step-num">4</div>
                    <span class="book-step-label">Payment</span>
                </div>
            </div>

            <!-- Cancellation / slot-conflict error -->
            <?php if ($bookingError): ?>
            <div class="booking-error-banner">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= $bookingError ?></span>
            </div>
            <?php endif; ?>

            <form action="book.php" method="POST" id="bookingForm">

                <!-- ── 1. Resort ── -->
                <div class="bk-field-group">
                    <label class="bk-field-label">
                        <i class="fas fa-map-marker-alt"></i> Select Resort
                    </label>
                    <div class="bk-select-wrap">
                        <select name="branch_id" id="fieldBranch" class="bk-input" required>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>"
                                    <?= ($preselectedBranch === intval($b['branch_id'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- ── 2. Date ── -->
                <div class="bk-field-group">
                    <label class="bk-field-label">
                        <i class="far fa-calendar-alt"></i> Check-in Date
                    </label>
                    <input
                        type="date"
                        name="check_in"
                        id="fieldDate"
                        class="bk-input"
                        required
                        min="<?= date('Y-m-d') ?>"
                        value="<?= htmlspecialchars($preselectedDate ?? '') ?>"
                    >
                </div>

                <!-- ── 3. Tour Type ── -->
                <div class="bk-field-group">
                    <label class="bk-field-label">
                        <i class="fas fa-tag"></i> Tour Type
                    </label>

                    <?php if ($allSlotsTaken): ?>
                        <div class="fully-booked-notice">
                            <i class="fas fa-calendar-times"></i>
                            <p>Both slots for this date are fully booked.<br>Please choose a different date or branch.</p>
                        </div>

                    <?php else: ?>

                        <?php if (!empty($bookedSlots)): ?>
                        <div class="booking-warning-banner">
                            <i class="fas fa-info-circle"></i>
                            One slot is already taken — only the available option is shown below.
                        </div>
                        <?php endif; ?>

                        <div class="tour-type-grid">

                            <?php $dayBooked = isset($bookedSlots['Day']); ?>
                            <div class="tour-type-card">
                                <input type="radio" name="type" id="typeDayTour" value="Day"
                                    <?= (!$dayBooked) ? 'checked' : 'disabled' ?>>
                                <label for="typeDayTour" class="tour-type-lbl <?= $dayBooked ? 'is-booked' : '' ?>">
                                    <div class="tt-icon"><i class="fas fa-sun"></i></div>
                                    <span class="tt-name">Day Tour</span>
                                    <span class="tt-price">₱900</span>
                                    <?php if ($dayBooked): ?>
                                        <span class="tt-booked-tag">Booked</span>
                                    <?php else: ?>
                                        <span class="tt-time">8 AM – 6 PM</span>
                                    <?php endif; ?>
                                </label>
                                <?php if (!$dayBooked): ?>
                                    <div class="tt-check"><i class="fas fa-check"></i></div>
                                <?php endif; ?>
                            </div>

                            <?php $nightBooked = isset($bookedSlots['Overnight']); ?>
                            <div class="tour-type-card">
                                <input type="radio" name="type" id="typeOvernight" value="Overnight"
                                    <?= (!$nightBooked && $dayBooked) ? 'checked' : '' ?>
                                    <?= $nightBooked ? 'disabled' : '' ?>>
                                <label for="typeOvernight" class="tour-type-lbl <?= $nightBooked ? 'is-booked' : '' ?>">
                                    <div class="tt-icon"><i class="fas fa-moon"></i></div>
                                    <span class="tt-name">Overnight</span>
                                    <span class="tt-price">₱1,000</span>
                                    <?php if ($nightBooked): ?>
                                        <span class="tt-booked-tag">Booked</span>
                                    <?php else: ?>
                                        <span class="tt-time">8 PM – 6 AM</span>
                                    <?php endif; ?>
                                </label>
                                <?php if (!$nightBooked): ?>
                                    <div class="tt-check"><i class="fas fa-check"></i></div>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endif; ?>
                </div>

                <!-- ── Price summary ── -->
                <?php if (!$allSlotsTaken): ?>
                <div class="book-price-summary">
                    <div class="book-price-left">
                        <span class="book-price-label">Total Amount</span>
                        <span class="book-price-note">Inclusive of all fees</span>
                    </div>
                    <div class="book-price-amount" id="priceDisplay">₱900</div>
                </div>
                <?php endif; ?>

                <!-- ── Submit ── -->
                <button type="submit" class="book-submit-btn" <?= $allSlotsTaken ? 'disabled' : '' ?>>
                    <i class="fas fa-lock" style="font-size:0.82rem;"></i>
                    Proceed to Payment
                    <i class="fas fa-arrow-right btn-arrow" style="font-size:0.82rem;"></i>
                </button>

            </form>

        </div>
    </div>

</div>

<script>
// Live price update
const priceMap     = { Day: '₱900', Overnight: '₱1,000' };
const priceDisplay = document.getElementById('priceDisplay');

document.querySelectorAll('input[name="type"]').forEach(radio => {
    radio.addEventListener('change', () => {
        if (!priceDisplay || !priceMap[radio.value]) return;
        priceDisplay.textContent = priceMap[radio.value];
        priceDisplay.style.animation = 'none';
        priceDisplay.offsetHeight; // force reflow
        priceDisplay.style.animation = 'pricePop 0.32s ease';
    });
});

// Sync price on load
(function () {
    const checked = document.querySelector('input[name="type"]:checked');
    if (checked && priceDisplay && priceMap[checked.value]) {
        priceDisplay.textContent = priceMap[checked.value];
    }
})();
</script>

<?php include 'footer.php'; ?>