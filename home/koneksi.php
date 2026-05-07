<?php
// Koneksi ke database hosting eksternal
// Gunakan localhost untuk koneksi internal di server yang sama
<?php
$host = getenv('103.58.102.59');
$port = getenv('3306') ?: '3306';
$db   = getenv('salutpur_demo_trace');
$user = getenv('salutpur_demo');
$pass = getenv('1t6NE0!wlmCs]vlO');

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
