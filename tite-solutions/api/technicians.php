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

ensureTechnicianColumns($conn);

// Validate HTTP method
$allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
if (!in_array($method, $allowedMethods, true)) {
    errorResponse('Method not allowed', HTTP_METHOD_NOT_ALLOWED);
}

function getTechnicianColumns($conn) {
    if (isset($GLOBALS['__technician_columns_cache']) && is_array($GLOBALS['__technician_columns_cache'])) {
        return $GLOBALS['__technician_columns_cache'];
    }

    $columns = [];
    try {
        $result = $conn->query("SHOW COLUMNS FROM technicians");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $columns[$row['Field']] = true;
            }
        }
    } catch (Exception $e) {
        // Keep empty column map; callers can still handle gracefully.
    }

    $GLOBALS['__technician_columns_cache'] = $columns;
    return $GLOBALS['__technician_columns_cache'];
}

function hasTechnicianColumn($conn, $columnName) {
    $columns = getTechnicianColumns($conn);
    return isset($columns[$columnName]);
}

function ensureTechnicianColumns($conn) {
    try {
        $desiredColumns = [
            "user_id" => "ALTER TABLE technicians ADD COLUMN user_id INT NULL AFTER id",
            "avatar_url" => "ALTER TABLE technicians ADD COLUMN avatar_url VARCHAR(255) NULL AFTER specialties",
            "is_active" => "ALTER TABLE technicians ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER avatar_url",
            "updated_at" => "ALTER TABLE technicians ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
        ];

        foreach ($desiredColumns as $columnName => $alterSql) {
            if (!hasTechnicianColumn($conn, $columnName)) {
                $conn->query($alterSql);
            }
        }

        // Refresh cached columns after migration attempts.
        $refresh = $conn->query("SHOW COLUMNS FROM technicians");
        if ($refresh) {
            $newColumns = [];
            while ($row = $refresh->fetch_assoc()) {
                $newColumns[$row['Field']] = true;
            }
            $GLOBALS['__technician_columns_cache'] = $newColumns;
        }
    } catch (Exception $e) {
        // Leave schema migration best-effort to avoid breaking read-only requests.
    }
}

function hasTechnicianUserIdColumn($conn) {
    return hasTechnicianColumn($conn, 'user_id');
}

/**
 * Save a base64-encoded avatar image to disk and return the relative URL path.
 * Returns null if input is empty or invalid.
 */
function saveAvatarBase64($base64Data, $technicianId = 0) {
    if (empty($base64Data) || !is_string($base64Data)) {
        return null;
    }

    // Must be a data URI: data:image/...;base64,...
    if (!preg_match('/^data:image\/(\w+);base64,(.+)$/s', $base64Data, $matches)) {
        return null;
    }

    $extension = strtolower($matches[1]);
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        return null;
    }

    $imageData = base64_decode($matches[2], true);
    if ($imageData === false) {
        return null;
    }

    // Limit file size to 2MB
    if (strlen($imageData) > 2 * 1024 * 1024) {
        return null;
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'tech_' . ($technicianId > 0 ? $technicianId : uniqid()) . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (file_put_contents($filepath, $imageData) === false) {
        return null;
    }

    return 'uploads/' . $filename;
}

function resolveCurrentTechnician($conn) {
    $userId = $_SESSION['user_id'] ?? null;
    $userEmail = strtolower(trim($_SESSION['user_email'] ?? ''));

    if (!$userId && !$userEmail) {
        return null;
    }

    // Prefer strict user_id mapping when available.
    if ($userId && hasTechnicianUserIdColumn($conn)) {
        $stmt = $conn->prepare("SELECT * FROM technicians WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row;
        }
        $stmt->close();
    }

    // Backward compatibility for old rows created without user_id linkage.
    if (!empty($userEmail)) {
        $stmt = $conn->prepare("SELECT * FROM technicians WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->bind_param("s", $userEmail);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row;
        }
        $stmt->close();
    }

    return null;
}

switch ($method) {
    case 'GET':
        getTechnicians($conn, $isAdmin, $isTechnician);
        break;
    case 'POST':
        if (!$isAdmin) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        createTechnician($conn);
        break;
    case 'PUT':
        if (!$isAdmin && !$isTechnician) {
            errorResponse('Admin or technician access required', HTTP_FORBIDDEN);
        }
        updateTechnician($conn, $isAdmin);
        break;
    case 'DELETE':
        if (!$isAdmin) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        deleteTechnician($conn);
        break;
}

function getTechnicians($conn, $isAdmin, $isTechnician) {
    // Allow logged-in technician to fetch their own profile: ?me=true
    $me = isset($_GET['me']) && ($_GET['me'] === 'true' || $_GET['me'] === '1');
    if ($me) {
        if (!isset($_SESSION['user_id'])) {
            errorResponse('Authentication required', HTTP_UNAUTHORIZED, 'NO_SESSION');
        }
        if (!$isAdmin && !$isTechnician) {
            errorResponse('Forbidden', HTTP_FORBIDDEN);
        }
        
        $row = resolveCurrentTechnician($conn);
        if (!$row) {
            errorResponse('Technician profile not found', HTTP_NOT_FOUND, 'TECHNICIAN_NOT_FOUND');
        }
        
        successResponse([
            'id' => (int)$row['id'],
            'user_id' => isset($row['user_id']) && $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'name' => $row['name'] ?? '',
            'email' => $row['email'] ?? '',
            'phone' => $row['phone'] ?? '',
            'specialties' => json_decode($row['specialties'] ?? '[]', true) ?: [],
            'avatar_url' => $row['avatar_url'] ?? null,
            'is_active' => isset($row['is_active']) ? (bool)$row['is_active'] : true,
            'pending_changes' => !empty($row['pending_changes']) ? json_decode($row['pending_changes'], true) : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null
        ]);
    }
    
    $service = $_GET['service'] ?? '';
    $date = $_GET['date'] ?? '';
    $timeSlot = $_GET['time_slot'] ?? '';
    $activeOnly = isset($_GET['active']) && $_GET['active'] === 'true';
    
    try {
        $sql = "SELECT t.*
                FROM technicians t";
        
        $params = [];
        $types = "";
        
        // Add service filter if specified
        if (!empty($service)) {
            // specialties is stored as JSON array; use LIKE for broad compatibility
            $sql .= (empty($params) ? " WHERE" : " AND") . " t.specialties LIKE ?";
            $params[] = '%"' . $service . '"%';
            $types .= "s";
        }
        
        if ($activeOnly && hasTechnicianColumn($conn, 'is_active')) {
            $sql .= (empty($params) ? " WHERE" : " AND") . " t.is_active = 1";
        }
        
        $sql .= " ORDER BY t.name";
        
        $stmt = $conn->prepare($sql);
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $technicians = [];
        while ($row = $result->fetch_assoc()) {
            $technician = [
                'id' => (int)$row['id'],
                'user_id' => isset($row['user_id']) && $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'name' => $row['name'] ?? '',
                'email' => $row['email'] ?? '',
                'phone' => $row['phone'] ?? '',
                'specialties' => json_decode($row['specialties'] ?? '[]', true) ?: [],
                'avatar_url' => $row['avatar_url'] ?? null,
                'is_active' => isset($row['is_active']) ? (bool)$row['is_active'] : true,
                'pending_changes' => !empty($row['pending_changes']) ? json_decode($row['pending_changes'], true) : null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null
            ];
            
            // Add availability if date and time slot are specified
            if (!empty($date) && !empty($timeSlot)) {
                $availabilitySql = "SELECT is_available as is_available FROM availability 
                                   WHERE technician_id = ? AND date = ? AND time_slot = ?";
                $availStmt = $conn->prepare($availabilitySql);
                $availStmt->bind_param("iss", $technician['id'], $date, $timeSlot);
                $availStmt->execute();
                $availResult = $availStmt->get_result();
                
                if ($availResult->num_rows > 0) {
                    $availRow = $availResult->fetch_assoc();
                    $technician['available'] = (bool)$availRow['is_available'];
                } else {
                    $technician['available'] = true; // Default to available
                }
            }
            
            $technicians[] = $technician;
        }
        
        jsonResponse(['success' => true, 'data' => $technicians]);
        
    } catch (Exception $e) {
        errorResponse('Failed to fetch technicians: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function createTechnician($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validation
    $required = ['name', 'email', 'phone', 'specialties', 'password'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            errorResponse("Field '$field' is required", HTTP_BAD_REQUEST, 'MISSING_FIELD');
        }
    }
    
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        errorResponse('Invalid email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
    }
    
    if (!is_array($input['specialties']) || empty($input['specialties'])) {
        errorResponse('Specialties must be a non-empty array', HTTP_BAD_REQUEST, 'INVALID_SPECIALTIES');
    }
    
    $password = $input['password'];
    if (strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password)) {
        errorResponse('Password must be at least 8 characters and contain uppercase, lowercase, and number', HTTP_BAD_REQUEST, 'WEAK_PASSWORD');
    }
    
    try {
        $conn->begin_transaction();
        
        // Ensure email isn't already used in users or technicians
        $email = strtolower(trim($input['email']));
        $checkUser = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $checkUser->bind_param("s", $email);
        $checkUser->execute();
        if ($checkUser->get_result()->num_rows > 0) {
            $checkUser->close();
            throw new Exception('Email already exists in users');
        }
        $checkUser->close();
        
        $checkTech = $conn->prepare("SELECT id FROM technicians WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $checkTech->bind_param("s", $email);
        $checkTech->execute();
        if ($checkTech->get_result()->num_rows > 0) {
            $checkTech->close();
            throw new Exception('Technician email already exists');
        }
        $checkTech->close();
        
        // Create auth user (role technician)
        $hashedPassword = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
        if (!$hashedPassword) {
            throw new Exception('Password hashing failed');
        }
        
        $userStmt = $conn->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, 'technician', ?)");
        $name = trim($input['name']);
        $phone = trim($input['phone']);
        $userStmt->bind_param("ssss", $name, $email, $hashedPassword, $phone);
        if (!$userStmt->execute()) {
            throw new Exception('Failed to create technician user account');
        }
        $userId = $conn->insert_id;
        $userStmt->close();
        
        // Handle avatar: base64 upload takes priority over URL string
        $avatarUrl = $input['avatar_url'] ?? null;
        if (!empty($input['avatar_base64'])) {
            $savedUrl = saveAvatarBase64($input['avatar_base64'], 0);
            if ($savedUrl !== null) {
                $avatarUrl = $savedUrl;
            }
        }
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;
        $specialtiesJson = json_encode($input['specialties']);

        // Insert technician profile using only columns that exist in the live schema.
        $insertColumns = ['name', 'email', 'phone'];
        $params = [$name, $email, $phone];
        $types = 'sss';

        if (hasTechnicianColumn($conn, 'specialties')) {
            $insertColumns[] = 'specialties';
            $params[] = $specialtiesJson;
            $types .= 's';
        }

        if (hasTechnicianColumn($conn, 'avatar_url')) {
            $insertColumns[] = 'avatar_url';
            $params[] = $avatarUrl;
            $types .= 's';
        }

        if (hasTechnicianColumn($conn, 'is_active')) {
            $insertColumns[] = 'is_active';
            $params[] = $isActive;
            $types .= 'i';
        }

        if (hasTechnicianUserIdColumn($conn)) {
            $insertColumns[] = 'user_id';
            $params[] = $userId;
            $types .= 'i';
        }

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $sql = "INSERT INTO technicians (" . implode(', ', $insertColumns) . ") VALUES (" . $placeholders . ")";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create technician');
        }
        
        $technicianId = $conn->insert_id;
        $stmt->close();
        
        $conn->commit();
        
        jsonResponse([
            'success' => true,
            'data' => [
                'id' => $technicianId,
                'user_id' => $userId,
                'name' => $input['name'],
                'email' => $input['email'],
                'phone' => $input['phone'],
                'specialties' => $input['specialties'],
                'avatar_url' => $avatarUrl ?? null,
                'is_active' => (bool)$isActive
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        errorResponse('Failed to create technician: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function updateTechnician($conn, $isAdmin = false) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['id'])) {
        errorResponse('Technician ID is required', HTTP_BAD_REQUEST, 'MISSING_ID');
    }

    // Self-update check for technicians
    if (!$isAdmin) {
        $userId = $_SESSION['user_id'] ?? 0;
        $ownCheck = $conn->prepare("SELECT id FROM technicians WHERE user_id = ? LIMIT 1");
        $ownCheck->bind_param("i", $userId);
        $ownCheck->execute();
        $ownResult = $ownCheck->get_result();
        if ($ownResult->num_rows === 0) {
            errorResponse('Technician profile not linked to user', HTTP_FORBIDDEN);
        }
        $ownRow = $ownResult->fetch_assoc();
        if ((int)$ownRow['id'] !== (int)$input['id']) {
            errorResponse('You can only update your own profile', HTTP_FORBIDDEN);
        }
    }

    // Validation: email format
    if (!empty($input['email'])) {
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            errorResponse('Invalid email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
        }
    }

    // Validation: phone format (Philippines format: starts with 09 followed by 9 digits, or +63)
    if (!empty($input['phone'])) {
        $phone = preg_replace('/\s+/', '', $input['phone']);
        if (!preg_match('/^(09\d{9}|\+63\d{10})$/', $phone)) {
            errorResponse('Invalid phone format. Use 09XXXXXXXXX (11 digits) or +63XXXXXXXXXX format', HTTP_BAD_REQUEST, 'INVALID_PHONE');
        }
    }

    try {
        // Check if technician exists
        $checkSql = "SELECT id, pending_changes FROM technicians WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $input['id']);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows === 0) {
            errorResponse('Technician not found', HTTP_NOT_FOUND, 'TECHNICIAN_NOT_FOUND');
        }
        $techRow = $checkResult->fetch_assoc();

        // For non-admin technicians: store changes as pending
        if (!$isAdmin) {
            $pendingChanges = [];
            if (!empty($techRow['pending_changes'])) {
                $pendingChanges = json_decode($techRow['pending_changes'], true) ?: [];
            }

            $allowedFields = ['name', 'email', 'phone'];
            $hasChanges = false;
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $input)) {
                    $pendingChanges[$field] = $input[$field];
                    $hasChanges = true;
                }
            }

            if (!$hasChanges) {
                errorResponse('No valid fields to update', HTTP_BAD_REQUEST, 'NO_FIELDS');
            }

            $updateStmt = $conn->prepare("UPDATE technicians SET pending_changes = ? WHERE id = ?");
            $pendingJson = json_encode($pendingChanges);
            $updateStmt->bind_param("si", $pendingJson, $input['id']);

            if (!$updateStmt->execute()) {
                throw new Exception('Failed to store pending changes');
            }

            jsonResponse(['success' => true, 'message' => 'Profile changes submitted for admin approval', 'pending_approval' => true]);
            return;
        }

        // Admin: apply changes directly
        $conn->begin_transaction();

        // Check if admin is approving pending changes
        if (!empty($input['approve_pending'])) {
            $existingPending = json_decode($techRow['pending_changes'] ?? '{}', true) ?: [];
            if (!empty($existingPending)) {
                foreach ($existingPending as $field => $value) {
                    $input[$field] = $value;
                }
            }
            // Clear pending_changes after approval
            $input['pending_changes'] = null;
        }

        // Handle avatar base64 upload for update
        if (!empty($input['avatar_base64'])) {
            $savedUrl = saveAvatarBase64($input['avatar_base64'], (int)$input['id']);
            if ($savedUrl !== null) {
                $input['avatar_url'] = $savedUrl;
            }
        }

        // Build dynamic update query
        $updateFields = [];
        $params = [];
        $types = "";

        $allowedFields = ['name', 'email', 'phone', 'specialties', 'avatar_url', 'is_active', 'pending_changes'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                if (!hasTechnicianColumn($conn, $field)) {
                    continue;
                }
                $updateFields[] = "$field = ?";
                if ($field === 'specialties') {
                    $params[] = json_encode($input[$field]);
                    $types .= 's';
                } elseif ($field === 'pending_changes') {
                    $params[] = $input[$field];
                    $types .= 's';
                } else {
                    $params[] = $input[$field];
                    if ($field === 'is_active') {
                        $types .= 'i';
                        $params[count($params) - 1] = (int)$params[count($params) - 1];
                    } else {
                        $types .= 's';
                    }
                }
            }
        }

        if (empty($updateFields)) {
            errorResponse('No valid fields to update', HTTP_BAD_REQUEST, 'NO_FIELDS');
        }

        $params[] = $input['id'];
        $types .= 'i';

        $sql = "UPDATE technicians SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update technician');
        }
        
        $conn->commit();
        
        jsonResponse(['success' => true, 'message' => 'Technician updated successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        errorResponse('Failed to update technician: ' . $e->getMessage(), HTTP_INTERNAL_SERVER_ERROR);
    }
}

function deleteTechnician($conn) {
    $technicianId = $_GET['id'] ?? '';
    $isPermanent = isset($_GET['permanent']) && $_GET['permanent'] === '1';
    
    if (empty($technicianId) || !is_numeric($technicianId)) {
        errorResponse('Valid technician ID is required', HTTP_BAD_REQUEST, 'INVALID_ID');
    }
    
    try {
        // Check if technician exists and get linked user_id
        $checkSql = "SELECT id, user_id FROM technicians WHERE id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $technicianId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            errorResponse('Technician not found', HTTP_NOT_FOUND, 'TECHNICIAN_NOT_FOUND');
        }
        
        $techRow = $result->fetch_assoc();
        $linkedUserId = $techRow['user_id'] ?? null;
        
        if ($isPermanent) {
            // Hard delete: delete technician first (tickets FK will SET NULL)
            // Then delete linked user account
            $conn->begin_transaction();
            
            // Delete technician record
            $delSql = "DELETE FROM technicians WHERE id = ?";
            $delStmt = $conn->prepare($delSql);
            $delStmt->bind_param("i", $technicianId);
            
            if (!$delStmt->execute()) {
                throw new Exception('Failed to delete technician record');
            }
            
            // Delete linked user account if exists
            if ($linkedUserId) {
                $userDelSql = "DELETE FROM users WHERE id = ? AND role = 'technician'";
                $userDelStmt = $conn->prepare($userDelSql);
                $userDelStmt->bind_param("i", $linkedUserId);
                $userDelStmt->execute(); // Silently ignore if user doesn't exist or isn't technician
            }
            
            $conn->commit();
            jsonResponse(['success' => true, 'message' => 'Technician account deleted permanently']);
        } else {
            // Soft delete (default behavior)
            $sql = hasTechnicianColumn($conn, 'is_active')
                ? "UPDATE technicians SET is_active = 0 WHERE id = ?"
                : "DELETE FROM technicians WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $technicianId);
            
            if (!$stmt->execute()) {
                errorResponse('Failed to delete technician', HTTP_INTERNAL_ERROR);
            }
            
            jsonResponse(['success' => true, 'message' => 'Technician deleted successfully']);
        }
        
    } catch (Exception $e) {
        if ($isPermanent) {
            $conn->rollback();
        }
        errorResponse('Failed to delete technician: ' . $e->getMessage(), HTTP_INTERNAL_ERROR);
    }
}
?>
