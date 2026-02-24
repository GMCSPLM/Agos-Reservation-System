<?php 
include 'header.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback'])) {
    if (!isset($_SESSION['customer_id'])) {
        echo "<script>alert('You must be logged in to submit feedback.'); window.location='login.php';</script>";
    } else {
        $rating = $_POST['rating'] ?? 5;
        $occupation = htmlspecialchars($_POST['occupation']);
        $raw_comment = htmlspecialchars($_POST['comments']);
        
        $final_comment = $occupation ? "$raw_comment (Occupation: $occupation)" : $raw_comment;
        
        try {
            $stmt = $pdo->prepare("INSERT INTO feedback (customer_id, rating, comments, feedback_date) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['customer_id'], $rating, $final_comment]);
            echo "<script>alert('Thank you for your feedback!'); window.location='index.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('Error submitting feedback.');</script>";
        }
    }
}

$branches = $pdo->query("SELECT * FROM branches")->fetchAll();
$amenities = $pdo->query("SELECT a.*, b.branch_name FROM amenities a JOIN branches b ON a.branch_id = b.branch_id")->fetchAll();
$feedbacks = $pdo->query("SELECT f.*, c.full_name FROM feedback f JOIN customers c ON f.customer_id = c.customer_id ORDER BY feedback_date DESC LIMIT 6")->fetchAll();

// Calendar Variables
$selectedMonth = $_GET['month'] ?? date('m');
$selectedYear = $_GET['year'] ?? date('Y');
$selectedBranch = $_GET['branch'] ?? 'all';

// Validate inputs
$selectedMonth = max(1, min(12, intval($selectedMonth)));
$selectedYear = max(2024, min(2030, intval($selectedYear)));

$monthName = date('F Y', strtotime("$selectedYear-$selectedMonth-01"));
$daysInMonth = date('t', strtotime("$selectedYear-$selectedMonth-01"));
$firstDayOfWeek = date('N', strtotime("$selectedYear-$selectedMonth-01")); 

// Build query based on branch selection
if ($selectedBranch === 'all') {
    // For "all branches", show if ANY branch is fully booked
    $stmt = $pdo->prepare("
        SELECT reservation_date, COUNT(DISTINCT branch_id) as booked_branches
        FROM reservations 
        WHERE MONTH(reservation_date) = ? 
        AND YEAR(reservation_date) = ? 
        AND status IN ('Confirmed', 'Pending')
        GROUP BY reservation_date
    ");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create lookup array
    $bookingsByDate = [];
    foreach ($bookings as $b) {
        $bookingsByDate[$b['reservation_date']] = $b['booked_branches'];
    }
    
    $totalBranches = count($branches);
} else {
    // For specific branch, show if THAT branch is booked
    $stmt = $pdo->prepare("
        SELECT reservation_date, COUNT(*) as booking_count
        FROM reservations 
        WHERE branch_id = ?
        AND MONTH(reservation_date) = ? 
        AND YEAR(reservation_date) = ? 
        AND status IN ('Confirmed', 'Pending')
        GROUP BY reservation_date
    ");
    $stmt->execute([$selectedBranch, $selectedMonth, $selectedYear]);
    $bookings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
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
    background: linear-gradient(rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.3)), url('https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1920&q=80');
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
                    <?php if($a['deposit_amount'] > 0): ?>
                        <span class="price" style="font-size: 0.9rem;">Deposit: ₱<?= number_format($a['deposit_amount'], 2) ?></span>
                    <?php endif; ?>
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
                        <input type="text" name="name" class="custom-input" placeholder="Name" 
                               value="<?= isset($_SESSION['username']) ? $_SESSION['username'] : '' ?>" required>
                        <span class="helper-text">Enter your name</span>
                    </div>
                    <div class="custom-input-group">
                        <input type="text" name="occupation" class="custom-input" placeholder="Occupation">
                        <span class="helper-text">Enter your job</span>
                    </div>
                </div>

                <div>
                    <textarea name="comments" class="custom-textarea" placeholder="Tell us your opinion!" required></textarea>
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
                <div class="rating">
                    <?php for($i=0; $i<$f['rating']; $i++) echo '<i class="fas fa-star"></i>'; ?>
                </div>
                <p>"<?= htmlspecialchars($f['comments']) ?>"</p>
                <div style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
                    <div style="width: 40px; height: 40px; background: #ddd; border-radius: 50%;"></div>
                    <div>
                        <strong style="display: block;"><?= htmlspecialchars($f['full_name']) ?></strong>
                        <small style="color: #888;"><?= date('M d, Y', strtotime($f['feedback_date'])) ?></small>
                    </div>
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
                <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>&branch=<?= $selectedBranch ?>#calendar" class="nav-btn">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
            <?php endif; ?>
            
            <div class="current-month">
                <i class="far fa-calendar-alt"></i> <?= $monthName ?>
            </div>
            
            <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>&branch=<?= $selectedBranch ?>#calendar" class="nav-btn">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        
        <div class="branch-selector">
            <label for="branchSelect">
                <i class="fas fa-building"></i> Select Branch:
            </label>
            <select id="branchSelect" class="branch-select" onchange="changeBranch(this.value)">
                <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
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
                $dateStr = sprintf("%s-%02d-%02d", $selectedYear, $selectedMonth, $day);
                $isPast = strtotime($dateStr) < strtotime(date('Y-m-d'));
                $isClickable = false;
                
                if ($isPast) {
                    // Past dates
                    $class = "day-past";
                    $statusText = "Past";
                    $statusClass = "";
                } else {
                    if ($selectedBranch === 'all') {
                        // All branches view
                        $bookedCount = isset($bookingsByDate[$dateStr]) ? $bookingsByDate[$dateStr] : 0;
                        
                        if ($bookedCount >= $totalBranches) {
                            $class = "day-booked";
                            $statusText = "Fully Booked";
                        } elseif ($bookedCount > 0) {
                            $class = "day-limited";
                            $statusText = "Limited";
                            $availableCount = $totalBranches - $bookedCount;
                            $isClickable = true;
                        } else {
                            $class = "day-available";
                            $statusText = "Available";
                            $isClickable = true;
                        }
                    } else {
                        // Specific branch view
                        $isBooked = isset($bookings[$dateStr]) && $bookings[$dateStr] > 0;
                        
                        if ($isBooked) {
                            $class = "day-booked";
                            $statusText = "Booked";
                        } else {
                            $class = "day-available";
                            $statusText = "Available";
                            $isClickable = true;
                        }
                    }
                }

                // Add clickable class and onclick event
                $clickableClass = $isClickable ? 'clickable' : '';
                $onclickAttr = $isClickable ? "onclick=\"bookDate('$dateStr', '$selectedBranch')\"" : '';
                
                echo "<div class='calendar-day $class $clickableClass' $onclickAttr data-date='$dateStr'>";
                echo "<div class='day-number'>$day</div>";
                echo "<div class='day-status'>$statusText</div>";
                
                // Show available count for limited availability
                if (!$isPast && $selectedBranch === 'all' && isset($availableCount) && $class === 'day-limited') {
                    echo "<div class='booking-count'>$availableCount available</div>";
                    unset($availableCount); // Reset for next iteration
                }
                
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
            <?php if ($selectedBranch === 'all'): ?>
            <div class="legend-item">
                <div class="legend-box legend-limited"></div>
                <span>Limited Availability</span>
            </div>
            <?php endif; ?>
            <div class="legend-item">
                <div class="legend-box legend-booked"></div>
                <span><?= $selectedBranch === 'all' ? 'Fully Booked' : 'Booked' ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-box legend-past"></div>
                <span>Past Date</span>
            </div>
        </div>
        
        <?php if ($selectedBranch === 'all'): ?>
        <p style="margin-top: 20px; text-align: center; color: #666; font-size: 0.9rem;">
            <i class="fas fa-info-circle"></i> Showing availability across all <?= count($branches) ?> branches. 
            Select a specific branch to see detailed availability.
        </p>
        <?php else: 
            $branchName = '';
            foreach($branches as $b) {
                if ($b['branch_id'] == $selectedBranch) {
                    $branchName = $b['branch_name'];
                    break;
                }
            }
        ?>
        <p style="margin-top: 20px; text-align: center; color: #666; font-size: 0.9rem;">
            <i class="fas fa-info-circle"></i> Showing availability for <strong><?= htmlspecialchars($branchName) ?></strong>
        </p>
        <?php endif; ?>
    </div>
</section>

<script>
// Check if user is logged in (passed from PHP)
const isLoggedIn = <?php echo isset($_SESSION['customer_id']) ? 'true' : 'false'; ?>;

function changeBranch(branchId) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('branch', branchId);
    window.location.href = '?' + urlParams.toString() + '#calendar';
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
    
    const branchText = branchId === 'all' ? 'any available branch' : 'this branch';
    
    const modal = document.createElement('div');
    modal.id = 'loginModal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 10000;
        animation: fadeIn 0.3s;
    `;
    
    modal.innerHTML = `
        <div style="
            background: white;
            padding: 40px;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            text-align: center;
            animation: slideUp 0.3s;
        ">
            <div style="
                width: 80px;
                height: 80px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 50%;
                margin: 0 auto 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 40px;
            ">
                🔒
            </div>
            <h2 style="color: #2c3e50; margin-bottom: 15px; font-size: 1.8rem;">Login Required</h2>
            <p style="color: #666; margin-bottom: 10px; font-size: 1.1rem;">
                You need to be logged in to book <strong>${branchText}</strong> for:
            </p>
            <p style="color: var(--primary); font-weight: bold; font-size: 1.2rem; margin-bottom: 25px;">
                📅 ${formattedDate}
            </p>
            <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                <a href="login.php?redirect=book&date=${date}&branch=${branchId}" 
                   style="
                       background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                       color: white;
                       padding: 15px 30px;
                       border-radius: 10px;
                       text-decoration: none;
                       font-weight: 600;
                       font-size: 1.1rem;
                       transition: 0.3s;
                       display: inline-block;
                   "
                   onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 5px 20px rgba(102, 126, 234, 0.4)';"
                   onmouseout="this.style.transform=''; this.style.boxShadow='';">
                    🔑 Login
                </a>
                <a href="signup.php" 
                   style="
                       background: white;
                       color: var(--primary);
                       border: 2px solid var(--primary);
                       padding: 15px 30px;
                       border-radius: 10px;
                       text-decoration: none;
                       font-weight: 600;
                       font-size: 1.1rem;
                       transition: 0.3s;
                       display: inline-block;
                   "
                   onmouseover="this.style.background='var(--primary)'; this.style.color='white';"
                   onmouseout="this.style.background='white'; this.style.color='var(--primary)';">
                    ✨ Sign Up
                </a>
                <button onclick="closeLoginModal()" 
                   style="
                       background: #e0e0e0;
                       color: #666;
                       border: none;
                       padding: 15px 30px;
                       border-radius: 10px;
                       font-weight: 600;
                       font-size: 1.1rem;
                       cursor: pointer;
                       transition: 0.3s;
                   "
                   onmouseover="this.style.background='#d0d0d0';"
                   onmouseout="this.style.background='#e0e0e0';">
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
            from { transform: translateY(50px); opacity: 0; }
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

<?php include 'footer.php'; ?>