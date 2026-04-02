<?php
$dir = 'uploads/';
if (!is_dir($dir)) {
    if (mkdir($dir, 0777, true)) {
        echo "Dir created successfully.<br>";
    } else {
        echo "Failed to create dir.<br>";
    }
} else {
    echo "Dir already exists.<br>";
}

if (is_writable($dir)) {
    echo "Dir is writable.<br>";
} else {
    echo "Dir is NOT writable.<br>";
}

$testFile = $dir . 'test_' . time() . '.txt';
if (file_put_contents($testFile, 'test')) {
    echo "File write successful.<br>";
    unlink($testFile);
} else {
    echo "File write FAILED.<br>";
}
?>
