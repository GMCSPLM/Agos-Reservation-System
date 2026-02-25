<?php 
include 'header.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'], $user['password_hash'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['customer_id'] = $user['customer_id'];
        
        $redirect = ($user['role'] == 'Admin') ? "admin/dashboard.php" : "book.php";
        echo "<script>window.location.href='$redirect';</script>";
        exit;
    } else {
        $error = "Invalid credentials.";
    }
}
?>

<!-- EmailJS SDK -->
<script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@3/dist/email.min.js"></script>
<script>
    // TODO: Replace with your actual EmailJS public key
    emailjs.init("bTi2PDWAACK7A9UPg");
</script>

<!-- LOGIN FORM -->
<div class="auth-box" id="loginBox">
    <h2>Welcome Back</h2>
    <?php if(isset($error)) echo "<p style='color:red; text-align:center;'>$error</p>"; ?>
    <form method="POST">
        <div class="form-group">
            <input type="email" name="email" placeholder="Email Address" required>
        </div>
        <div class="form-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button type="submit">LOG IN</button>
    </form>
    <p style="text-align: center; margin-top: 15px;">
        <a href="#" onclick="showForgot()" style="color: var(--primary);">Forgot Password?</a>
    </p>
    <p style="text-align: center; margin-top: 10px;">New here? <a href="signup.php" style="color: var(--primary);">Create Account</a></p>
</div>

<!-- FORGOT PASSWORD - STEP 1: Enter Email -->
<div class="auth-box" id="forgotBox" style="display:none;">
    <h2>Forgot Password</h2>
    <p style="text-align:center; color:#666; margin-bottom:20px;">Enter your email to receive an OTP.</p>
    <div id="forgotMsg"></div>
    <div class="form-group">
        <input type="email" id="forgotEmail" placeholder="Email Address" required>
    </div>
    <button onclick="sendOTP()" id="sendOtpBtn">SEND OTP</button>
    <p style="text-align: center; margin-top: 15px;">
        <a href="#" onclick="showLogin()" style="color: var(--primary);">Back to Login</a>
    </p>
</div>

<!-- FORGOT PASSWORD - STEP 2: OTP Verification -->
<div class="auth-box" id="otpBox" style="display:none;">
    <h2>OTP Verification</h2>
    <p style="text-align:center; color:#666; margin-bottom:5px;">Enter the 6-digit code sent to your email.</p>
    <p style="text-align:center; font-weight:bold; color: var(--primary); margin-bottom:20px;" id="otpTimer"></p>
    <div id="otpMsg"></div>
    <div class="form-group">
        <input type="text" id="otpInput" placeholder="Enter OTP Code" maxlength="6" required>
    </div>
    <button onclick="verifyOTP()" id="verifyOtpBtn">VERIFY OTP</button>
    <p style="text-align: center; margin-top: 15px;">
        <a href="#" onclick="showForgot()" style="color: var(--primary);">Resend OTP</a>
        &nbsp;|&nbsp;
        <a href="#" onclick="showLogin()" style="color: var(--primary);">Back to Login</a>
    </p>
</div>

<!-- FORGOT PASSWORD - STEP 3: New Password -->
<div class="auth-box" id="newPassBox" style="display:none;">
    <h2>New Password</h2>
    <p style="text-align:center; color:#666; margin-bottom:20px;">Set your new password below.</p>
    <div id="newPassMsg"></div>
    <div class="form-group">
        <input type="password" id="newPassword" placeholder="New Password" required>
    </div>
    <div class="form-group">
        <input type="password" id="confirmPassword" placeholder="Confirm Password" required>
    </div>
    <button onclick="resetPassword()" id="resetBtn">RESET PASSWORD</button>
</div>

<script>
    let generatedOTP = "";
    let otpExpiry = null;
    let timerInterval = null;
    let verifiedEmail = "";

    function showLogin() {
        document.getElementById('loginBox').style.display = 'block';
        document.getElementById('forgotBox').style.display = 'none';
        document.getElementById('otpBox').style.display = 'none';
        document.getElementById('newPassBox').style.display = 'none';
        clearInterval(timerInterval);
    }

    function showForgot() {
        document.getElementById('loginBox').style.display = 'none';
        document.getElementById('forgotBox').style.display = 'block';
        document.getElementById('otpBox').style.display = 'none';
        document.getElementById('newPassBox').style.display = 'none';
        document.getElementById('forgotMsg').innerHTML = '';
        clearInterval(timerInterval);
    }

    function sendOTP() {
        const email = document.getElementById('forgotEmail').value.trim();
        const btn = document.getElementById('sendOtpBtn');
        const msg = document.getElementById('forgotMsg');

        if (!email) {
            msg.innerHTML = "<p style='color:red; text-align:center;'>Please enter your email.</p>";
            return;
        }

        // Generate 6-digit OTP
        generatedOTP = Math.floor(100000 + Math.random() * 900000).toString();
        otpExpiry = Date.now() + (5 * 60 * 1000); // 5 minutes
        verifiedEmail = email;

        btn.disabled = true;
        btn.textContent = "Sending...";

        // TODO: Replace with your EmailJS Service ID and Template ID
        emailjs.send("se_project", "template_5revkij", {
            to_email: email,
            otp_code: generatedOTP,
            expiry_time: "5 minutes"
        }).then(function() {
            btn.disabled = false;
            btn.textContent = "SEND OTP";

            // Show OTP box
            document.getElementById('forgotBox').style.display = 'none';
            document.getElementById('otpBox').style.display = 'block';
            document.getElementById('otpMsg').innerHTML = '';

            // Start countdown timer
            startTimer();

        }).catch(function(error) {
            btn.disabled = false;
            btn.textContent = "SEND OTP";
            msg.innerHTML = "<p style='color:red; text-align:center;'>Failed to send OTP. Please try again.</p>";
            console.error("EmailJS error:", error);
        });
    }

    function startTimer() {
        clearInterval(timerInterval);
        timerInterval = setInterval(function() {
            const remaining = Math.floor((otpExpiry - Date.now()) / 1000);
            if (remaining <= 0) {
                clearInterval(timerInterval);
                document.getElementById('otpTimer').textContent = "OTP expired. Please request a new one.";
                document.getElementById('otpTimer').style.color = "red";
                generatedOTP = "";
            } else {
                const mins = Math.floor(remaining / 60);
                const secs = remaining % 60;
                document.getElementById('otpTimer').textContent = 
                    `OTP expires in: ${mins}:${secs.toString().padStart(2, '0')}`;
                document.getElementById('otpTimer').style.color = "var(--primary)";
            }
        }, 1000);
    }

    function verifyOTP() {
        const inputOTP = document.getElementById('otpInput').value.trim();
        const msg = document.getElementById('otpMsg');

        if (!inputOTP) {
            msg.innerHTML = "<p style='color:red; text-align:center;'>Please enter the OTP.</p>";
            return;
        }

        if (Date.now() > otpExpiry) {
            msg.innerHTML = "<p style='color:red; text-align:center;'>OTP has expired. Please request a new one.</p>";
            return;
        }

        if (inputOTP === generatedOTP) {
            clearInterval(timerInterval);
            document.getElementById('otpBox').style.display = 'none';
            document.getElementById('newPassBox').style.display = 'block';
            document.getElementById('newPassMsg').innerHTML = '';
        } else {
            msg.innerHTML = "<p style='color:red; text-align:center;'>Invalid OTP. Please try again.</p>";
        }
    }

    function resetPassword() {
        const newPass = document.getElementById('newPassword').value;
        const confirmPass = document.getElementById('confirmPassword').value;
        const msg = document.getElementById('newPassMsg');
        const btn = document.getElementById('resetBtn');

        if (!newPass || !confirmPass) {
            msg.innerHTML = "<p style='color:red; text-align:center;'>Please fill in both fields.</p>";
            return;
        }

        if (newPass !== confirmPass) {
            msg.innerHTML = "<p style='color:red; text-align:center;'>Passwords do not match.</p>";
            return;
        }

        if (newPass.length < 6) {
            msg.innerHTML = "<p style='color:red; text-align:center;'>Password must be at least 6 characters.</p>";
            return;
        }

        btn.disabled = true;
        btn.textContent = "Resetting...";

        // Send new password to server via AJAX
        fetch('reset_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: verifiedEmail, password: newPass })
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = "RESET PASSWORD";
            if (data.success) {
                msg.innerHTML = "<p style='color:green; text-align:center;'>Password reset successful! Redirecting...</p>";
                setTimeout(() => showLogin(), 2000);
            } else {
                msg.innerHTML = "<p style='color:red; text-align:center;'>" + data.message + "</p>";
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = "RESET PASSWORD";
            msg.innerHTML = "<p style='color:red; text-align:center;'>Something went wrong. Try again.</p>";
        });
    }
</script>

<?php include 'footer.php'; ?>