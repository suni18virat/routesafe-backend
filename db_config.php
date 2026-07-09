<?php
// Prevent direct access to db_config.php to protect credentials if accessed via browser directly
if (basename($_SERVER['SCRIPT_FILENAME']) === 'db_config.php') {
    header("Content-Type: application/json");
    exit(json_encode(["error" => "Direct access not permitted"]));
}

// Detect environment
$isLocal = false;
if (php_sapi_name() === 'cli') {
    $isLocal = true;
} else {
    $hostName = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $serverAddr = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
    if (
        strpos($hostName, 'localhost') !== false || 
        strpos($hostName, '127.0.0.1') !== false || 
        strpos($hostName, '192.168.') !== false ||
        $serverAddr === '127.0.0.1' || 
        $serverAddr === '::1'
    ) {
        $isLocal = true;
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$con = null;

if ($isLocal) {
    // Local configuration (e.g. XAMPP)
    $db_host = '127.0.0.1';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'roadcare_db';
    $db_port = 3307; // Default local port in user's environment
    
    $con = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);
    
    // Fallback to default port 3306 if 3307 fails
    if (!$con) {
        $db_port = 3306;
        $con = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);
    }
} else {
    // ==========================================
    // CLEVER CLOUD DATABASE CONFIGURATION
    // ==========================================
    $db_host = 'bzvrotxo7tt4htiiqyj8-mysql.services.clever-cloud.com';
    $db_user = 'uubpzisqyra5om4j';
    $db_pass = '4lfqFcwEaExSMLbb337T';
    $db_name = 'bzvrotxo7tt4htiiqyj8';
    $db_port = 3306;
    
    $con = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);
}

// If connection failed, return a clean JSON error explaining the problem
if (!$con) {
    header("Content-Type: application/json");
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed. Please check backend db configuration.",
        "debug" => [
            "environment" => $isLocal ? "local" : "production",
            "host" => $db_host,
            "port" => $db_port,
            "user" => $db_user,
            "database" => $db_name,
            "error" => mysqli_connect_error(),
            "error_code" => mysqli_connect_errno()
        ]
    ]);
    exit();
}

// ==========================================
// AUTO-INSTALLATION SCHEMA SETUP
// Automatically builds all tables on the database if they are not already present.
// ==========================================
$tables = [
    "admin" => "CREATE TABLE IF NOT EXISTS admin (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "user" => "CREATE TABLE IF NOT EXISTS user (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        mobile VARCHAR(20) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "team" => "CREATE TABLE IF NOT EXISTS team (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        mobile VARCHAR(20) NOT NULL,
        email VARCHAR(255) NOT NULL,
        username VARCHAR(255) NULL UNIQUE,
        address VARCHAR(255) NULL,
        password VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "complaint" => "CREATE TABLE IF NOT EXISTS complaint (
        cid INT AUTO_INCREMENT PRIMARY KEY,
        description TEXT NULL,
        uid VARCHAR(255) NULL,
        latitude VARCHAR(50) NULL,
        longitude VARCHAR(50) NULL,
        datetime VARCHAR(100) NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        image VARCHAR(255) NULL,
        completedimage VARCHAR(255) NULL,
        teamid VARCHAR(50) NULL,
        remarks TEXT NULL,
        admin_remarks TEXT NULL,
        completed_latitude VARCHAR(50) NULL,
        completed_longitude VARCHAR(50) NULL,
        completeddatetime VARCHAR(100) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "support_message" => "CREATE TABLE IF NOT EXISTS support_message (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_mobile VARCHAR(20) NOT NULL,
        message TEXT NULL,
        media_url VARCHAR(255) NULL,
        reply TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

foreach ($tables as $tblName => $sql) {
    if (!mysqli_query($con, $sql)) {
        error_log("Failed to create table $tblName: " . mysqli_error($con));
    }
}

// Ensure default admin user is present
$adminCheckQuery = "SELECT id FROM admin WHERE username = 'admin' LIMIT 1";
$adminCheckResult = mysqli_query($con, $adminCheckQuery);
if ($adminCheckResult && mysqli_num_rows($adminCheckResult) === 0) {
    $insertAdminQuery = "INSERT INTO admin (username, password) VALUES ('admin', 'admin')";
    mysqli_query($con, $insertAdminQuery);
}
