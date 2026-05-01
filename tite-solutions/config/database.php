<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'tite_solutions');

class Database {
    private $connection;
    private static $instance = null;
    
    // Singleton pattern to prevent multiple connections
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($this->connection->connect_error) {
            error_log("Database connection failed: " . $this->connection->connect_error);
            require_once __DIR__ . '/response.php';
            errorResponse('Database connection failed', 500);
        }
        
        $this->connection->set_charset("utf8mb4");
        
        // Enable strict mode for better security
        $this->connection->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function close() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
    
    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Security headers for all API responses
function setSecurityHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // CORS - restrict to your domain in production
    $allowedOrigins = ['http://localhost', 'https://yourdomain.com'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
    
    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// Improved sanitize function - but use prepared statements instead when possible
function sanitize($conn, $input) {
    if (!is_string($input)) {
        return '';
    }
    $input = trim($input);
    $input = $conn->real_escape_string($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    return $input;
}

// Validate email format
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Validate date format (YYYY-MM-DD)
function validateDate($date) {
    if (empty($date)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date && $d >= new DateTime('today');
}

// Validate time format (HH:MM AM/PM or HH:MM)
function validateTime($time) {
    if (empty($time)) return false;
    // Accept both "09:00 AM" and "09:00" formats
    return preg_match('/^(0?[1-9]|1[0-2]):[0-5][0-9](\s?[AP]M)?$/i', $time);
}

// Generate cryptographically secure ticket number
function generateTicketNumber() {
    return 'TKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

// Initialize security headers
setSecurityHeaders();
?>