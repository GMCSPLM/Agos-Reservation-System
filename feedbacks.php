<?php 
include 'header.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback'])) {
    if (!isset($_SESSION['customer_id'])) {
        echo "<script>alert('You must be logged in to submit feedback.'); window.location='login.php';</script>";
    } else {
        $rating = $_POST['rating'] ?? 5;
        $occupation = htmlspecialchars($_POST['occupation']);
        $raw_comment = htmlspecialchars($_POST['comments']);
        $branch_id = isset($_POST['branch_id']) && is_numeric($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
        
        $final_comment = $occupation ? "$raw_comment (Occupation: $occupation)" : $raw_comment;
        
        try {
            $stmt = $pdo->prepare("INSERT INTO feedback (customer_id, branch_id, rating, comments, feedback_date) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['customer_id'], $branch_id, $rating, $final_comment]);
            echo "<script>alert('Thank you for your feedback!'); window.location='feedbacks.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('Error submitting feedback.');</script>";
        }
    }
}

$branches = $pdo->query("SELECT * FROM branches")->fetchAll();
$feedbacks = $pdo->query("SELECT f.*, c.full_name, b.branch_name FROM feedback f JOIN customers c ON f.customer_id = c.customer_id LEFT JOIN branches b ON f.branch_id = b.branch_id ORDER BY feedback_date DESC")->fetchAll();
?>

<style>
/* Updated Background to Sea Image */
.feedback-section-wrapper {
    background: linear-gradient(rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.3)), url('Ripple-Effect.png');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    padding: 80px 0;
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
</style>

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

<section class="container" style="padding-bottom: 4rem;">
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
                        <?php if (!empty($f['branch_name'])): ?>
                            <small style="color: #023e8a; font-weight: 600;"><?= htmlspecialchars($f['branch_name']) ?></small><br>
                        <?php endif; ?>
                        <small style="color: #888;"><?= date('M d, Y', strtotime($f['feedback_date'])) ?></small>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php include 'footer.php'; ?>