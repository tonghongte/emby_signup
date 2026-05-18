<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Syntax Check Started</h2>";

try {
    // We register an error handler to catch compilation errors
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error !== null) {
            echo "<h3>Fatal Error Caught:</h3>";
            echo "<pre>";
            print_r($error);
            echo "</pre>";
        }
    });

    echo "Requiring admin.php...<br>";
    require 'admin.php';
    echo "admin.php successfully executed!";
} catch (Throwable $e) {
    echo "<h3>Exception Caught:</h3>";
    echo "<pre>" . htmlspecialchars((string)$e) . "</pre>";
}
