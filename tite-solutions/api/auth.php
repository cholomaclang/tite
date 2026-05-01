<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, log them instead
ini_set('log_errors', 1);

// Start session and include dependencies
session_start();
require_once '../config/database.php';
require_once '../config/response.php';

// Rate limiting - prevent brute force
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt'] = time();
}

// Reset attempts after 15 minutes
if (isset($_SESSION['last_attempt']) && time() - $_SESSION['last_attempt'] > 900) {
    $_SESSION['login_attempts'] = 0;
}

// Check rate limit (5 attempts per 15 minutes)
if ($_SESSION['login_attempts'] >= 5) {
    errorResponse('Too many login attempts. Please try again in 15 minutes.', HTTP_UNAUTHORIZED, 'RATE_LIMITED');
}

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';

// Validate action
$allowedActions = ['register', 'login', 'logout', 'check'];
if (!in_array($action, $allowedActions, true)) {
    errorResponse('Invalid action', HTTP_BAD_REQUEST);
}

switch ($action) {
    case 'register':
        handleRegister($conn);
        break;
    case 'login':
        handleLogin($conn);
        break;
    case 'logout':
        handleLogout();
        break;
    case 'check':
        checkSession();
        break;
}

function handleRegister($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($data)) {
        errorResponse('Invalid request data', HTTP_BAD_REQUEST);
    }
    
    $name = trim($data['name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    
    // Validation
    if (empty($name) || empty($email) || empty($password)) {
        errorResponse('All fields are required', HTTP_BAD_REQUEST, 'MISSING_FIELDS');
    }
    
    if (strlen($name) < 2 || strlen($name) > 100) {
        errorResponse('Name must be between 2 and 100 characters', HTTP_BAD_REQUEST, 'INVALID_NAME');
    }
    
    if (!validateEmail($email)) {
        errorResponse('Invalid email format', HTTP_BAD_REQUEST, 'INVALID_EMAIL');
    }
    
    if (strlen($password) < 8) {
        errorResponse('Password must be at least 8 characters', HTTP_BAD_REQUEST, 'WEAK_PASSWORD');
    }
    
    // Check if email exists (case-insensitive)
    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        errorResponse('Email already registered', HTTP_CONFLICT, 'EMAIL_EXISTS');
    }
    $stmt->close();
    
    // Hash password with strong algorithm
    $hashedPassword = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ]);
    
    if (!$hashedPassword) {
        errorResponse('Password hashing failed', HTTP_INTERNAL_ERROR);
    }
    
    // Insert user with default role 'user'
    $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')");
    $stmt->bind_param("sss", $name, $email, $hashedPassword);
    
    if (!$stmt->execute()) {
        errorResponse('Registration failed: ' . $conn->error, HTTP_INTERNAL_ERROR);
    }
    
    $userId = $stmt->insert_id;
    $stmt->close();
    
    // Return success but DON'T auto-login (security best practice)
    successResponse(['user_id' => $userId], 'Registration successful. Please login.');
}

function handleLogin($conn) {
    // Increment attempt counter
    $_SESSION['login_attempts']++;
    $_SESSION['last_attempt'] = time();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($data)) {
        errorResponse('Invalid request data', HTTP_BAD_REQUEST);
    }
    
    $email = strtolower(trim($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        errorResponse('Email and password are required', HTTP_BAD_REQUEST, 'MISSING_CREDENTIALS');
    }
    
    // Fetch user with all necessary fields
    $stmt = $conn->prepare("
        SELECT id, name, email, password, role, address, phone, created_at 
        FROM users 
        WHERE LOWER(email) = LOWER(?)
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Use same error message to prevent user enumeration
        errorResponse('Invalid credentials', HTTP_UNAUTHORIZED, 'INVALID_CREDENTIALS');
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        errorResponse('Invalid credentials', HTTP_UNAUTHORIZED, 'INVALID_CREDENTIALS');
    }
    
    // Check if password needs rehash (algorithm upgrade)
    if (password_needs_rehash($user['password'], PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
        if ($newHash) {
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->bind_param("si", $newHash, $user['id']);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
    
    // CRITICAL: Prevent session fixation attack
    session_regenerate_id(true);
    
    // Clear any old session data
    $_SESSION = [];
    
    // Set session data
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Reset login attempts on success
    $_SESSION['login_attempts'] = 0;
    
    // Remove password from response
    unset($user['password']);
    
    // Add admin flag for UI
    $user['isAdmin'] = ($user['role'] === 'admin');
    
    successResponse($user, 'Login successful');
}

function handleLogout() {
    // Clear all session data
    $_SESSION = [];
    
    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }
    
    // Destroy session
    session_destroy();
    
    successResponse(null, 'Logged out successfully');
}

function checkSession() {
    // Validate session integrity
    if (!isset($_SESSION['user_id'])) {
        errorResponse('Not authenticated', HTTP_UNAUTHORIZED, 'NO_SESSION');
    }
    
    // Check session binding to prevent hijacking
    $currentIp = $_SERVER['REMOTE_ADDR'] ?? null;
    $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // IP check (optional - may be too strict for mobile users)
    // if ($_SESSION['ip_address'] !== $currentIp) {
    //     errorResponse('Session invalid', HTTP_UNAUTHORIZED, 'SESSION_HIJACK_DETECTED');
    // }
    
    // User agent check (basic)
    if ($_SESSION['user_agent'] !== $currentAgent) {
        errorResponse('Session invalid', HTTP_UNAUTHORIZED, 'SESSION_INVALID');
    }
    
    // Session timeout (24 hours)
    if (time() - ($_SESSION['login_time'] ?? 0) > 86400) {
        // Clear session directly instead of calling handleLogout to avoid recursion
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
        session_destroy();
        errorResponse('Session expired', HTTP_UNAUTHORIZED, 'SESSION_EXPIRED');
    }
    
    successResponse([
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'],
        'role' => $_SESSION['user_role'],
        'isAdmin' => ($_SESSION['user_role'] === 'admin')
    ]);
}
?>