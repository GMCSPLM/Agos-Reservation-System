<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = $_POST['branch_id'];
    $date = $_POST['check_in'];
    $type = $_POST['type'];
    
    $amount_map = ['Day' => 500000, 'Night' => 600000, 'Overnight' => 1000000];
    $amount = $amount_map[$type];

    $url = "https://api.paymongo.com/v1/checkout_sessions";
    $payload = [
        'data' => [
            'attributes' => [
                'billing' => ['name' => $_SESSION['username'], 'email' => $_SESSION['username']],
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => $amount,
                    'description' => "$type Stay",
                    'name' => 'Resort Reservation',
                    'quantity' => 1
                ]],
                'payment_method_types' => ['card', 'gcash', 'paymaya'],
                'success_url' => 'http://localhost/checkmates/success_handler.php',
                'cancel_url' => 'http://localhost/checkmates/book.php'
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode('sk_test_ZB6HXXR7pALKhDbycZejtLNB') 
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    $result = curl_exec($ch);
    $json = json_decode($result, true);
    curl_close($ch);

    if (isset($json['data']['attributes']['checkout_url'])) {
        $stmt = $pdo->prepare("INSERT INTO reservations (customer_id, branch_id, reservation_date, reservation_type, total_amount, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$_SESSION['customer_id'], $branch_id, $date, $type, ($amount/100)]);
        
        header("Location: " . $json['data']['attributes']['checkout_url']);
    } else {
        echo "Payment Gateway Error. Please configure API Key.";
    }
}
?>