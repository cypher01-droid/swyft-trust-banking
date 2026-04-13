<?php
// debug_session.php
session_start();

echo "<h2>Session Debug Information</h2>";
echo "<pre>";

echo "Session Status: ";
switch (session_status()) {
    case PHP_SESSION_DISABLED: echo "Sessions are disabled"; break;
    case PHP_SESSION_NONE: echo "No session exists"; break;
    case PHP_SESSION_ACTIVE: echo "Session is active"; break;
}
echo "\n\n";

echo "Session ID: " . session_id() . "\n\n";

echo "Session Data:\n";
print_r($_SESSION);

echo "\n\nCookies:\n";
print_r($_COOKIE);

echo "\n\nServer Variables:\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'Not set') . "\n";
echo "HTTP_REFERER: " . ($_SERVER['HTTP_REFERER'] ?? 'Not set') . "\n";
echo "REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'Not set') . "\n";

// Test if we can make AJAX requests
echo "\n\nAJAX Test Link: ";
echo '<a href="ajax/get_user.php?id=6" target="_blank">Test AJAX Directly</a>';

echo "</pre>";