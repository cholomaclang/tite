<?php
require_once 'database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

function runQuery($conn, $sql) {
    if (!$conn->query($sql)) {
        echo "SQL error: " . $conn->error . "\n";
    }
}

// Create technicians table (linked to auth users)
$techniciansTable = "
CREATE TABLE IF NOT EXISTS technicians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    specialties JSON NOT NULL,
    avatar_url VARCHAR(255) NULL,
    is_active TINYINT(1) DEFAULT 1,
    pending_changes JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_technicians_user_id (user_id),
    CONSTRAINT fk_technicians_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)";

// Create services table
$servicesTable = "
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    base_price DECIMAL(10,2) NOT NULL,
    duration_minutes INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    icon VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

// Create availability table
$availabilityTable = "
CREATE TABLE IF NOT EXISTS availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id INT NOT NULL,
    date DATE NOT NULL,
    time_slot VARCHAR(20) NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE,
    UNIQUE KEY unique_availability (technician_id, date, time_slot)
)";

// Add assigned technician to tickets (simple assignment model)
$alterTicketsAssign = "
ALTER TABLE tickets
    ADD COLUMN assigned_technician_id INT NULL AFTER service_id,
    ADD KEY idx_assigned_technician_id (assigned_technician_id),
    ADD CONSTRAINT fk_tickets_assigned_technician
        FOREIGN KEY (assigned_technician_id) REFERENCES technicians(id) ON DELETE SET NULL
";

// Expand ticket status to include in_progress (ignore error if already updated)
$alterTicketStatusEnum = "
ALTER TABLE tickets
    MODIFY status ENUM('pending','confirmed','in_progress','completed','cancelled') DEFAULT 'pending'
";

// Insert default services
$insertServices = "
INSERT INTO services (name, description, base_price, duration_minutes, category, icon) VALUES
('Computer Repair', 'Hardware troubleshooting, virus removal, and system optimization.', 500.00, 90, 'repair', 'fa-laptop'),
('Network Setup', 'Router installation, WiFi configuration, and network security.', 800.00, 120, 'network', 'fa-wifi'),
('Hardware Upgrade', 'RAM, SSD, GPU upgrades and performance enhancements.', 700.00, 60, 'upgrade', 'fa-microchip'),
('Data Recovery', 'Recover lost files from damaged drives and storage devices.', 1200.00, 150, 'recovery', 'fa-database'),
('Software Installation', 'OS installation, driver updates, and software configuration.', 400.00, 45, 'software', 'fa-download'),
('Maintenance', 'Regular cleaning, thermal paste replacement, and health checks.', 600.00, 60, 'maintenance', 'fa-tools')
ON DUPLICATE KEY UPDATE 
    description = VALUES(description),
    base_price = VALUES(base_price),
    duration_minutes = VALUES(duration_minutes)
";

// Insert default technicians
$insertTechnicians = "
INSERT INTO technicians (name, email, phone, specialties, avatar_url) VALUES
('Cholo Maclang', 'cholo@tite.com', '09123456789', '[\"Computer Repair\", \"Hardware Upgrade\", \"Maintenance\"]', 'pics/cholo.jpg'),
('Justine Sambito', 'justine@tite.com', '09123456790', '[\"Computer Repair\", \"Hardware Upgrade\", \"Data Recovery\"]', 'pics/justine.jpg'),
('Noriel Jermaine Antonio', 'noriel@tite.com', '09123456791', '[\"Network Setup\", \"Maintenance\"]', 'pics/nj.jpg'),
('Gerson Marchan', 'gerson@tite.com', '09123456792', '[\"Network Setup\", \"Software Installation\"]', 'pics/gerson.jpg')
ON DUPLICATE KEY UPDATE 
    phone = VALUES(phone),
    specialties = VALUES(specialties)
";

// Create default availability for next 30 days
$createAvailability = "
INSERT IGNORE INTO availability (technician_id, date, time_slot, is_available)
SELECT 
    t.id,
    DATE(DATE_ADD(CURDATE(), INTERVAL seq.n DAY)) as date,
    ts.time_slot,
    TRUE
FROM technicians t
CROSS JOIN (
    SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION 
    SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION
    SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION
    SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION
    SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24 UNION
    SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
) seq
CROSS JOIN (
    SELECT '09:00 AM' as time_slot UNION
    SELECT '11:00 AM' UNION
    SELECT '02:00 PM' UNION
    SELECT '04:00 PM'
) ts
WHERE DATE(DATE_ADD(CURDATE(), INTERVAL seq.n DAY)) >= CURDATE()
";

// Execute table creation
$tables = [$techniciansTable, $servicesTable, $availabilityTable];
foreach ($tables as $sql) runQuery($conn, $sql);

// Try to add assignment column to existing tickets table (ignore if already added)
runQuery($conn, $alterTicketsAssign);
runQuery($conn, $alterTicketStatusEnum);

// Add pending_changes column to existing technicians table (ignore if already added)
$alterTechniciansPending = "ALTER TABLE technicians ADD COLUMN pending_changes JSON NULL";
runQuery($conn, $alterTechniciansPending);

// Create ticket_messages table for technician-user communication
$ticketMessagesTable = "
CREATE TABLE IF NOT EXISTS ticket_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    sender_id INT NOT NULL,
    sender_type ENUM('user', 'technician') NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_sender (sender_id, sender_type),
    INDEX idx_created_at (created_at)
)";
runQuery($conn, $ticketMessagesTable);

// Try to add foreign key constraint (may fail if tickets table doesn't exist yet)
$alterTicketMessagesFK = "ALTER TABLE ticket_messages ADD CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE";
$conn->query($alterTicketMessagesFK); // Silently ignore error

// Insert default data
$dataInserts = [$insertServices, $insertTechnicians, $createAvailability];
foreach ($dataInserts as $sql) runQuery($conn, $sql);

echo "Database setup completed successfully!\n";
?>
