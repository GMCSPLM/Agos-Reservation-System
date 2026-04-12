<?php 
include 'header.php';
if (!isset($_SESSION['user_id'])) echo "<script>window.location='login.php';</script>";

// ─── Server-side availability guard ──────────────────────────────────────────
// Re-check the exact (branch × date × type) slot RIGHT before handing off to
// PayMongo. The form-level UI already hides taken slots, but a race condition
// (or a manually crafted POST) could still slip a double-booking through.
// If the slot is still free we forward all POST fields to paymongo_api.php via
// a self-submitting hidden form — no data is lost and no redirect quirks occur.
// ─────────────────────────────────────────────────────────────────────────────
$bookingError = null;

// ── Cancel flow: user returned from PayMongo without paying ───────────────────
// PayMongo redirects here on cancel. Wipe any stale booking intent from the
// session so the slot stays available for the next visitor (or the same user).
// ─────────────────────────────────────────────────────────────────────────────
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
        // Only block on 'Confirmed'. 'Pending' is now written ONLY after a
        // successful PayMongo callback, so it is never a ghost/abandoned row.
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
            // Block: stay on book.php and show a clear error
            $typeLabel  = ($postType === 'Day') ? 'Day Tour' : 'Overnight';
            $bookingError = "Sorry, the <strong>{$typeLabel}</strong> slot on "
                          . htmlspecialchars(date('F j, Y', strtotime($postDate)))
                          . " is no longer available. Please choose a different date or tour type.";
        } else {
            // Slot is free — forward to PayMongo via a self-submitting hidden form
            // (we can't use header() here because the body has already started via
            //  header.php, so a JS-driven form post is the cleanest approach)
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
// ─────────────────────────────────────────────────────────────────────────────

$branches = $pdo->query("SELECT * FROM branches")->fetchAll();

// Pre-fill from calendar click (branch & date passed via URL)
$preselectedBranch = $_GET['branch'] ?? null;
$preselectedDate   = $_GET['date']   ?? null;

// Only use branch if it's a valid numeric ID (ignore 'all')
$preselectedBranch = (is_numeric($preselectedBranch)) ? intval($preselectedBranch) : null;

// ─── Slot-level availability for the pre-selected branch + date ───────────────
// Check which slots are Confirmed or Pending so we can disable those in the
// form. 'Pending' now only exists after a verified successful PayMongo payment,
// so it reliably represents a real booking — not an abandoned checkout.
// ─────────────────────────────────────────────────────────────────────────────
$bookedSlots = []; // e.g. ['Day' => true]

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

<div class="container">
    <div style="display: flex; gap: 40px; margin-top: 40px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 300px;">
            <h1 style="font-size: 3rem; color: white; line-height: 1.2; text-shadow: 2px 2px 8px rgba(0,0,0,0.6);">Ready to unwind?<br>Secure your spot.</h1>
            <p style="margin-top: 20px; color: white; text-shadow: 1px 1px 6px rgba(0,0,0,0.6);">Choose your preferred resort branch and date. We handle the rest.</p>
        </div>
        
        <div class="auth-box" style="margin: 0; flex: 1;">
            <h3>Reservation Details</h3>

            <?php if ($bookingError): ?>
            <div style="background:#ffebee; border:1px solid #e57373; border-radius:8px; padding:14px 18px; color:#c62828; font-size:0.9rem; font-weight:600; margin-bottom:18px; display:flex; align-items:flex-start; gap:10px;">
                <i class="fas fa-exclamation-circle" style="margin-top:2px; flex-shrink:0;"></i>
                <span><?= $bookingError ?></span>
            </div>
            <?php endif; ?>

            <form action="book.php" method="POST">
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

                    <?php if ($allSlotsTaken): ?>
                    <!-- Both slots are booked — show a clear error instead of a broken form -->
                    <div style="background:#ffebee; border:1px solid #e57373; border-radius:6px; padding:12px 16px; color:#c62828; font-size:0.9rem; font-weight:600;">
                        <i class="fas fa-exclamation-circle"></i>
                        Both slots for this date are fully booked.
                        Please choose a different date or branch.
                    </div>
                    <select name="type" disabled style="margin-top:8px; opacity:0.5; cursor:not-allowed;">
                        <option>Day Tour (₱900) — Booked</option>
                        <option>Overnight (₱1000) — Booked</option>
                    </select>
                    <?php else: ?>

                    <?php if (!empty($bookedSlots)): ?>
                    <!-- Partial — let the user know one slot is gone -->
                    <div style="background:#fff3e0; border:1px solid #ffb74d; border-radius:6px; padding:10px 14px; color:#e65100; font-size:0.85rem; font-weight:600; margin-bottom:8px;">
                        <i class="fas fa-info-circle"></i>
                        One slot for this date is already taken. Only the available option is shown below.
                    </div>
                    <?php endif; ?>

                    <select name="type" required>
                        <?php
                        $slots = [
                            'Day'       => 'Day Tour (₱900)',
                            'Overnight' => 'Overnight (₱1000)',
                        ];
                        foreach ($slots as $value => $label):
                            $isTaken  = isset($bookedSlots[$value]);
                            // Skip taken slots entirely so the user cannot select them
                            if ($isTaken) continue;
                        ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <button type="submit" <?= $allSlotsTaken ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>PROCEED TO PAYMENT</button>
            </form>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>