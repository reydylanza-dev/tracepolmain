<?php
// Koneksi ke database hosting eksternal
// Gunakan localhost untuk koneksi internal di server yang sama
$servername = "localhost";
$username = "root";
$password = "";
$db = "demo_salut";
$database = "demo_salut";
// Create connection
$koneksi = mysqli_connect($servername, $username, $password, $database);
$link = mysqli_connect($servername, $username, $password, $database);

// Check connection
if (!$koneksi) {
    die("Connection failed: " . mysqli_connect_error());
}

if($link === false){
    die("ERROR: Could not connect. " . mysqli_connect_error());
}
?>