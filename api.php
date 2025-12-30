<?php
ob_start(); // Start output buffering to prevent unwanted output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Disable HTML errors
ini_set('display_errors', 0);

session_start();

try {
    if (!file_exists('config.php')) {
        throw new Exception("config.php file not found");
    }
    include 'config.php';
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database Connection Error: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ==================== REGISTRATION ====================
if ($action === 'register') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';
    $country = $_POST['country'] ?? 'IN-INR';
    
    // Validation
    if (!$name || !$email || !$phone || !$password) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'All fields required']);
        exit;
    }
    
    if (strlen($password) < 6) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Password must be 6+ characters']);
        exit;
    }
    
    // Check if email or phone already exists
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
    $stmtCheck->execute([$email, $phone]);
    if ($stmtCheck->fetch()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Email or Phone already registered']);
        exit;
    }
    
    try {
        $referralCode = generateReferralCode($name);
        $hashedPassword = hashPassword($password);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, referral_code, total_earnings, country) 
                                  VALUES (?, ?, ?, ?, ?, 50, ?)");
            $stmt->execute([$name, $email, $phone, $hashedPassword, $referralCode, $country]);
        } catch (PDOException $e) {
            // Auto-fix: Add missing 'country' column if it doesn't exist
            if (stripos($e->getMessage(), 'no column named country') !== false || stripos($e->getMessage(), 'Unknown column') !== false) {
                $pdo->exec("ALTER TABLE users ADD COLUMN country VARCHAR(20) DEFAULT 'IN-INR'");
                $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, referral_code, total_earnings, country) 
                                      VALUES (?, ?, ?, ?, ?, 50, ?)");
                $stmt->execute([$name, $email, $phone, $hashedPassword, $referralCode, $country]);
            } else {
                throw $e;
            }
        }
        
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Registration successful!']);
    } catch(Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ==================== LOGIN ====================
else if ($action === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && verifyPassword($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        ob_clean();
        echo json_encode(['success' => true, 'user_id' => $user['id']]);
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
}

// ==================== GET USER DATA ====================
else if ($action === 'get_user') {
    $userId = $_SESSION['user_id'] ?? $_GET['user_id'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Get tasks count
        $stmtTasks = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE user_id = ? AND is_completed = 1");
        $stmtTasks->execute([$userId]);
        $tasksCount = $stmtTasks->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Get withdrawals
        $stmtWithdraw = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmtWithdraw->execute([$userId]);
        $withdrawals = $stmtWithdraw->fetchAll(PDO::FETCH_ASSOC);
        
        $user['tasksCompleted'] = $tasksCount;
        $user['withdrawals'] = $withdrawals;
        
        ob_clean();
        echo json_encode($user);
    } else {
        ob_clean();
        echo json_encode(['error' => 'User not found']);
    }
}

// ==================== COMPLETE TASK ====================
else if ($action === 'complete_task') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? '';
    $taskName = $data['task_name'] ?? '';
    $amount = $data['amount'] ?? 0;
    
    // Check if already completed today
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND task_name = ? AND DATE(completed_at) = ?");
    $stmt->execute([$userId, $taskName, $today]);
    
    if ($stmt->fetch()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Already completed today']);
        exit;
    }
    
    // Get user multiplier
    $stmtUser = $pdo->prepare("SELECT multiplier FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    $multiplier = $user['multiplier'] ?? 1;
    $finalAmount = $amount * $multiplier;
    
    // Add task
    $stmtTask = $pdo->prepare("INSERT INTO tasks (user_id, task_name, earning_amount, is_completed, completed_at) 
                               VALUES (?, ?, ?, 1, ?)");
    $stmtTask->execute([$userId, $taskName, $finalAmount, date('Y-m-d H:i:s')]);
    
    // Update earnings
    $stmtUpdate = $pdo->prepare("UPDATE users SET total_earnings = total_earnings + ? WHERE id = ?");
    $stmtUpdate->execute([$finalAmount, $userId]);
    
    ob_clean();
    echo json_encode(['success' => true, 'earnings' => $finalAmount]);
}

// ==================== BUY PREMIUM ====================
else if ($action === 'buy_premium') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? '';
    $amount = 1000;
    
    try {
        // Check if already premium
        $stmt = $pdo->prepare("SELECT is_premium, total_earnings FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user['is_premium']) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Already premium']);
            exit;
        }

        if ($user['total_earnings'] < $amount) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Insufficient balance (Need 1000)']);
            exit;
        }
        
        // Update user to premium
        $stmtUpdate = $pdo->prepare("UPDATE users SET is_premium = 1, plan_type = 'premium', multiplier = 2, total_earnings = total_earnings - ? WHERE id = ?");
        $stmtUpdate->execute([$amount, $userId]);
        
        // Record purchase
        $stmtPurchase = $pdo->prepare("INSERT INTO purchases (user_id, plan_type, amount, status, created_at) VALUES (?, ?, ?, 'completed', ?)");
        $stmtPurchase->execute([$userId, 'premium', $amount, date('Y-m-d H:i:s')]);
        
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Premium activated!']);
    } catch(Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ==================== UPDATE PROFILE ====================
else if ($action === 'update_profile') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? '';
    $name = $data['name'] ?? '';
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    
    if (!$userId || !$email || !$name) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Name and Email are required']);
        exit;
    }

    // Check if email is taken by another user
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmtCheck->execute([$email, $userId]);
    if ($stmtCheck->fetch()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Email already in use']);
        exit;
    }

    try {
        $sql = "UPDATE users SET name = ?, email = ? WHERE id = ?";
        $params = [$name, $email, $userId];

        if (!empty($password)) {
            if (strlen($password) < 6) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Password must be 6+ characters']);
                exit;
            }
            $sql = "UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?";
            $params = [$name, $email, hashPassword($password), $userId];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Update failed']);
    }
}

// ==================== REQUEST WITHDRAWAL ====================
else if ($action === 'request_withdrawal') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? '';
    $amount = $data['amount'] ?? 0;
    $method = $data['method'] ?? '';
    $upiId = $data['upi_id'] ?? '';

    if ($upiId) {
        $method = "UPI: " . $upiId;
    }
    
    // Check balance
    $stmt = $pdo->prepare("SELECT total_earnings FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['total_earnings'] < $amount || $amount < 500) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Minimum withdrawal: 500']);
        exit;
    }
    
    // Create withdrawal request
    $stmtWithdraw = $pdo->prepare("INSERT INTO withdrawals (user_id, amount, method, status, created_at) VALUES (?, ?, ?, 'pending', ?)");
    $stmtWithdraw->execute([$userId, $amount, $method, date('Y-m-d H:i:s')]);
    
    // Deduct from balance
    $stmtUpdate = $pdo->prepare("UPDATE users SET total_earnings = total_earnings - ? WHERE id = ?");
    $stmtUpdate->execute([$amount, $userId]);
    
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Withdrawal request submitted']);
}

// ==================== GET TRANSACTIONS ====================
else if ($action === 'get_transactions') {
    $userId = $_GET['user_id'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ob_clean();
    echo json_encode(['success' => true, 'data' => $withdrawals]);
}

// ==================== GET EARNINGS HISTORY ====================
else if ($action === 'get_earnings') {
    $userId = $_GET['user_id'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? ORDER BY completed_at DESC");
    $stmt->execute([$userId]);
    $earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ob_clean();
    echo json_encode(['success' => true, 'data' => $earnings]);
}

// ==================== ADMIN: GET USERS ====================
else if ($action === 'admin_get_users') {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY id DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ob_clean();
    echo json_encode($users);
}

// ==================== ADMIN: GET SINGLE USER ====================
else if ($action === 'admin_get_user') {
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    ob_clean();
    echo json_encode($user);
}

// ==================== ADMIN: UPDATE USER ====================
else if ($action === 'admin_update_user') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = $data['id'];
    $name = $data['name'];
    $email = $data['email'];
    $phone = $data['phone'];
    $earnings = $data['total_earnings'];
    $isPremium = $data['is_premium'];
    $password = $data['password'];

    $sql = "UPDATE users SET name=?, email=?, phone=?, total_earnings=?, is_premium=? WHERE id=?";
    $params = [$name, $email, $phone, $earnings, $isPremium, $id];

    if (!empty($password)) {
        $sql = "UPDATE users SET name=?, email=?, phone=?, total_earnings=?, is_premium=?, password=? WHERE id=?";
        $params = [$name, $email, $phone, $earnings, $isPremium, hashPassword($password), $id];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    ob_clean();
    echo json_encode(['success' => true]);
}

// ==================== ADMIN: GET WITHDRAWALS ====================
else if ($action === 'admin_get_withdrawals') {
    $stmt = $pdo->query("SELECT w.id, w.amount, w.method, w.status, u.name, u.email FROM withdrawals w JOIN users u ON w.user_id = u.id ORDER BY w.created_at DESC");
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ob_clean();
    echo json_encode($withdrawals);
}

// ==================== ADMIN: UPDATE WITHDRAWAL ====================
else if ($action === 'admin_update_withdrawal') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'];
    $status = $data['status'];
    
    $stmt = $pdo->prepare("UPDATE withdrawals SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    
    ob_clean();
    echo json_encode(['success' => true]);
}

// ==================== ADMIN: DELETE USER ====================
else if ($action === 'admin_delete_user') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'];
    
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    
    ob_clean();
    echo json_encode(['success' => true]);
}

// ==================== LOGOUT ====================
else if ($action === 'logout') {
    session_destroy();
    ob_clean();
    echo json_encode(['success' => true]);
}

else {
    ob_clean();
    echo json_encode(['error' => 'Invalid action']);
}
?>
