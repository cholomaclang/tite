<?php
// Debug script to test auth API responses
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test registration
echo "<h2>Testing Registration</h2>";
$testData = [
    'name' => 'Test User',
    'email' => 'test' . time() . '@test.com',
    'password' => 'TestPass123'
];

$ch = curl_init('http://localhost/tite-solutions/api/auth.php?action=register');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";

// Test login with existing user
echo "<h2>Testing Login</h2>";
$loginData = [
    'email' => 'admin@tite.admin',
    'password' => 'admin123' // You'll need to use the actual password
];

$ch = curl_init('http://localhost/tite-solutions/api/auth.php?action=login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";

// Check if JSON is valid
$jsonData = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON Error: " . json_last_error_msg() . "<br>";
} else {
    echo "JSON is valid<br>";
}
?>
