<?php 
include 'header.php';
if (!isset($_SESSION['user_id'])) echo "<script>window.location='login.php';</script>";

$bookingError = null;

if (isset($_GET['cancelled'])) {
    unset($_SESSION['booking_intent'], $_SESSION['paymongo_session_id']);
    $bookingError = "Your payment was cancelled. The slot is still available — feel free to try again.";
}

/* ───── Load branches w/ status + global maintenance flag ─────────────────
 * Source of truth for both the form and the POST guard. We intentionally
 * load these BEFORE the POST handler so the handler can enforce them.
 * --------------------------------------------------------------------- */
$branches = $pdo->query("SELECT * FROM v_branches_full ORDER BY branch_name")->fetchAll();

$globalMaintenance = (int)$pdo->query("
    SELECT setting_value FROM system_settings
    WHERE  setting_key = 'all_branches_maintenance' LIMIT 1
")->fetchColumn();

/* Bookable subset — used by the resort dropdown so customers cannot
 * pick an unavailable branch in the first place.                        */
$bookableBranches = array_values(array_filter($branches, function ($b) use ($globalMaintenance) {
    return $globalMaintenance !== 1 && (int)$b['is_available'] === 1;
}));

/* ───── Gallery images grouped by branch (for the dynamic slider) ────────
 * One query, then bucket by branch_id. We include the cover (is_primary=1)
 * as a fallback FIRST slide whenever a branch has no gallery uploads yet,
 * so the slider always has at least one image to show.
 * --------------------------------------------------------------------- */
$galleryStmt = $pdo->query("
    SELECT branch_id, image_path, is_primary, sort_order
    FROM   branch_images
    ORDER  BY branch_id, is_primary DESC, sort_order, image_id
");
$galleryByBranch = [];
$coverByBranch   = [];
foreach ($galleryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $bid = (int)$row['branch_id'];
    if ((int)$row['is_primary'] === 1) {
        $coverByBranch[$bid] = $row['image_path'];
    } else {
        $galleryByBranch[$bid][] = $row['image_path'];
    }
}
// Build the per-branch slider lists: prefer gallery images, else fall back
// to the cover, else fall back to the system default.
$sliderByBranch = [];
foreach ($branches as $br) {
    $bid = (int)$br['branch_id'];
    if (!empty($galleryByBranch[$bid])) {
        $sliderByBranch[$bid] = $galleryByBranch[$bid];
    } elseif (!empty($coverByBranch[$bid])) {
        $sliderByBranch[$bid] = [$coverByBranch[$bid]];
    } else {
        $sliderByBranch[$bid] = ['assets/default.jpg'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postBranchId = isset($_POST['branch_id']) && is_numeric($_POST['branch_id'])
        ? intval($_POST['branch_id']) : null;
    $postDate     = $_POST['check_in'] ?? null;
    $postType     = $_POST['type']     ?? null;

    if (!$postBranchId || !$postDate || !$postType) {
        $bookingError = "Invalid booking data. Please fill in all fields and try again.";
    } else {
        /* ── Same-day booking guard ─────────────────────────────────────────
         * Same-day reservations are not allowed — guests must book at
         * least one day in advance. This is enforced server-side first
         * (the `min` attribute on the date input is only a UX hint and
         * can be bypassed by a hand-crafted POST).
         * ----------------------------------------------------------------- */
        $todayYmd      = date('Y-m-d');
        $tomorrowLabel = date('F j, Y', strtotime('+1 day'));
        $postTs        = strtotime($postDate);
        if ($postTs === false) {
            $bookingError = "Please choose a valid check-in date.";
        } elseif ($postDate <= $todayYmd) {
            $bookingError = "Same-day bookings are not allowed. The earliest available "
                          . "check-in date is <strong>" . htmlspecialchars($tomorrowLabel)
                          . "</strong>. Please choose a later date.";
        }
    }

    // Continue with the remaining checks only if no error has been raised yet.
    if (!$bookingError && $postBranchId && $postDate && $postType) {
        /* ── Branch-availability guard ──────────────────────────────────────
         * Defense-in-depth. Stops:
         *   • Direct POSTs to book.php that bypass the dropdown
         *   • Stale forms left open while the admin disables the branch
         *   • Pages cached before maintenance was switched on
         * ----------------------------------------------------------------- */
        $availStmt = $pdo->prepare("
            SELECT branch_name, is_available
            FROM   v_branches_full
            WHERE  branch_id = ?
        ");
        $availStmt->execute([$postBranchId]);
        $availRow = $availStmt->fetch(PDO::FETCH_ASSOC);

        if ($globalMaintenance === 1) {
            $bookingError = "Online bookings are currently paused — all branches are under maintenance. "
                          . "Please try again later.";
        } elseif (!$availRow) {
            $bookingError = "That branch could not be found.";
        } elseif ((int)$availRow['is_available'] !== 1) {
            $bookingError = "Sorry, <strong>" . htmlspecialchars($availRow['branch_name'])
                          . "</strong> is currently unavailable for booking. "
                          . "Please choose a different branch.";
        } else {
            /* ── Slot-availability guard (existing) ──────────────────────── */
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
        }
    }
}

$preselectedBranch = $_GET['branch'] ?? null;
$preselectedDate   = $_GET['date']   ?? null;
$preselectedBranch = (is_numeric($preselectedBranch)) ? intval($preselectedBranch) : null;

/* Same-day bookings are not allowed: if the URL prefilled today or an
 * earlier date (e.g. from a stale calendar link), strip it. The `min`
 * attribute on the date input will then default to the earliest
 * allowable day (tomorrow). */
if ($preselectedDate !== null) {
    $todayYmd = date('Y-m-d');
    if (strtotime($preselectedDate) === false || $preselectedDate <= $todayYmd) {
        $preselectedDate = null;
    }
}

/* If the customer arrived via a link to an unavailable branch (e.g. a stale
 * bookmark, or an admin disabled it after the link was shared), keep them
 * on the page but flag the situation so the form shows a clear notice. */
$preselectedUnavailable      = false;
$preselectedBranchNameForMsg = '';
if ($preselectedBranch !== null) {
    foreach ($branches as $b) {
        if ((int)$b['branch_id'] === $preselectedBranch) {
            $preselectedBranchNameForMsg = $b['branch_name'];
            if ((int)$b['is_available'] !== 1 || $globalMaintenance === 1) {
                $preselectedUnavailable = true;
                $preselectedBranch      = null; // let the dropdown fall back to first bookable
            }
            break;
        }
    }
}

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

/* Master flag — the form is only rendered when at least one branch is
 * currently bookable AND we're not under global maintenance. */
$canBook = ($globalMaintenance !== 1) && !empty($bookableBranches);

/* ───── Pick the slider's initial branch ─────────────────────────────────
 * Stays in sync with whichever branch the dropdown will default to. */
$initialSliderBranchId = null;
if ($preselectedBranch !== null) {
    foreach ($bookableBranches as $bb) {
        if ((int)$bb['branch_id'] === (int)$preselectedBranch) {
            $initialSliderBranchId = (int)$preselectedBranch;
            break;
        }
    }
}
if ($initialSliderBranchId === null && !empty($bookableBranches)) {
    $initialSliderBranchId = (int)$bookableBranches[0]['branch_id'];
}
if ($initialSliderBranchId === null && !empty($branches)) {
    $initialSliderBranchId = (int)$branches[0]['branch_id'];
}
$initialSlides = $initialSliderBranchId !== null
    ? ($sliderByBranch[$initialSliderBranchId] ?? ['assets/default.jpg'])
    : ['assets/default.jpg'];

$initialBranchName = '';
$initialBranchLocation = '';
if ($initialSliderBranchId !== null) {
    foreach ($branches as $b) {
        if ((int)$b['branch_id'] === $initialSliderBranchId) {
            $initialBranchName     = $b['branch_name'];
            $initialBranchLocation = $b['location'];
            break;
        }
    }
}
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
   LEFT  —  Branch gallery slider panel
   ════════════════════════════════════════════════════════════════════════════ */
.book-hero-panel {
    flex: 1.1;
    background: linear-gradient(155deg, #011f4b 0%, #023e8a 48%, #0077b6 100%);
    position: relative;
    overflow: hidden;
    min-height: 100vh;
    display: flex;
    align-items: stretch;
    justify-content: stretch;
}

/* The slide track — every slide is absolute-positioned, only the active
   one is visible. Crossfades happen via opacity transitions. */
.gallery-slider {
    position: absolute;
    inset: 0;
    overflow: hidden;
}
.gallery-slide {
    position: absolute;
    inset: 0;
    opacity: 0;
    transition: opacity 0.9s ease;
    will-change: opacity;
}
.gallery-slide.is-active { opacity: 1; }
.gallery-slide img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
    /* Subtle "ken burns" zoom for the active slide */
    transform: scale(1.0);
    transition: transform 7s linear;
}
.gallery-slide.is-active img { transform: scale(1.08); }

/* Top-down vignette that lifts the readability of the overlay text */
.gallery-slider::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg,
                rgba(2,28,73,0.55) 0%,
                rgba(2,28,73,0.20) 35%,
                rgba(2,28,73,0.55) 100%);
    pointer-events: none;
    z-index: 2;
}

/* Floating overlay (branch name, tagline) */
.gallery-overlay {
    position: absolute;
    z-index: 3;
    left: 56px; right: 56px;
    bottom: 56px;
    color: #fff;
    text-shadow: 0 2px 18px rgba(0,0,0,0.45);
    pointer-events: none;
}
.gallery-overlay-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(255,255,255,0.16);
    border: 1px solid rgba(255,255,255,0.28);
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 1.6px;
    text-transform: uppercase;
    padding: 6px 14px;
    border-radius: 30px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    margin-bottom: 14px;
}
.gallery-overlay-title {
    font-family: 'Playfair Display', serif;
    font-size: clamp(1.6rem, 2.6vw, 2.5rem);
    font-weight: 700;
    margin: 0 0 6px;
    line-height: 1.18;
    transition: opacity 0.4s ease;
}
.gallery-overlay-sub {
    font-size: 0.88rem;
    color: rgba(255,255,255,0.85);
    margin: 0;
    transition: opacity 0.4s ease;
}
.gallery-overlay-sub i { color: #90e0ef; margin-right: 6px; }

/* Side prev / next buttons */
.gallery-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 4;
    width: 44px; height: 44px;
    border-radius: 50%;
    border: none;
    background: rgba(255,255,255,0.18);
    color: #fff;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    transition: background 0.2s, transform 0.15s;
}
.gallery-nav:hover { background: rgba(255,255,255,0.32); }
.gallery-nav:active { transform: translateY(-50%) scale(0.92); }
.gallery-nav.prev { left: 22px; }
.gallery-nav.next { right: 22px; }
.gallery-nav.is-hidden { display: none; }   /* hidden when only 1 image */

/* Dot indicators */
.gallery-dots {
    position: absolute;
    z-index: 4;
    bottom: 22px;
    right: 56px;
    display: flex;
    gap: 7px;
}
.gallery-dot {
    width: 9px; height: 9px;
    border-radius: 50%;
    background: rgba(255,255,255,0.4);
    border: none;
    padding: 0;
    cursor: pointer;
    transition: width 0.25s, background 0.25s;
}
.gallery-dot.is-active {
    background: #90e0ef;
    width: 22px;
    border-radius: 6px;
}

/* Subtle slide-counter (e.g. "3 / 7") top-right */
.gallery-counter {
    position: absolute;
    z-index: 4;
    top: 22px; right: 22px;
    font-size: 0.72rem;
    font-weight: 600;
    color: rgba(255,255,255,0.85);
    background: rgba(0,0,0,0.32);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    padding: 5px 12px;
    border-radius: 30px;
    letter-spacing: 0.04em;
}

/* Empty / placeholder state — shown when there's nothing to display
   (extreme edge case: no branches at all). */
.gallery-empty {
    position: absolute;
    z-index: 3;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    color: rgba(255,255,255,0.7);
    text-align: center;
    padding: 30px;
}
.gallery-empty i { font-size: 2.6rem; color: #90e0ef; }

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

/* Inline helper note shown beneath an input (e.g. "no same-day bookings"). */
.bk-field-note {
    margin: 8px 0 0;
    padding: 8px 12px;
    font-size: 0.78rem;
    color: #856404;
    background: rgba(255, 193, 7, 0.10);
    border-left: 3px solid #ffc107;
    border-radius: 6px;
    display: flex;
    align-items: flex-start;
    gap: 7px;
    line-height: 1.45;
}
.bk-field-note i { color: #b08800; flex-shrink: 0; margin-top: 2px; }
.bk-field-note strong { color: #6b4d00; }

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

/* ── Booking-blocked card (maintenance / no bookable branches) ──────────── */
.booking-blocked-card {
    text-align: center;
    padding: 36px 28px;
    background: linear-gradient(135deg, #fff8e6 0%, #fff3cd 100%);
    border: 1.5px solid rgba(243,156,18,0.35);
    border-radius: 16px;
    box-shadow: 0 6px 22px rgba(243,156,18,0.12);
    margin: 8px 0;
}
.booking-blocked-icon {
    width: 64px; height: 64px;
    margin: 0 auto 14px;
    border-radius: 50%;
    background: rgba(243,156,18,0.18);
    color: #b06d00;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem;
}
.booking-blocked-title {
    color: #6b3e00;
    font-size: 1.2rem;
    font-weight: 700;
    margin: 0 0 8px;
}
.booking-blocked-text {
    color: #7a4d00;
    font-size: 0.92rem;
    line-height: 1.55;
    margin: 0 auto 22px;
    max-width: 420px;
}
.booking-blocked-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 26px;
    background: var(--primary, #0077b6);
    color: white;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.92rem;
    box-shadow: 0 4px 14px rgba(0,119,182,0.28);
    transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
}
.booking-blocked-btn:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(0,119,182,0.38);
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

.gallery-overlay    { animation: fadeSlideUp 0.6s ease 0.2s both; }
.book-form-card     { animation: fadeSlideLeft 0.55s ease 0.10s both; }

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 920px) {
    .book-page-wrapper   { flex-direction: column; }
    .book-hero-panel     { min-height: 360px; }
    .gallery-overlay     { left: 28px; right: 28px; bottom: 28px; }
    .gallery-dots        { right: 28px; bottom: 14px; }
    .book-form-panel     { min-height: auto; padding: 30px 20px 50px; }
    .book-form-card      { padding: 32px 26px; }
}
@media (max-width: 500px) {
    .book-hero-panel     { min-height: 300px; }
    .gallery-overlay     { left: 18px; right: 18px; bottom: 18px; }
    .gallery-dots        { right: 18px; bottom: 10px; }
    .gallery-nav         { width: 36px; height: 36px; font-size: 0.82rem; }
    .gallery-nav.prev    { left: 12px; }
    .gallery-nav.next    { right: 12px; }
    .gallery-counter     { top: 14px; right: 14px; padding: 4px 10px; font-size: 0.68rem; }
    .book-form-card      { padding: 26px 18px; border-radius: 18px; }
    .tour-type-grid      { grid-template-columns: 1fr; }
    .book-step-label     { display: none; }
}
</style>

<div class="book-page-wrapper">

    <!-- ╔══════════════════════════════════════╗
         ║       LEFT  –  Gallery Slider         ║
         ╚══════════════════════════════════════╝ -->
    <div class="book-hero-panel">
        <div class="gallery-slider" id="gallerySlider">
            <?php foreach ($initialSlides as $idx => $imgPath): ?>
                <div class="gallery-slide <?= $idx === 0 ? 'is-active' : '' ?>">
                    <img src="<?= htmlspecialchars($imgPath) ?>"
                         alt="<?= htmlspecialchars($initialBranchName) ?> photo"
                         <?= $idx === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>
                         onerror="this.src='assets/default.jpg'">
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="gallery-nav prev <?= count($initialSlides) <= 1 ? 'is-hidden' : '' ?>"
                id="galleryPrev" aria-label="Previous image">
            <i class="fas fa-chevron-left"></i>
        </button>
        <button type="button" class="gallery-nav next <?= count($initialSlides) <= 1 ? 'is-hidden' : '' ?>"
                id="galleryNext" aria-label="Next image">
            <i class="fas fa-chevron-right"></i>
        </button>

        <div class="gallery-counter" id="galleryCounter">
            <span id="galleryIdx">1</span> / <span id="galleryTotal"><?= count($initialSlides) ?></span>
        </div>

        <div class="gallery-overlay">
            <span class="gallery-overlay-badge">
                <i></i> Emiart Private Resorts
            </span>
            <h1 class="gallery-overlay-title" id="galleryBranchName">
                <?= htmlspecialchars($initialBranchName ?: 'Welcome') ?>
            </h1>
            <p class="gallery-overlay-sub" id="galleryBranchSub">
                <?php if ($initialBranchLocation): ?>
                    <i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($initialBranchLocation) ?>
                <?php else: ?>
                    <i class="fas fa-info-circle"></i>Select a resort to view its gallery
                <?php endif; ?>
            </p>
        </div>

        <div class="gallery-dots" id="galleryDots">
            <?php foreach ($initialSlides as $idx => $_): ?>
                <button type="button" class="gallery-dot <?= $idx === 0 ? 'is-active' : '' ?>"
                        data-index="<?= $idx ?>" aria-label="Go to image <?= $idx + 1 ?>"></button>
            <?php endforeach; ?>
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

            <?php if (!$canBook): ?>
                <!-- ── No bookings can be accepted right now ────────────────── -->
                <div class="booking-blocked-card">
                    <div class="booking-blocked-icon">
                        <i class="fas <?= $globalMaintenance === 1 ? 'fa-tools' : 'fa-info-circle' ?>"></i>
                    </div>
                    <h3 class="booking-blocked-title">
                        <?= $globalMaintenance === 1
                            ? 'All Branches Under Maintenance'
                            : 'No Branches Available For Booking' ?>
                    </h3>
                    <p class="booking-blocked-text">
                        <?php if ($globalMaintenance === 1): ?>
                            Online bookings are temporarily paused while we make improvements to all of our resorts.
                            Please check back soon — we appreciate your patience.
                        <?php else: ?>
                            None of our branches are currently accepting bookings. Please check back later
                            or contact us for assistance.
                        <?php endif; ?>
                    </p>
                    <a href="branches.php" class="booking-blocked-btn">
                        <i class="fas fa-arrow-left"></i> Back to Resorts
                    </a>
                </div>

            <?php else: ?>
                <?php if ($preselectedUnavailable): ?>
                <!-- The link the customer used points at an unavailable branch — keep
                     them on the page, but tell them clearly so they pick another. -->
                <div class="booking-error-banner" style="background:rgba(243,156,18,0.1);border-left-color:#f39c12;color:#7a4d00;">
                    <i class="fas fa-info-circle" style="color:#b06d00;"></i>
                    <span>
                        <strong><?= htmlspecialchars($preselectedBranchNameForMsg) ?></strong>
                        is currently unavailable for booking. Please choose a different branch from the list below.
                    </span>
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
                                <?php foreach ($bookableBranches as $b): ?>
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
                            min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                            value="<?= htmlspecialchars($preselectedDate ?? '') ?>"
                        >
                        <p class="bk-field-note">
                            <i class="fas fa-info-circle"></i>
                            Same-day bookings are not allowed &mdash; the earliest available
                            date is <strong><?= date('F j, Y', strtotime('+1 day')) ?></strong>.
                        </p>
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
            <?php endif; /* end if ($canBook) */ ?>

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

/* ═════════════════════════════════════════════════════════════════════════
   GALLERY SLIDER  —  per-branch image carousel on the LEFT panel
   ═════════════════════════════════════════════════════════════════════════
   Data shape:
     branchSliders.images    = { branchId: ['path', 'path', ...] }
     branchSliders.names     = { branchId: 'Branch Name' }
     branchSliders.locations = { branchId: 'Branch location' }
     branchSliders.initial   = branchId initially selected
   ────────────────────────────────────────────────────────────────────── */
const branchSliders = <?= json_encode([
    'images'    => $sliderByBranch,
    'names'     => array_column($branches, 'branch_name', 'branch_id'),
    'locations' => array_column($branches, 'location',    'branch_id'),
    'initial'   => $initialSliderBranchId,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

(function () {
    const slider     = document.getElementById('gallerySlider');
    const dotsHost   = document.getElementById('galleryDots');
    const idxLabel   = document.getElementById('galleryIdx');
    const totalLabel = document.getElementById('galleryTotal');
    const counter    = document.getElementById('galleryCounter');
    const prevBtn    = document.getElementById('galleryPrev');
    const nextBtn    = document.getElementById('galleryNext');
    const titleEl    = document.getElementById('galleryBranchName');
    const subEl      = document.getElementById('galleryBranchSub');
    const branchSel  = document.getElementById('fieldBranch');

    if (!slider) return; // no slider on this page (e.g. blocked state)

    let currentBranchId = branchSliders.initial;
    let slides = [];           // array of <div class="gallery-slide"> nodes
    let activeIdx = 0;
    let autoTimer = null;
    const AUTO_INTERVAL = 5000;

    function clearAutoTimer() {
        if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
    }
    function startAutoTimer() {
        clearAutoTimer();
        if (slides.length > 1) {
            autoTimer = setInterval(() => goTo(activeIdx + 1), AUTO_INTERVAL);
        }
    }

    function preloadImages(paths) {
        // Trigger browser cache for non-active slides so transitions are instant.
        paths.forEach(p => { const im = new Image(); im.src = p; });
    }

    function buildSlides(branchId) {
        const paths = (branchSliders.images && branchSliders.images[branchId]) || ['assets/default.jpg'];
        const branchName = branchSliders.names[branchId] || '';

        // Wipe existing slides + dots
        slider.innerHTML = '';
        dotsHost.innerHTML = '';
        slides = [];

        paths.forEach((p, i) => {
            const slide = document.createElement('div');
            slide.className = 'gallery-slide' + (i === 0 ? ' is-active' : '');
            const img = document.createElement('img');
            img.src = p;
            img.alt = branchName + ' photo';
            if (i === 0) img.setAttribute('fetchpriority', 'high');
            else        img.loading = 'lazy';
            img.onerror = function () { this.src = 'assets/default.jpg'; };
            slide.appendChild(img);
            slider.appendChild(slide);
            slides.push(slide);

            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'gallery-dot' + (i === 0 ? ' is-active' : '');
            dot.setAttribute('aria-label', 'Go to image ' + (i + 1));
            dot.dataset.index = String(i);
            dot.addEventListener('click', () => { goTo(i); resetAutoTimer(); });
            dotsHost.appendChild(dot);
        });

        activeIdx = 0;
        if (totalLabel) totalLabel.textContent = paths.length;
        if (idxLabel)   idxLabel.textContent   = '1';
        if (counter)    counter.style.display = paths.length > 1 ? '' : 'none';
        if (prevBtn)    prevBtn.classList.toggle('is-hidden', paths.length <= 1);
        if (nextBtn)    nextBtn.classList.toggle('is-hidden', paths.length <= 1);

        preloadImages(paths.slice(1));
    }

    function goTo(targetIdx) {
        if (slides.length === 0) return;
        const next = ((targetIdx % slides.length) + slides.length) % slides.length;
        if (next === activeIdx) return;

        slides[activeIdx].classList.remove('is-active');
        slides[next].classList.add('is-active');

        const dots = dotsHost.querySelectorAll('.gallery-dot');
        if (dots[activeIdx]) dots[activeIdx].classList.remove('is-active');
        if (dots[next])      dots[next].classList.add('is-active');

        activeIdx = next;
        if (idxLabel) idxLabel.textContent = (next + 1).toString();
    }

    function resetAutoTimer() { startAutoTimer(); }

    function switchBranch(newBranchId) {
        if (newBranchId === currentBranchId) return;
        currentBranchId = newBranchId;

        // Fade-out, rebuild, fade-in
        slider.style.transition = 'opacity 0.35s ease';
        slider.style.opacity = '0';
        setTimeout(() => {
            buildSlides(newBranchId);

            // Update overlay text
            const name = branchSliders.names[newBranchId] || '';
            const loc  = branchSliders.locations[newBranchId] || '';
            if (titleEl) titleEl.textContent = name;
            if (subEl)   subEl.innerHTML = loc
                ? '<i class="fas fa-map-marker-alt"></i>' + escapeHtml(loc)
                : '<i class="fas fa-info-circle"></i>Select a resort to view its gallery';

            slider.style.opacity = '1';
            startAutoTimer();
        }, 350);
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ── Wire up controls ─────────────────────────────────────────────────
    if (prevBtn) prevBtn.addEventListener('click', () => { goTo(activeIdx - 1); resetAutoTimer(); });
    if (nextBtn) nextBtn.addEventListener('click', () => { goTo(activeIdx + 1); resetAutoTimer(); });

    // Initial dot click handlers (server-rendered dots)
    dotsHost.querySelectorAll('.gallery-dot').forEach(dot => {
        dot.addEventListener('click', () => {
            goTo(parseInt(dot.dataset.index, 10) || 0);
            resetAutoTimer();
        });
    });

    // Pause auto-rotate when the user hovers the slider
    const heroPanel = document.querySelector('.book-hero-panel');
    if (heroPanel) {
        heroPanel.addEventListener('mouseenter', clearAutoTimer);
        heroPanel.addEventListener('mouseleave', startAutoTimer);
    }

    // Cache initial server-rendered slides into the JS array so prev/next/dots
    // work before the first dropdown change.
    slides = Array.from(slider.querySelectorAll('.gallery-slide'));
    activeIdx = 0;
    preloadImages((branchSliders.images[currentBranchId] || []).slice(1));

    // Branch dropdown drives the slider
    if (branchSel) {
        branchSel.addEventListener('change', () => {
            const newId = parseInt(branchSel.value, 10);
            if (Number.isFinite(newId)) switchBranch(newId);
        });
    }

    startAutoTimer();
})();
</script>

<?php include 'footer.php'; ?>