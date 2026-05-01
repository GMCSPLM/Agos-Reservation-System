<?php
require_once 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$email    = $data['email']    ?? '';
$password = $data['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// Look up the user by email/username
$stmt = $pdo->prepare("SELECT user_id, password_hash FROM users WHERE username = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Email not found.']);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   Minimum length check — matches signup.php (6 characters).
   Runs BEFORE the reuse check and before any database write so
   a too-short password never gets hashed or stored.
   ───────────────────────────────────────────────────────────── */
if (strlen($password) < 6) {
    echo json_encode([
        'success' => false,
        'message' => 'Password must be at least 6 characters.'
    ]);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   Prevent reuse of the CURRENT password.
   ─────────────────────────────────────────────────────────────
   The comparison is done with password_verify() against the
   stored bcrypt hash — never against a plaintext value. Older
   passwords (anything used before the current one) are NOT
   tracked, so they remain reusable; only the most recent hash
   is checked. This validation runs BEFORE the UPDATE, so the
   database is never touched when the new password matches the
   current one.
   ───────────────────────────────────────────────────────────── */
if (password_verify($password, $user['password_hash'])) {
    echo json_encode([
        'success' => false,
        'message' => 'New password must be different from your current password.'
    ]);
    exit;
}

// Hash and update
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
$stmt->execute([$hash, $email]);

echo json_encode(['success' => true]);
?>