<?php
require_once '../includes/db.php';
session_start();

if ($_SESSION['role'] !== 'admin') die(json_encode([]));

$userId = $_GET['user_id'];
$stmt = $pdo->prepare("SELECT * FROM balances WHERE user_id = ?");
$stmt->execute([$userId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));