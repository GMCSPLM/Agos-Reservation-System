<?php 
include 'header.php'; 

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $contact = preg_replace('/\D/', '', $_POST['contact']); // strip non-digits
    $email   = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($contact) !== 11) {
        $error = 'Contact number must be exactly 11 digits.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO customers (full_name, email, contact_number) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['name'], $_POST['email'], $contact]);
            $cust_id = $pdo->lastInsertId();

            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, customer_id) VALUES (?, ?, 'Customer', ?)");
            $stmt->execute([$_POST['email'], $hash, $cust_id]);

            $pdo->commit();
            echo "<script>alert('Success! Please login.'); window.location='login.php';</script>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error: Email is already registered.';
        }
    }
}
?>

<div class="auth-box">
    <h2>Create Account</h2>

    <?php if ($error): ?>
        <p style="color: #d32f2f; background: #ffebee; padding: 10px 14px; border-radius: 8px; 
                  font-size: 0.9rem; margin-bottom: 15px; text-align: center;">
            <?= htmlspecialchars($error) ?>
        </p>
    <?php endif; ?>

    <form method="POST" id="signupForm" novalidate>
        <div class="form-group">
            <input type="text" name="name" placeholder="Full Name" 
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <input type="email" name="email" id="email" placeholder="Email Address" 
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            <small id="emailError" style="font-size: 0.82rem; margin-top: 4px; display: block; min-height: 18px;"></small>
        </div>
        <div class="form-group">
            <input type="tel" name="contact" id="contact" placeholder="Contact Number (11 digits)"
                   value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>"
                   maxlength="11" inputmode="numeric" required>
            <small id="contactError" style="color: #d32f2f; font-size: 0.82rem; display: none; margin-top: 4px; display: block; min-height: 18px;"></small>
        </div>
        <div class="form-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button type="submit">SIGN UP</button>
    </form>
</div>

<script>
const contactInput = document.getElementById('contact');
const contactError = document.getElementById('contactError');
const emailInput   = document.getElementById('email');
const emailError   = document.getElementById('emailError');

// Email validation
emailInput.addEventListener('input', validateEmail);
emailInput.addEventListener('blur', validateEmail);

function validateEmail() {
    const val = emailInput.value.trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (val.length === 0) {
        emailError.textContent = '';
    } else if (!emailRegex.test(val)) {
        emailError.textContent = 'Please enter a valid email address (e.g. name@example.com)';
        emailError.style.color = '#d32f2f';
    } else {
        emailError.textContent = '✓ Valid email address';
        emailError.style.color = '#2e7d32';
    }
}

// Only allow digits while typing
contactInput.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 11);
    validateContact();
});

function validateContact() {
    const val = contactInput.value;
    if (val.length === 0) {
        contactError.textContent = '';
    } else if (val.length < 11) {
        contactError.textContent = `${val.length}/11 digits entered`;
        contactError.style.color = '#e65100';
    } else {
        contactError.textContent = '✓ Valid contact number';
        contactError.style.color = '#2e7d32';
    }
}

document.getElementById('signupForm').addEventListener('submit', function (e) {
    let valid = true;

    // Validate email
    const emailVal = emailInput.value.trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(emailVal)) {
        e.preventDefault();
        emailError.textContent = 'Please enter a valid email address (e.g. name@example.com)';
        emailError.style.color = '#d32f2f';
        if (valid) emailInput.focus();
        valid = false;
    }

    // Validate contact
    const contactVal = contactInput.value;
    if (contactVal.length !== 11) {
        e.preventDefault();
        contactError.textContent = 'Contact number must be exactly 11 digits.';
        contactError.style.color = '#d32f2f';
        if (valid) contactInput.focus();
        valid = false;
    }
});
</script>

<?php include 'footer.php'; ?>