<?php
$host   = $_ENV['MYSQLHOST']     ?? getenv('MYSQLHOST');
$dbname = $_ENV['MYSQLDATABASE'] ?? getenv('MYSQLDATABASE');
$user   = $_ENV['MYSQLUSER']     ?? getenv('MYSQLUSER');
$pass   = $_ENV['MYSQLPASSWORD'] ?? getenv('MYSQLPASSWORD');
$port   = $_ENV['MYSQLPORT']     ?? getenv('MYSQLPORT');

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database Connection Error.");
}

session_start();

// Auto-complete confirmed reservations whose date has passed
$pdo->query("
    UPDATE reservations 
    SET status = 'Completed' 
    WHERE status = 'Confirmed' 
    AND reservation_date < CURDATE()
");
?>