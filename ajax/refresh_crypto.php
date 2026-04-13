// ajax/refresh_crypto.php
<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

// Cache for 60 seconds to avoid too many API calls
$cache_file = '../cache/crypto_prices.json';
$cache_time = 60;

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
    $prices = json_decode(file_get_contents($cache_file), true);
} else {
    $prices = getLiveCryptoPrices();
    file_put_contents($cache_file, json_encode($prices));
}

echo json_encode(['success' => true, 'prices' => $prices]);
?>