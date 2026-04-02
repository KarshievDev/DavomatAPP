<?php
echo "PHP Version: " . phpversion() . "\n";
echo "CURL: " . (extension_loaded('curl') ? 'Enabled' : 'Disabled') . "\n";
echo "PDO: " . (extension_loaded('pdo') ? 'Enabled' : 'Disabled') . "\n";
echo "PDO_MYSQL: " . (extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled') . "\n";
echo "PDO_SQLITE: " . (extension_loaded('pdo_sqlite') ? 'Enabled' : 'Disabled') . "\n";
?>
