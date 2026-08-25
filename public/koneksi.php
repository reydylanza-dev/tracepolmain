<?php
// Koneksi ke database hosting eksternal
// Gunakan localhost untuk koneksi internal di server yang sama

$host = getenv('');
$port = getenv('3306') ?: '3306';
$db   = getenv('');
$user = getenv('');
$pass = getenv('');

// Create connection
$koneksi = new mysqli($host, $user, $pass, $db, $port);
$link = new mysqli($host, $user, $pass, $db, $port);

// Check connection
if (!$koneksi) {
    die("Connection failed: " . mysqli_connect_error());
}

if($link === false){
    die("ERROR: Could not connect. " . mysqli_connect_error());
}
?>
