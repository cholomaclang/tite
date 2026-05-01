<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once 'config/database.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    echo "Database connection: SUCCESS\n";
    
    // Test query
    $stmt = $conn->prepare("SELECT email, password FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo "Admin email: " . $user['email'] . "\n";
        echo "Password hash exists: " . (!empty($user['password']) ? 'YES' : 'NO') . "\n";
    } else {
        echo "No admin user found\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
