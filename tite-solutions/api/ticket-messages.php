<?php
session_start();
require_once '../config/database.php';

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// HTTP status codes
const HTTP_OK = 200;
const HTTP_BAD_REQUEST = 400;
const HTTP_UNAUTHORIZED = 401;
const HTTP_FORBIDDEN = 403;
const HTTP_NOT_FOUND = 404;
const HTTP_INTERNAL_ERROR = 500;

function jsonResponse($data) {
    echo json_encode($data);
    exit;
}

function errorResponse($message, $code = HTTP_INTERNAL_ERROR) {
    http_response_code($code);
    jsonResponse(['success' => false, 'error' => $message]);
}

function successResponse($data, $message = '') {
    $response = ['success' => true];
    if ($message) $response['message'] = $message;
    if ($data !== null) $response['data'] = $data;
    jsonResponse($response);
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    errorResponse('Authentication required', HTTP_UNAUTHORIZED);
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'user';
$isAdmin = ($userRole === 'admin');
$isTechnician = ($userRole === 'technician');

$db = Database::getInstance();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getMessages($conn, $userId, $isAdmin, $isTechnician);
        break;
    case 'POST':
        sendMessage($conn, $userId, $isAdmin, $isTechnician);
        break;
    default:
        errorResponse('Method not allowed', 405);
}

function getMessages($conn, $userId, $isAdmin, $isTechnician) {
    $ticketId = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;
    if (!$ticketId) {
        errorResponse('Ticket ID is required', HTTP_BAD_REQUEST);
    }

    try {
        // Verify access to this ticket
        if (!$isAdmin) {
            $checkSql = "SELECT t.user_id, t.assigned_technician_id, tech.user_id as tech_user_id 
                        FROM tickets t 
                        LEFT JOIN technicians tech ON t.assigned_technician_id = tech.id 
                        WHERE t.id = ? LIMIT 1";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("i", $ticketId);
            $checkStmt->execute();
            $ticket = $checkStmt->get_result()->fetch_assoc();
            
            if (!$ticket) {
                errorResponse('Ticket not found', HTTP_NOT_FOUND);
            }
            
            $isOwner = ($ticket['user_id'] == $userId);
            $isAssignedTech = ($isTechnician && $ticket['tech_user_id'] == $userId);
            
            if (!$isOwner && !$isAssignedTech) {
                errorResponse('Access denied to this ticket', HTTP_FORBIDDEN);
            }
        }

        // Get messages with sender info
        $sql = "SELECT tm.*, 
                CASE 
                    WHEN tm.sender_type = 'user' THEN u.name 
                    WHEN tm.sender_type = 'technician' THEN t.name 
                END as sender_name
                FROM ticket_messages tm
                LEFT JOIN users u ON tm.sender_id = u.id AND tm.sender_type = 'user'
                LEFT JOIN technicians t ON tm.sender_id = t.id AND tm.sender_type = 'technician'
                WHERE tm.ticket_id = ?
                ORDER BY tm.created_at ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = [
                'id' => (int)$row['id'],
                'ticket_id' => (int)$row['ticket_id'],
                'sender_id' => (int)$row['sender_id'],
                'sender_type' => $row['sender_type'],
                'sender_name' => $row['sender_name'] ?? 'Unknown',
                'message' => $row['message'],
                'is_read' => (bool)$row['is_read'],
                'created_at' => $row['created_at']
            ];
        }
        
        // Mark messages as read for the current user
        $markReadSql = "";
        if ($isTechnician) {
            $markReadSql = "UPDATE ticket_messages SET is_read = 1 WHERE ticket_id = ? AND sender_type = 'user' AND is_read = 0";
        } else {
            $markReadSql = "UPDATE ticket_messages SET is_read = 1 WHERE ticket_id = ? AND sender_type = 'technician' AND is_read = 0";
        }
        $markStmt = $conn->prepare($markReadSql);
        $markStmt->bind_param("i", $ticketId);
        $markStmt->execute();
        
        successResponse($messages);
        
    } catch (Exception $e) {
        errorResponse('Failed to fetch messages: ' . $e->getMessage());
    }
}

function sendMessage($conn, $userId, $isAdmin, $isTechnician) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $ticketId = isset($data['ticket_id']) ? intval($data['ticket_id']) : 0;
    $message = isset($data['message']) ? trim($data['message']) : '';
    
    if (!$ticketId) {
        errorResponse('Ticket ID is required', HTTP_BAD_REQUEST);
    }
    if (empty($message)) {
        errorResponse('Message is required', HTTP_BAD_REQUEST);
    }
    if (strlen($message) > 2000) {
        errorResponse('Message too long (max 2000 characters)', HTTP_BAD_REQUEST);
    }

    try {
        // Determine sender type and verify access
        $senderType = '';
        $senderId = 0;
        
        if ($isAdmin) {
            // Admin can send as either, default to user
            $senderType = $data['sender_type'] ?? 'user';
            $senderId = $userId;
        } elseif ($isTechnician) {
            // Check if technician is assigned to this ticket
            $checkSql = "SELECT t.id as ticket_id, tech.id as tech_id 
                        FROM tickets t 
                        JOIN technicians tech ON t.assigned_technician_id = tech.id 
                        WHERE t.id = ? AND tech.user_id = ? LIMIT 1";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("ii", $ticketId, $userId);
            $checkStmt->execute();
            $ticket = $checkStmt->get_result()->fetch_assoc();
            
            if (!$ticket) {
                errorResponse('You are not assigned to this ticket', HTTP_FORBIDDEN);
            }
            
            $senderType = 'technician';
            $senderId = $ticket['tech_id'];
        } else {
            // Regular user - check they own the ticket
            $checkSql = "SELECT user_id FROM tickets WHERE id = ? LIMIT 1";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("i", $ticketId);
            $checkStmt->execute();
            $ticket = $checkStmt->get_result()->fetch_assoc();
            
            if (!$ticket || $ticket['user_id'] != $userId) {
                errorResponse('Access denied', HTTP_FORBIDDEN);
            }
            
            $senderType = 'user';
            $senderId = $userId;
        }

        // Insert message
        $insertSql = "INSERT INTO ticket_messages (ticket_id, sender_id, sender_type, message) VALUES (?, ?, ?, ?)";
        $insertStmt = $conn->prepare($insertSql);
        $insertStmt->bind_param("iiss", $ticketId, $senderId, $senderType, $message);
        
        if (!$insertStmt->execute()) {
            throw new Exception('Failed to send message');
        }
        
        $messageId = $conn->insert_id;
        
        // Get sender name for response
        $senderName = '';
        if ($senderType === 'user') {
            $nameStmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
            $nameStmt->bind_param("i", $senderId);
            $nameStmt->execute();
            $senderName = $nameStmt->get_result()->fetch_assoc()['name'] ?? 'User';
        } else {
            $nameStmt = $conn->prepare("SELECT name FROM technicians WHERE id = ?");
            $nameStmt->bind_param("i", $senderId);
            $nameStmt->execute();
            $senderName = $nameStmt->get_result()->fetch_assoc()['name'] ?? 'Technician';
        }
        
        successResponse([
            'id' => $messageId,
            'ticket_id' => $ticketId,
            'sender_id' => $senderId,
            'sender_type' => $senderType,
            'sender_name' => $senderName,
            'message' => $message,
            'is_read' => false,
            'created_at' => date('Y-m-d H:i:s')
        ], 'Message sent successfully');
        
    } catch (Exception $e) {
        errorResponse('Failed to send message: ' . $e->getMessage());
    }
}
