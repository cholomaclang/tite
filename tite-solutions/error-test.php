<?php
// Test file to identify the exact error
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing basic PHP...<br>";

try {
    require_once 'config/database.php';
    echo "Database config loaded successfully<br>";
    
    $db = Database::getInstance();
    echo "Database instance created<br>";
    
    $conn = $db->getConnection();
    echo "Database connection established<br>";
    
    // Test simple query
    $result = $conn->query("SELECT 1");
    echo "Query execution successful<br>";
    
    require_once 'config/response.php';
    echo "Response config loaded<br>";
    
    // Test JSON response
    successResponse(['test' => 'working'], 'Test successful');
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
} catch (Error $e) {
    echo "Fatal Error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
}
?>
