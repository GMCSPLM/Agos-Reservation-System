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

$branches = $pdo->query("SELECT * FROM branches")->fetchAll();
$amenities = $pdo->query("SELECT a.*, b.branch_name FROM amenities a JOIN branches b ON a.branch_id = b.branch_id")->fetchAll();
$feedbacks = $pdo->query("SELECT f.*, c.full_name, b.branch_name FROM feedback f JOIN customers c ON f.customer_id = c.customer_id LEFT JOIN branches b ON f.branch_id = b.branch_id ORDER BY feedback_date DESC LIMIT 9")->fetchAll();

// Calendar Variables
$selectedMonth = $_GET['month'] ?? date('m');
$selectedYear = $_GET['year'] ?? date('Y');
// Default to the first branch instead of 'all' — the "All Branches" option has been removed
$firstBranch    = $branches[0]['branch_id'] ?? null;
$selectedBranch = $_GET['branch'] ?? $firstBranch;
// Reject 'all' in case it arrives via a stale URL
if ($selectedBranch === 'all') $selectedBranch = $firstBranch;

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
$slotsByDate = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $slotsByDate[$row['reservation_date']][$row['reservation_type']] = true;
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

.calendar-day:hover:not(.day-past):not(.day-booked) {
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
    
    .branch-select {
        width: 100%;
    }
    
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

/* ── ADDED: Feedback form responsive ── */
@media (max-width: 768px) {
    .feedback-section-wrapper {
        padding: 40px 0;
        background-attachment: scroll;
    }
    .feedback-header {
        flex-direction: column;
        gap: 10px;
        margin-bottom: 20px;
    }
    .header-split {
        width: 100%;
    }
    .header-split h3 {
        font-size: 1.3rem;
        white-space: normal;
    }
    .custom-form-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    .star-rating label { font-size: 2.2rem; }
}

/* ── ADDED: Calendar responsive for 320px–480px ── */
@media (max-width: 480px) {
    .calendar-container    { padding: 1rem 0.75rem; }
    .calendar-grid         { gap: 3px; }
    .calendar-day          { min-height: 62px; padding: 6px 3px; }
    .calendar-day-header   { font-size: 0.62rem; padding: 8px 2px; }
    .day-number            { font-size: 0.9rem; margin-bottom: 3px; }
    .day-status            { font-size: 0.52rem; padding: 2px 3px; }
    .slot-pills            { gap: 2px; margin-top: 2px; }
    .slot-pill             { font-size: 0.48rem; padding: 1px 3px; }
    .current-month         { font-size: 1rem; min-width: auto; }
    .nav-btn               { padding: 8px 12px; font-size: 0.82rem; gap: 5px; }
    .calendar-controls     { padding: 16px; }
    .calendar-legend       { gap: 14px; padding: 14px; }
    .legend-item           { font-size: 0.78rem; }
    .legend-box            { width: 15px; height: 15px; }
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
</section>

<section class="container" style="padding-top: 4rem;">
    <h2 class="section-title">Resort Amenities</h2>
    <div class="grid">
        <?php foreach($amenities as $a): ?>
            <div class="card" style="border-left: 4px solid var(--secondary);">
                <div class="card-content">
                    <h3><?= htmlspecialchars($a['amenity_name']) ?></h3>
                    <small style="color: #888; text-transform: uppercase;"><?= htmlspecialchars($a['branch_name']) ?></small>
                    <p style="margin-top: 10px;"><?= htmlspecialchars($a['description']) ?></p>
                    <span style="background: #e0f7fa; color: #006064; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem;"><?= $a['availability'] ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

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
            <select id="branchSelect" class="branch-select" onchange="changeBranch(this.value)">
                <?php foreach($branches as $b): ?>
                    <option value="<?= $b['branch_id'] ?>" <?= $selectedBranch == $b['branch_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateStr     = sprintf("%s-%02d-%02d", $selectedYear, $selectedMonth, $day);
                $isPast      = strtotime($dateStr) < strtotime(date('Y-m-d'));
                $isClickable = false;
                $slotPillsHtml  = '';
                $extraInfoHtml  = '';

                if ($isPast) {
                    $class      = "day-past";
                    $statusText = "Past";
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
                <div class="legend-box legend-past"></div>
                <span>Past Date</span>
            </div>
        </div>
        
        <?php
            $branchName = '';
            foreach($branches as $b) {
                if ($b['branch_id'] == $selectedBranch) {
                    $branchName = $b['branch_name'];
                    break;
                }
            }
        ?>
        <p style="margin-top: 20px; text-align: center; color: #666; font-size: 0.9rem;">
            <i class="fas fa-info-circle"></i> Showing slot availability for <strong><?= htmlspecialchars($branchName) ?></strong>.
            Each date has a <strong>Day Tour</strong> and an <strong>Overnight</strong> slot — booking one leaves the other open.
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
