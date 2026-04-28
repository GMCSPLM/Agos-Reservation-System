<?php 
include 'header.php'; 

// ════════════════════════════════════════════════════════════
// SECURITY HELPERS
// ════════════════════════════════════════════════════════════

/**
 * Simple file-based rate limiter keyed by IP.
 * Blocks brute-force and slows DDoS attempts on the login endpoint.
 *
 * @return array ['allowed' => bool, 'wait' => int|null, ...]
 */
function checkLoginRateLimit($maxAttempts = 5, $windowSeconds = 300, $lockoutSeconds = 900) {
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $hashedIp = hash('sha256', $ip);

    $dir = __DIR__ . '/login_attempts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        // Block direct web access if anyone tries to browse the folder
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.html', '');
    }

    $file = $dir . '/' . $hashedIp . '.json';
    $now  = time();
    $data = ['attempts' => [], 'locked_until' => 0];

    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $decoded = @json_decode($raw, true);
            if (is_array($decoded)) $data = $decoded + $data;
        }
    }

    // Currently locked out?
    if (!empty($data['locked_until']) && $data['locked_until'] > $now) {
        return ['allowed' => false, 'wait' => $data['locked_until'] - $now];
    }

    // Drop attempts outside the sliding window
    $data['attempts'] = array_values(array_filter(
        $data['attempts'] ?? [],
        fn($t) => ($now - (int)$t) < $windowSeconds
    ));

    if (count($data['attempts']) >= $maxAttempts) {
        $data['locked_until'] = $now + $lockoutSeconds;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return ['allowed' => false, 'wait' => $lockoutSeconds];
    }

    return ['allowed' => true, 'data' => $data, 'file' => $file, 'now' => $now];
}

function recordFailedLogin(array $rateState) {
    if (!isset($rateState['file'])) return;
    $rateState['data']['attempts'][] = $rateState['now'];
    @file_put_contents($rateState['file'], json_encode($rateState['data']), LOCK_EX);
}

function clearLoginAttempts() {
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $hashedIp = hash('sha256', $ip);
    $file     = __DIR__ . '/login_attempts/' . $hashedIp . '.json';
    if (file_exists($file)) @unlink($file);
}

// ════════════════════════════════════════════════════════════
// CSRF TOKEN
// ════════════════════════════════════════════════════════════
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ════════════════════════════════════════════════════════════
// CAPTURE PENDING BOOKING INTENT (validated)
// ════════════════════════════════════════════════════════════
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    if (isset($_GET['redirect']) && $_GET['redirect'] === 'book'
        && !empty($_GET['date']) && !empty($_GET['branch'])) {

        // Validate date as YYYY-MM-DD and branch as a safe short string
        $dateOk   = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
                    && strtotime($_GET['date']) !== false;
        $branchOk = (bool) preg_match('/^[A-Za-z0-9 _\-]{1,100}$/', $_GET['branch']);

        if ($dateOk && $branchOk) {
            $_SESSION['pending_booking_date']   = $_GET['date'];
            $_SESSION['pending_booking_branch'] = $_GET['branch'];
        } else {
            unset($_SESSION['pending_booking_date'], $_SESSION['pending_booking_branch']);
        }
    } else {
        unset($_SESSION['pending_booking_date'], $_SESSION['pending_booking_branch']);
    }
}

// ════════════════════════════════════════════════════════════
// HANDLE LOGIN POST
// ════════════════════════════════════════════════════════════
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1) CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        // 2) Rate-limit check (per IP): 5 attempts / 5 min, 15 min lockout
        $rate = checkLoginRateLimit(5, 300, 900);

        if (!$rate['allowed']) {
            $minutes = (int) ceil($rate['wait'] / 60);
            $error   = "Too many login attempts. Please try again in {$minutes} minute(s).";
        } else {
            // 3) Validate email format and length
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($email)    > 254
                || strlen($password) > 1024     // hard cap to prevent absurd payloads
                || $password === '') {
                recordFailedLogin($rate);
                $error = "Invalid credentials.";
            } else {
                // 4) Look up the user (already using a prepared statement → safe from SQLi)
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    // SUCCESS: prevent session fixation by issuing a fresh session ID
                    session_regenerate_id(true);

                    $_SESSION['user_id']     = $user['user_id'];
                    $_SESSION['username']    = $user['username'];
                    $_SESSION['role']        = $user['role'];
                    $_SESSION['customer_id'] = $user['customer_id'];

                    // Rotate CSRF token after auth state change
                    $_SESSION['csrf_token']  = bin2hex(random_bytes(32));

                    clearLoginAttempts();

                    if ($user['role'] == 'Admin') {
                        $redirect = "admin/dashboard.php";
                    } elseif (!empty($_SESSION['pending_booking_date']) && !empty($_SESSION['pending_booking_branch'])) {
                        $date   = urlencode($_SESSION['pending_booking_date']);
                        $branch = urlencode($_SESSION['pending_booking_branch']);
                        unset($_SESSION['pending_booking_date'], $_SESSION['pending_booking_branch']);
                        $redirect = "book.php?date=$date&branch=$branch";
                    } else {
                        $redirect = "index.php";
                    }

                    // Proper HTTP redirect (no JS injection surface)
                    header("Location: $redirect");
                    exit;
                } else {
                    recordFailedLogin($rate);
                    $error = "Invalid credentials.";
                }
            }
        }
    }
}
?>

<!-- ═══════════════════════════════════════════
     EYE-BUTTON FIX (lock position so the icon
     swap between fa-eye and fa-eye-slash can't
     shift the button)
════════════════════════════════════════════ -->
<style>
    .pw-wrapper { position: relative; }

    .pw-wrapper input {
        width: 100%;
        padding-right: 44px;   /* reserve space for the toggle */
        box-sizing: border-box;
    }

    .pw-toggle {
        position: absolute;
        top: 50%;
        right: 12px;
        transform: translateY(-50%);
        background: transparent;
        border: none;
        padding: 0;
        margin: 0;
        cursor: pointer;
        width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }

    .pw-toggle:focus { outline: none; }

    /* Lock icon width — fa-eye and fa-eye-slash render at slightly
       different widths, which is what causes the button to "jump". */
    .pw-toggle i {
        display: inline-block;
        width: 18px;
        text-align: center;
        line-height: 1;
        color: #666;
        pointer-events: none;
    }
</style>

<!-- EmailJS SDK -->
<script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@3/dist/email.min.js"></script>
<script>
    emailjs.init("bTi2PDWAACK7A9UPg");
</script>

<!-- ═══════════════════════════════════════════
     LOGIN FORM
════════════════════════════════════════════ -->
<div class="auth-box" id="loginBox">
    <h2>Welcome Back</h2>
    <?php if (isset($error)) {
        echo "<p style='color:red; text-align:center;'>"
           . htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
           . "</p>";
    } ?>
    <form method="POST" autocomplete="on">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group">
            <input type="email" name="email" placeholder="Email Address"
                   maxlength="254" required>
        </div>
        <div class="form-group">
            <div class="pw-wrapper">
                <input type="password" name="password" id="loginPassword"
                       placeholder="Password" maxlength="1024" required>
                <button type="button" class="pw-toggle"
                        onclick="togglePw('loginPassword','loginEye')" tabindex="-1"
                        aria-label="Show or hide password">
                    <i class="fas fa-eye" id="loginEye"></i>
                </button>
            </div>
        </div>
        <button type="submit">LOG IN</button>
    </form>
    <p style="text-align: center; margin-top: 15px;">
        <a href="#" onclick="showForgot()" style="color: var(--primary);">Forgot Password?</a>
    </p>
    <p style="text-align: center; margin-top: 10px;">New here? <a href="signup.php" style="color: var(--primary);">Create Account</a></p>
</div>

<!-- ═══════════════════════════════════════════
     FORGOT PASSWORD – STEP 1: Enter Email
════════════════════════════════════════════ -->
<div class="auth-box" id="forgotBox" style="display:none;">
    <h2>Forgot Password</h2>
    <p style="text-align:center; color:#666; margin-bottom:20px;">Enter your email to receive an OTP.</p>
    <div id="forgotMsg"></div>
    <div class="form-group">
        <input type="email" id="forgotEmail" placeholder="Email Address" required>
    </div>
    <!-- OTP attempt counter notice -->
    <p id="otpLimitNotice" style="text-align:center; font-size:0.85rem; color:#888; margin-bottom:10px;"></p>
    <button onclick="sendOTP()" id="sendOtpBtn">SEND OTP</button>
    <p style="text-align: center; margin-top: 15px;">
        <a href="#" onclick="showLogin()" style="color: var(--primary);">Back to Login</a>
    </p>
</div>

<!-- ═══════════════════════════════════════════
     FORGOT PASSWORD – STEP 2: OTP Verification
════════════════════════════════════════════ -->
<div class="auth-box" id="otpBox" style="display:none;">
    <h2>OTP Verification</h2>
    <p style="text-align:center; color:#666; margin-bottom:5px;">Enter the 6-digit code sent to your email.</p>
    <p style="text-align:center; font-weight:bold; color: var(--primary); margin-bottom:20px;" id="otpTimer"></p>
    <div id="otpMsg"></div>
    <div class="form-group">
        <input type="text" id="otpInput" placeholder="Enter OTP Code" maxlength="6" required>
    </div>
    <button onclick="verifyOTP()" id="verifyOtpBtn">VERIFY OTP</button>
    <p style="text-align: center; margin-top: 15px;" id="resendRow">
        <a href="#" onclick="handleResend()" style="color: var(--primary);">Resend OTP</a>
        &nbsp;|&nbsp;
        <a href="#" onclick="showLogin()" style="color: var(--primary);">Back to Login</a>
    </p>
</div>

<!-- ═══════════════════════════════════════════
     FORGOT PASSWORD – STEP 3: New Password
════════════════════════════════════════════ -->
<div class="auth-box" id="newPassBox" style="display:none;">
    <h2>New Password</h2>
    <p style="text-align:center; color:#666; margin-bottom:20px;">Set your new password below.</p>
    <div id="newPassMsg"></div>

    <!-- New password with eye icon -->
    <div class="form-group">
        <div class="pw-wrapper">
            <input type="password" id="newPassword" placeholder="New Password" required>
            <button type="button" class="pw-toggle" onclick="togglePw('newPassword','eyeNew')" tabindex="-1"
                    aria-label="Show or hide password">
                <i class="fas fa-eye" id="eyeNew"></i>
            </button>
        </div>
    </div>

    <!-- Confirm password with eye icon -->
    <div class="form-group">
        <div class="pw-wrapper">
            <input type="password" id="confirmPassword" placeholder="Confirm Password" required>
            <button type="button" class="pw-toggle" onclick="togglePw('confirmPassword','eyeConfirm')" tabindex="-1"
                    aria-label="Show or hide password">
                <i class="fas fa-eye" id="eyeConfirm"></i>
            </button>
        </div>
    </div>

    <button onclick="resetPassword()" id="resetBtn">RESET PASSWORD</button>
</div>

<script>
    // ── Globals ───────────────────────────────────────────────
    let generatedOTP  = "";
    let otpExpiry     = null;
    let timerInterval = null;
    let verifiedEmail = "";

    // OTP-send attempt tracking (max 3 per session)
    const OTP_LIMIT = 3;
    let otpSendCount = 0;

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

    // ── Navigation helpers ────────────────────────────────────
    function showLogin() {
        document.getElementById('loginBox').style.display    = 'block';
        document.getElementById('forgotBox').style.display   = 'none';
        document.getElementById('otpBox').style.display      = 'none';
        document.getElementById('newPassBox').style.display  = 'none';
        clearInterval(timerInterval);
    }

    function showForgot() {
        document.getElementById('loginBox').style.display    = 'none';
        document.getElementById('forgotBox').style.display   = 'block';
        document.getElementById('otpBox').style.display      = 'none';
        document.getElementById('newPassBox').style.display  = 'none';
        document.getElementById('forgotMsg').innerHTML       = '';
        clearInterval(timerInterval);
        updateOtpLimitNotice();
    }

    function updateOtpLimitNotice() {
        const notice  = document.getElementById('otpLimitNotice');
        const btn     = document.getElementById('sendOtpBtn');
        const remaining = OTP_LIMIT - otpSendCount;
        if (remaining <= 0) {
            notice.innerHTML = "<span style='color:#d32f2f; font-weight:600;'>OTP limit reached. Please try again later.</span>";
            btn.disabled = true;
        } else {
            notice.textContent = `OTP attempts remaining: ${remaining} / ${OTP_LIMIT}`;
        }
    }

    // ── Send OTP (max 3 times) ────────────────────────────────
    function sendOTP() {
        const email = document.getElementById('forgotEmail').value.trim();
        const btn   = document.getElementById('sendOtpBtn');
        const msg   = document.getElementById('forgotMsg');

        if (!email) {
            msg.innerHTML = "<p style='color:red; text-align:center;'>Please enter your email.</p>";
            return;
        }

        if (otpSendCount >= OTP_LIMIT) {
            msg.innerHTML = "<p style='color:red; text-align:center;'>You have reached the maximum OTP requests. Please try again later.</p>";
            btn.disabled = true;
            return;
        }

        btn.disabled = true;
        btn.textContent = "Checking...";

        // Step 1: Validate email in database
        fetch('check_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email })
        })
        .then(res => res.json())
        .then(data => {
            if (!data.exists) {
                btn.disabled = false;
                btn.textContent = "SEND OTP";
                msg.innerHTML = `<p style='color:red; text-align:center;'>${data.message}</p>`;
                return;
            }

            // Step 2: Generate OTP and send
            generatedOTP  = Math.floor(100000 + Math.random() * 900000).toString();
            otpExpiry     = Date.now() + (5 * 60 * 1000);
            verifiedEmail = email;

            btn.textContent = "Sending...";

            emailjs.send("se_project", "template_5revkij", {
                to_email: email,
                otp_code: generatedOTP,
                expiry_time: "5 minutes"
            }).then(function() {
                otpSendCount++;
                btn.disabled = false;
                btn.textContent = "SEND OTP";

                document.getElementById('forgotBox').style.display = 'none';
                document.getElementById('otpBox').style.display    = 'block';
                document.getElementById('otpMsg').innerHTML        = '';

                // Show remaining attempts in OTP box
                if (OTP_LIMIT - otpSendCount > 0) {
                    document.getElementById('otpMsg').innerHTML =
                        `<p style='color:#888; text-align:center; font-size:0.85rem;'>
                            OTP resend attempts remaining: ${OTP_LIMIT - otpSendCount}
                         </p>`;
                }

                startTimer();
            }).catch(function(error) {
                btn.disabled = false;
                btn.textContent = "SEND OTP";
                msg.innerHTML = "<p style='color:red; text-align:center;'>Failed to send OTP. Please try again.</p>";
                console.error("EmailJS error:", error);
            });
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = "SEND OTP";
            msg.innerHTML = "<p style='color:red; text-align:center;'>Something went wrong. Please try again.</p>";
        });
    }

    // ── Handle Resend (respects the 3-OTP cap) ────────────────
    function handleResend() {
        if (otpSendCount >= OTP_LIMIT) {
            document.getElementById('otpMsg').innerHTML =
                "<p style='color:red; text-align:center;'>OTP limit reached. Please try again later.</p>";
            document.getElementById('resendRow').innerHTML =
                "<a href='#' onclick='showLogin()' style='color: var(--primary);'>Back to Login</a>";
            return;
        }
        showForgot();
    }

    // ── Timer ─────────────────────────────────────────────────
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

    // ── Verify OTP ────────────────────────────────────────────
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
            document.getElementById('otpBox').style.display     = 'none';
            document.getElementById('newPassBox').style.display = 'block';
            document.getElementById('newPassMsg').innerHTML     = '';
        } else {
            msg.innerHTML = "<p style='color:red; text-align:center;'>Invalid OTP. Please try again.</p>";
        }
    }

    // ── Reset Password (rejects old password via server) ──────
    function resetPassword() {
        const newPass     = document.getElementById('newPassword').value;
        const confirmPass = document.getElementById('confirmPassword').value;
        const msg         = document.getElementById('newPassMsg');
        const btn         = document.getElementById('resetBtn');

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

        // Server-side handles old-password rejection via check_old_password flag
        fetch('reset_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email:              verifiedEmail,
                password:           newPass,
                reject_old_password: true   // tells server to compare against current hash
            })
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = "RESET PASSWORD";
            if (data.success) {
                msg.innerHTML = "<p style='color:green; text-align:center;'>Password reset successful! Redirecting…</p>";
                setTimeout(() => showLogin(), 2000);
            } else {
                msg.innerHTML = `<p style='color:red; text-align:center;'>${data.message}</p>`;
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