<?php 
include 'header.php'; 

$error = '';

/* ─────────────────────────────────────────────────────────────
   Country-based phone rules (digits AFTER the country code)
   - Use 'length' for an exact required number of digits
   - Use 'min' / 'max' for a range (some countries vary)
   ───────────────────────────────────────────────────────────── */
$phoneRules = [
    '+63'  => ['length' => 11, 'name' => 'Philippines'],
    '+1'   => ['length' => 10, 'name' => 'US/Canada'],
    '+44'  => ['min' => 10, 'max' => 11, 'name' => 'United Kingdom'],
    '+61'  => ['length' => 9,  'name' => 'Australia'],
    '+81'  => ['min' => 10, 'max' => 11, 'name' => 'Japan'],
    '+82'  => ['min' => 9,  'max' => 11, 'name' => 'South Korea'],
    '+86'  => ['length' => 11, 'name' => 'China'],
    '+91'  => ['length' => 10, 'name' => 'India'],
    '+971' => ['length' => 9,  'name' => 'UAE'],
    '+966' => ['length' => 9,  'name' => 'Saudi Arabia'],
    '+65'  => ['length' => 8,  'name' => 'Singapore'],
    '+60'  => ['min' => 9,  'max' => 10, 'name' => 'Malaysia'],
    '+62'  => ['min' => 9,  'max' => 12, 'name' => 'Indonesia'],
    '+66'  => ['length' => 9,  'name' => 'Thailand'],
    '+84'  => ['min' => 9,  'max' => 10, 'name' => 'Vietnam'],
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $raw_contact    = trim($_POST['contact']);
    $country_code   = trim($_POST['country_code'] ?? '+63');
    $contact_digits = preg_replace('/\D/', '', $raw_contact);
    $full_contact   = $country_code . $contact_digits;

    $email   = trim($_POST['email']);
    $name    = trim($_POST['name']);
    $agreed  = isset($_POST['terms']);

    // Country-aware phone validation
    $rule = $phoneRules[$country_code] ?? null;
    $phoneValid = false;
    $expectedDesc = 'a valid number';
    if ($rule) {
        $len = strlen($contact_digits);
        if (isset($rule['length'])) {
            $phoneValid   = $len === $rule['length'];
            $expectedDesc = $rule['length'] . ' digits';
        } else {
            $phoneValid   = $len >= $rule['min'] && $len <= $rule['max'];
            $expectedDesc = $rule['min'] . '–' . $rule['max'] . ' digits';
        }
    }

    if (!preg_match("/^[a-zA-Z\s\-'\.]+$/", $name)) {
        $error = 'Full name must contain letters only.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($contact_digits)) {
        $error = 'Please enter a valid contact number.';
    } elseif (!$phoneValid) {
        $error = 'Phone number must be ' . $expectedDesc . ' for ' . ($rule['name'] ?? 'the selected country') . '.';
    } elseif ($_POST['password'] !== $_POST['confirm_password']) {
        $error = 'Passwords do not match.';
    } elseif (strlen($_POST['password']) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!$agreed) {
        $error = 'You must agree to the Terms and Conditions to sign up.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO customers (full_name, email, contact_number) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $full_contact]);
            $cust_id = $pdo->lastInsertId();

            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, customer_id) VALUES (?, ?, 'Customer', ?)");
            $stmt->execute([$email, $hash, $cust_id]);

            $pdo->commit();
            echo "<script>alert('Success! Please login.'); window.location='login.php';</script>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error: Email is already registered.';
        }
    }
}
?>

<style>
    /* Phone row: narrow code selector + wide number input */
.phone-row {
    display: flex;
    flex-direction: row;
    gap: 10px;
    align-items: stretch;
    width: 100%;
    box-sizing: border-box;
}
.phone-row select {
    flex: 0 0 120px;
    width: 120px !important;
    cursor: pointer;
    box-sizing: border-box;
}
.phone-row input {
    flex: 1 1 0;
    min-width: 0;
    width: 0 !important;
    box-sizing: border-box;
}
@media (max-width: 380px) {
    .phone-row { gap: 6px; }
    .phone-row select {
        flex: 0 0 95px;
        width: 95px !important;
        font-size: 0.82rem;
        padding-left: 6px;
        padding-right: 6px;
    }
}

    /* ── Password eye button: locked in place ──────────────────
       The bug was the global `button:hover { transform: translateY(-2px) }`
       overriding the centering transform on hover. Using !important on
       the toggle's transform for ALL states keeps it pinned.            */
    .pw-wrapper {
        position: relative;
        display: block;
        width: 100%;
    }
    .pw-wrapper input {
        width: 100%;
        padding-right: 44px !important;
        box-sizing: border-box;
    }
    .pw-toggle {
        position: absolute !important;
        top: 50% !important;
        right: 14px !important;
        transform: translateY(-50%) !important;
        width: 28px !important;
        height: 28px !important;
        padding: 0 !important;
        margin: 0 !important;
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        cursor: pointer;
        color: #888;
        font-size: 1.05rem;
        line-height: 1;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: color 0.2s;
    }
    .pw-toggle:hover,
    .pw-toggle:focus,
    .pw-toggle:active {
        transform: translateY(-50%) !important; /* never shifts */
        background: transparent !important;
        box-shadow: none !important;
        color: var(--primary);
    }

    /* ── Terms and Conditions block ──────────────────────────── */
    .terms-group {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin: 6px 0 4px 0;
        font-size: 0.92rem;
        color: var(--text-dark);
    }
    .terms-group input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin-top: 3px;
        flex-shrink: 0;
        cursor: pointer;
        accent-color: var(--primary);
    }
    .terms-group label {
        cursor: pointer;
        line-height: 1.45;
        font-weight: 400;
    }
    .terms-group a {
        color: var(--primary);
        text-decoration: underline;
        font-weight: 500;
    }
    #termsError {
        font-size: 0.82rem;
        margin-top: 2px;
        display: block;
        min-height: 18px;
    }

    /* ── Disabled submit button ─────────────────────────────── */
    button[type="submit"]:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        filter: grayscale(0.3);
    }
    button[type="submit"]:disabled:hover {
        transform: none;
        box-shadow: none;
    }

    /* ── Terms modal ────────────────────────────────────────── */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .modal-overlay.active { display: flex; animation: fadeIn 0.25s; }
    .modal-box {
        background: white;
        max-width: 600px;
        width: 100%;
        max-height: 80vh;
        border-radius: 16px;
        padding: 30px;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    .modal-box h3 { color: var(--primary-dark); margin-bottom: 12px; font-size: 1.5rem; }
    .modal-box h4 { color: var(--primary-dark); margin: 14px 0 4px; font-size: 1rem; }
    .modal-box p  { font-size: 0.92rem; line-height: 1.55; margin-bottom: 6px; color: var(--text-dark); }
    .modal-close {
        margin-top: 18px;
        width: auto !important;
        padding: 10px 28px;
        float: right;
    }
    .modal-box::after { content: ""; display: block; clear: both; }
</style>

<div class="auth-box">
    <h2>Create Account</h2>

    <?php if ($error): ?>
        <p style="color: #d32f2f; background: #ffebee; padding: 10px 14px; border-radius: 8px; 
                  font-size: 0.9rem; margin-bottom: 15px; text-align: center;">
            <?= htmlspecialchars($error) ?>
        </p>
    <?php endif; ?>

    <form method="POST" id="signupForm" novalidate>

        <!-- Full Name -->
        <div class="form-group">
            <input type="text" name="name" id="name" placeholder="Full Name" 
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            <small id="nameError" style="font-size:0.82rem; margin-top:4px; display:block; min-height:18px;"></small>
        </div>

        <!-- Email -->
        <div class="form-group">
            <input type="email" name="email" id="email" placeholder="Email Address" 
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            <small id="emailError" style="font-size:0.82rem; margin-top:4px; display:block; min-height:18px;"></small>
        </div>

        <!-- Contact Number with Country Code -->
        <div class="form-group">
            <div class="phone-row">
                <select name="country_code" id="countryCode">
                    <option value="+63"  data-len="11"                  data-name="Philippines">🇵🇭 +63</option>
                    <option value="+1"   data-len="10"                  data-name="US/Canada">🇺🇸 +1</option>
                    <option value="+44"  data-min="10" data-max="11"    data-name="United Kingdom">🇬🇧 +44</option>
                    <option value="+61"  data-len="9"                   data-name="Australia">🇦🇺 +61</option>
                    <option value="+81"  data-min="10" data-max="11"    data-name="Japan">🇯🇵 +81</option>
                    <option value="+82"  data-min="9"  data-max="11"    data-name="South Korea">🇰🇷 +82</option>
                    <option value="+86"  data-len="11"                  data-name="China">🇨🇳 +86</option>
                    <option value="+91"  data-len="10"                  data-name="India">🇮🇳 +91</option>
                    <option value="+971" data-len="9"                   data-name="UAE">🇦🇪 +971</option>
                    <option value="+966" data-len="9"                   data-name="Saudi Arabia">🇸🇦 +966</option>
                    <option value="+65"  data-len="8"                   data-name="Singapore">🇸🇬 +65</option>
                    <option value="+60"  data-min="9"  data-max="10"    data-name="Malaysia">🇲🇾 +60</option>
                    <option value="+62"  data-min="9"  data-max="12"    data-name="Indonesia">🇮🇩 +62</option>
                    <option value="+66"  data-len="9"                   data-name="Thailand">🇹🇭 +66</option>
                    <option value="+84"  data-min="9"  data-max="10"    data-name="Vietnam">🇻🇳 +84</option>
                </select>
                <input type="tel" name="contact" id="contact" placeholder="Contact Number"
                       value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>"
                       inputmode="numeric" required>
            </div>
            <small id="contactError" style="font-size:0.82rem; margin-top:4px; display:block; min-height:18px;"></small>
        </div>

        <!-- Password -->
        <div class="form-group">
            <div class="pw-wrapper">
                <input type="password" name="password" id="password" placeholder="Password" required>
                <button type="button" class="pw-toggle" onclick="togglePw('password', 'eyeIcon1')" tabindex="-1" aria-label="Show password">
                    <i class="fas fa-eye" id="eyeIcon1"></i>
                </button>
            </div>
            <small id="passwordError" style="font-size:0.82rem; margin-top:4px; display:block; min-height:18px;"></small>
        </div>

        <!-- Confirm Password -->
        <div class="form-group">
            <div class="pw-wrapper">
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required>
                <button type="button" class="pw-toggle" onclick="togglePw('confirm_password', 'eyeIcon2')" tabindex="-1" aria-label="Show password">
                    <i class="fas fa-eye" id="eyeIcon2"></i>
                </button>
            </div>
            <small id="confirmError" style="font-size:0.82rem; margin-top:4px; display:block; min-height:18px;"></small>
        </div>

        <!-- Terms and Conditions -->
        <div class="form-group" style="margin-bottom: 10px;">
            <div class="terms-group">
                <input type="checkbox" name="terms" id="terms" 
                       <?= isset($_POST['terms']) ? 'checked' : '' ?>>
                <label for="terms">
                    I have read and agree to the
                    <a href="#" id="openTerms">Terms and Conditions</a>.
                </label>
            </div>
            <small id="termsError"></small>
        </div>

        <button type="submit" id="submitBtn" disabled>SIGN UP</button>
    </form>
    <p style="text-align: center; margin-top: 15px;">Already have an account? <a href="login.php" style="color: var(--primary);">Log In</a></p>
</div>

<!-- Terms & Conditions Modal -->
<div class="modal-overlay" id="termsModal" role="dialog" aria-modal="true" aria-labelledby="termsTitle">
    <div class="modal-box">
        <h3 id="termsTitle">Terms and Conditions</h3>
        <p>By creating an account, you agree to the following terms. Please read them carefully before signing up.</p>

        <h4>1. Account Registration</h4>
        <p>You agree to provide accurate, current, and complete information during registration and to keep your account details up to date.</p>

        <h4>2. Booking and Reservations</h4>
        <p>All bookings are subject to availability. Reservations are confirmed only after a successful payment or deposit, where applicable.</p>

        <h4>3. Cancellation and Refund Policy</h4>
        <p>All transactions, payments, and deposits made through our platform are <strong>strictly non-refundable</strong>. Once a booking or payment has been confirmed, no refunds will be issued under any circumstances, including but not limited to cancellations, no-shows, early check-outs, or changes in travel plans. Guests are encouraged to review their booking details carefully before completing any transaction.</p>

        <h4>4. Privacy</h4>
        <p>Your personal information is handled in accordance with our Privacy Policy. We do not share your data with third parties without your consent, except as required by law.</p>

        <h4>5. Guest Conduct</h4>
        <p>Guests are expected to follow house rules and respect the property, staff, and other guests. Any violation may result in account suspension or removal.</p>

        <h4>6. Changes to Terms</h4>
        <p>We reserve the right to update these terms at any time. Continued use of the service after changes constitutes acceptance of the updated terms.</p>

        <button type="button" class="modal-close" id="closeTerms">Close</button>
    </div>
</div>

<script>
    /* ── Build phone rules from option data attributes ────── */
    const phoneRules = {};
    document.querySelectorAll('#countryCode option').forEach(opt => {
        phoneRules[opt.value] = {
            length: opt.dataset.len ? parseInt(opt.dataset.len) : null,
            min:    opt.dataset.min ? parseInt(opt.dataset.min) : null,
            max:    opt.dataset.max ? parseInt(opt.dataset.max) : null,
            name:   opt.dataset.name
        };
    });

    /* ── Eye toggle ───────────────────────────────────────── */
    function togglePw(inputId, iconId) {
        const inp  = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (inp.type === 'password') {
            inp.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            inp.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    /* ── Full Name ────────────────────────────────────────── */
    const nameInput = document.getElementById('name');
    const nameError = document.getElementById('nameError');
    nameInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^a-zA-Z\s\-'\.]/g, '');
        validateName();
    });
    nameInput.addEventListener('blur', validateName);
    function validateName() {
        const val = nameInput.value.trim();
        if (val.length === 0) {
            nameError.textContent = '';
        } else if (!/^[a-zA-Z\s\-'\.]+$/.test(val)) {
            nameError.textContent = 'Full name must contain letters only.';
            nameError.style.color = '#d32f2f';
        } else {
            nameError.textContent = '✓ Valid name';
            nameError.style.color = '#2e7d32';
        }
    }

    /* ── Email ────────────────────────────────────────────── */
    const emailInput = document.getElementById('email');
    const emailError = document.getElementById('emailError');
    emailInput.addEventListener('input', validateEmail);
    emailInput.addEventListener('blur',  validateEmail);
    function validateEmail() {
        const val = emailInput.value.trim();
        const re  = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (val.length === 0) {
            emailError.textContent = '';
        } else if (!re.test(val)) {
            emailError.textContent = 'Please enter a valid email (e.g. name@example.com)';
            emailError.style.color = '#d32f2f';
        } else {
            emailError.textContent = '✓ Valid email address';
            emailError.style.color = '#2e7d32';
        }
    }

    /* ── Contact: country-aware validation ────────────────── */
    const countrySelect = document.getElementById('countryCode');
    const contactInput  = document.getElementById('contact');
    const contactError  = document.getElementById('contactError');

    function getCurrentRule() {
        return phoneRules[countrySelect.value] || { length: null, min: 7, max: 15, name: 'selected country' };
    }
    function expectedText(rule) {
        if (rule.length) return rule.length + ' digits';
        if (rule.min && rule.max) {
            return rule.min === rule.max ? rule.min + ' digits' : (rule.min + '–' + rule.max + ' digits');
        }
        return 'a valid number';
    }
    function updateContactPlaceholder() {
        const rule = getCurrentRule();
        contactInput.placeholder = 'Contact Number (' + expectedText(rule) + ')';
        contactInput.maxLength   = rule.length || rule.max || 15;
    }
    function validateContact() {
        const val  = contactInput.value;
        const rule = getCurrentRule();
        if (val.length === 0) {
            contactError.textContent = '';
            return false;
        }
        let ok = false;
        if (rule.length)               ok = val.length === rule.length;
        else if (rule.min && rule.max) ok = val.length >= rule.min && val.length <= rule.max;

        if (!ok) {
            contactError.textContent = 'Phone number for ' + rule.name + ' must be ' + expectedText(rule) + '.';
            contactError.style.color = '#d32f2f';
            return false;
        }
        contactError.textContent = '✓ Valid contact number';
        contactError.style.color = '#2e7d32';
        return true;
    }
    contactInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '');
        validateContact();
    });
    countrySelect.addEventListener('change', function () {
        const rule   = getCurrentRule();
        const maxLen = rule.length || rule.max || 15;
        if (contactInput.value.length > maxLen) {
            contactInput.value = contactInput.value.substring(0, maxLen);
        }
        updateContactPlaceholder();
        validateContact();
    });
    updateContactPlaceholder();

    /* ── Password ─────────────────────────────────────────── */
    const pwInput      = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const pwError      = document.getElementById('passwordError');
    const confirmError = document.getElementById('confirmError');
    pwInput.addEventListener('input',      validatePasswords);
    confirmInput.addEventListener('input', validatePasswords);
    function validatePasswords() {
        const pw  = pwInput.value;
        const cpw = confirmInput.value;
        if (pw.length === 0) {
            pwError.textContent = '';
        } else if (pw.length < 6) {
            pwError.textContent = 'Password must be at least 6 characters.';
            pwError.style.color = '#d32f2f';
        } else {
            pwError.textContent = '✓ Strong enough';
            pwError.style.color = '#2e7d32';
        }
        if (cpw.length === 0) {
            confirmError.textContent = '';
        } else if (pw !== cpw) {
            confirmError.textContent = 'Passwords do not match.';
            confirmError.style.color = '#d32f2f';
        } else {
            confirmError.textContent = '✓ Passwords match';
            confirmError.style.color = '#2e7d32';
        }
    }

    /* ── Terms checkbox controls submit button ────────────── */
    const termsBox   = document.getElementById('terms');
    const termsError = document.getElementById('termsError');
    const submitBtn  = document.getElementById('submitBtn');

    function syncSubmitState() {
        submitBtn.disabled = !termsBox.checked;
        if (termsBox.checked) termsError.textContent = '';
    }
    termsBox.addEventListener('change', syncSubmitState);
    syncSubmitState(); // honor initial state (e.g. after PHP error reload)

    /* ── Terms modal open/close ───────────────────────────── */
    const termsModal = document.getElementById('termsModal');
    document.getElementById('openTerms').addEventListener('click', function (e) {
        e.preventDefault();
        termsModal.classList.add('active');
    });
    document.getElementById('closeTerms').addEventListener('click', function () {
        termsModal.classList.remove('active');
    });
    termsModal.addEventListener('click', function (e) {
        if (e.target === termsModal) termsModal.classList.remove('active');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') termsModal.classList.remove('active');
    });

    /* ── Final submit validation ──────────────────────────── */
    document.getElementById('signupForm').addEventListener('submit', function (e) {
        let valid = true;
        let firstInvalid = null;

        const nameVal = nameInput.value.trim();
        if (!/^[a-zA-Z\s\-'\.]+$/.test(nameVal) || nameVal.length === 0) {
            nameError.textContent = 'Full name must contain letters only.';
            nameError.style.color = '#d32f2f';
            firstInvalid = firstInvalid || nameInput;
            valid = false;
        }

        const emailVal = emailInput.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
            emailError.textContent = 'Please enter a valid email address.';
            emailError.style.color = '#d32f2f';
            firstInvalid = firstInvalid || emailInput;
            valid = false;
        }

        if (!validateContact()) {
            firstInvalid = firstInvalid || contactInput;
            valid = false;
        }

        const pw  = pwInput.value;
        const cpw = confirmInput.value;
        if (pw.length < 6) {
            pwError.textContent = 'Password must be at least 6 characters.';
            pwError.style.color = '#d32f2f';
            firstInvalid = firstInvalid || pwInput;
            valid = false;
        } else if (pw !== cpw) {
            confirmError.textContent = 'Passwords do not match.';
            confirmError.style.color = '#d32f2f';
            firstInvalid = firstInvalid || confirmInput;
            valid = false;
        }

        if (!termsBox.checked) {
            termsError.textContent = 'Please agree to the Terms and Conditions to continue.';
            termsError.style.color = '#d32f2f';
            firstInvalid = firstInvalid || termsBox;
            valid = false;
        }

        if (!valid) {
            e.preventDefault();
            if (firstInvalid) firstInvalid.focus();
        }
    });
</script>

<?php include 'footer.php'; ?>