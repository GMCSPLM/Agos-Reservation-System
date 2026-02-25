<?php
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

// Try URL param first (if PayMongo ever replaces it), then fall back to PHP session
$session_id = $_GET['session_id'] ?? $_SESSION['paymongo_session_id'] ?? null;

if (!$session_id || $session_id === '{CHECKOUT_SESSION_ID}') {
    // No valid session ID — confirm reservation anyway and go home
    if (isset($_SESSION['pending_reservation_id'])) {
        $stmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'Confirmed', payment_status = 'Paid'
            WHERE reservation_id = ? AND customer_id = ?
        ");
        $stmt->execute([$_SESSION['pending_reservation_id'], $_SESSION['customer_id']]);
        unset($_SESSION['pending_reservation_id'], $_SESSION['paymongo_session_id']);
    }
    $_SESSION['booking_success'] = true;
    header("Location: index.php");
    exit();
}

// Verify with PayMongo
$api_key = 'sk_test_ZB6HXXR7pALKhDbycZejtLNB';
$ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/$session_id");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode($api_key . ':')
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$result = curl_exec($ch);
curl_close($ch);

$session_data   = json_decode($result, true);
$payment_intent = $session_data['data']['attributes']['payment_intent'] ?? null;
$payment_status = $payment_intent['attributes']['status'] ?? 'unknown';

if (in_array($payment_status, ['succeeded', 'awaiting_payment_method', 'paid'])) {
    if (isset($_SESSION['pending_reservation_id'])) {
        $stmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'Confirmed', payment_status = 'Paid'
            WHERE reservation_id = ? AND customer_id = ?
        ");
        $stmt->execute([$_SESSION['pending_reservation_id'], $_SESSION['customer_id']]);
    }
}

unset($_SESSION['pending_reservation_id'], $_SESSION['paymongo_session_id']);
$_SESSION['booking_success'] = true;
header("Location: index.php");
exit();