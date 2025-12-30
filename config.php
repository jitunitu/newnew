<?php
// Enable error reporting
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);

// ==========================================
// DATABASE SETTINGS (Vercel requires MySQL)
// ==========================================

// 1. LOCALHOST (XAMPP) - Leave these empty to use SQLite automatically
// 2. VERCEL (CLOUD) - Fill these details from Aiven/PlanetScale/CleverCloud
$db_host = 'mysql-32537cf1-jkmobileshop-e0c7.h.aivencloud.com'; 
$db_name = 'defaultdb';
$db_user = 'avnadmin';
$db_pass = 'AVNS_KFPO0dwxP1IHJEghIjK';
$db_port = '14831';

try {
    $pdo = null;

    // Detect if running on Localhost (XAMPP)
    $is_localhost = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1' || strpos($_SERVER['HTTP_HOST'], '192.168.') === 0);

    // Use Cloud MySQL ONLY if NOT on localhost AND credentials exist
    if (!$is_localhost && $db_host !== 'your-cloud-db-host.com' && !empty($db_host)) {
        $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass);
    } else {
        // Fallback to SQLite (Local XAMPP)
        $dbPath = __DIR__ . '/database.db';
        $pdo = new PDO("sqlite:" . $dbPath);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Detect Driver to use correct SQL Syntax
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $auto_inc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $text_type = ($driver === 'sqlite') ? 'TEXT' : 'VARCHAR(255)';
    
    // Create Tables (Compatible with both MySQL and SQLite)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id $auto_inc,
        name $text_type,
        email $text_type UNIQUE,
        phone $text_type UNIQUE,
        password $text_type,
        referral_code $text_type,
        total_earnings REAL DEFAULT 50,
        is_premium INTEGER DEFAULT 0,
        plan_type $text_type DEFAULT 'free',
        multiplier REAL DEFAULT 1,
        country $text_type DEFAULT 'IN-INR'
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id $auto_inc,
        user_id INTEGER,
        task_name $text_type,
        earning_amount REAL,
        is_completed INTEGER,
        completed_at DATETIME
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawals (
        id $auto_inc,
        user_id INTEGER,
        amount REAL,
        method $text_type,
        status $text_type,
        created_at DATETIME
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchases (
        id $auto_inc,
        user_id INTEGER,
        plan_type $text_type,
        amount REAL,
        status $text_type,
        created_at DATETIME
    )");

} catch(PDOException $e) {
    ob_clean(); // Clean any previous output
    // Return JSON error for Frontend
    die(json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]));
}

// Helper function to generate referral code
function generateReferralCode($name) {
    return strtoupper(substr($name, 0, 3) . date('Y'));
}

// Helper function to hash password
function hashPassword($password) {
    return md5($password); // या password_hash($password, PASSWORD_BCRYPT);
}

// Helper function to verify password
function verifyPassword($password, $hash) {
    return md5($password) === $hash;
}
?>
