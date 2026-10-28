<?php
// views/auth/login.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (isLoggedIn()) {
    // If already logged in, redirect based on role
    if (isCashier()) {
        header('Location: /pos-system/views/dashboard/index.php');
    } elseif (isManager()) {
        header('Location: /pos-system/views/dashboard/index.php'); // Limited dashboard for manager
    } elseif (isAdmin()) {
        header('Location: /pos-system/views/dashboard/index.php'); // Full dashboard for admin
    } else {
        header('Location: /pos-system/views/dashboard/index.php'); // Default
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    try {
        $db = getDB(); // This returns PDO connection
        
        // Query to get user with role - using PDO prepared statement
        $query = "SELECT id, username, password, full_name, role, status 
                  FROM users 
                  WHERE username = :username AND status = 'active'";
        
        $stmt = $db->prepare($query);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            
            // Log the login
            error_log("User logged in: {$username} with role: {$user['role']}");
            
            // Role-based redirect
            if ($user['role'] === 'cashier') {
                // Cashiers go to limited dashboard
                header('Location: /pos-system/views/dashboard/index.php');
            } elseif ($user['role'] === 'manager') {
                // Managers go to limited dashboard
                header('Location: /pos-system/views/dashboard/index.php');
            } elseif ($user['role'] === 'admin') {
                // Admins go to full dashboard
                header('Location: /pos-system/views/dashboard/index.php');
            } else {
                // Default fallback
                header('Location: /pos-system/views/dashboard/index.php');
            }
            exit();
        } else {
            $error = 'Invalid username or password';
            error_log("Failed login attempt for username: {$username}");
        }
    } catch (PDOException $e) {
        $error = 'Database error occurred';
        error_log("Login error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - POS System</title>
    <style>
        body { 
            font-family: 'Segoe UI', Arial, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
        }
        .login-container { 
            background: white; 
            padding: 40px; 
            border-radius: 10px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); 
            width: 400px; 
        }
        h2 { 
            text-align: center; 
            color: #333; 
            margin-bottom: 30px; 
            font-size: 28px;
        }
        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo i {
            font-size: 60px;
            color: #4e73df;
        }
        .form-group { 
            margin-bottom: 20px; 
        }
        label { 
            display: block; 
            margin-bottom: 8px; 
            color: #555; 
            font-weight: 500;
        }
        input { 
            width: 100%; 
            padding: 12px; 
            border: 2px solid #e0e0e0; 
            border-radius: 6px; 
            box-sizing: border-box; 
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #4e73df;
        }
        button { 
            width: 100%; 
            padding: 14px; 
            background: #4e73df; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 16px; 
            font-weight: 600;
            transition: background 0.3s;
        }
        button:hover { 
            background: #2e59d9; 
        }
        .error { 
            color: #e74a3b; 
            text-align: center; 
            margin-bottom: 20px; 
            padding: 10px;
            background: #f8d7da;
            border-radius: 6px;
            font-size: 14px;
        }
        .register-link {
            text-align: center;
            margin: 15px 0;
        }
        .register-link a {
            color: #4e73df;
            text-decoration: none;
            font-size: 14px;
        }
        .register-link a:hover {
            text-decoration: underline;
            color: #2e59d9;
        }
        .info { 
            text-align: center; 
            margin-top: 20px; 
            color: #999; 
            font-size: 13px;
            border-top: 1px solid #e0e0e0;
            padding-top: 20px;
        }
        .demo-credentials {
            background: #f8f9fc;
            padding: 15px;
            border-radius: 6px;
            margin-top: 20px;
        }
        .demo-credentials p {
            margin: 5px 0;
            color: #666;
            font-size: 13px;
        }
        .demo-credentials strong {
            color: #4e73df;
        }
        .role-description {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <i class="fas fa-cash-register"></i>
        </div>
        <h2>POS System</h2>
        
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Username</label>
                <input type="text" name="username" placeholder="Enter username" required>
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> Password</label>
                <input type="password" name="password" placeholder="Enter password" required>
            </div>
            <button type="submit">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>
        
        <!-- Registration link -->
        <div class="register-link">
            <a href="register.php"><i class="fas fa-user-plus"></i> Register New Account (Admin Only)</a>
        </div>
          
        <div class="info">
            <i class="fas fa-shield-alt"></i> Secure Point of Sale System
        </div>  
    </div>
</body>
</html>
