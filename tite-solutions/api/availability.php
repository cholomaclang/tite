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
$isTechnician = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'technician');

// Fallback: re-fetch role from DB if session is missing it
if (!$isAdmin && !$isTechnician && isset($_SESSION['user_id'])) {
    $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    if ($roleStmt) {
        $roleStmt->bind_param("i", $_SESSION['user_id']);
        $roleStmt->execute();
        $roleResult = $roleStmt->get_result();
        if ($roleResult && $roleResult->num_rows > 0) {
            $dbRole = $roleResult->fetch_assoc()['role'];
            if ($dbRole === 'admin') {
                $isAdmin = true;
            } elseif ($dbRole === 'technician') {
                $isTechnician = true;
            }
            $_SESSION['user_role'] = $dbRole;
        }
        $roleStmt->close();
    }
}

// Validate HTTP method
$allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
if (!in_array($method, $allowedMethods, true)) {
    errorResponse('Method not allowed', HTTP_METHOD_NOT_ALLOWED);
}

switch ($method) {
    case 'GET':
        getAvailability($conn);
        break;
    case 'POST':
        if (!$isAdmin && !$isTechnician) {
            errorResponse('Admin or technician access required', HTTP_FORBIDDEN);
        }
        createAvailability($conn, $isAdmin, $isTechnician);
        break;
    case 'PUT':
        if (!$isAdmin && !$isTechnician) {
            errorResponse('Admin or technician access required', HTTP_FORBIDDEN);
        }
        updateAvailability($conn, $isAdmin, $isTechnician);
        break;
    case 'DELETE':
        if (!$isAdmin) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        deleteAvailability($conn);
        break;
}

function resolveLoggedInTechnicianId($conn) {
    $userId = $_SESSION['user_id'] ?? null;
    $userEmail = strtolower(trim($_SESSION['user_email'] ?? ''));
    if (!$userId && !$userEmail) {
        return null;
    }

    $userIdCol = $conn->query("SHOW COLUMNS FROM technicians LIKE 'user_id'");
    if ($userIdCol && $userIdCol->num_rows > 0) {
        $stmt = $conn->prepare("SELECT id FROM technicians WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $id = (int)$res->fetch_assoc()['id'];
            $stmt->close();
            return $id;
        }
        $stmt->close();
    }

    if (!empty($userEmail)) {
        $stmt = $conn->prepare("SELECT id FROM technicians WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->bind_param("s", $userEmail);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $id = (int)$res->fetch_assoc()['id'];
            $stmt->close();
            return $id;
        }
        $stmt->close();
    }

    return null;
}

function getAvailability($conn) {
    $technicianId = $_GET['technician_id'] ?? '';
    $date = $_GET['date'] ?? '';
    $service = $_GET['service'] ?? '';
    
    try {
        $sql = "SELECT a.*, t.name as technician_name, t.specialties
                FROM availability a
                LEFT JOIN technicians t ON a.technician_id = t.id
                WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if (!empty($technicianId)) {
            $sql .= " AND a.technician_id = ?";
            $params[] = $technicianId;
            $types .= "i";
        }
        
        if (!empty($date)) {
            $sql .= " AND a.date = ?";
            $params[] = $date;
            $types .= "s";
        }
        
        // Filter by service if specified
        if (!empty($service)) {
            $sql .= " AND JSON_CONTAINS(t.specialties, ?)";
            $params[] = json_encode($service);
            $types .= "s";
        }
        
        $sql .= " AND (t.is_active = TRUE OR t.id IS NULL) AND a.date >= CURDATE()
                ORDER BY a.date, a.time_slot, t.name";
        
        $stmt = $conn->prepare($sql);
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $availability = [];
        while ($row = $result->fetch_assoc()) {
            $availability[] = [
                'id' => (int)$row['id'],
                'technician_id' => (int)$row['technician_id'],
                'technician_name' => $row['technician_name'],
                'date' => $row['date'],
                'time_slot' => $row['time_slot'],
                'is_available' => (bool)$row['is_available'],
                'specialties' => json_decode($row['specialties'] ?? '[]', true) ?: []
            ];
        }
        
        jsonResponse(['success' => true, 'data' => $availability]);
        
    } catch (Exception $e) {
        errorResponse('Failed to fetch availability: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function createAvailability($conn, $isAdmin, $isTechnician) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validation - technician_id is optional, defaults to 0 for global availability
    $required = ['date', 'time_slot'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            errorResponse("Field '$field' is required", HTTP_BAD_REQUEST, 'MISSING_FIELD');
        }
    }
    
    // Set technician_id to 0 if not provided (global availability)
    $technicianId = $input['technician_id'] ?? 0;
    
    // Validate date
    if (!DateTime::createFromFormat('Y-m-d', $input['date']) || $input['date'] < date('Y-m-d')) {
        errorResponse('Invalid or past date', HTTP_BAD_REQUEST, 'INVALID_DATE');
    }
    
    // Validate time slot
    $allowedSlots = ['09:00 AM', '11:00 AM', '02:00 PM', '04:00 PM'];
    if (!in_array($input['time_slot'], $allowedSlots)) {
        errorResponse('Invalid time slot', HTTP_BAD_REQUEST, 'INVALID_TIME_SLOT');
    }
    
    try {
        if ($isTechnician && !$isAdmin) {
            $techId = resolveLoggedInTechnicianId($conn);
            if (!$techId) {
                errorResponse('Technician profile not found', HTTP_FORBIDDEN, 'TECHNICIAN_NOT_FOUND');
            }
            $technicianId = $techId;
        }

        $sql = "INSERT INTO availability (technician_id, date, time_slot, is_available) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE is_available = VALUES(is_available)";
        
        $stmt = $conn->prepare($sql);
        $isAvailable = $input['is_available'] ?? true;
        
        $stmt->bind_param(
            "issi",
            $technicianId,
            $input['date'],
            $input['time_slot'],
            $isAvailable
        );
        
        if (!$stmt->execute()) {
            errorResponse('Failed to create availability', HTTP_INTERNAL_SERVER_ERROR);
        }
        
        jsonResponse([
            'success' => true,
            'message' => 'Availability created/updated successfully'
        ]);
        
    } catch (Exception $e) {
        errorResponse('Failed to create availability: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function updateAvailability($conn, $isAdmin, $isTechnician) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['id'])) {
        errorResponse('Availability ID is required', HTTP_BAD_REQUEST, 'MISSING_ID');
    }
    
    try {
        // Check if availability exists
        $checkSql = "SELECT id FROM availability WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $input['id']);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows === 0) {
            errorResponse('Availability not found', HTTP_NOT_FOUND, 'AVAILABILITY_NOT_FOUND');
        }
        
        if ($isTechnician && !$isAdmin) {
            $techId = resolveLoggedInTechnicianId($conn);
            if (!$techId) {
                errorResponse('Technician profile not found', HTTP_FORBIDDEN, 'TECHNICIAN_NOT_FOUND');
            }

            $ownerStmt = $conn->prepare("SELECT technician_id FROM availability WHERE id = ? LIMIT 1");
            $ownerStmt->bind_param("i", $input['id']);
            $ownerStmt->execute();
            $ownerRow = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();
            if ((int)($ownerRow['technician_id'] ?? 0) !== $techId) {
                errorResponse('Unauthorized', HTTP_FORBIDDEN);
            }
        }

        // Update availability
        $sql = "UPDATE availability SET is_available = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $isAvailable = $input['is_available'] ?? true;
        
        $stmt->bind_param("ii", $isAvailable, $input['id']);
        
        if (!$stmt->execute()) {
            errorResponse('Failed to update availability', HTTP_INTERNAL_SERVER_ERROR);
        }
        
        jsonResponse(['success' => true, 'message' => 'Availability updated successfully']);
        
    } catch (Exception $e) {
        errorResponse('Failed to update availability: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function deleteAvailability($conn) {
    $availabilityId = $_GET['id'] ?? '';
    
    if (empty($availabilityId) || !is_numeric($availabilityId)) {
        errorResponse('Valid availability ID is required', HTTP_BAD_REQUEST, 'INVALID_ID');
    }
    
    try {
        // Check if availability exists
        $checkSql = "SELECT id FROM availability WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $availabilityId);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows === 0) {
            errorResponse('Availability not found', HTTP_NOT_FOUND, 'AVAILABILITY_NOT_FOUND');
        }
        
        $sql = "DELETE FROM availability WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $availabilityId);
        
        if (!$stmt->execute()) {
            errorResponse('Failed to delete availability', HTTP_INTERNAL_SERVER_ERROR);
        }
        
        jsonResponse(['success' => true, 'message' => 'Availability deleted successfully']);
        
    } catch (Exception $e) {
        errorResponse('Failed to delete availability: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}
?>
