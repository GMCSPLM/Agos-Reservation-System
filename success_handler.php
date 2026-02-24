<?php
require_once 'db.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

// Get the checkout session ID from URL (PayMongo replaces {CHECKOUT_SESSION_ID} with actual ID)
$session_id = $_GET['session_id'] ?? null;

if (!$session_id) {
    die("Error: No session ID provided. URL: " . $_SERVER['REQUEST_URI']);
}

// Retrieve the checkout session from PayMongo to verify payment
$api_key = 'sk_test_ZB6HXXR7pALKhDbycZejtLNB';
$url = "https://api.paymongo.com/v1/checkout_sessions/$session_id";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode($api_key . ':')
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$result = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    die("Error connecting to PayMongo: " . $curl_error);
}

$session_data = json_decode($result, true);

// Debug: Log the response
error_log("PayMongo Session Data: " . print_r($session_data, true));

// Check if we got valid data
if (!isset($session_data['data'])) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Payment Verification Error</title>
        <style>
            body { font-family: Arial; padding: 40px; background: #f5f5f5; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h2 { color: #d32f2f; }
            pre { background: #f5f5f5; padding: 15px; overflow-x: auto; border-radius: 5px; }
            .btn { display: inline-block; padding: 12px 24px; background: #1976d2; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>Unable to Verify Payment</h2>
            <p>We couldn't verify your payment status. This might be a temporary issue.</p>
            <p><strong>Session ID:</strong> " . htmlspecialchars($session_id) . "</p>
            <p><strong>HTTP Code:</strong> $http_code</p>
            <details>
                <summary>Technical Details</summary>
                <pre>" . htmlspecialchars(print_r($session_data, true)) . "</pre>
            </details>
            <a href='index.php' class='btn'>Go to Home</a>
        </div>
    </body>
    </html>";
    exit();
}

// Extract payment information
$payment_intent = $session_data['data']['attributes']['payment_intent'] ?? null;
$payment_status = $payment_intent['attributes']['status'] ?? 'unknown';
$amount_paid = ($payment_intent['attributes']['amount'] ?? 0) / 100;
$payment_method = $payment_intent['attributes']['payment_method_used'] ?? 'Unknown';

// Update reservation if payment succeeded
$reservation_updated = false;
if ($payment_status === 'succeeded' || $payment_status === 'awaiting_payment_method') {
    try {
        if (isset($_SESSION['pending_reservation_id'])) {
            $reservation_id = $_SESSION['pending_reservation_id'];
            
            $stmt = $pdo->prepare("
                UPDATE reservations 
                SET status = 'Confirmed', 
                    payment_status = 'Paid',
                    notes = CONCAT(COALESCE(notes, ''), '\nPayment confirmed: ', ?, '\nPayment Method: ', ?)
                WHERE reservation_id = ? AND customer_id = ?
            ");
            
            $stmt->execute([
                date('Y-m-d H:i:s'),
                $payment_method,
                $reservation_id,
                $_SESSION['customer_id']
            ]);
            
            $reservation_updated = true;
            
            // Clear session variables
            unset($_SESSION['pending_reservation_id']);
            unset($_SESSION['checkout_session_id']);
        }
        
    } catch (PDOException $e) {
        error_log("Database update error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment <?php echo $payment_status === 'succeeded' ? 'Success' : 'Status'; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .success-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: <?php echo $payment_status === 'succeeded' ? '#4caf50' : '#ff9800'; ?>;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: scaleIn 0.5s ease-out 0.2s both;
        }
        
        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }
        
        .success-icon svg {
            width: 50px;
            height: 50px;
            stroke: white;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }
        
        h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 15px;
        }
        
        .message {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .payment-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #666;
            font-weight: 500;
        }
        
        .detail-value {
            color: #333;
            font-weight: 600;
        }
        
        .amount {
            color: #4caf50;
            font-size: 24px;
        }
        
        .btn {
            display: inline-block;
            padding: 15px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin: 10px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-success {
            background: #e8f5e9;
            color: #4caf50;
        }
        
        .status-pending {
            background: #fff3e0;
            color: #ff9800;
        }
        
        .status-failed {
            background: #ffebee;
            color: #f44336;
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">
            <?php if ($payment_status === 'succeeded'): ?>
                <svg viewBox="0 0 52 52">
                    <polyline points="14 27 22 35 38 19"/>
                </svg>
            <?php else: ?>
                <svg viewBox="0 0 52 52">
                    <circle cx="26" cy="26" r="20"/>
                    <line x1="26" y1="18" x2="26" y2="28"/>
                    <circle cx="26" cy="34" r="1.5" fill="white"/>
                </svg>
            <?php endif; ?>
        </div>
        
        <h1>
            <?php 
            if ($payment_status === 'succeeded') {
                echo 'Payment Successful!';
            } elseif ($payment_status === 'awaiting_payment_method') {
                echo 'Payment Pending';
            } else {
                echo 'Payment Status: ' . ucfirst($payment_status);
            }
            ?>
        </h1>
        
        <p class="message">
            <?php 
            if ($payment_status === 'succeeded') {
                echo 'Thank you for your reservation. Your payment has been processed successfully.';
                if ($reservation_updated) {
                    echo '<br><strong>Your reservation has been confirmed!</strong>';
                }
            } elseif ($payment_status === 'awaiting_payment_method') {
                echo 'Your payment is being processed. You will receive a confirmation email shortly.';
            } else {
                echo 'Please check your payment status or contact support if you need assistance.';
            }
            ?>
        </p>
        
        <div class="payment-details">
            <div class="detail-row">
                <span class="detail-label">Payment Status</span>
                <span class="detail-value">
                    <span class="status-badge <?php 
                        if ($payment_status === 'succeeded') echo 'status-success';
                        elseif ($payment_status === 'awaiting_payment_method') echo 'status-pending';
                        else echo 'status-failed';
                    ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $payment_status)); ?>
                    </span>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Amount</span>
                <span class="detail-value amount">₱<?php echo number_format($amount_paid, 2); ?></span>
            </div>
            <?php if ($payment_method !== 'Unknown'): ?>
            <div class="detail-row">
                <span class="detail-label">Payment Method</span>
                <span class="detail-value"><?php echo ucfirst($payment_method); ?></span>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="detail-label">Transaction ID</span>
                <span class="detail-value" style="font-size: 11px; word-break: break-all;">
                    <?php echo htmlspecialchars($session_id); ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Date</span>
                <span class="detail-value"><?php echo date('F d, Y g:i A'); ?></span>
            </div>
        </div>

        <?php if ($reservation_updated): ?>
        <div class="info-box">
            <strong>✓ Reservation Confirmed</strong><br>
            Your booking has been confirmed and saved to your account.
        </div>
        <?php endif; ?>
        
        <div>
            <a href="index.php" class="btn">Go to Home</a>
            <a href="book.php" class="btn btn-secondary">Make Another Booking</a>
        </div>
        
        <p style="margin-top: 30px; font-size: 14px; color: #999;">
            <?php if ($payment_status === 'succeeded'): ?>
                A confirmation email will be sent to your registered email address.
            <?php endif; ?>
        </p>
    </div>
</body>
</html>