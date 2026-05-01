<?php
// Most basic test to isolate the error
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting test...<br>";

try {
    // Test 1: Basic includes
    require_once 'config/database.php';
    echo "Database.php loaded<br>";
    
    // Test 2: Database connection
    $db = Database::getInstance();
    $conn = $db->getConnection();
    echo "Database connected<br>";
    
    // Test 3: Response functions
    require_once 'config/response.php';
    echo "Response.php loaded<br>";
    
    // Test 4: Simple JSON response
    successResponse(['test' => 'working'], 'Simple test successful');
    
} catch (ParseError $e) {
    echo "Parse Error: " . $e->getMessage() . " at line " . $e->getLine() . "<br>";
} catch (Error $e) {
    echo "Fatal Error: " . $e->getMessage() . " at line " . $e->getLine() . "<br>";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . " at line " . $e->getLine() . "<br>";
}
?>
