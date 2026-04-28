<?php 
include 'header.php';

// Show booking success toast if redirected from payment
$showBookingSuccess = false;
if (isset($_SESSION['booking_success']) && $_SESSION['booking_success']) {
    $showBookingSuccess = true;
    unset($_SESSION['booking_success']);
}
?>

<?php if ($showBookingSuccess): ?>
<style>
#booking-toast {
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%) translateY(-20px);
    background: #2e7d32;
    color: white;
    padding: 18px 40px;
    border-radius: 50px;
    font-size: 1.1rem;
    font-weight: 600;
    z-index: 99999;
    box-shadow: 0 8px 30px rgba(0,0,0,0.25);
    display: flex;
    align-items: center;
    gap: 12px;
    opacity: 0;
    animation: toastIn 0.4s ease forwards, toastOut 0.4s ease 4s forwards;
}
@keyframes toastIn {
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}
@keyframes toastOut {
    to { opacity: 0; transform: translateX(-50%) translateY(-20px); }
}
</style>
<div id="booking-toast">
    <span style="font-size:1.4rem;">&#10003;</span>
    Booking confirmed! Your reservation has been successfully placed.
</div>
<script>
setTimeout(() => {
    const t = document.getElementById('booking-toast');
    if (t) t.remove();
}, 4600);
</script>
<?php endif; ?>

<?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback'])) {
    if (!isset($_SESSION['customer_id'])) {
        echo "<script>alert('You must be logged in to submit feedback.'); window.location='login.php';</script>";
    } else {
        $rating = $_POST['rating'] ?? 5;
        $occupation = htmlspecialchars($_POST['occupation']);
        $raw_comment = htmlspecialchars($_POST['comments']);
        
        $final_comment = $occupation ? "$raw_comment (Occupation: $occupation)" : $raw_comment;
        
        try {
            $branch_id = isset($_POST['branch_id']) && is_numeric($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
            $stmt = $pdo->prepare("INSERT INTO feedback (customer_id, branch_id, rating, comments, feedback_date) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['customer_id'], $branch_id, $rating, $final_comment]);
            echo "<script>alert('Thank you for your feedback!'); window.location='index.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('Error submitting feedback.');</script>";
        }
    }
}

// Pull every branch with its current status & primary image (single query
// via the v_branches_full view added by the Branches Management migration).
$branches = $pdo->query("SELECT * FROM v_branches_full ORDER BY branch_name")->fetchAll();
$amenities = $pdo->query("SELECT a.*, b.branch_name FROM amenities a JOIN branches b ON a.branch_id = b.branch_id")->fetchAll();
$feedbacks = $pdo->query("SELECT f.*, c.full_name, b.branch_name FROM feedback f JOIN customers c ON f.customer_id = c.customer_id LEFT JOIN branches b ON f.branch_id = b.branch_id ORDER BY feedback_date DESC LIMIT 9")->fetchAll();

// Global maintenance flag (1 = ALL branches under maintenance, no bookings allowed)
$globalMaintenance = (int)$pdo->query("
    SELECT setting_value FROM system_settings
    WHERE  setting_key = 'all_branches_maintenance'
    LIMIT 1
")->fetchColumn();

// Branches that can actually accept bookings RIGHT NOW (used by the calendar
// branch picker so customers can never select an unbookable branch).
$bookableBranches = array_values(array_filter($branches, function($b) use ($globalMaintenance) {
    return $globalMaintenance !== 1 && (int)$b['is_available'] === 1;
}));

// Calendar Variables
$selectedMonth = $_GET['month'] ?? date('m');
$selectedYear = $_GET['year'] ?? date('Y');
// Default to the first BOOKABLE branch instead of 'all' — the "All Branches" option has been removed
$firstBranch    = $bookableBranches[0]['branch_id'] ?? ($branches[0]['branch_id'] ?? null);
$selectedBranch = $_GET['branch'] ?? $firstBranch;
// Reject 'all' in case it arrives via a stale URL
if ($selectedBranch === 'all') $selectedBranch = $firstBranch;

// If the requested branch is not bookable, fall back to the first bookable one
$selectedIsBookable = false;
foreach ($bookableBranches as $bb) {
    if ((int)$bb['branch_id'] === (int)$selectedBranch) { $selectedIsBookable = true; break; }
}
if (!$selectedIsBookable && !empty($bookableBranches)) {
    $selectedBranch = $bookableBranches[0]['branch_id'];
}

// Validate inputs
$selectedMonth = max(1, min(12, intval($selectedMonth)));
$selectedYear = max(2024, min(2030, intval($selectedYear)));

$monthName = date('F Y', strtotime("$selectedYear-$selectedMonth-01"));
$daysInMonth = date('t', strtotime("$selectedYear-$selectedMonth-01"));
$firstDayOfWeek = date('N', strtotime("$selectedYear-$selectedMonth-01")); 

// ─── Slot-aware availability query ───────────────────────────────────────────
// Each date has two discrete booking slots: "Day" (day tour) and "Overnight".
// A date is only fully booked when BOTH slots are taken for the selected branch.
// ─────────────────────────────────────────────────────────────────────────────

// For the selected branch: fetch which reservation_type slots are taken on each date.
// GROUP BY (date, reservation_type) so one record = one booked slot, not one record per guest.
// Skip the lookup entirely when no branch is selectable (avoids a query with branch_id = NULL).
$slotsByDate = [];
if (!empty($selectedBranch)) {
    $stmt = $pdo->prepare("
        SELECT reservation_date, reservation_type
        FROM   reservations
        WHERE  branch_id = ?
          AND  MONTH(reservation_date) = ?
          AND  YEAR(reservation_date)  = ?
          AND  status IN ('Confirmed', 'Pending')
        GROUP BY reservation_date, reservation_type
    ");
    $stmt->execute([$selectedBranch, $selectedMonth, $selectedYear]);

    // $slotsByDate['2025-06-15']['Day']       = true  → Day slot is taken
    // $slotsByDate['2025-06-15']['Overnight'] = true  → Overnight slot is taken
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slotsByDate[$row['reservation_date']][$row['reservation_type']] = true;
    }
}

// Calculate previous and next month
$prevMonth = $selectedMonth - 1;
$prevYear = $selectedYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $selectedMonth + 1;
$nextYear = $selectedYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}
?>

<style>
.feedback-section-wrapper {
    background: linear-gradient(rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.3)), url('Ripple-Effect.png');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    padding: 80px 0;
    margin-top: 4rem;
    margin-bottom: 4rem;
    box-shadow: inset 0 0 20px rgba(0,0,0,0.1);
}

.feedback-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    font-family: 'Poppins', sans-serif;
}

.header-split {
    display: flex;
    align-items: center;
    width: 48%;
}

.header-split h3 {
    font-size: 1.8rem;
    font-weight: 700;
    color: #023e8a;
    white-space: nowrap;
    margin: 0 15px;
    text-shadow: 0 2px 5px rgba(255,255,255,0.7);
}

.line {
    height: 2px;
    background: #023e8a;
    flex-grow: 1;
    opacity: 0.7;
}

.custom-form-grid {
    display: grid;
    grid-template-columns: 1fr 1.5fr 1fr;
    gap: 30px;
    max-width: 1000px;
    margin: 0 auto;
}

.custom-input-group { margin-bottom: 20px; }
.custom-input {
    width: 100%;
    padding: 15px;
    border: 1px solid rgba(255,255,255,0.8);
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.9);
    font-size: 1rem;
    outline: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
}
.custom-input:focus {
    background: white;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.helper-text {
    font-size: 0.85rem;
    color: #023e8a;
    font-weight: 500;
    margin-top: 5px;
    display: block;
    text-shadow: 0 1px 2px rgba(255,255,255,0.8);
}

.custom-textarea {
    width: 100%;
    height: 100%;
    min-height: 180px;
    padding: 15px;
    border: 1px solid rgba(255,255,255,0.8);
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.9);
    resize: none;
    font-family: 'Poppins', sans-serif;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
}
.custom-textarea:focus {
    background: white;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.star-rating {
    display: flex;
    flex-direction: row-reverse;
    justify-content: center;
    gap: 10px;
    margin-bottom: 20px;
}
.star-rating input { display: none; }
.star-rating label {
    font-size: 3rem;
    color: rgba(255, 255, 255, 0.8);
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
    cursor: pointer;
    transition: color 0.2s;
}
.star-rating input:checked ~ label,
.star-rating label:hover,
.star-rating label:hover ~ label {
    color: #ffb703;
    text-shadow: none;
}

.btn-submit-custom {
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid #023e8a;
    color: #023e8a;
    font-weight: 700;
    font-size: 1.2rem;
    padding: 10px 40px;
    border-radius: 8px;
    cursor: pointer;
    transition: 0.3s;
    width: 100%;
    display: block;
}
.btn-submit-custom:hover {
    background: #023e8a;
    color: white;
}

/* Character Count Bar */
.word-count-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 6px;
    padding: 4px 10px;
    background: rgba(255,255,255,0.85);
    border-radius: 20px;
    font-family: 'Poppins', sans-serif;
}
.word-count-label { font-size: 0.75rem; color: #555; }
.word-count-progress {
    flex: 1;
    height: 5px;
    background: #e0e0e0;
    border-radius: 10px;
    margin: 0 10px;
    overflow: hidden;
}
.word-count-progress-fill {
    height: 100%;
    border-radius: 10px;
    background: #2a9d8f;
    transition: width 0.2s, background 0.2s;
}
.word-count-num {
    font-size: 0.8rem;
    font-weight: 700;
    color: #023e8a;
    transition: color 0.2s;
}
.word-count-num.warn { color: #e07b00; }
.word-count-num.over { color: #d62828; }
.word-count-num.ok   { color: #2a9d8f; }

/* Calendar Styles */
.calendar-controls {
    background: white;
    padding: 25px;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.calendar-nav {
    display: flex;
    align-items: center;
    gap: 20px;
}

.nav-btn {
    background: var(--primary);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.nav-btn:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 119, 182, 0.3);
}

.nav-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
    transform: none;
}

.nav-btn.disabled {
    background: #ccc;
    cursor: not-allowed;
    pointer-events: none;
}

.current-month {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--primary);
    min-width: 200px;
    text-align: center;
}

.branch-selector {
    display: flex;
    align-items: center;
    gap: 10px;
}

.branch-selector label {
    font-weight: 600;
    color: #333;
}

.branch-select {
    padding: 10px 15px;
    border: 2px solid var(--primary);
    border-radius: 8px;
    font-size: 1rem;
    cursor: pointer;
    background: white;
    min-width: 200px;
}

.branch-select:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
}

.calendar-container {
    background: white;
    padding: 2rem;
    border-radius: 20px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 10px;
    margin-top: 20px;
}

.calendar-day-header {
    font-weight: bold;
    padding: 15px 10px;
    color: var(--primary);
    text-transform: uppercase;
    font-size: 0.9rem;
    text-align: center;
    background: rgba(0, 119, 182, 0.05);
    border-radius: 8px;
}

.calendar-day {
    padding: 15px;
    border-radius: 10px;
    font-size: 0.95rem;
    min-height: 90px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s;
    border: 2px solid transparent;
    position: relative;
}

.calendar-day:hover:not(.day-past):not(.day-booked):not(.day-today) {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    cursor: pointer;
}

.calendar-day.clickable {
    cursor: pointer;
}

.calendar-day.clickable:hover::after {
    content: 'Click to Book';
    position: absolute;
    bottom: 5px;
    font-size: 0.7rem;
    color: #2e7d32;
    font-weight: 600;
    animation: fadeIn 0.3s;
}
@media (hover: none) {
    .calendar-day.clickable:hover::after { display: none; }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.day-number {
    font-size: 1.3rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.day-status {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    padding: 4px 8px;
    border-radius: 4px;
}

.day-available {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #2e7d32;
    border-color: #81c784;
}

.day-available .day-status {
    background: #2e7d32;
    color: white;
}

.day-booked {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    color: #c62828;
    border-color: #e57373;
}

.day-booked .day-status {
    background: #c62828;
    color: white;
}

.day-past {
    background: #f5f5f5;
    color: #999;
    border-color: #e0e0e0;
    opacity: 0.6;
}

.day-past .day-status {
    background: #999;
    color: white;
}

/* Today — visually distinct so guests can find it on the grid, but locked
 * (cursor: not-allowed) because same-day bookings are not permitted. */
.day-today {
    background: linear-gradient(135deg, #fff8e1 0%, #ffe0b2 100%);
    color: #6b3e00;
    border: 2px dashed #f39c12;
    cursor: not-allowed;
    position: relative;
}
.day-today::after {
    content: 'TODAY';
    position: absolute;
    top: 4px; right: 6px;
    font-size: 0.55rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: #b06d00;
    background: rgba(243,156,18,0.18);
    padding: 2px 6px;
    border-radius: 50px;
}
.day-today .day-status {
    background: #f39c12;
    color: white;
    font-size: 0.72rem;
}

.day-limited {
    background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    color: #e65100;
    border-color: #ffb74d;
}

.day-limited .day-status {
    background: #e65100;
    color: white;
}

.calendar-legend {
    margin-top: 25px;
    padding: 20px;
    background: rgba(0, 119, 182, 0.05);
    border-radius: 10px;
    display: flex;
    justify-content: center;
    gap: 30px;
    flex-wrap: wrap;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    font-weight: 500;
}

.legend-box {
    width: 20px;
    height: 20px;
    border-radius: 4px;
    border: 2px solid;
}

.legend-available {
    background: #e8f5e9;
    border-color: #81c784;
}

.legend-limited {
    background: #fff3e0;
    border-color: #ffb74d;
}

.legend-booked {
    background: #ffebee;
    border-color: #e57373;
}

.legend-past {
    background: #f5f5f5;
    border-color: #e0e0e0;
}

.legend-today {
    background: #fff8e1;
    border-color: #f39c12;
    border-style: dashed;
}

.booking-count {
    font-size: 0.75rem;
    color: #666;
    margin-top: 4px;
}

/* ── Slot pills: Day / Night badges inside each calendar cell ── */
.slot-pills {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    justify-content: center;
    margin-top: 5px;
}

.slot-pill {
    font-size: 0.62rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    letter-spacing: 0.2px;
    white-space: nowrap;
    line-height: 1.4;
}

/* Available slot → green outline */
.slot-open {
    background: rgba(46, 125, 50, 0.10);
    color: #2e7d32;
    border: 1px solid #81c784;
}

/* Taken slot → muted red with strikethrough */
.slot-taken {
    background: rgba(198, 40, 40, 0.08);
    color: #b71c1c;
    border: 1px solid #ef9a9a;
    text-decoration: line-through;
    opacity: 0.75;
}

@media (max-width: 768px) {
    .calendar-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .calendar-nav {
        justify-content: space-between;
    }
    
    .branch-selector {
        flex-direction: column;
        align-items: stretch;
    }
    
    .branch-select { min-width: unset; width: 100%; }
    
    .calendar-grid {
        gap: 5px;
    }
    
    .calendar-day {
        min-height: 70px;
        padding: 8px;
    }
    
    .day-number {
        font-size: 1.1rem;
    }
    
    .day-status {
        font-size: 0.7rem;
        padding: 2px 6px;
    }
}

/* ── "What They Say" Redesigned Feedback Cards ─────────────────────────────── */
.feedback-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 24px;
    margin-top: 16px;
}

.feedback-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 24px 22px 20px;
    box-shadow: 0 4px 18px rgba(2, 62, 138, 0.08);
    border-top: 4px solid #0077b6;
    display: flex;
    flex-direction: column;
    gap: 12px;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    position: relative;
    overflow: hidden;
}

.feedback-card::before {
    content: '\201C';
    position: absolute;
    top: -6px;
    right: 16px;
    font-size: 5.5rem;
    line-height: 1;
    color: #0077b6;
    opacity: 0.08;
    font-family: Georgia, serif;
    pointer-events: none;
}

.feedback-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(2, 62, 138, 0.14);
}

.feedback-card-header {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.feedback-card-name {
    font-size: 1.05rem;
    font-weight: 700;
    color: #023e8a;
    font-family: 'Poppins', sans-serif;
    letter-spacing: 0.2px;
}

.feedback-card-stars {
    display: flex;
    gap: 3px;
}

.feedback-card-stars i {
    font-size: 0.85rem;
    color: #ffb703;
}

.feedback-card-comment {
    font-size: 0.92rem;
    color: #444;
    line-height: 1.65;
    font-style: italic;
    flex-grow: 1;
    border-left: 3px solid rgba(0, 119, 182, 0.2);
    padding-left: 12px;
    margin: 0;
}

.feedback-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px;
    padding-top: 10px;
    border-top: 1px solid rgba(2, 62, 138, 0.08);
    margin-top: auto;
}

.feedback-card-branch {
    font-size: 0.78rem;
    font-weight: 600;
    color: #0077b6;
    background: rgba(0, 119, 182, 0.08);
    padding: 3px 10px;
    border-radius: 20px;
    letter-spacing: 0.1px;
}

.feedback-card-date {
    font-size: 0.76rem;
    color: #999;
    font-weight: 500;
}

@media (max-width: 768px) {
    .feedback-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
}

/* ── Small screens ≤ 480px ── */
@media (max-width: 480px) {
    .calendar-controls   { padding: 14px 10px; gap: 10px; }
    .current-month       { font-size: 0.95rem; min-width: unset; }
    .nav-btn             { padding: 7px 10px; font-size: 0.78rem; gap: 4px; }
    .branch-select       { min-width: unset; width: 100%; font-size: 0.88rem; }
    .calendar-container  { padding: 0.8rem 0.4rem; }
    .calendar-grid       { gap: 3px; margin-top: 8px; }
    .calendar-day-header { padding: 7px 1px; font-size: 0.52rem; border-radius: 4px; }
    .calendar-day        { min-height: 72px; padding: 4px 2px; border-radius: 6px; border-width: 1px; justify-content: flex-start; align-items: center; gap: 2px;}
    .day-number          { font-size: 0.72rem; margin-bottom: 1px; font-weight: 700; align-self: unset; }
    .day-status          { font-size: 0.38rem; padding: 1px 2px; border-radius: 3px; }
    .day-today .day-status { font-size: 0.34rem; padding: 1px 2px; line-height: 1.2; word-break: break-word; white-space: normal; text-align: center; max-width: 100%; width: 100%; }
    .day-today::after    { font-size: 0.42rem; padding: 1px 4px; top: 2px; right: 3px; }
    .slot-pills          { gap: 1px; margin-top: 1px; }
    .slot-pill           { font-size: 0.38rem; padding: 1px 2px; border-radius: 4px; }
    .calendar-legend     { gap: 10px; padding: 10px; flex-wrap: wrap; justify-content: center; }
    .legend-item         { font-size: 0.7rem; gap: 5px; }
    .legend-box          { width: 12px; height: 12px; }
    .feedback-section-wrapper { padding: 30px 0; background-attachment: scroll; }
    .feedback-header     { flex-direction: column; gap: 8px; margin-bottom: 14px; }
    .header-split        { width: 100%; }
    .header-split h3     { font-size: 1.1rem; white-space: normal; }
    .custom-form-grid    { grid-template-columns: 1fr; gap: 14px; }
    .star-rating label   { font-size: 1.9rem; }
    .custom-textarea     { min-height: 110px; }
    .btn-submit-custom   { font-size: 0.92rem; }
    .container > div[style*="max-width:1100px"] { /* maintenance banner */
        flex-direction: column;
        gap: 8px;
        padding: 12px 14px;
        font-size: 0.85rem;
    }
    #booking-toast {
    padding: 14px 20px;
    font-size: 0.88rem;
    width: 90%;
    text-align: center;
    border-radius: 14px;
}
}

/* ── Very small phones ≤ 380px ── */
@media (max-width: 380px) {
    .calendar-day        { min-height: 44px; padding: 3px 1px; }
    .calendar-day-header { font-size: 0.46rem; padding: 5px 1px; }
    .day-number          { font-size: 0.7rem; }
    .day-status          { font-size: 0.36rem; }
    .slot-pill           { font-size: 0.34rem; }
    .current-month       { font-size: 0.85rem; }
    .nav-btn             { padding: 6px 8px; font-size: 0.72rem; }
    .header-split h3     { font-size: 0.95rem; }
    .custom-input        { padding: 10px; font-size: 0.85rem; }
    .custom-textarea     { min-height: 90px; font-size: 0.85rem; padding: 10px; }
    .star-rating label   { font-size: 1.55rem; }
    .btn-submit-custom   { font-size: 0.86rem; padding: 9px 14px; }
}
</style>

<header class="hero">
    <div class="hero-content">
        <h1>Welcome to Emiart Private Resorts!</h1>
        <p>Breathe in the calm, feel the breeze, and let every moment be unforgettable.</p>
        <a href="#calendar" class="btn-hero" onclick="scrollToCalendar(event)">Check Available Dates Now</a>
    </div>
</header>

<section class="container" style="padding-top: 4rem;">
    <h2 class="section-title">Branches</h2>

    <?php if ($globalMaintenance === 1): ?>
        <div style="max-width:1100px;margin:0 auto 1.5rem;background:linear-gradient(135deg,#fff3cd 0%,#ffe8a3 100%);border-left:5px solid #f39c12;padding:14px 22px;border-radius:12px;box-shadow:0 4px 16px rgba(243,156,18,0.15);display:flex;align-items:center;gap:14px;">
            <i class="fas fa-tools" style="font-size:1.5rem;color:#b06d00;flex-shrink:0;"></i>
            <div>
                <strong style="display:block;color:#6b3e00;">All branches are temporarily under maintenance.</strong>
                <span style="color:#8a5a00;font-size:0.9rem;">Online bookings are paused. Please check back soon.</span>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid">
        <?php foreach($branches as $b):
            $bAvail    = (int)$b['is_available'] === 1;
            $bBookable = $bAvail && $globalMaintenance !== 1;

            if ($globalMaintenance === 1) {
                $statusBg='#f39c12'; $statusTxt='Under Maintenance';
            } elseif ($bAvail) {
                $statusBg='#28a745'; $statusTxt='Available';
            } else {
                $statusBg='#e74c3c'; $statusTxt='Unavailable';
            }
        ?>
            <div class="card" style="position:relative;<?= $bBookable ? '' : 'opacity:0.92;' ?>">
                <div style="position:relative;">
                    <img src="<?= htmlspecialchars($b['image_url']) ?>" alt="Resort Image"
                         onerror="this.src='assets/default.jpg'"
                         style="<?= $bBookable ? '' : 'filter:grayscale(55%) brightness(0.9);' ?>">
                    <span style="position:absolute;top:10px;right:10px;background:<?= $statusBg ?>;color:#fff;padding:5px 12px;border-radius:50px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;box-shadow:0 3px 10px rgba(0,0,0,0.15);">
                        <?= $statusTxt ?>
                    </span>
                </div>
                <div class="card-content">
                    <h3><?= htmlspecialchars($b['branch_name']) ?></h3>
                    <p style="font-size: 0.9rem; color: #666; margin-bottom: 10px;">
                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($b['location']) ?>
                    </p>
                    <p><?= htmlspecialchars($b['opening_hours'] ?? 'Always Open') ?></p>
                    <?php if ($bBookable): ?>
                        <a href="book.php?branch=<?= $b['branch_id'] ?>" style="display:block; text-align:center; margin-top:15px; color:var(--primary); font-weight:bold;">Book Here &rarr;</a>
                    <?php else: ?>
                        <span style="display:block;text-align:center;margin-top:15px;color:#999;font-weight:bold;cursor:not-allowed;">
                            <i class="fas fa-lock"></i> Booking Unavailable
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php
    // ─── Resort Amenities — group by branch for the dropdown filter + 6/page pagination ──
    // Build a list of branches that actually have amenities (so the dropdown
    // never shows an empty option), and a parallel array of amenities indexed
    // by branch_id. Pagination is handled client-side for a smooth UX (no
    // full page reload when changing branch / page).
    $amenityBranchList = [];   // [{id, name}, …] — branches that appear in the dropdown
    $amenitiesByBranch = [];   // [branch_id => [ {amenity_name, description, availability}, … ]]
    foreach ($amenities as $a) {
        $bid = (int)$a['branch_id'];
        if (!isset($amenitiesByBranch[$bid])) {
            $amenitiesByBranch[$bid] = [];
            $amenityBranchList[] = [
                'id'   => $bid,
                'name' => $a['branch_name'],
            ];
        }
        $amenitiesByBranch[$bid][] = $a;
    }

    // Sort each branch's items: Available first, then alphabetical.
    foreach ($amenitiesByBranch as $bid => $items) {
        usort($items, function ($x, $y) {
            $xa = ($x['availability'] === 'Available') ? 0 : 1;
            $ya = ($y['availability'] === 'Available') ? 0 : 1;
            if ($xa !== $ya) return $xa <=> $ya;
            return strcasecmp($x['amenity_name'], $y['amenity_name']);
        });
        $amenitiesByBranch[$bid] = $items;
    }
    // Sort the dropdown list alphabetically too.
    usort($amenityBranchList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    // Pick a representative icon based on the amenity's name. Pure cosmetic
    // — keeps the data model schema-clean (no icon column needed).
    if (!function_exists('amenity_icon_index')) {
        function amenity_icon_index(string $name): string {
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
    }

    $AMENITY_PAGE_SIZE = 6;
?>

<style>
/* ─── Resort Amenities Section ───────────────────────────────────────────── */
.amenities-section {
    padding-top: 4rem;
    padding-bottom: 1rem;
}
.amenities-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 18px;
    margin-bottom: 2rem;
    text-align: center;
}
.amenities-header .section-title {
    margin: 0;
    text-align: center;
    width: 100%;
}

.amenity-filter {
    display: inline-flex; align-items: center; gap: 10px;
    background: white;
    padding: 6px 6px 6px 18px;
    border-radius: 50px;
    box-shadow: 0 4px 18px rgba(0, 119, 182, 0.10);
    border: 1.5px solid rgba(0, 119, 182, 0.18);
}
.amenity-filter label {
    font-size: 0.82rem; font-weight: 600;
    color: #475569;
    display: inline-flex; align-items: center; gap: 7px;
    white-space: nowrap;
}
.amenity-filter label i { color: var(--primary); font-size: 0.88rem; }
.amenity-filter select {
    appearance: none; -webkit-appearance: none;
    background: var(--primary) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24'%3E%3Cpath fill='white' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat right 14px center;
    color: white;
    border: none;
    padding: 9px 36px 9px 18px;
    border-radius: 50px;
    font-size: 0.86rem; font-weight: 600;
    cursor: pointer; outline: none;
    font-family: inherit;
    transition: filter 0.18s, box-shadow 0.18s;
    box-shadow: 0 3px 10px rgba(0, 119, 182, 0.28);
    min-width: 180px;
}
.amenity-filter select:hover, .amenity-filter select:focus {
    filter: brightness(1.08);
    box-shadow: 0 5px 14px rgba(0, 119, 182, 0.35);
}
.amenity-filter select option {
    background: white; color: #2c3e50;
}

/* Each branch lives in its own panel; only the active one is shown. */
.amenity-branch-panel { display: none; animation: amFade 0.32s ease-out; }
.amenity-branch-panel.is-active { display: block; }
@keyframes amFade {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* The grid of cards — three columns on desktop, two on tablet, one on phone. */
.amenity-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.8rem;
}

/* Individual amenity card. */
.amenity-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
    border: 1px solid rgba(0, 119, 182, 0.12);
    border-radius: 18px;
    padding: 1.4rem 1.4rem 1.5rem;
    display: flex; flex-direction: column; gap: 12px;
    position: relative;
    transition: transform 0.22s, box-shadow 0.22s, border-color 0.22s;
    overflow: hidden;
}
.amenity-card::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
    background: linear-gradient(180deg, var(--secondary) 0%, var(--primary) 100%);
    transition: width 0.22s;
}
.amenity-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 30px rgba(0, 119, 182, 0.14);
    border-color: rgba(0, 119, 182, 0.26);
}
.amenity-card:hover::before { width: 6px; }

.amenity-card-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 10px;
}
.amenity-icon {
    width: 46px; height: 46px; border-radius: 12px;
    background: linear-gradient(135deg, rgba(0,119,182,0.13) 0%, rgba(0,180,216,0.13) 100%);
    color: var(--primary);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.18rem; flex-shrink: 0;
    transition: transform 0.22s;
}
.amenity-card:hover .amenity-icon { transform: scale(1.06) rotate(-3deg); }

.amenity-status {
    font-size: 0.68rem; font-weight: 700;
    padding: 4px 11px; border-radius: 50px;
    text-transform: uppercase; letter-spacing: 0.05em;
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap;
}
.amenity-status.s-on  { background: rgba(40,167,69,0.15);  color: #1e7d36; }
.amenity-status.s-off { background: rgba(231,76,60,0.15);  color: #b03a2e; }
.amenity-status i { font-size: 0.62rem; }

.amenity-card h3 {
    margin: 0;
    font-size: 1.05rem;
    color: var(--primary-dark);
    font-weight: 700;
    line-height: 1.3;
}
.amenity-card p {
    margin: 0;
    font-size: 0.86rem;
    color: #555;
    line-height: 1.55;
    flex: 1;
}
.amenity-card .am-empty { color: #aaa; font-style: italic; font-size: 0.85rem; }

.amenity-card.is-unavail {
    background: linear-gradient(135deg, #fafafa 0%, #f3f4f7 100%);
    border-color: rgba(231, 76, 60, 0.18);
}
.amenity-card.is-unavail::before {
    background: linear-gradient(180deg, #e74c3c 0%, #c0392b 100%);
}
.amenity-card.is-unavail .amenity-icon {
    background: linear-gradient(135deg, rgba(231,76,60,0.13) 0%, rgba(231,76,60,0.07) 100%);
    color: #c0392b;
    filter: grayscale(15%);
}
.amenity-card.is-unavail h3 { color: #6b6b6b; }
.amenity-card.is-unavail p  { color: #888; }

/* Empty state ("No amenities for this branch"). */
.amenity-empty {
    text-align: center;
    padding: 3rem 1.5rem;
    background: white;
    border-radius: 18px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
    color: #888;
}
.amenity-empty i {
    font-size: 2.6rem;
    color: var(--primary);
    opacity: 0.55;
    margin-bottom: 0.8rem;
}
.amenity-empty h3 {
    color: var(--primary-dark);
    font-size: 1.1rem;
    margin: 0 0 0.3rem;
}
.amenity-empty p { margin: 0; font-size: 0.92rem; }

/* Pagination — only shown when items > page size.
 * The * width: auto / flex: 0 0 auto / box-sizing rules are intentional:
 * header.php / style.css can ship a global `button { width: 100% }` or
 * `form button` rule (we've seen this on other pages) that would otherwise
 * stretch each pagination button to fill the row. These resets keep the
 * buttons compact regardless of the host page's button styles.            */
.amenity-pagination {
    display: flex; justify-content: center; align-items: center;
    gap: 8px; flex-wrap: wrap; margin-top: 0.6rem;
}
.amenity-pagination .ap-btn {
    flex: 0 0 auto;
    width: auto;
    min-width: 40px;
    height: 40px;
    padding: 0 12px;
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;

    border: 1.5px solid #d8dee6;
    background: white;
    color: #475569;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: border-color 0.18s, color 0.18s, background 0.18s, box-shadow 0.18s, transform 0.15s;
    font-family: inherit;
    line-height: 1;
}
.amenity-pagination .ap-btn:hover:not(.is-disabled):not(.is-active) {
    border-color: var(--primary);
    color: var(--primary);
    transform: translateY(-1px);
}
.amenity-pagination .ap-btn.is-active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
    box-shadow: 0 3px 10px rgba(0, 119, 182, 0.3);
    cursor: default;
}
.amenity-pagination .ap-btn.is-disabled {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
}
.amenity-pagination .ap-btn i { font-size: 0.78rem; line-height: 1; }
.amenity-pagination .ap-info {
    font-size: 0.85rem;
    color: #94a3b8;
    margin-left: 10px;
    white-space: nowrap;
    align-self: center;
}
.amenity-pagination .ap-ellipsis {
    color: #aaa;
    padding: 0 4px;
    user-select: none;
    align-self: center;
    font-weight: 700;
}

@media (max-width: 960px) {
    .amenity-grid { grid-template-columns: repeat(2, 1fr); gap: 1.1rem; }
}
@media (max-width: 600px) {
    .amenity-filter select { min-width: 0; max-width: 100%; }
    .amenity-grid { grid-template-columns: 1fr; }
    .amenity-pagination { gap: 6px; }
    .amenity-pagination .ap-btn { min-width: 36px; height: 36px; font-size: 0.85rem; }
    .amenity-pagination .ap-info { width: 100%; text-align: center; margin-left: 0; margin-top: 4px; }
}
/* ── Tablet fix for feedback form (481px – 768px) ── */
@media (min-width: 481px) and (max-width: 768px) {
    .custom-form-grid {
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .feedback-section-wrapper { padding: 40px 0; background-attachment: scroll; }
    .feedback-header          { flex-direction: column; gap: 10px; }
    .header-split             { width: 100%; }
    .header-split h3          { font-size: 1.3rem; }
}
</style>

<section class="container amenities-section">
    <div class="amenities-header">
        <h2 class="section-title">Resort Amenities</h2>

        <?php if (!empty($amenityBranchList)): ?>
        <div class="amenity-filter">
            <label for="amenityBranchSelect">
                <i class="fas fa-building"></i> View by Branch:
            </label>
            <select id="amenityBranchSelect" aria-label="Filter amenities by branch">
                <?php foreach ($amenityBranchList as $i => $br): ?>
                    <option value="<?= (int)$br['id'] ?>" <?= $i === 0 ? 'selected' : '' ?>>
                        <?= htmlspecialchars($br['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($amenityBranchList)): ?>
        <!-- No amenities at all in the system. -->
        <div class="amenity-empty">
            <i class="fas fa-umbrella-beach"></i>
            <h3>No amenities available</h3>
            <p>Our team is still setting up. Please check back soon!</p>
        </div>
    <?php else: ?>
        <?php foreach ($amenityBranchList as $i => $br):
            $bid   = (int)$br['id'];
            $items = $amenitiesByBranch[$bid] ?? [];
            $count = count($items);
        ?>
        <div class="amenity-branch-panel <?= $i === 0 ? 'is-active' : '' ?>"
             data-branch-id="<?= $bid ?>"
             data-branch-name="<?= htmlspecialchars($br['name']) ?>"
             data-total="<?= $count ?>"
             data-page-size="<?= $AMENITY_PAGE_SIZE ?>">

            <?php if ($count === 0): ?>
                <div class="amenity-empty">
                    <i class="fas fa-info-circle"></i>
                    <h3>No amenities available</h3>
                    <p>This branch hasn't listed any amenities yet.</p>
                </div>
            <?php else: ?>
                <div class="amenity-grid">
                    <?php foreach ($items as $idx => $am):
                        $isAvail   = ($am['availability'] === 'Available');
                        $iconCls   = amenity_icon_index($am['amenity_name']);
                        // Tag each card with its index inside the branch so JS can
                        // hide / show ranges based on the current page.
                        $itemIndex = $idx;
                    ?>
                        <article class="amenity-card <?= $isAvail ? '' : 'is-unavail' ?>"
                                 data-item-index="<?= $itemIndex ?>">
                            <div class="amenity-card-head">
                                <div class="amenity-icon"><i class="fas <?= $iconCls ?>"></i></div>
                                <span class="amenity-status <?= $isAvail ? 's-on' : 's-off' ?>">
                                    <i class="fas <?= $isAvail ? 'fa-check-circle' : 'fa-tools' ?>"></i>
                                    <?= $isAvail ? 'Available' : 'Unavailable' ?>
                                </span>
                            </div>
                            <h3><?= htmlspecialchars($am['amenity_name']) ?></h3>
                            <?php if (!empty($am['description'])): ?>
                                <p><?= htmlspecialchars($am['description']) ?></p>
                            <?php else: ?>
                                <p class="am-empty">
                                    <?= $isAvail
                                        ? 'Available for all guests during your stay.'
                                        : 'Currently undergoing maintenance.' ?>
                                </p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination is rendered by JS based on current page state.
                     Hidden when the branch has <= page-size items. -->
                <div class="amenity-pagination" data-pagination-for="<?= $bid ?>"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<script>
/* ─── Resort Amenities — branch filter + per-branch pagination ────────────
 * All branch panels are rendered server-side; this script only controls
 * which panel is visible and which slice of cards within that panel is
 * shown. No page reload is needed when switching branches or pages.
 * ----------------------------------------------------------------------- */
(function () {
    const select = document.getElementById('amenityBranchSelect');
    if (!select) return;   // page has no amenities at all

    const panels = document.querySelectorAll('.amenity-branch-panel');
    // Track the active page per branch so switching branches and back
    // preserves the page the user was on.
    const branchPage = {};
    panels.forEach(p => { branchPage[p.dataset.branchId] = 1; });

    function renderPagination(panel) {
        const container = panel.querySelector('.amenity-pagination');
        if (!container) return;

        const total    = parseInt(panel.dataset.total, 10) || 0;
        const pageSize = parseInt(panel.dataset.pageSize, 10) || 6;
        const pages    = Math.max(1, Math.ceil(total / pageSize));
        const branchId = panel.dataset.branchId;
        const current  = branchPage[branchId] || 1;

        // No pagination needed if everything fits on one page.
        if (pages <= 1) { container.innerHTML = ''; return; }

        const parts = [];
        // Prev
        parts.push(
          `<button type="button" class="ap-btn ap-prev ${current <= 1 ? 'is-disabled' : ''}"
                  data-page="${current - 1}" aria-label="Previous page">
              <i class="fas fa-chevron-left"></i>
           </button>`
        );

        // Sliding window of page numbers (max 5 visible) with ellipsis
        const win = 2;
        let lo = Math.max(1, current - win);
        let hi = Math.min(pages, current + win);
        if (lo > 1) {
            parts.push(`<button type="button" class="ap-btn" data-page="1">1</button>`);
            if (lo > 2) parts.push(`<span class="ap-ellipsis">…</span>`);
        }
        for (let p = lo; p <= hi; p++) {
            parts.push(
              `<button type="button" class="ap-btn ${p === current ? 'is-active' : ''}"
                      data-page="${p}">${p}</button>`
            );
        }
        if (hi < pages) {
            if (hi < pages - 1) parts.push(`<span class="ap-ellipsis">…</span>`);
            parts.push(`<button type="button" class="ap-btn" data-page="${pages}">${pages}</button>`);
        }

        // Next
        parts.push(
          `<button type="button" class="ap-btn ap-next ${current >= pages ? 'is-disabled' : ''}"
                  data-page="${current + 1}" aria-label="Next page">
              <i class="fas fa-chevron-right"></i>
           </button>`
        );
        parts.push(`<span class="ap-info">Page ${current} of ${pages}</span>`);

        container.innerHTML = parts.join('');
    }

    function applyPage(panel) {
        const pageSize = parseInt(panel.dataset.pageSize, 10) || 6;
        const branchId = panel.dataset.branchId;
        const page     = branchPage[branchId] || 1;
        const start    = (page - 1) * pageSize;
        const end      = start + pageSize;

        panel.querySelectorAll('.amenity-card').forEach(card => {
            const idx = parseInt(card.dataset.itemIndex, 10);
            card.style.display = (idx >= start && idx < end) ? '' : 'none';
        });
        renderPagination(panel);
    }

    function showBranch(branchId) {
        let activePanel = null;
        panels.forEach(p => {
            const isMatch = (p.dataset.branchId === String(branchId));
            p.classList.toggle('is-active', isMatch);
            if (isMatch) activePanel = p;
        });
        if (activePanel) applyPage(activePanel);
    }

    // Wire up the dropdown.
    select.addEventListener('change', e => showBranch(e.target.value));

    // Pagination clicks (event-delegated so re-rendering doesn't lose handlers).
    document.querySelectorAll('.amenity-pagination').forEach(container => {
        container.addEventListener('click', e => {
            const btn = e.target.closest('.ap-btn');
            if (!btn || btn.classList.contains('is-disabled')) return;
            const panel = btn.closest('.amenity-branch-panel');
            if (!panel) return;
            const newPage = parseInt(btn.dataset.page, 10);
            if (!Number.isFinite(newPage) || newPage < 1) return;

            branchPage[panel.dataset.branchId] = newPage;
            applyPage(panel);

            // Keep the section roughly in view when paging on long pages,
            // without jarring full-page jumps.
            const sec = panel.closest('.amenities-section');
            if (sec) {
                const top = sec.getBoundingClientRect().top + window.pageYOffset - 80;
                if (window.pageYOffset > top + 80) {
                    window.scrollTo({ top, behavior: 'smooth' });
                }
            }
        });
    });

    // Initialize: render pagination + slice for the panel that's active on load.
    panels.forEach(p => {
        if (p.classList.contains('is-active')) applyPage(p);
    });
})();
</script>

<div class="feedback-section-wrapper">
    <div class="container">
        <div class="feedback-header">
            <div class="header-split">
                <div class="line"></div>
                <h3>Add yours!</h3>
                <div class="line"></div>
            </div>
            <div class="header-split">
                <div class="line"></div>
                <h3>What do you think?</h3>
                <div class="line"></div>
            </div>
        </div>

        <form method="POST">
            <div class="custom-form-grid">
                <div>
                    <div class="custom-input-group">
                        <input type="text" name="name" class="custom-input" placeholder="Email Address" 
                               value="<?= isset($_SESSION['username']) ? $_SESSION['username'] : '' ?>" required>
                        <span class="helper-text">Enter your email</span>
                    </div>
                    <div class="custom-input-group">
                        <input type="text" name="occupation" class="custom-input" placeholder="Occupation">
                        <span class="helper-text">Enter your job</span>
                    </div>
                    <div class="custom-input-group">
                        <select name="branch_id" class="custom-input" required>
                            <option value="" disabled selected>Select a branch</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>">
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="helper-text">Which branch did you visit?</span>
                </div>
                </div>

                <div>
                    <textarea name="comments" id="feedbackComments" class="custom-textarea" placeholder="Tell us your opinion" maxlength="50" required></textarea>
                    <div class="word-count-bar">
                        <span class="word-count-label">Chars:</span>
                        <div class="word-count-progress">
                            <div class="word-count-progress-fill" id="wcFill" style="width:0%"></div>
                        </div>
                        <span class="word-count-num" id="wcNum">0 / 50</span>
                    </div>
                    <span class="helper-text">How would you describe your stay with us?</span>
                </div>

                <div style="text-align: center; display: flex; flex-direction: column; justify-content: center;">
                    <div class="star-rating">
                        <input type="radio" id="star5" name="rating" value="5" checked><label for="star5" title="5 stars">★</label>
                        <input type="radio" id="star4" name="rating" value="4"><label for="star4" title="4 stars">★</label>
                        <input type="radio" id="star3" name="rating" value="3"><label for="star3" title="3 stars">★</label>
                        <input type="radio" id="star2" name="rating" value="2"><label for="star2" title="2 stars">★</label>
                        <input type="radio" id="star1" name="rating" value="1"><label for="star1" title="1 star">★</label>
                    </div>
                    <button type="submit" name="submit_feedback" class="btn-submit-custom">Submit</button>
                </div>
            </div>
        </form>
    </div>
</div>

<section class="container">
    <h2 class="section-title">What They Say</h2>
    <div class="feedback-grid">
        <?php foreach($feedbacks as $f): ?>
            <div class="feedback-card">
                <div class="feedback-card-header">
                    <span class="feedback-card-name"><?= htmlspecialchars($f['full_name']) ?></span>
                    <div class="feedback-card-stars">
                        <?php for($i = 0; $i < intval($f['rating']); $i++): ?>
                            <i class="fas fa-star"></i>
                        <?php endfor; ?>
                        <?php for($i = intval($f['rating']); $i < 5; $i++): ?>
                            <i class="far fa-star" style="color:#ddd;"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                <p class="feedback-card-comment"><?= htmlspecialchars($f['comments']) ?></p>
                <div class="feedback-card-footer">
                    <?php if (!empty($f['branch_name'])): ?>
                        <span class="feedback-card-branch">
                            <i class="fas fa-map-marker-alt" style="font-size:0.7rem;"></i>
                            <?= htmlspecialchars($f['branch_name']) ?>
                        </span>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <span class="feedback-card-date">
                        <?= date('M d, Y', strtotime($f['feedback_date'])) ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- NEW IMPROVED CALENDAR -->
<section class="container" style="padding-top: 4rem; padding-bottom: 4rem;">
    <h2 class="section-title">Availability Calendar</h2>

    <?php if ($globalMaintenance === 1 || empty($bookableBranches)): ?>
        <div style="max-width:1100px;margin:0 auto 1.5rem;background:linear-gradient(135deg,#fff3cd 0%,#ffe8a3 100%);border-left:5px solid #f39c12;padding:14px 22px;border-radius:12px;box-shadow:0 4px 16px rgba(243,156,18,0.15);display:flex;align-items:center;gap:14px;">
            <i class="fas <?= $globalMaintenance === 1 ? 'fa-tools' : 'fa-info-circle' ?>" style="font-size:1.5rem;color:#b06d00;flex-shrink:0;"></i>
            <div>
                <strong style="display:block;color:#6b3e00;">
                    <?= $globalMaintenance === 1
                        ? 'All branches are temporarily under maintenance.'
                        : 'No branches are currently accepting bookings.' ?>
                </strong>
                <span style="color:#8a5a00;font-size:0.9rem;">
                    The calendar is shown for reference only &mdash; new bookings are paused.
                </span>
            </div>
        </div>
    <?php endif; ?>

<!-- Calendar Controls -->
<div class="calendar-controls">
    <div class="calendar-nav">
        <?php 
        $prevDisabled = ($prevYear < date('Y') || ($prevYear == date('Y') && $prevMonth < date('m')));
        if ($prevDisabled): ?>
            <span class="nav-btn disabled">
                <i class="fas fa-chevron-left"></i> Previous
            </span>
        <?php else: ?>
            <a href="javascript:void(0)" class="nav-btn" onclick="navigateCalendar(<?= $prevMonth ?>, <?= $prevYear ?>, '<?= $selectedBranch ?>')">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
        <?php endif; ?>
        
        <div class="current-month">
            <i class="far fa-calendar-alt"></i> <?= $monthName ?>
        </div>
        
        <a href="javascript:void(0)" class="nav-btn" onclick="navigateCalendar(<?= $nextMonth ?>, <?= $nextYear ?>, '<?= $selectedBranch ?>')">
            Next <i class="fas fa-chevron-right"></i>
        </a>
    </div>
        
        <div class="branch-selector">
            <label for="branchSelect">
                <i class="fas fa-building"></i> Select Branch:
            </label>
            <?php if (!empty($bookableBranches)): ?>
            <select id="branchSelect" class="branch-select" onchange="changeBranch(this.value)">
                <?php foreach($bookableBranches as $b): ?>
                    <option value="<?= $b['branch_id'] ?>" <?= $selectedBranch == $b['branch_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <span style="color:#888;font-style:italic;">
                <i class="fas fa-info-circle"></i> No branches available for booking right now
            </span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Calendar -->
    <div class="calendar-container" id="calendar">
        <div class="calendar-grid">
            <!-- Day Headers -->
            <div class="calendar-day-header">Mon</div>
            <div class="calendar-day-header">Tue</div>
            <div class="calendar-day-header">Wed</div>
            <div class="calendar-day-header">Thu</div>
            <div class="calendar-day-header">Fri</div>
            <div class="calendar-day-header">Sat</div>
            <div class="calendar-day-header">Sun</div>

            <?php
            // Empty cells before first day
            for ($x = 1; $x < $firstDayOfWeek; $x++) {
                echo "<div></div>";
            }

            // Days of the month
            $bookingsBlocked = ($globalMaintenance === 1 || empty($bookableBranches) || empty($selectedBranch));
            $todayStr        = date('Y-m-d');
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateStr     = sprintf("%s-%02d-%02d", $selectedYear, $selectedMonth, $day);
                $isPast      = strtotime($dateStr) < strtotime($todayStr);
                $isToday     = ($dateStr === $todayStr);
                $isClickable = false;
                $slotPillsHtml  = '';
                $extraInfoHtml  = '';

                if ($isPast) {
                    $class      = "day-past";
                    $statusText = "Past";
                } elseif ($isToday) {
                    // Same-day bookings are not allowed (mirrors the rule in book.php).
                    // Today is shown but not clickable, with a clear "Today" label so
                    // guests understand why it's not selectable.
                    $class      = "day-today";
                    $statusText = "Today &mdash; Booking Closed";
                } else {
                        // Check each reservation_type slot independently.
                        $dayBooked       = isset($slotsByDate[$dateStr]['Day']);
                        $overnightBooked = isset($slotsByDate[$dateStr]['Overnight']);

                        if ($dayBooked && $overnightBooked) {
                            // Both slots occupied → truly fully booked
                            $class      = "day-booked";
                            $statusText = "Fully Booked";
                        } elseif ($dayBooked || $overnightBooked) {
                            // One slot still open → partial availability
                            $class      = "day-limited";
                            $statusText = "Partial";
                            $isClickable = true;
                        } else {
                            // No slots taken → completely available
                            $class      = "day-available";
                            $statusText = "Available";
                            $isClickable = true;
                        }

                        // If bookings are globally blocked (maintenance / no bookable branch),
                        // make every future day non-clickable.
                        if ($bookingsBlocked) {
                            $isClickable = false;
                        }

                        // Slot pills: show Day and Overnight status as mini-badges
                        $slotPillsHtml  = "<div class='slot-pills'>";
                        $slotPillsHtml .= "<span class='slot-pill " . ($dayBooked       ? 'slot-taken' : 'slot-open') . "'>&#9728; Day</span>";
                        $slotPillsHtml .= "<span class='slot-pill " . ($overnightBooked ? 'slot-taken' : 'slot-open') . "'>&#9790; Night</span>";
                        $slotPillsHtml .= "</div>";
                }

                $clickableClass = $isClickable ? 'clickable' : '';
                $onclickAttr    = $isClickable ? "onclick=\"bookDate('{$dateStr}', '{$selectedBranch}')\"" : '';

                echo "<div class='calendar-day {$class} {$clickableClass}' {$onclickAttr} data-date='{$dateStr}'>";
                echo "<div class='day-number'>{$day}</div>";
                echo "<div class='day-status'>{$statusText}</div>";
                echo $slotPillsHtml;
                echo $extraInfoHtml;
                echo "</div>";
            }
            ?>
        </div>
        
        <!-- Legend -->
        <div class="calendar-legend">
            <div class="legend-item">
                <div class="legend-box legend-available"></div>
                <span>Available</span>
            </div>
            <div class="legend-item">
                <div class="legend-box legend-limited"></div>
                <span>Partial (1 slot open)</span>
            </div>
            <div class="legend-item">
                <div class="legend-box legend-booked"></div>
                <span>Fully Booked</span>
            </div>
            <div class="legend-item">
                <div class="legend-box legend-today"></div>
                <span>Today (Booking Closed)</span>
            </div>
            <div class="legend-item">
                <div class="legend-box legend-past"></div>
                <span>Past Date</span>
            </div>
        </div>
        
        <?php
            $branchName = '';
            if (!empty($selectedBranch)) {
                foreach($branches as $b) {
                    if ($b['branch_id'] == $selectedBranch) {
                        $branchName = $b['branch_name'];
                        break;
                    }
                }
            }
        ?>
        <p style="margin-top: 20px; text-align: center; color: #666; font-size: 0.9rem;">
            <i class="fas fa-info-circle"></i>
            <?php if ($branchName !== ''): ?>
                Showing slot availability for <strong><?= htmlspecialchars($branchName) ?></strong>.
                Each date has a <strong>Day Tour</strong> and an <strong>Overnight</strong> slot &mdash; booking one leaves the other open.
            <?php else: ?>
                There are no branches available for booking right now.
            <?php endif; ?>
        </p>
    </div>
</section>

<script>
const wcTextarea = document.getElementById('feedbackComments');
const wcNum      = document.getElementById('wcNum');
const wcFill     = document.getElementById('wcFill');
const WC_MAX     = 50;
const WC_MIN     = 20;

function updateCharCount() {
    const cc = wcTextarea.value.length;
    wcNum.textContent = `${cc} / ${WC_MAX}`;
    wcFill.style.width = Math.min(cc / WC_MAX * 100, 100) + '%';
    wcNum.className = 'word-count-num';
    if (cc >= WC_MAX)           { wcNum.classList.add('over'); wcFill.style.background = '#d62828'; }
    else if (cc >= WC_MAX * 0.85) { wcNum.classList.add('warn'); wcFill.style.background = '#e07b00'; }
    else if (cc >= WC_MIN)      { wcNum.classList.add('ok');   wcFill.style.background = '#2a9d8f'; }
    else                        {                               wcFill.style.background = '#aaa'; }
}

if (wcTextarea) {
    wcTextarea.addEventListener('input', updateCharCount);
    updateCharCount();
}
</script>

<script>
// Check if user is logged in (passed from PHP)
const isLoggedIn = <?php echo isset($_SESSION['customer_id']) ? 'true' : 'false'; ?>;

function changeBranch(branchId) {
    const urlParams = new URLSearchParams(window.location.search);
    const month = urlParams.get('month') || '<?= $selectedMonth ?>';
    const year = urlParams.get('year') || '<?= $selectedYear ?>';
    navigateCalendar(month, year, branchId);
}

// Smooth scroll to calendar when navigating
if (window.location.hash === '#calendar') {
    setTimeout(() => {
        document.getElementById('calendar').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
}

// Smooth scroll to calendar from hero button
function scrollToCalendar(event) {
    event.preventDefault();
    document.getElementById('calendar').scrollIntoView({ 
        behavior: 'smooth', 
        block: 'center' 
    });
    // Optional: Add a small delay then highlight the calendar
    setTimeout(() => {
        document.getElementById('calendar').style.transform = 'scale(1.02)';
        setTimeout(() => {
            document.getElementById('calendar').style.transform = 'scale(1)';
        }, 300);
    }, 500);
}

// Book a specific date
function bookDate(date, branchId) {
    // Check if user is logged in
    if (!isLoggedIn) {
        // Show login prompt
        showLoginModal(date, branchId);
    } else {
        // Proceed to booking page
        proceedToBooking(date, branchId);
    }
}

// Show login modal
function showLoginModal(date, branchId) {
    const formattedDate = new Date(date).toLocaleDateString('en-US', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    
    const branchText = 'this branch';
    
    const modal = document.createElement('div');
    modal.id = 'loginModal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 10000;
        animation: fadeIn 0.3s;
    `;
    
    modal.innerHTML = `
        <div style="
            background: white;
            padding: 45px 40px 40px;
            border-radius: 16px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            text-align: center;
            animation: slideUp 0.3s;
        ">
            <h2 style="color: #1a1a2e; margin-bottom: 10px; font-size: 1.7rem; font-weight: 700;">Welcome Back</h2>
            <p style="color: #888; margin-bottom: 8px; font-size: 1rem;">
                You need to be logged in to book <strong style="color:#333">${branchText}</strong> for:
            </p>
            <p style="color: var(--primary); font-weight: 600; font-size: 1.05rem; margin-bottom: 30px;">
                ${formattedDate}
            </p>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="login.php?redirect=book&date=${date}&branch=${branchId}" 
                   style="
                       background: linear-gradient(135deg, #1a3a6e 0%, #0077b6 100%);
                       color: white;
                       padding: 14px 30px;
                       border-radius: 8px;
                       text-decoration: none;
                       font-weight: 600;
                       font-size: 1rem;
                       transition: opacity 0.2s;
                       display: block;
                   "
                   onmouseover="this.style.opacity='0.9';"
                   onmouseout="this.style.opacity='1';">
                    LOG IN
                </a>
                <a href="signup.php" 
                   style="
                       background: white;
                       color: #0077b6;
                       border: 2px solid #0077b6;
                       padding: 13px 30px;
                       border-radius: 8px;
                       text-decoration: none;
                       font-weight: 600;
                       font-size: 1rem;
                       transition: 0.2s;
                       display: block;
                   "
                   onmouseover="this.style.background='#f0f7ff';"
                   onmouseout="this.style.background='white';">
                    Create Account
                </a>
                <button onclick="closeLoginModal()" 
                   style="
                       background: none;
                       color: #aaa;
                       border: none;
                       padding: 10px;
                       font-size: 0.95rem;
                       cursor: pointer;
                       transition: color 0.2s;
                   "
                   onmouseover="this.style.color='#0077b6';"
                   onmouseout="this.style.color='#aaa';">
                    Cancel
                </button>
            </div>
        </div>
    `;
    
    // Add animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);
    
    document.body.appendChild(modal);
    
    // Close on background click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeLoginModal();
        }
    });
}

// Close login modal
function closeLoginModal() {
    const modal = document.getElementById('loginModal');
    if (modal) {
        modal.style.animation = 'fadeOut 0.3s';
        setTimeout(() => modal.remove(), 300);
    }
}

// Proceed to booking
function proceedToBooking(date, branchId) {
    // Redirect to booking page with date and branch parameters
    window.location.href = `book.php?date=${date}&branch=${branchId}`;
}

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLoginModal();
    }
});
</script>

<style>
@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}
</style>

<script>
function navigateCalendar(month, year, branch) {
    fetch('?month=' + month + '&year=' + year + '&branch=' + branch)
        .then(res => res.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // Update calendar grid
            const newCalendar = doc.getElementById('calendar');
            const oldCalendar = document.getElementById('calendar');
            if (newCalendar && oldCalendar) {
                oldCalendar.innerHTML = newCalendar.innerHTML;
            }

            // Update entire calendar-nav (prev/next buttons + month title)
            const newNav = doc.querySelector('.calendar-nav');
            const oldNav = document.querySelector('.calendar-nav');
            if (newNav && oldNav) {
                oldNav.innerHTML = newNav.innerHTML;
            }

            // Update URL without page reload
            history.pushState(null, '', '?month=' + month + '&year=' + year + '&branch=' + branch + '#calendar');
        });
}
</script>

<?php include 'footer.php'; ?>
