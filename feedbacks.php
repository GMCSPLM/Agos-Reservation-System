<?php 
include 'header.php';

// ─── Constants ────────────────────────────────────────────────────────────────
define('COMMENT_MIN_CHARS', 20);   // must type at least 20 characters
define('COMMENT_MAX_CHARS', 50);  // hard cap at 500 characters
define('OCCUPATION_MAX_CHARS', 20);
define('NAME_MAX_CHARS', 60);

// ─── Form Errors Array ────────────────────────────────────────────────────────
$errors   = [];
$success  = false;
$old      = [];   // repopulate fields on error

// ─── POST Handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {

    if (!isset($_SESSION['customer_id'])) {
        echo "<script>alert('You must be logged in to submit feedback.'); window.location='login.php';</script>";
        exit;
    }

    // Collect raw values
    $rating     = filter_input(INPUT_POST, 'rating',     FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) ?: 5;
    $occupation = trim($_POST['occupation'] ?? '');
    $comment    = trim($_POST['comments']   ?? '');
    $branch_id  = isset($_POST['branch_id']) && is_numeric($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;

    $old = ['occupation' => $occupation, 'comments' => $comment, 'branch_id' => $branch_id, 'rating' => $rating];

    // ── Validate occupation ──────────────────────────────────────────────────
    if ($occupation !== '' && strlen($occupation) > OCCUPATION_MAX_CHARS) {
        $errors['occupation'] = 'Occupation must be ' . OCCUPATION_MAX_CHARS . ' characters or fewer.';
    }
    if ($occupation !== '' && !preg_match('/^[\p{L}\p{N}\s\-\/\.,]+$/u', $occupation)) {
        $errors['occupation'] = 'Occupation contains invalid characters.';
    }

    // ── Validate branch ──────────────────────────────────────────────────────
    if (!$branch_id) {
        $errors['branch_id'] = 'Please select a branch you visited.';
    }

    // ── Validate comment ─────────────────────────────────────────────────────
    if (empty($comment)) {
        $errors['comments'] = 'Comment cannot be empty.';
    } else {
        $charCount = mb_strlen($comment);
        if ($charCount < COMMENT_MIN_CHARS) {
            $errors['comments'] = "Your comment is too short — please write at least " . COMMENT_MIN_CHARS . " characters.";
        } elseif ($charCount > COMMENT_MAX_CHARS) {
            $errors['comments'] = "Your comment exceeds the " . COMMENT_MAX_CHARS . "-character limit (you used $charCount characters).";
        }
    }

    // ── Insert if no errors ──────────────────────────────────────────────────
    if (empty($errors)) {
        $safe_comment    = htmlspecialchars($comment);
        $safe_occupation = htmlspecialchars($occupation);
        $final_comment   = $safe_occupation ? "$safe_comment (Occupation: $safe_occupation)" : $safe_comment;

        try {
            $stmt = $pdo->prepare("INSERT INTO feedback (customer_id, branch_id, rating, comments, feedback_date) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['customer_id'], $branch_id, $rating, $final_comment]);
            $success = true;
            $old = [];
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast('Thank you for your feedback!', 'success');
                });
            </script>";
        } catch (Exception $e) {
            $errors['db'] = 'Something went wrong. Please try again.';
        }
    }
}

$branches  = $pdo->query("SELECT * FROM branches")->fetchAll();
$feedbacks = $pdo->query("SELECT f.*, c.full_name, b.branch_name 
                           FROM feedback f 
                           JOIN customers c ON f.customer_id = c.customer_id 
                           LEFT JOIN branches b ON f.branch_id = b.branch_id 
                           ORDER BY feedback_date DESC
                           LIMIT 9")->fetchAll();

// Session-based username for autocomplete
$loggedInName = $_SESSION['username'] ?? '';
$isLoggedIn   = isset($_SESSION['customer_id']);
?>

<style>
/* ── Toast Notification ───────────────────────────────────────────────────── */
#toast-container {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.toast {
    min-width: 280px;
    padding: 14px 20px;
    border-radius: 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.93rem;
    font-weight: 600;
    color: #fff;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideIn 0.35s ease forwards;
}
.toast.success { background: #0077b6; }
.toast.error   { background: #d62828; }
@keyframes slideIn {
    from { opacity: 0; transform: translateX(60px); }
    to   { opacity: 1; transform: translateX(0); }
}
@keyframes fadeOut {
    to { opacity: 0; transform: translateX(60px); }
}

/* ── Background & Wrapper ─────────────────────────────────────────────────── */
.feedback-section-wrapper {
    background: linear-gradient(rgba(255,255,255,0.3), rgba(255,255,255,0.3)), url('Ripple-Effect.png');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    padding: 80px 0;
    margin-bottom: 4rem;
    box-shadow: inset 0 0 20px rgba(0,0,0,0.1);
}

/* ── Header ───────────────────────────────────────────────────────────────── */
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

/* ── Form Grid ────────────────────────────────────────────────────────────── */
.custom-form-grid {
    display: grid;
    grid-template-columns: 1fr 1.5fr 1fr;
    gap: 30px;
    max-width: 1000px;
    margin: 0 auto;
}

/* ── Inputs ───────────────────────────────────────────────────────────────── */
.custom-input-group { margin-bottom: 20px; position: relative; }

.custom-input {
    width: 100%;
    padding: 15px;
    border: 1px solid rgba(255,255,255,0.8);
    border-radius: 6px;
    background: rgba(255,255,255,0.9);
    font-size: 1rem;
    outline: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: border-color 0.25s, box-shadow 0.25s;
    font-family: 'Poppins', sans-serif;
}
.custom-input:focus {
    background: white;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.custom-input.is-invalid {
    border-color: #d62828 !important;
    background: rgba(255, 240, 240, 0.95);
}
.custom-input.is-valid {
    border-color: #2a9d8f !important;
}

/* ── Autocomplete / Locked Name ───────────────────────────────────────────── */
.name-locked-wrapper {
    position: relative;
}
.name-locked-wrapper .custom-input[readonly] {
    background: rgba(2, 62, 138, 0.06);
    color: #023e8a;
    font-weight: 600;
    cursor: not-allowed;
    border-color: rgba(2, 62, 138, 0.3) !important;
}
.lock-badge {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.72rem;
    background: #023e8a;
    color: #fff;
    padding: 2px 8px;
    border-radius: 20px;
    font-family: 'Poppins', sans-serif;
    font-weight: 600;
    pointer-events: none;
}

/* ── Helper / Error Texts ─────────────────────────────────────────────────── */
.helper-text {
    font-size: 0.85rem;
    color: #023e8a;
    font-weight: 500;
    margin-top: 5px;
    display: block;
    text-shadow: 0 1px 2px rgba(255,255,255,0.8);
}
.field-error {
    font-size: 0.82rem;
    color: #d62828;
    font-weight: 600;
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 4px;
    background: rgba(255,255,255,0.85);
    padding: 4px 8px;
    border-radius: 4px;
}
.field-error::before { content: "⚠"; font-size: 0.85rem; }

/* ── Textarea ─────────────────────────────────────────────────────────────── */
.textarea-wrapper { position: relative; height: 100%; }
.custom-textarea {
    width: 100%;
    height: 100%;
    min-height: 180px;
    padding: 15px;
    padding-bottom: 36px; /* room for counter */
    border: 1px solid rgba(255,255,255,0.8);
    border-radius: 6px;
    background: rgba(255,255,255,0.9);
    resize: none;
    font-family: 'Poppins', sans-serif;
    font-size: 1rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: border-color 0.25s, box-shadow 0.25s;
    box-sizing: border-box;
}
.custom-textarea:focus {
    background: white;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    outline: none;
}
.custom-textarea.is-invalid { border-color: #d62828 !important; background: rgba(255,240,240,0.95); }
.custom-textarea.is-valid   { border-color: #2a9d8f !important; }

/* Word count badge */
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
.word-count-num {
    font-size: 0.8rem;
    font-weight: 700;
    color: #023e8a;
    transition: color 0.2s;
}
.word-count-num.warn  { color: #e07b00; }
.word-count-num.over  { color: #d62828; }
.word-count-num.ok    { color: #2a9d8f; }
.word-count-label {
    font-size: 0.75rem;
    color: #555;
}
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

/* ── Stars ────────────────────────────────────────────────────────────────── */
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
    color: rgba(255,255,255,0.8);
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

/* ── Submit Button ────────────────────────────────────────────────────────── */
.btn-submit-custom {
    background: rgba(255,255,255,0.9);
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
    font-family: 'Poppins', sans-serif;
}
.btn-submit-custom:hover { background: #023e8a; color: white; }
.btn-submit-custom:disabled {
    background: rgba(200,200,200,0.6);
    border-color: #aaa;
    color: #aaa;
    cursor: not-allowed;
}

/* ── DB-level error banner ────────────────────────────────────────────────── */
.alert-error {
    background: rgba(214, 40, 40, 0.12);
    border: 1px solid #d62828;
    color: #d62828;
    padding: 12px 18px;
    border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 18px;
    max-width: 1000px;
    margin-left: auto;
    margin-right: auto;
}

/* ── "What They Say" Feedback Cards ──────────────────────────────────────── */
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

/* ── ADDED: Form responsive ── */
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
    .star-rating label  { font-size: 2.2rem; }
    .custom-textarea    { min-height: 130px; }
}

/* ── ADDED: 320px–375px screens ── */
@media (max-width: 375px) {
    .feedback-section-wrapper  { padding: 26px 0; }
    .header-split h3           { font-size: 1rem; margin: 0 8px; }
    .custom-input              { padding: 11px; font-size: 0.88rem; }
    .custom-textarea           { min-height: 100px; font-size: 0.88rem; padding: 11px; }
    .star-rating               { gap: 4px; margin-bottom: 14px; }
    .star-rating label         { font-size: 1.65rem; }
    .btn-submit-custom         { font-size: 0.95rem; padding: 9px 16px; }
    .word-count-bar            { padding: 3px 8px; }
    .word-count-label          { font-size: 0.68rem; }
    .word-count-num            { font-size: 0.72rem; }
    .helper-text               { font-size: 0.78rem; }
    .field-error               { font-size: 0.75rem; }
    .feedback-card             { padding: 18px 14px 14px; }
    .feedback-card-name        { font-size: 0.92rem; }
    .feedback-card-comment     { font-size: 0.82rem; }
}
</style>

<!-- Toast Container -->
<div id="toast-container"></div>

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

        <?php if (!empty($errors['db'])): ?>
            <div class="alert-error">⚠ <?= htmlspecialchars($errors['db']) ?></div>
        <?php endif; ?>

        <form method="POST" id="feedbackForm" novalidate>
            <div class="custom-form-grid">

                <!-- ── Column 1: Name / Occupation / Branch ── -->
                <div>
                    <!-- Name (autocomplete + lock when signed in) -->
                    <div class="custom-input-group">
                        <div class="name-locked-wrapper">
                            <input
                                type="text"
                                name="name"
                                id="fieldName"
                                class="custom-input <?= !empty($errors['name']) ? 'is-invalid' : '' ?>"
                                placeholder="Email Address"
                                maxlength="<?= NAME_MAX_CHARS ?>"
                                value="<?= htmlspecialchars($loggedInName) ?>"
                                <?= $isLoggedIn ? 'readonly' : 'required' ?>
                            >
                            </div>
                        <?php if (!empty($errors['name'])): ?>
                            <span class="field-error"><?= htmlspecialchars($errors['name']) ?></span>
                        <?php else: ?>
                            <span class="helper-text">
                                <?= $isLoggedIn ? 'Signed in as <strong>' . htmlspecialchars($loggedInName) . '</strong>' : 'Enter your email' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Occupation -->
                    <div class="custom-input-group">
                        <input
                            type="text"
                            name="occupation"
                            id="fieldOccupation"
                            class="custom-input <?= !empty($errors['occupation']) ? 'is-invalid' : '' ?>"
                            placeholder="Occupation"
                            maxlength="<?= OCCUPATION_MAX_CHARS ?>"
                            value="<?= htmlspecialchars($old['occupation'] ?? '') ?>"
                        >
                        <?php if (!empty($errors['occupation'])): ?>
                            <span class="field-error"><?= htmlspecialchars($errors['occupation']) ?></span>
                        <?php else: ?>
                            <span class="helper-text">Enter your job</em></span>
                        <?php endif; ?>
                    </div>

                    <!-- Branch -->
                    <div class="custom-input-group">
                        <select
                            name="branch_id"
                            id="fieldBranch"
                            class="custom-input <?= !empty($errors['branch_id']) ? 'is-invalid' : '' ?>"
                            required
                        >
                            <option value="" disabled <?= empty($old['branch_id']) ? 'selected' : '' ?>>Select a branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option
                                    value="<?= $b['branch_id'] ?>"
                                    <?= (!empty($old['branch_id']) && $old['branch_id'] == $b['branch_id']) ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['branch_id'])): ?>
                            <span class="field-error"><?= htmlspecialchars($errors['branch_id']) ?></span>
                        <?php else: ?>
                            <span class="helper-text">Which branch did you visit?</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Column 2: Comments + word counter ── -->
                <div style="display:flex; flex-direction:column;">
                    <div class="textarea-wrapper" style="flex:1;">
                        <textarea
                            name="comments"
                            id="fieldComments"
                            class="custom-textarea <?= !empty($errors['comments']) ? 'is-invalid' : '' ?>"
                            placeholder="Tell us your opinion"
                            maxlength="<?= COMMENT_MAX_CHARS ?>"
                            required
                        ><?= htmlspecialchars($old['comments'] ?? '') ?></textarea>
                    </div>

                    <!-- Character count progress bar -->
                    <div class="word-count-bar">
                        <span class="word-count-label">Chars:</span>
                        <div class="word-count-progress">
                            <div class="word-count-progress-fill" id="wcFill" style="width:0%"></div>
                        </div>
                        <span class="word-count-num" id="wcNum">0 / <?= COMMENT_MAX_CHARS ?></span>
                    </div>

                    <?php if (!empty($errors['comments'])): ?>
                        <span class="field-error" id="commentsError"><?= htmlspecialchars($errors['comments']) ?></span>
                    <?php else: ?>
                        <span class="helper-text" id="commentsHint">
                            Min <?= COMMENT_MIN_CHARS ?> chars · Max <?= COMMENT_MAX_CHARS ?> chars
                        </span>
                    <?php endif; ?>
                </div>

                <!-- ── Column 3: Stars + Submit ── -->
                <div style="text-align:center; display:flex; flex-direction:column; justify-content:center;">
                    <div class="star-rating">
                        <?php
                        $selectedRating = $old['rating'] ?? 5;
                        for ($s = 5; $s >= 1; $s--): ?>
                            <input type="radio" id="star<?= $s ?>" name="rating" value="<?= $s ?>"
                                   <?= $selectedRating == $s ? 'checked' : '' ?>>
                            <label for="star<?= $s ?>" title="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">★</label>
                        <?php endfor; ?>
                    </div>
                    <button type="submit" name="submit_feedback" id="btnSubmit" class="btn-submit-custom">
                        Submit
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<!-- ── Feedback Cards ──────────────────────────────────────────────────────── -->
<section class="container" style="padding-bottom: 4rem;">
    <h2 class="section-title">What They Say</h2>
    <div class="feedback-grid">
        <?php foreach ($feedbacks as $f): ?>
            <div class="feedback-card">
                <div class="feedback-card-header">
                    <span class="feedback-card-name"><?= htmlspecialchars($f['full_name']) ?></span>
                    <div class="feedback-card-stars">
                        <?php for ($i = 0; $i < intval($f['rating']); $i++): ?>
                            <i class="fas fa-star"></i>
                        <?php endfor; ?>
                        <?php for ($i = intval($f['rating']); $i < 5; $i++): ?>
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

<script>
// ── Config (mirrors PHP constants) ────────────────────────────────────────────
const MIN_CHARS = <?= COMMENT_MIN_CHARS ?>;
const MAX_CHARS = <?= COMMENT_MAX_CHARS ?>;

// ── Toast ──────────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<span>${type === 'success' ? '✔' : '✖'}</span> ${msg}`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.4s ease forwards';
        setTimeout(() => toast.remove(), 400);
    }, 3500);
}

const textarea   = document.getElementById('fieldComments');
const wcNum      = document.getElementById('wcNum');
const wcFill     = document.getElementById('wcFill');
const btnSubmit  = document.getElementById('btnSubmit');

function updateCharCount() {
    const cc = textarea.value.length;
    wcNum.textContent = `${cc} / ${MAX_CHARS}`;

    const pct = Math.min(cc / MAX_CHARS * 100, 100);
    wcFill.style.width = pct + '%';

    // Colour states
    wcNum.className = 'word-count-num';
    if (cc >= MAX_CHARS) {
        wcNum.classList.add('over');
        wcFill.style.background = '#d62828';
    } else if (cc >= MAX_CHARS * 0.85) {
        wcNum.classList.add('warn');
        wcFill.style.background = '#e07b00';
    } else if (cc >= MIN_CHARS) {
        wcNum.classList.add('ok');
        wcFill.style.background = '#2a9d8f';
    } else {
        wcFill.style.background = '#aaa';
    }
}

if (textarea) {
    textarea.addEventListener('input', updateCharCount);
    updateCharCount(); // init on page load (for repopulated values)
}

// ── Client-side validation before submit ──────────────────────────────────────
document.getElementById('feedbackForm').addEventListener('submit', function(e) {
    let valid = true;

    // Branch
    const branch = document.getElementById('fieldBranch');
    clearError(branch);
    if (!branch.value) {
        showFieldError(branch, 'Please select a branch you visited.');
        valid = false;
    }

    // Name (only if not locked/readonly)
    const nameField = document.getElementById('fieldName');
    clearError(nameField);
    if (!nameField.readOnly) {
        if (nameField.value.trim().length < 2) {
            showFieldError(nameField, 'Please enter your name (at least 2 characters).');
            valid = false;
        } else if (nameField.value.trim().length > <?= NAME_MAX_CHARS ?>) {
            showFieldError(nameField, 'Name is too long (max <?= NAME_MAX_CHARS ?> characters).');
            valid = false;
        }
    }

    // Comments
    const comments = document.getElementById('fieldComments');
    clearError(comments);
    const cc = comments.value.length;
    if (cc < MIN_CHARS) {
        showFieldError(comments, `Comment too short — please write at least ${MIN_CHARS} characters.`);
        valid = false;
    } else if (cc > MAX_CHARS) {
        showFieldError(comments, `Comment exceeds the ${MAX_CHARS}-character limit.`);
        valid = false;
    }

    if (!valid) {
        e.preventDefault();
        showToast('Please fix the highlighted fields before submitting.', 'error');
        // Scroll to first error
        const firstErr = document.querySelector('.is-invalid');
        if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});

function showFieldError(input, msg) {
    input.classList.add('is-invalid');
    input.classList.remove('is-valid');
    let errSpan = input.parentElement.querySelector('.field-error');
    if (!errSpan) {
        errSpan = document.createElement('span');
        errSpan.className = 'field-error';
        input.parentElement.appendChild(errSpan);
    }
    errSpan.textContent = msg;
}

function clearError(input) {
    input.classList.remove('is-invalid');
    const errSpan = input.parentElement.querySelector('.field-error');
    if (errSpan) errSpan.remove();
}

// Mark valid on blur
['fieldOccupation', 'fieldBranch', 'fieldComments'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('blur', () => {
        if (el.value.trim()) {
            el.classList.remove('is-invalid');
            el.classList.add('is-valid');
        }
    });
});
</script>

<?php include 'footer.php'; ?>