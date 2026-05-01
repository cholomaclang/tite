<?php
session_start();
require_once '../config/database.php';
require_once '../config/response.php';

$db = Database::getInstance();
$conn = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Whitelist for allowed tables (CRITICAL: prevents SQL injection)
const ALLOWED_MESSAGE_TYPES = [
    'contact' => 'messages',
    'chat' => 'chat_messages'
];

switch ($method) {
    case 'GET':
        // Admin only
        if (!isset($_SESSION['user_id']) || (isset($_SESSION['user_role']) && $_SESSION['user_role'] !== 'admin')) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        
        // Session validation
        if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            errorResponse('Session invalid', HTTP_UNAUTHORIZED, 'SESSION_INVALID');
        }
        
        getMessages($conn);
        break;
        
    case 'POST':
        createMessage($conn);
        break;
        
    case 'DELETE':
        // Admin only
        if (!isset($_SESSION['user_id']) || (isset($_SESSION['user_role']) && $_SESSION['user_role'] !== 'admin')) {
            errorResponse('Admin access required', HTTP_FORBIDDEN);
        }
        
        // Session validation
        if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            errorResponse('Session invalid', HTTP_UNAUTHORIZED, 'SESSION_INVALID');
        }
        
        deleteMessage($conn);
        break;
        
    default:
        errorResponse('Method not allowed', HTTP_METHOD_NOT_ALLOWED);
}

function getMessages($conn) {
    // STRICT validation of type parameter
    $type = $_GET['type'] ?? 'contact';
    
    if (!array_key_exists($type, ALLOWED_MESSAGE_TYPES)) {
        errorResponse('Invalid message type', HTTP_BAD_REQUEST, 'INVALID_TYPE');
    }
    
    $table = ALLOWED_MESSAGE_TYPES[$type];
    
    try {
        if ($type === 'chat') {
            // Chat messages with user info
            $limit = min(intval($_GET['limit'] ?? 100), 500); // Max 500
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT c.id, c.message as text, c.sender as from, 
                           u.name as user_name, c.created_at as timestamp,
                           c.session_id, c.user_id
                    FROM {$table} c 
                    LEFT JOIN users u ON c.user_id = u.id 
                    ORDER BY c.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $limit, $offset);
        } else {
            // Contact form messages
            $limit = min(intval($_GET['limit'] ?? 50), 200); // Max 200
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT id, name, email, message as text, 
                           is_read, created_at as timestamp
                    FROM {$table} 
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $limit, $offset);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        
        while ($row = $result->fetch_assoc()) {
            $message = [
                'id' => (int)$row['id'],
                'text' => $row['text'],
                'timestamp' => $row['timestamp']
            ];
            
            if ($type === 'chat') {
                $message['from'] = $row['from'];
                $message['user'] = $row['user_name'] ?? 'Guest';
                $message['userId'] = $row['user_id'] ? (int)$row['user_id'] : null;
                $message['sessionId'] = $row['session_id'];
            } else {
                $message['name'] = $row['name'];
                $message['email'] = $row['email'];
                $message['isRead'] = (bool)$row['is_read'];
            }
            
            $messages[] = $message;
        }
        
        $stmt->close();
        successResponse($messages);
        
    } catch (Exception $e) {
        errorResponse('Failed to fetch messages', HTTP_INTERNAL_ERROR);
    }
}

function createMessage($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($data)) {
        errorResponse('Invalid request data', HTTP_BAD_REQUEST);
    }
    
    // Contact form submission (public, no auth required)
    if (isset($data['name'])) {
        $name = trim($data['name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $message = trim($data['message'] ?? '');
        
        // Validation
        $errors = [];
        if (empty($name)) $errors[] = 'Name is required';
        if (strlen($name) > 100) $errors[] = 'Name too long (max 100)';
        
        if (empty($email)) {
            $errors[] = 'Email is required';
        } elseif (!validateEmail($email)) {
            $errors[] = 'Invalid email format';
        }
        
        if (empty($message)) {
            $errors[] = 'Message is required';
        } elseif (strlen($message) > 5000) {
            $errors[] = 'Message too long (max 5000 characters)';
        }
        
        if (!empty($errors)) {
            errorResponse('Validation failed: ' . implode(', ', $errors), HTTP_UNPROCESSABLE, 'VALIDATION_ERROR');
        }
        
        // Rate limiting for contact form (by IP)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateKey = 'contact_' . md5($ip);
        
        if (!isset($_SESSION[$rateKey])) {
            $_SESSION[$rateKey] = ['count' => 0, 'time' => time()];
        }
        
        // Max 5 messages per hour from same IP
        if ($_SESSION[$rateKey]['count'] >= 5 && (time() - $_SESSION[$rateKey]['time']) < 3600) {
            errorResponse('Too many messages. Please try again later.', HTTP_TOO_MANY_REQUESTS, 'RATE_LIMITED');
        }
        
        $_SESSION[$rateKey]['count']++;
        
        // Insert message
        $stmt = $conn->prepare("
            INSERT INTO messages (name, email, message, is_read) 
            VALUES (?, ?, ?, 0)
        ");
        $stmt->bind_param("sss", $name, $email, $message);
        
        if (!$stmt->execute()) {
            $stmt->close();
            errorResponse('Failed to send message', HTTP_INTERNAL_ERROR);
        }
        
        $stmt->close();
        
        // Optional: Send notification email to admin here
        
        successResponse(null, 'Message sent successfully');
        
    } 
    // Chat message (requires session)
    else {
        // Validate session for chat
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $userId = $_SESSION['user_id'] ?? null;
        $message = trim($data['message'] ?? '');
        $sender = $data['sender'] ?? 'user';
        
        // Validate sender
        $allowedSenders = ['user', 'bot', 'staff'];
        if (!in_array($sender, $allowedSenders, true)) {
            $sender = 'user'; // Default to user if invalid
        }
        
        if (empty($message)) {
            errorResponse('Message cannot be empty', HTTP_BAD_REQUEST);
        }
        
        if (strlen($message) > 2000) {
            errorResponse('Message too long (max 2000 characters)', HTTP_BAD_REQUEST);
        }
        
        $sessionId = session_id();
        
        // Verify user exists if userId provided
        if ($userId !== null) {
            $userCheck = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $userCheck->bind_param("i", $userId);
            $userCheck->execute();
            if ($userCheck->get_result()->num_rows === 0) {
                $userCheck->close();
                $userId = null; // Reset to guest if invalid
            } else {
                $userCheck->close();
            }
        }
        
        $stmt = $conn->prepare("
            INSERT INTO chat_messages (user_id, message, sender, session_id) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $userId, $message, $sender, $sessionId);
        
        if (!$stmt->execute()) {
            $stmt->close();
            errorResponse('Failed to save message', HTTP_INTERNAL_ERROR);
        }
        
        $insertId = $stmt->insert_id;
        $stmt->close();
        
        successResponse(['id' => $insertId], 'Message saved');
    }
}

function deleteMessage($conn) {
    // STRICT validation of type parameter (CRITICAL FIX)
    $type = $_GET['type'] ?? 'contact';
    
    if (!array_key_exists($type, ALLOWED_MESSAGE_TYPES)) {
        errorResponse('Invalid message type', HTTP_BAD_REQUEST, 'INVALID_TYPE');
    }
    
    $table = ALLOWED_MESSAGE_TYPES[$type];
    
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    
    if (!$id || $id <= 0) {
        errorResponse('Valid message ID is required', HTTP_BAD_REQUEST, 'INVALID_ID');
    }
    
    // Verify message exists
    $checkStmt = $conn->prepare("SELECT id FROM {$table} WHERE id = ?");
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows === 0) {
        $checkStmt->close();
        errorResponse('Message not found', HTTP_NOT_FOUND);
    }
    $checkStmt->close();
    
    // Perform deletion
    $stmt = $conn->prepare("DELETE FROM {$table} WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if (!$stmt->execute()) {
        $stmt->close();
        errorResponse('Delete failed: ' . $stmt->error, HTTP_INTERNAL_ERROR);
    }
    
    $stmt->close();
    successResponse(null, 'Message deleted successfully');
}
?>