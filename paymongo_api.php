<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = $_POST['branch_id'];
    $date      = $_POST['check_in'];
    $type      = $_POST['type'];

    $amount_map = ['Day' => 900, 'Overnight' => 1000]; // in PHP (x100 for centavos)
    $amount = $amount_map[$type] * 100; // PayMongo expects centavos

    $url = "https://api.paymongo.com/v1/checkout_sessions";
    $payload = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'name'  => $_SESSION['username'],
                    'email' => $_SESSION['username']
                ],
                'line_items' => [[
                    'currency'    => 'PHP',
                    'amount'      => $amount,
                    'description' => "$type Stay at Emiart Resort",
                    'name'        => 'Resort Reservation',
                    'quantity'    => 1
                ]],
                'payment_method_types' => ['card', 'gcash', 'paymaya'],
                // {CHECKOUT_SESSION_ID} is replaced by PayMongo with the actual session ID
                'success_url' => 'http://localhost/agos/success_handler.php',
                'cancel_url'  => 'http://localhost/agos/book.php'
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode('sk_test_gmW1yUU2gXPpHL6X1BqHdXU5:')
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $result = curl_exec($ch);
    $json   = json_decode($result, true);
    curl_close($ch);

    if (isset($json['data']['attributes']['checkout_url'])) {
        // Save reservation as Pending and store its ID in session
        $stmt = $pdo->prepare("
            INSERT INTO reservations 
                (customer_id, branch_id, reservation_date, reservation_type, total_amount, status) 
            VALUES (?, ?, ?, ?, ?, 'Pending')
        ");
        $stmt->execute([
            $_SESSION['customer_id'],
            $branch_id,
            $date,
            $type,
            $amount / 100
        ]);
        // Store reservation ID and PayMongo session ID so success_handler.php can confirm it
        $_SESSION['pending_reservation_id'] = $pdo->lastInsertId();
        $_SESSION['paymongo_session_id']    = $json['data']['id'];

        header("Location: " . $json['data']['attributes']['checkout_url']);
        exit();
    } else {
        echo "<p style='color:red; font-family:sans-serif; padding:20px;'>
            Payment Gateway Error — could not create checkout session.<br>
            <small>" . htmlspecialchars(print_r($json, true)) . "</small>
        </p>";
    }
}