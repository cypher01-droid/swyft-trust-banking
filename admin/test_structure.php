<?php
// test_structure.php
echo "<h2>Checking File Structure</h2>";
echo "<pre>";

$paths = [
    'config/database.php',
    'includes/db.php', 
    '../includes/db.php',
    '../../includes/db.php',
    dirname(__DIR__) . '/includes/db.php',
    __DIR__ . '/config/database.php',
    __DIR__ . '/../config/database.php'
];

foreach ($paths as $path) {
    if (file_exists($path)) {
        echo "✅ FOUND: $path\n";
        echo "   Real path: " . realpath($path) . "\n";
        echo "   Size: " . filesize($path) . " bytes\n";
    } else {
        echo "❌ NOT FOUND: $path\n";
    }
    echo "\n";
}

// Check current directory
echo "Current directory: " . __DIR__ . "\n";
echo "Parent directory: " . dirname(__DIR__) . "\n";

// List files in admin directory
echo "\nFiles in admin directory:\n";
$files = scandir(__DIR__);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        $type = is_dir($file) ? '📁' : '📄';
        echo "$type $file\n";
    }
}

echo "</pre>";