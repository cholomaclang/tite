<?php
session_start();
require_once '../config/database.php';
require_once '../config/response.php';

// Authentication check (optional for GET requests)
if (!isset($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Authentication required', HTTP_UNAUTHORIZED, 'NO_SESSION');
}

// Session validation (only if user is logged in)
if (isset($_SESSION['user_id']) && isset($_SESSION['user_agent'])) {
    if ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        errorResponse('Session invalid', HTTP_UNAUTHORIZED, 'SESSION_INVALID');
    }
}

$db = Database::getInstance();
$conn = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');

// Validate HTTP method
$allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
if (!in_array($method, $allowedMethods, true)) {
    errorResponse('Method not allowed', HTTP_METHOD_NOT_ALLOWED);
}

switch ($method) {
    case 'GET':
        getServices($conn);
        break;
    case 'POST':
        if (!$isAdmin) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        createService($conn);
        break;
    case 'PUT':
        if (!$isAdmin) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        updateService($conn);
        break;
    case 'DELETE':
        if (!$isAdmin) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        deleteService($conn);
        break;
}

function getServices($conn) {
    $activeOnly = isset($_GET['active']) && $_GET['active'] === 'true';
    
    try {
        $sql = "SELECT * FROM services";
        if ($activeOnly) {
            $sql .= " WHERE is_active = TRUE";
        }
        $sql .= " ORDER BY category, name";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $services = [];
        while ($row = $result->fetch_assoc()) {
            $services[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'base_price' => (float)$row['base_price'],
                'duration_minutes' => (int)$row['duration_minutes'],
                'category' => $row['category'],
                'icon' => $row['icon'],
                'is_active' => (bool)(isset($row['is_active']) ? $row['is_active'] : 1),
                'created_at' => $row['created_at'],
                'updated_at' => isset($row['updated_at']) ? $row['updated_at'] : null
            ];
        }
        
        jsonResponse(['success' => true, 'data' => $services]);
        
    } catch (Exception $e) {
        errorResponse('Failed to fetch services: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function createService($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validation
    $required = ['name', 'description', 'base_price', 'duration_minutes', 'category'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            errorResponse("Field '$field' is required", HTTP_BAD_REQUEST, 'MISSING_FIELD');
        }
    }
    
    if (!is_numeric($input['base_price']) || $input['base_price'] <= 0) {
        errorResponse('Base price must be a positive number', HTTP_BAD_REQUEST, 'INVALID_PRICE');
    }
    
    if (!is_numeric($input['duration_minutes']) || $input['duration_minutes'] <= 0) {
        errorResponse('Duration must be a positive number', HTTP_BAD_REQUEST, 'INVALID_DURATION');
    }
    
    $allowedCategories = ['repair', 'network', 'upgrade', 'recovery', 'software', 'maintenance'];
    if (!in_array($input['category'], $allowedCategories)) {
        errorResponse('Invalid category', HTTP_BAD_REQUEST, 'INVALID_CATEGORY');
    }
    
    try {
        $sql = "INSERT INTO services (name, description, base_price, duration_minutes, category, icon, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $icon = $input['icon'] ?? 'fa-tools';
        $isActive = $input['is_active'] ?? true;
        
        $stmt->bind_param(
            "ssdiisi",
            $input['name'],
            $input['description'],
            $input['base_price'],
            $input['duration_minutes'],
            $input['category'],
            $icon,
            $isActive
        );
        
        if (!$stmt->execute()) {
            errorResponse('Failed to create service', HTTP_INTERNAL_SERVER_ERROR);
        }
        
        $serviceId = $conn->insert_id;
        
        jsonResponse([
            'success' => true,
            'data' => [
                'id' => $serviceId,
                'name' => $input['name'],
                'description' => $input['description'],
                'base_price' => (float)$input['base_price'],
                'duration_minutes' => (int)$input['duration_minutes'],
                'category' => $input['category'],
                'icon' => $icon,
                'is_active' => (bool)$isActive
            ]
        ]);
        
    } catch (Exception $e) {
        errorResponse('Failed to create service: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function updateService($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['id'])) {
        errorResponse('Service ID is required', HTTP_BAD_REQUEST, 'MISSING_ID');
    }
    
    try {
        // Check if service exists
        $checkSql = "SELECT id FROM services WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $input['id']);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows === 0) {
            errorResponse('Service not found', HTTP_NOT_FOUND, 'SERVICE_NOT_FOUND');
        }
        
        // Build dynamic update query
        $updateFields = [];
        $params = [];
        $types = "";
        
        $allowedFields = ['name', 'description', 'base_price', 'duration_minutes', 'category', 'icon', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $updateFields[] = "$field = ?";
                $params[] = $input[$field];
                $types .= gettype($input[$field]) === 'boolean' ? 'i' : 's';
            }
        }
        
        if (empty($updateFields)) {
            errorResponse('No valid fields to update', HTTP_BAD_REQUEST, 'NO_FIELDS');
        }
        
        $params[] = $input['id'];
        $types .= 'i';
        
        $sql = "UPDATE services SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if (!$stmt->execute()) {
            errorResponse('Failed to update service', HTTP_INTERNAL_SERVER_ERROR);
        }
        
        jsonResponse(['success' => true, 'message' => 'Service updated successfully']);
        
    } catch (Exception $e) {
        errorResponse('Failed to update service: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function deleteService($conn) {
    $serviceId = $_GET['id'] ?? '';
    
    if (empty($serviceId) || !is_numeric($serviceId)) {
        errorResponse('Valid service ID is required', HTTP_BAD_REQUEST, 'INVALID_ID');
    }
    
    try {
        // Check if service exists
        $checkSql = "SELECT id FROM services WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $serviceId);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows === 0) {
            errorResponse('Service not found', HTTP_NOT_FOUND, 'SERVICE_NOT_FOUND');
        }
        
        // Soft delete by setting is_active to false
        $sql = "UPDATE services SET is_active = FALSE WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $serviceId);
        
        if (!$stmt->execute()) {
            errorResponse('Failed to delete service', HTTP_INTERNAL_SERVER_ERROR);
        }
        
        jsonResponse(['success' => true, 'message' => 'Service deleted successfully']);
        
    } catch (Exception $e) {
        errorResponse('Failed to delete service: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}
?>
