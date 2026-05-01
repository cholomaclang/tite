<?php
/**
 * Standardized API Response Functions
 * Includes security headers and proper HTTP status handling
 */

// Prevent direct access to this file
if (basename($_SERVER['PHP_SELF']) === 'response.php') {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Send JSON response with proper headers
 * @param array $data Response data
 * @param int $status HTTP status code
 */
function jsonResponse($data, $status = 200) {
    // Clear any previous output
    if (ob_get_length()) ob_clean();
    
    // Set security headers
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    
    // Set HTTP status code
    http_response_code($status);
    
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $status HTTP status code (default 400)
 * @param string|null $code Error code for client handling
 */
function errorResponse($message, $status = 400, $code = null) {
    $response = [
        'success' => false,
        'error' => $message
    ];
    
    if ($code !== null) {
        $response['code'] = $code;
    }
    
    // Log errors for monitoring (don't log in production to avoid info leakage)
    if ($status >= 500) {
        error_log("API Error [$status]: $message");
    }
    
    jsonResponse($response, $status);
}

/**
 * Send success response
 * @param mixed $data Response data
 * @param string $message Success message
 */
function successResponse($data = null, $message = 'Success') {
    $response = [
        'success' => true,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    jsonResponse($response, 200);
}

/**
 * Common HTTP status codes reference
 */
const HTTP_OK = 200;
const HTTP_CREATED = 201;
const HTTP_BAD_REQUEST = 400;
const HTTP_UNAUTHORIZED = 401;
const HTTP_FORBIDDEN = 403;
const HTTP_NOT_FOUND = 404;
const HTTP_METHOD_NOT_ALLOWED = 405;
const HTTP_CONFLICT = 409;
const HTTP_UNPROCESSABLE = 422;
const HTTP_INTERNAL_ERROR = 500;
const HTTP_INTERNAL_SERVER_ERROR = 500;
?>