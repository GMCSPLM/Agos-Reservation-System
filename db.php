<?php
$host   = getenv('MYSQLHOST');
$dbname = getenv('MYSQLDATABASE');
$user   = getenv('MYSQLUSER');
$pass   = getenv('MYSQLPASSWORD');
$port   = getenv('MYSQLPORT') ?: '3306';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Error: host=" . $host . " db=" . $dbname . " user=" . $user . " port=" . $port . " | " . $e->getMessage());
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