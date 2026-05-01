<?php
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

// Validate HTTP method
$allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
if (!in_array($method, $allowedMethods, true)) {
    errorResponse('Method not allowed', HTTP_METHOD_NOT_ALLOWED);
}

switch ($method) {
    case 'GET':
        getUser($conn, $userId, $isAdmin);
        break;
    case 'POST':
        if (!$isAdmin) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        createUser($conn);
        break;
    case 'PUT':
        updateUser($conn, $userId, $isAdmin);
        break;
    case 'DELETE':
        if (!$isAdmin) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        deleteUser($conn, $userId);
        break;
}

function getUser($conn, $userId, $isAdmin) {
    try {
        if ($isAdmin && isset($_GET['email'])) {
            // Admin fetching specific user by email
            $email = strtolower(trim($_GET['email']));
            
            if (!validateEmail($email)) {
                errorResponse('Invalid email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
            }
            
            $stmt = $conn->prepare("
                SELECT id, name, email, role, address, phone, created_at 
                FROM users 
                WHERE email = ?
            ");
            $stmt->bind_param("s", $email);
        } elseif ($isAdmin && isset($_GET['list'])) {
            // Admin fetching all users (with pagination)
            $limit = min(intval($_GET['limit'] ?? 20), 100); // Max 100
            $offset = intval($_GET['offset'] ?? 0);
            
            $stmt = $conn->prepare("
                SELECT id, name, email, role, address, phone, created_at 
                FROM users 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param("ii", $limit, $offset);
        } else {
            // Regular user fetching own profile
            $stmt = $conn->prepare("
                SELECT id, name, email, role, address, phone, created_at 
                FROM users 
                WHERE id = ?
            ");
            $stmt->bind_param("i", $userId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            errorResponse('User not found', HTTP_NOT_FOUND);
        }
        
        if ($isAdmin && isset($_GET['list'])) {
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
            successResponse($users);
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        // Remove sensitive data for non-admin viewing other users
        if (!$isAdmin && $user['id'] != $userId) {
            unset($user['email']);
            unset($user['phone']);
            unset($user['address']);
        }

        successResponse($user);
        
    } catch (Exception $e) {
        errorResponse('Failed to fetch user', HTTP_INTERNAL_ERROR);
    }
}

function createUser($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        errorResponse('Invalid request data', HTTP_BAD_REQUEST);
    }

    $name = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    $role = strtolower(trim($data['role'] ?? 'user'));
    $address = trim($data['address'] ?? '');
    $phone = trim($data['phone'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        errorResponse('Name, email, and password are required', HTTP_BAD_REQUEST, 'MISSING_FIELDS');
    }

    if (!validateEmail($email)) {
        errorResponse('Invalid email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
    }

    if (strlen($password) < 8) {
        errorResponse('Password must be at least 8 characters', HTTP_BAD_REQUEST, 'WEAK_PASSWORD');
    }

    $allowedRoles = ['user', 'admin', 'technician', 'manager'];
    if (!in_array($role, $allowedRoles, true)) {
        errorResponse('Invalid role specified', HTTP_BAD_REQUEST, 'INVALID_ROLE');
    }

    $checkStmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        $checkStmt->close();
        errorResponse('Email already exists', HTTP_CONFLICT, 'EMAIL_EXISTS');
    }
    $checkStmt->close();

    $hashedPassword = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ]);
    if (!$hashedPassword) {
        errorResponse('Failed to hash password', HTTP_INTERNAL_ERROR);
    }

    $stmt = $conn->prepare("
        INSERT INTO users (name, email, password, role, address, phone)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssss", $name, $email, $hashedPassword, $role, $address, $phone);

    if (!$stmt->execute()) {
        $stmt->close();
        errorResponse('Failed to create user: ' . $stmt->error, HTTP_INTERNAL_ERROR);
    }

    $newId = $stmt->insert_id;
    $stmt->close();

    successResponse([
        'id' => $newId,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'address' => $address,
        'phone' => $phone
    ], 'User created successfully');
}

function updateUser($conn, $userId, $isAdmin) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($data)) {
        errorResponse('Invalid request data', HTTP_BAD_REQUEST);
    }
    
    // Determine target user
    $targetId = $userId;
    $targetEmail = null;
    
    if ($isAdmin && isset($data['email'])) {
        $targetEmail = strtolower(trim($data['email']));
        
        if (!validateEmail($targetEmail)) {
            errorResponse('Invalid target email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
        }
        
        // Fetch target user ID
        $findStmt = $conn->prepare("SELECT id, role FROM users WHERE email = ?");
        $findStmt->bind_param("s", $targetEmail);
        $findStmt->execute();
        $findResult = $findStmt->get_result();
        
        if ($findResult->num_rows === 0) {
            $findStmt->close();
            errorResponse('Target user not found', HTTP_NOT_FOUND);
        }
        
        $targetUser = $findResult->fetch_assoc();
        $targetId = $targetUser['id'];
        $findStmt->close();
        
        // Prevent admin from modifying other admins unless super admin
        // Add super_admin check if you have that role level
        if ($targetUser['role'] === 'admin' && $targetId !== $userId) {
            errorResponse('Cannot modify other admin accounts', HTTP_FORBIDDEN);
        }
    }
    
    // Prevent self-demotion from admin (lockout prevention)
    if ($targetId === $userId && isset($data['role']) && $data['role'] !== 'admin' && $isAdmin) {
        // Check if this is the last admin
        $adminCheck = $conn->prepare("SELECT COUNT(*) as admin_count FROM users WHERE role = 'admin'");
        $adminCheck->execute();
        $adminCount = $adminCheck->get_result()->fetch_assoc()['admin_count'];
        $adminCheck->close();
        
        if ($adminCount <= 1) {
            errorResponse('Cannot demote the last admin account', HTTP_FORBIDDEN, 'LAST_ADMIN');
        }
    }
    
    // Build update fields with validation
    $allowedFields = [];
    
    // Name validation
    if (isset($data['name'])) {
        $name = trim($data['name']);
        if (strlen($name) < 2 || strlen($name) > 100) {
            errorResponse('Name must be between 2 and 100 characters', HTTP_BAD_REQUEST, 'INVALID_NAME');
        }
        $allowedFields['name'] = ['value' => $name, 'type' => 's'];
    }
    
    // Address validation
    if (isset($data['address'])) {
        $address = trim($data['address']);
        if (strlen($address) > 500) {
            errorResponse('Address too long (max 500 characters)', HTTP_BAD_REQUEST, 'INVALID_ADDRESS');
        }
        $allowedFields['address'] = ['value' => $address, 'type' => 's'];
    }
    
    // Phone validation
    if (isset($data['phone'])) {
        $phone = trim($data['phone']);
        // Basic phone validation - adjust regex for your needs
        if (!preg_match('/^[0-9\s\-\+\(\)]{7,20}$/', $phone)) {
            errorResponse('Invalid phone format', HTTP_BAD_REQUEST, 'INVALID_PHONE');
        }
        $allowedFields['phone'] = ['value' => $phone, 'type' => 's'];
    }
    
    // Password validation and hashing
    if (isset($data['password']) && !empty($data['password'])) {
        $password = $data['password'];
        
        if (strlen($password) < 8) {
            errorResponse('Password must be at least 8 characters', HTTP_BAD_REQUEST, 'WEAK_PASSWORD');
        }
        
        // Check password strength
        if (!preg_match('/[A-Z]/', $password) || 
            !preg_match('/[a-z]/', $password) || 
            !preg_match('/[0-9]/', $password)) {
            errorResponse('Password must contain uppercase, lowercase, and number', HTTP_BAD_REQUEST, 'WEAK_PASSWORD');
        }
        
        $hashedPassword = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
        
        $allowedFields['password'] = ['value' => $hashedPassword, 'type' => 's'];
    }
    
    // Role validation (admin only)
    if ($isAdmin && isset($data['role'])) {
        $allowedRoles = ['user', 'admin', 'technician', 'manager']; // Add your roles
        $role = strtolower(trim($data['role']));
        
        if (!in_array($role, $allowedRoles, true)) {
            errorResponse('Invalid role specified', HTTP_BAD_REQUEST, 'INVALID_ROLE');
        }
        
        $allowedFields['role'] = ['value' => $role, 'type' => 's'];
    }
    
    // Email change validation (admin only, with verification)
    if ($isAdmin && isset($data['new_email']) && $targetId !== $userId) {
        $newEmail = strtolower(trim($data['new_email']));
        
        if (!validateEmail($newEmail)) {
            errorResponse('Invalid new email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
        }
        
        // Check if email already exists
        $emailCheck = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $emailCheck->bind_param("si", $newEmail, $targetId);
        $emailCheck->execute();
        
        if ($emailCheck->get_result()->num_rows > 0) {
            $emailCheck->close();
            errorResponse('Email already in use', HTTP_CONFLICT, 'EMAIL_EXISTS');
        }
        $emailCheck->close();
        
        $allowedFields['email'] = ['value' => $newEmail, 'type' => 's'];
    }
    
    if (empty($allowedFields)) {
        errorResponse('No valid fields to update', HTTP_BAD_REQUEST, 'NO_CHANGES');
    }
    
    // Build and execute query
    $setClauses = [];
    $types = "";
    $values = [];
    
    foreach ($allowedFields as $column => $field) {
        $setClauses[] = "$column = ?";
        $types .= $field['type'];
        $values[] = $field['value'];
    }
    
    $sql = "UPDATE users SET " . implode(", ", $setClauses) . " WHERE id = ?";
    $types .= "i";
    $values[] = $targetId;
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        $stmt->close();
        errorResponse('Update failed: ' . $stmt->error, HTTP_INTERNAL_ERROR);
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    // If user updated their own profile, refresh session data
    if ($targetId === $userId && isset($allowedFields['name'])) {
        $_SESSION['user_name'] = $allowedFields['name']['value'];
    }
    
    successResponse(['affected_rows' => $affectedRows], 'Profile updated successfully');
}

function deleteUser($conn, $adminUserId) {
    $email = strtolower(trim($_GET['email'] ?? ''));
    
    if (empty($email)) {
        errorResponse('Email is required', HTTP_BAD_REQUEST);
    }
    
    if (!validateEmail($email)) {
        errorResponse('Invalid email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
    }
    
    // Prevent self-deletion
    if ($email === $_SESSION['user_email']) {
        errorResponse('Cannot delete your own account', HTTP_FORBIDDEN, 'SELF_DELETE');
    }
    
    // Get target user info before deletion
    $checkStmt = $conn->prepare("SELECT id, role FROM users WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        $checkStmt->close();
        errorResponse('User not found', HTTP_NOT_FOUND);
    }
    
    $targetUser = $result->fetch_assoc();
    $checkStmt->close();
    
    // Prevent deleting other admins
    if ($targetUser['role'] === 'admin') {
        errorResponse('Cannot delete admin accounts', HTTP_FORBIDDEN, 'DELETE_ADMIN');
    }
    
    // Check for existing tickets
    $ticketCheck = $conn->prepare("SELECT COUNT(*) as ticket_count FROM tickets WHERE user_id = ?");
    $ticketCheck->bind_param("i", $targetUser['id']);
    $ticketCheck->execute();
    $ticketCount = $ticketCheck->get_result()->fetch_assoc()['ticket_count'];
    $ticketCheck->close();
    
    if ($ticketCount > 0) {
        // Option 1: Prevent deletion
        // errorResponse('Cannot delete user with existing tickets', HTTP_CONFLICT, 'HAS_TICKETS');
        
        // Option 2: Cascade delete or reassign (implement based on your needs)
        // For now, we'll allow but log it
        error_log("Deleting user $email with $ticketCount tickets");
    }
    
    // Perform deletion
    $stmt = $conn->prepare("DELETE FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    
    if (!$stmt->execute()) {
        $stmt->close();
        errorResponse('Delete failed: ' . $stmt->error, HTTP_INTERNAL_ERROR);
    }
    
    $stmt->close();
    successResponse(null, 'User deleted successfully');
}
?> 