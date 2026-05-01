<?php
// Simple test to check JSON response
header('Content-Type: application/json');

// Test 1: Basic JSON
echo json_encode(['success' => true, 'message' => 'Test working']);
?>
