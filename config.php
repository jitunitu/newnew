<?php
// Enable error reporting
ini_set('display_errors', 0); // Changed to 0 to prevent breaking JSON
error_reporting(E_ALL & ~E_NOTICE);

// Database path
$dbPath = __DIR__ . '/database.db';

// Create database if not exists
try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if users table exists
    $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    if (!$check->fetch()) {
        // Create tables
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT UNIQUE,
            phone TEXT UNIQUE,
            password TEXT,
            referral_code TEXT,
            total_earnings REAL DEFAULT 50,
            is_premium INTEGER DEFAULT 0,
            plan_type TEXT DEFAULT 'free',
            multiplier REAL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            task_name TEXT,
            earning_amount REAL,
            is_completed INTEGER,
            completed_at DATETIME
        );
        CREATE TABLE IF NOT EXISTS withdrawals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            amount REAL,
            method TEXT,
            status TEXT,
            created_at DATETIME
        );
        CREATE TABLE IF NOT EXISTS purchases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            plan_type TEXT,
            amount REAL,
            status TEXT,
            created_at DATETIME
        );
        ";
        $pdo->exec($sql);
    }
} catch(PDOException $e) {
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
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
