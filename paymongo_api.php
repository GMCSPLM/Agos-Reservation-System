<?php
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/config.php');

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
                'success_url' => 'https://agos.up.railway.app/success_handler.php',
                'cancel_url'  => 'https://agos.up.railway.app/book.php?cancelled=1'
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $result = curl_exec($ch);
    $json   = json_decode($result, true);
    curl_close($ch);

    if (isset($json['data']['attributes']['checkout_url'])) {
        // ── DO NOT insert into DB yet ─────────────────────────────────────────
        // Storing a reservation here (before payment) would block the slot even
        // if the user abandons the checkout. Instead, we keep the booking intent
        // in the session only. success_handler.php will do the actual INSERT
        // after verifying payment success directly with PayMongo's API.
        // ─────────────────────────────────────────────────────────────────────
        $_SESSION['booking_intent'] = [
            'customer_id'      => $_SESSION['customer_id'],
            'branch_id'        => $branch_id,
            'reservation_date' => $date,
            'reservation_type' => $type,
            'total_amount'     => $amount / 100,
        ];
        $_SESSION['paymongo_session_id'] = $json['data']['id'];

        header("Location: " . $json['data']['attributes']['checkout_url']);
        exit();
    } else {
        echo "<p style='color:red; font-family:sans-serif; padding:20px;'>
            Payment Gateway Error — could not create checkout session.<br>
            <small>" . htmlspecialchars(print_r($json, true)) . "</small>
        </p>";
    }
}