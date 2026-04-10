<?php 
include 'header.php'; 

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $raw_contact = trim($_POST['contact']);
    $country_code = trim($_POST['country_code'] ?? '+63');
    // Strip non-digits from contact
    $contact_digits = preg_replace('/\D/', '', $raw_contact);
    $full_contact   = $country_code . $contact_digits;

    $email = trim($_POST['email']);
    $name  = trim($_POST['name']);

    if (!preg_match("/^[a-zA-Z\s\-'\.]+$/", $name)) {
        $error = 'Full name must contain letters only.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($contact_digits)) {
        $error = 'Please enter a valid contact number.';
    } elseif ($_POST['password'] !== $_POST['confirm_password']) {
        $error = 'Passwords do not match.';
    } elseif (strlen($_POST['password']) < 6) {
        $error = 'Password must be at least 6 characters.';
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
        align-items: stretch; /* Ensures both boxes are the exact same height */
        width: 100%;
    }
    
    .phone-row select {
        flex: 0 0 120px;         /* Locks the dropdown width */
        width: 120px !important; /* Overrides the global width: 100% */
        cursor: pointer;
    }
    
    .phone-row input {
        flex: 1;                 /* Expands to fill all remaining space */
        width: auto !important;  /* Overrides the global width: 100% */
    }
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

        <!-- Full Name (letters only) -->
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
                    <option value="+63">🇵🇭 +63</option>
                    <option value="+1">🇺🇸 +1</option>
                    <option value="+44">🇬🇧 +44</option>
                    <option value="+61">🇦🇺 +61</option>
                    <option value="+81">🇯🇵 +81</option>
                    <option value="+82">🇰🇷 +82</option>
                    <option value="+86">🇨🇳 +86</option>
                    <option value="+91">🇮🇳 +91</option>
                    <option value="+971">🇦🇪 +971</option>
                    <option value="+966">🇸🇦 +966</option>
                    <option value="+65">🇸🇬 +65</option>
                    <option value="+60">🇲🇾 +60</option>
                    <option value="+62">🇮🇩 +62</option>
                    <option value="+66">🇹🇭 +66</option>
                    <option value="+84">🇻🇳 +84</option>
                </select>
                <input type="tel" name="contact" id="contact" placeholder="Contact Number"
                       value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>"
                       inputmode="numeric" required>
            </div>
            <small id="contactError" style="font-size:0.82rem; margin-top:4px; display:block; min-height:18px;"></small>
        </div>

        <!-- Password with eye icon -->
        <div class="form-group">
            <div class="pw-wrapper">
                <input type="password" name="password" id="password" placeholder="Password" required>
                <button type="button" class="pw-toggle" onclick="togglePw('password', 'eyeIcon1')" tabindex="-1">
                    <i class="fas fa-eye" id="eyeIcon1"></i>
                </button>
            </div>
            <small id="passwordError" style="font-size:0.82rem; margin-top:4px; display:block; min-height:18px;"></small>
        </div>

        <!-- Confirm Password with eye icon -->
        <div class="form-group">
            <div class="pw-wrapper">
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required>
                <button type="button" class="pw-toggle" onclick="togglePw('confirm_password', 'eyeIcon2')" tabindex="-1">
                    <i class="fas fa-eye" id="eyeIcon2"></i>
                </button>
            </div>
            <small id="confirmError" style="font-size:0.82rem; margin-top:4px; display:block; min-height:18px;"></small>
        </div>

        <button type="submit">SIGN UP</button>
    </form>
    <p style="text-align: center; margin-top: 15px;">Already have an account? <a href="login.php" style="color: var(--primary);">Log In</a></p>
</div>

<script>
    // ── Eye toggle ────────────────────────────────────────────
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

    // ── Full Name: letters only ───────────────────────────────
    const nameInput = document.getElementById('name');
    const nameError = document.getElementById('nameError');

    nameInput.addEventListener('input', function () {
        // Remove non-letter characters (allow spaces, hyphens, apostrophes, dots)
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

    // ── Email ─────────────────────────────────────────────────
    const emailInput = document.getElementById('email');
    const emailError = document.getElementById('emailError');
    emailInput.addEventListener('input', validateEmail);
    emailInput.addEventListener('blur', validateEmail);

    function validateEmail() {
        const val = emailInput.value.trim();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (val.length === 0) {
            emailError.textContent = '';
        } else if (!emailRegex.test(val)) {
            emailError.textContent = 'Please enter a valid email (e.g. name@example.com)';
            emailError.style.color = '#d32f2f';
        } else {
            emailError.textContent = '✓ Valid email address';
            emailError.style.color = '#2e7d32';
        }
    }

    // ── Contact number ────────────────────────────────────────
    const contactInput = document.getElementById('contact');
    const contactError = document.getElementById('contactError');

    contactInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '');
        validateContact();
    });

    function validateContact() {
        const val = contactInput.value;
        if (val.length === 0) {
            contactError.textContent = '';
        } else if (val.length < 7) {
            contactError.textContent = 'Please enter a valid contact number.';
            contactError.style.color = '#e65100';
        } else {
            contactError.textContent = '✓ Valid contact number';
            contactError.style.color = '#2e7d32';
        }
    }

    // ── Password match ────────────────────────────────────────
    const pwInput      = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const pwError      = document.getElementById('passwordError');
    const confirmError = document.getElementById('confirmError');

    pwInput.addEventListener('input', validatePasswords);
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

    // ── Form submit validation ─────────────────────────────────
    document.getElementById('signupForm').addEventListener('submit', function (e) {
        let valid = true;

        const nameVal = nameInput.value.trim();
        if (!/^[a-zA-Z\s\-'\.]+$/.test(nameVal) || nameVal.length === 0) {
            e.preventDefault();
            nameError.textContent = 'Full name must contain letters only.';
            nameError.style.color = '#d32f2f';
            if (valid) nameInput.focus();
            valid = false;
        }

        const emailVal = emailInput.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
            e.preventDefault();
            emailError.textContent = 'Please enter a valid email address.';
            emailError.style.color = '#d32f2f';
            if (valid) emailInput.focus();
            valid = false;
        }

        const contactVal = contactInput.value;
        if (contactVal.length < 7) {
            e.preventDefault();
            contactError.textContent = 'Please enter a valid contact number.';
            contactError.style.color = '#d32f2f';
            if (valid) contactInput.focus();
            valid = false;
        }

        const pw  = pwInput.value;
        const cpw = confirmInput.value;
        if (pw.length < 6) {
            e.preventDefault();
            pwError.textContent = 'Password must be at least 6 characters.';
            pwError.style.color = '#d32f2f';
            if (valid) pwInput.focus();
            valid = false;
        } else if (pw !== cpw) {
            e.preventDefault();
            confirmError.textContent = 'Passwords do not match.';
            confirmError.style.color = '#d32f2f';
            if (valid) confirmInput.focus();
            valid = false;
        }
    });
</script>

<?php include 'footer.php'; ?>