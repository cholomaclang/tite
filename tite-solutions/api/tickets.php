<?php
// Force JSON-safe API output even on warnings/fatal errors.
ob_start();
ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_OFF);

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) {
    if (ob_get_length()) {
        ob_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Ticket API failed: ' . $e->getMessage(),
        'code' => 'UNCAUGHT_EXCEPTION'
    ]);
    exit;
});

register_shutdown_function(function () {
    $lastError = error_get_last();
    if (!$lastError) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($lastError['type'], $fatalTypes, true)) {
        return;
    }

    if (ob_get_length()) {
        ob_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        http_response_code(500);
    }

    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'code' => 'FATAL_ERROR'
    ]);
});

session_start();
require_once '../config/database.php';
require_once '../config/response.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    errorResponse('Authentication required', HTTP_UNAUTHORIZED, 'NO_SESSION');
}

// Session validation
if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    errorResponse('Session invalid', HTTP_UNAUTHORIZED, 'SESSION_INVALID');
}

$db = Database::getInstance();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];
$isAdmin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');
$isTechnician = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'technician');

// Fallback: re-fetch role from DB if session is missing it
if (!$isAdmin && !$isTechnician && isset($_SESSION['user_id'])) {
    $roleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    if ($roleStmt) {
        $roleStmt->bind_param("i", $userId);
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

ensureTicketAssignmentColumn($conn);

// Validate HTTP method
$allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
if (!in_array($method, $allowedMethods, true)) {
    errorResponse('Method not allowed', HTTP_METHOD_NOT_ALLOWED);
}

switch ($method) {
    case 'GET':
        getTickets($conn, $userId, $isAdmin);
        break;
    case 'POST':
        createTicket($conn, $userId);
        break;
    case 'PUT':
        updateTicket($conn, $userId, $isAdmin);
        break;
    case 'DELETE':
        deleteTicket($conn, $userId, $isAdmin);
        break;
}

function ticketColumnExists($conn, $column) {
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM tickets LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function ticketIdNeedsManualValue($conn) {
    $result = $conn->query("SHOW COLUMNS FROM tickets LIKE 'id'");
    if (!$result || $result->num_rows === 0) {
        return false;
    }
    $row = $result->fetch_assoc();
    $extra = strtolower((string)($row['Extra'] ?? ''));
    return strpos($extra, 'auto_increment') === false;
}

function ensureTicketAssignmentColumn($conn) {
    try {
        if (!ticketColumnExists($conn, 'assigned_technician_id')) {
            $conn->query("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS assigned_technician_id INT NULL");
        }
    } catch (Exception $e) {
        // Best-effort migration only.
    }
}

function resolveTechnicianIdForSession($conn, $userId) {
    if (ticketColumnExists($conn, 'assigned_technician_id') === false) {
        return null;
    }

    $userEmail = strtolower(trim($_SESSION['user_email'] ?? ''));

    $userIdColumn = false;
    $userIdCheck = $conn->query("SHOW COLUMNS FROM technicians LIKE 'user_id'");
    if ($userIdCheck && $userIdCheck->num_rows > 0) {
        $userIdColumn = true;
    }

    if ($userIdColumn) {
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

function findAvailableTechnicianForBooking($conn, $service, $date, $time) {
    if (!ticketColumnExists($conn, 'assigned_technician_id')) {
        return null;
    }

    $technicianHasIsActive = false;
    try {
        $activeCol = $conn->query("SHOW COLUMNS FROM technicians LIKE 'is_active'");
        $technicianHasIsActive = $activeCol && $activeCol->num_rows > 0;
    } catch (Exception $e) {
        $technicianHasIsActive = false;
    }

    $sql = "
        SELECT t.id
        FROM technicians t
        INNER JOIN availability a
            ON a.technician_id = t.id
            AND a.date = ?
            AND a.time_slot = ?
            AND a.is_available = 1
        WHERE t.specialties LIKE ?
    ";
    if ($technicianHasIsActive) {
        $sql .= " AND t.is_active = 1";
    }
    $sql .= "
        ORDER BY t.id ASC
        LIMIT 1
    ";
    $specialtyPattern = '%"' . $service . '"%';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("sss", $date, $time, $specialtyPattern);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $techId = $res->num_rows > 0 ? (int)$res->fetch_assoc()['id'] : null;
    $stmt->close();

    return $techId;
}

function getTickets($conn, $userId, $isAdmin) {
    // Validate status filter
    $allowedStatuses = ['all', 'pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
    $status = $_GET['status'] ?? 'all';
    if (!is_string($status)) {
        $status = 'all';
    }
    $status = strtolower(trim($status));
    if ($status === 'in-progress') {
        $status = 'in_progress';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'all';
    }
    
    try {
        if ($isAdmin) {
            // Admin sees all tickets with user info
            $sql = "SELECT t.*, u.name as user_name, u.email as user_email 
                    FROM tickets t 
                    LEFT JOIN users u ON t.user_id = u.id";
            
            $params = [];
            $types = "";
            
            if ($status !== 'all') {
                $sql .= " WHERE t.status = ?";
                $params[] = $status;
                $types .= "s";
            }
            
            $sql .= " ORDER BY t.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
        } elseif (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'technician') {
            // Technician sees tickets assigned to them
            if (!ticketColumnExists($conn, 'assigned_technician_id')) {
                successResponse([]);
            }

            $technicianId = resolveTechnicianIdForSession($conn, $userId);
            if (!$technicianId) {
                // Keep technician dashboard usable even when profile linkage is missing.
                successResponse([]);
            }
            
            $sql = "SELECT t.* 
                    FROM tickets t
                    WHERE t.assigned_technician_id = ?";
            
            $params = [$technicianId];
            $types = "i";
            
            if ($status !== 'all') {
                $sql .= " AND t.status = ?";
                $params[] = $status;
                $types .= "s";
            }
            
            $orderDateColumn = ticketColumnExists($conn, 'booking_date') ? 'booking_date' : (ticketColumnExists($conn, 'date') ? 'date' : 'created_at');
            $orderTimeColumn = ticketColumnExists($conn, 'booking_time') ? 'booking_time' : (ticketColumnExists($conn, 'time') ? 'time' : 'created_at');
            $sql .= " ORDER BY t.{$orderDateColumn} ASC, t.{$orderTimeColumn} ASC";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                successResponse([]);
            }
            $stmt->bind_param($types, ...$params);
        } else {
            // Regular user sees only their tickets
            $sql = "SELECT t.*, u.name as user_name, 
                    tech.name as technician_name, tech.avatar_url as technician_avatar
                    FROM tickets t 
                    LEFT JOIN users u ON t.user_id = u.id 
                    LEFT JOIN technicians tech ON t.assigned_technician_id = tech.id
                    WHERE t.user_id = ?";
            
            $params = [$userId];
            $types = "i";
            
            if ($status !== 'all') {
                $sql .= " AND t.status = ?";
                $params[] = $status;
                $types .= "s";
            }
            
            $sql .= " ORDER BY t.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = [];
        
        while ($row = $result->fetch_assoc()) {
            $ticketId = $row['ticket_number'] ?? ($row['id'] ?? null);
            $serviceValue = $row['service_name'] ?? ($row['service'] ?? '');
            $dateValue = $row['booking_date'] ?? ($row['date'] ?? '');
            $timeValue = $row['booking_time'] ?? ($row['time'] ?? '');
            $firstNameValue = $row['firstName'] ?? '';
            $lastNameValue = $row['lastName'] ?? '';
            $customerValue = $row['customer_name'] ?? trim($firstNameValue . ' ' . $lastNameValue);

            $tickets[] = [
                'id' => $ticketId,
                'service' => $serviceValue,
                'date' => $dateValue,
                'time' => $timeValue,
                'customer' => $customerValue,
                'firstName' => $firstNameValue,
                'lastName' => $lastNameValue,
                'email' => $row['email'] ?? '',
                'phone' => $row['phone'] ?? '',
                'address' => $row['address'] ?? '',
                'description' => $row['description'] ?? '',
                'status' => $row['status'] ?? 'pending',
                'assigned_technician_id' => isset($row['assigned_technician_id']) && $row['assigned_technician_id'] !== null ? (int)$row['assigned_technician_id'] : null,
                'technician_name' => $row['technician_name'] ?? null,
                'technician_avatar' => $row['technician_avatar'] ?? null,
                'price' => isset($row['price']) ? (float)$row['price'] : 0.0,
                'createdAt' => $row['created_at'] ?? null,
                'userEmail' => $isAdmin ? ($row['user_email'] ?? 'Unknown') : $_SESSION['user_email'],
                'userName' => $isAdmin ? ($row['user_name'] ?? 'Unknown') : null
            ];
        }
        
        $stmt->close();
        successResponse($tickets);
        
    } catch (Exception $e) {
        errorResponse('Failed to fetch tickets: ' . $e->getMessage(), HTTP_INTERNAL_ERROR);
    }
}

function createTicket($conn, $userId) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($data)) {
        errorResponse('Invalid request data', HTTP_BAD_REQUEST);
    }
    
    // Extract and validate fields
    $service = trim($data['service'] ?? '');
    $date = $data['date'] ?? '';
    $time = trim($data['time'] ?? '');
    $firstName = trim($data['firstName'] ?? '');
    $lastName = trim($data['lastName'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $phone = trim($data['phone'] ?? '');
    $address = trim($data['address'] ?? '');
    $description = trim($data['description'] ?? '');
    
    // Required field validation
    $errors = [];
    if (empty($service)) $errors[] = 'Service is required';
    if (empty($date)) $errors[] = 'Date is required';
    if (empty($time)) $errors[] = 'Time is required';
    if (empty($firstName)) $errors[] = 'First name is required';
    if (empty($lastName)) $errors[] = 'Last name is required';
    if (empty($email)) $errors[] = 'Email is required';
    if (empty($phone)) $errors[] = 'Phone is required';
    
    if (!empty($errors)) {
        errorResponse('Validation failed: ' . implode(', ', $errors), HTTP_UNPROCESSABLE, 'VALIDATION_ERROR');
    }
    
    // Format validation
    if (!validateDate($date)) {
        errorResponse('Invalid date format or past date', HTTP_BAD_REQUEST, 'INVALID_DATE');
    }
    
    if (!validateTime($time)) {
        errorResponse('Invalid time format', HTTP_BAD_REQUEST, 'INVALID_TIME');
    }
    
    if (!validateEmail($email)) {
        errorResponse('Invalid email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
    }
    
    $serviceColumn = ticketColumnExists($conn, 'service_name') ? 'service_name' : (ticketColumnExists($conn, 'service') ? 'service' : null);
    $dateColumn = ticketColumnExists($conn, 'booking_date') ? 'booking_date' : (ticketColumnExists($conn, 'date') ? 'date' : null);
    $timeColumn = ticketColumnExists($conn, 'booking_time') ? 'booking_time' : (ticketColumnExists($conn, 'time') ? 'time' : null);

    // Check for duplicate booking only when the required columns exist in live schema.
    if ($serviceColumn && $dateColumn && $timeColumn) {
        $checkSql = "SELECT id FROM tickets WHERE user_id = ? AND {$serviceColumn} = ? AND {$dateColumn} = ? AND {$timeColumn} = ?";
        $checkStmt = $conn->prepare($checkSql);
        if ($checkStmt) {
            $checkStmt->bind_param("isss", $userId, $service, $date, $time);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $checkStmt->close();
                errorResponse('Duplicate booking detected', HTTP_CONFLICT, 'DUPLICATE_BOOKING');
            }
            $checkStmt->close();
        }
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get service details including ID and price
        $serviceStmt = $conn->prepare("
            SELECT id, base_price 
            FROM services 
            WHERE name = ? AND is_active = 1 
            LIMIT 1
        ");
        if (!$serviceStmt) {
            throw new Exception('Service query preparation failed');
        }
        $serviceStmt->bind_param("s", $service);
        $serviceStmt->execute();
        $serviceResult = $serviceStmt->get_result();
        
        if ($serviceResult->num_rows === 0) {
            $serviceStmt->close();
            throw new Exception('Service not found or inactive');
        }
        
        $serviceData = $serviceResult->fetch_assoc();
        $serviceId = $serviceData['id'];
        $price = $serviceData['base_price'] ?? 0;
        $serviceStmt->close();
        
        // Generate unique ticket number
        $ticketNumber = generateTicketNumber();
        
        // Verify ticket number uniqueness when schema supports it.
        // If preparation fails on older schema variants, skip this step and rely on insert constraints.
        $hasTicketNumber = ticketColumnExists($conn, 'ticket_number');
        if ($hasTicketNumber) {
            $uniqueCheck = $conn->prepare("SELECT id FROM tickets WHERE ticket_number = ?");
            if ($uniqueCheck) {
                $maxAttempts = 5;
                $attempts = 0;
                
                while ($attempts < $maxAttempts) {
                    $uniqueCheck->bind_param("s", $ticketNumber);
                    $uniqueCheck->execute();
                    if ($uniqueCheck->get_result()->num_rows === 0) {
                        break;
                    }
                    $ticketNumber = generateTicketNumber();
                    $attempts++;
                }
                
                if ($attempts >= $maxAttempts) {
                    $uniqueCheck->close();
                    throw new Exception('Failed to generate unique ticket number');
                }
                $uniqueCheck->close();
            }
        }
        
        $customerName = trim($firstName . ' ' . $lastName);
        
        $assignedTechnicianId = findAvailableTechnicianForBooking($conn, $service, $date, $time);

        // Build insert dynamically to match live tickets schema.
        $insertColumns = ['user_id'];
        $insertValues = [$userId];
        $insertTypes = 'i';

        if (ticketColumnExists($conn, 'id') && ticketIdNeedsManualValue($conn)) {
            $nextId = 1;
            $idResult = $conn->query("SELECT MAX(id) AS max_id FROM tickets");
            if ($idResult && $idResult->num_rows > 0) {
                $maxRow = $idResult->fetch_assoc();
                $nextId = ((int)($maxRow['max_id'] ?? 0)) + 1;
            }
            $insertColumns[] = 'id';
            $insertValues[] = $nextId;
            $insertTypes .= 'i';
        }

        if (ticketColumnExists($conn, 'ticket_number')) {
            $insertColumns[] = 'ticket_number';
            $insertValues[] = $ticketNumber;
            $insertTypes .= 's';
        }

        if (ticketColumnExists($conn, 'service_id')) {
            $insertColumns[] = 'service_id';
            $insertValues[] = (int)$serviceId;
            $insertTypes .= 'i';
        }
        if ($serviceColumn) {
            $insertColumns[] = $serviceColumn;
            $insertValues[] = $service;
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'firstName')) {
            $insertColumns[] = 'firstName';
            $insertValues[] = $firstName;
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'lastName')) {
            $insertColumns[] = 'lastName';
            $insertValues[] = $lastName;
            $insertTypes .= 's';
        }
        if ($dateColumn) {
            $insertColumns[] = $dateColumn;
            $insertValues[] = $date;
            $insertTypes .= 's';
        }
        if ($timeColumn) {
            $insertColumns[] = $timeColumn;
            $insertValues[] = $time;
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'customer_name')) {
            $insertColumns[] = 'customer_name';
            $insertValues[] = $customerName;
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'email')) {
            $insertColumns[] = 'email';
            $insertValues[] = $email;
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'phone')) {
            $insertColumns[] = 'phone';
            $insertValues[] = $phone;
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'address')) {
            $insertColumns[] = 'address';
            $insertValues[] = $address;
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'description')) {
            $insertColumns[] = 'description';
            $insertValues[] = $description;
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'price')) {
            $insertColumns[] = 'price';
            $insertValues[] = (float)$price;
            $insertTypes .= 'd';
        }
        if (ticketColumnExists($conn, 'status')) {
            $insertColumns[] = 'status';
            $insertValues[] = 'pending';
            $insertTypes .= 's';
        }
        if (ticketColumnExists($conn, 'assigned_technician_id') && $assignedTechnicianId !== null) {
            $insertColumns[] = 'assigned_technician_id';
            $insertValues[] = (int)$assignedTechnicianId;
            $insertTypes .= 'i';
        }

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $insertSql = "INSERT INTO tickets (" . implode(', ', $insertColumns) . ") VALUES (" . $placeholders . ")";
        $insertStmt = $conn->prepare($insertSql);
        if (!$insertStmt) {
            throw new Exception('Ticket insert preparation failed: ' . $conn->error . ' | SQL: ' . $insertSql);
        }
        $insertStmt->bind_param($insertTypes, ...$insertValues);
        
        if (!$insertStmt->execute()) {
            throw new Exception('Failed to create ticket: ' . $insertStmt->error);
        }
        
        $ticketId = $insertStmt->insert_id;
        $insertStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        successResponse([
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'price' => (float)$price
        ], 'Booking created successfully');
        
    } catch (Exception $e) {
        $conn->rollback();
        errorResponse($e->getMessage(), HTTP_INTERNAL_ERROR);
    }
}

function updateTicket($conn, $userId, $isAdmin) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($data)) {
        errorResponse('Invalid request data', HTTP_BAD_REQUEST);
    }
    
    $ticketId = trim($data['id'] ?? '');
    
    if (empty($ticketId)) {
        errorResponse('Ticket ID is required', HTTP_BAD_REQUEST);
    }
    
    $ticketKeyColumn = ticketColumnExists($conn, 'ticket_number') ? 'ticket_number' : 'id';
    $ticketIdType = is_numeric($ticketId) && strpos($ticketId, '.') === false ? 'i' : 's';
    $ticketIdValue = $ticketIdType === 'i' ? (int)$ticketId : $ticketId;

    // Validate ticket exists and check ownership
    $checkStmt = $conn->prepare("
        SELECT t.user_id, t.status 
        FROM tickets t 
        WHERE t.{$ticketKeyColumn} = ?
    ");
    if (!$checkStmt) {
        errorResponse('Failed to prepare ticket lookup', HTTP_INTERNAL_ERROR);
    }
    $checkStmt->bind_param($ticketIdType, $ticketIdValue);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        $checkStmt->close();
        errorResponse('Ticket not found', HTTP_NOT_FOUND);
    }
    
    $ticket = $result->fetch_assoc();
    $checkStmt->close();
    
    // Authorization check
    if (!$isAdmin && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'technician') {
        // Technician can update only tickets assigned to them, and only status field
        $technicianId = resolveTechnicianIdForSession($conn, $userId);
        if (!$technicianId) {
            errorResponse('Technician profile not found', HTTP_FORBIDDEN, 'TECHNICIAN_NOT_FOUND');
        }
        
        $assignCheck = $conn->prepare("SELECT assigned_technician_id FROM tickets WHERE {$ticketKeyColumn} = ? LIMIT 1");
        if (!$assignCheck) {
            errorResponse('Failed to prepare technician assignment lookup', HTTP_INTERNAL_ERROR);
        }
        $assignCheck->bind_param($ticketIdType, $ticketIdValue);
        $assignCheck->execute();
        $assignedRow = $assignCheck->get_result()->fetch_assoc();
        $assignCheck->close();
        
        if ((int)($assignedRow['assigned_technician_id'] ?? 0) !== $technicianId) {
            errorResponse('Unauthorized', HTTP_FORBIDDEN);
        }
        
        // Technicians can only update status
        $data = array_intersect_key($data, array_flip(['id', 'status']));
    } elseif (!$isAdmin && $ticket['user_id'] != $userId) {
        errorResponse('Unauthorized', HTTP_FORBIDDEN);
    }
    
    // Non-admin cannot update completed/cancelled tickets, except reverting completed to pending
    if (!$isAdmin && in_array($ticket['status'], ['completed', 'cancelled'])) {
        $isReverting = isset($data['status']) && $data['status'] === 'pending' && $ticket['status'] === 'completed';
        if (!$isReverting) {
            errorResponse('Cannot update completed or cancelled tickets', HTTP_FORBIDDEN);
        }
    }
    
    // Build dynamic update query matching live schema
    $serviceColumn = ticketColumnExists($conn, 'service_name') ? 'service_name' : 'service';
    $dateColumn = ticketColumnExists($conn, 'booking_date') ? 'booking_date' : 'date';
    $timeColumn = ticketColumnExists($conn, 'booking_time') ? 'booking_time' : 'time';
    $hasAssignedTech = ticketColumnExists($conn, 'assigned_technician_id');

    $allowedFields = [
        'status' => ['column' => 'status', 'type' => 's', 'validate' => function($v) {
            $allowed = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
            return in_array($v, $allowed, true);
        }],
        'service' => ['column' => $serviceColumn, 'type' => 's', 'validate' => null],
        'date' => ['column' => $dateColumn, 'type' => 's', 'validate' => null],
        'time' => ['column' => $timeColumn, 'type' => 's', 'validate' => null],
        'address' => ['column' => 'address', 'type' => 's', 'validate' => null],
        'description' => ['column' => 'description', 'type' => 's', 'validate' => null],
        'firstName' => ['column' => 'firstName', 'type' => 's', 'validate' => null],
        'lastName' => ['column' => 'lastName', 'type' => 's', 'validate' => null],
        'email' => ['column' => 'email', 'type' => 's', 'validate' => null],
        'phone' => ['column' => 'phone', 'type' => 's', 'validate' => null]
    ];

    // Always allow technician assignment field
    $allowedFields['assigned_technician_id'] = ['column' => 'assigned_technician_id', 'type' => 'i', 'validate' => function($v) {
        return $v === null || $v === '' || is_numeric($v);
    }];

    $fields = [];
    $types = "";
    $values = [];

    foreach ($allowedFields as $key => $config) {
        if (!array_key_exists($key, $data)) continue;

        $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];

        // Validation
        if ($config['validate'] !== null) {
            if (is_callable($config['validate'])) {
                if (!$config['validate']($value)) {
                    errorResponse("Invalid value for $key", HTTP_BAD_REQUEST);
                }
            }
        }

        $fields[] = "{$config['column']} = ?";
        $types .= $config['type'];
        $values[] = $value;

        // Update service_id if service name changed and column exists
        if ($key === 'service' && $isAdmin && ticketColumnExists($conn, 'service_id')) {
            $serviceStmt = $conn->prepare("SELECT id FROM services WHERE name = ? AND is_active = 1 LIMIT 1");
            if ($serviceStmt) {
                $serviceStmt->bind_param("s", $value);
                $serviceStmt->execute();
                $svcResult = $serviceStmt->get_result();
                if ($svcResult->num_rows > 0) {
                    $fields[] = "service_id = ?";
                    $types .= "i";
                    $values[] = $svcResult->fetch_assoc()['id'];
                }
                $serviceStmt->close();
            }
        }
    }

    if (empty($fields)) {
        errorResponse('No valid fields to update', HTTP_BAD_REQUEST);
    }

    // Add updated_at only when column exists in live schema.
    if (ticketColumnExists($conn, 'updated_at')) {
        $fields[] = "updated_at = NOW()";
    }

    $sql = "UPDATE tickets SET " . implode(", ", $fields) . " WHERE {$ticketKeyColumn} = ?";
    $types .= $ticketIdType;
    $values[] = $ticketIdValue;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        errorResponse('Failed to prepare ticket update: ' . $conn->error, HTTP_INTERNAL_ERROR);
    }
    
    // bind_param requires references; use call_user_func_array to safely handle mixed types
    $bindParams = array_merge([$types], $values);
    $refs = [];
    foreach ($bindParams as $key => $value) {
        $refs[$key] = &$bindParams[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        errorResponse('Update failed: ' . $error, HTTP_INTERNAL_ERROR);
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    successResponse(['affected_rows' => $affectedRows], 'Ticket updated successfully');
}

function deleteTicket($conn, $userId, $isAdmin) {
    // Only admins can delete
    if (!$isAdmin) {
        errorResponse('Admin access required', HTTP_FORBIDDEN);
    }
    
    $ticketId = trim($_GET['id'] ?? '');
    
    if (empty($ticketId)) {
        errorResponse('Ticket ID is required', HTTP_BAD_REQUEST);
    }
    
    $ticketKeyColumn = ticketColumnExists($conn, 'ticket_number') ? 'ticket_number' : 'id';
    $ticketIdType = is_numeric($ticketId) && strpos($ticketId, '.') === false ? 'i' : 's';
    $ticketIdValue = $ticketIdType === 'i' ? (int)$ticketId : $ticketId;

    // Check if ticket exists
    $checkStmt = $conn->prepare("SELECT id FROM tickets WHERE {$ticketKeyColumn} = ?");
    if (!$checkStmt) {
        errorResponse('Failed to prepare ticket lookup', HTTP_INTERNAL_ERROR);
    }
    $checkStmt->bind_param($ticketIdType, $ticketIdValue);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows === 0) {
        $checkStmt->close();
        errorResponse('Ticket not found', HTTP_NOT_FOUND);
    }
    $checkStmt->close();
    
    // Soft delete - update status instead of actual delete (safer)
    // Or hard delete if you prefer:
    $stmt = $conn->prepare("DELETE FROM tickets WHERE {$ticketKeyColumn} = ?");
    if (!$stmt) {
        errorResponse('Failed to prepare delete statement', HTTP_INTERNAL_ERROR);
    }
    $stmt->bind_param($ticketIdType, $ticketIdValue);
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        errorResponse('Delete failed: ' . $error, HTTP_INTERNAL_ERROR);
    }
    
    $stmt->close();
    successResponse(null, 'Ticket deleted successfully');
}

?>