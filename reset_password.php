<?php
require_once 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// Check if user exists
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Email not found.']);
    exit;
}

// Update password
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
$stmt->execute([$hash, $email]);

echo json_encode(['success' => true]);
?>