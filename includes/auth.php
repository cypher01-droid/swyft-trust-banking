<?php
session_start();
require_once 'db.php';

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /login.php");
        exit();
    }
}

function checkPin() {
    if (!isset($_SESSION['pin_verified']) || $_SESSION['pin_verified'] !== true) {
        header("Location: /pin.php");
        exit();
    }
}
?>
