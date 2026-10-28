<?php
// C:\xampp\htdocs\pos-system\views\auth\drugstore_login.php

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is already logged in and system type matches
if (isset($_SESSION['user_id']) && isset($_SESSION['selected_system']) && $_SESSION['selected_system'] === 'drugstore') {
    header('Location: /pos-system/views/dashboard/drugstore_dashboard.php');
    exit();
}

// Check if system type is selected
if (!isset($_SESSION['selected_system']) || $_SESSION['selected_system'] !== 'drugstore') {
    header('Location: /pos-system/index.php');
    exit();
}

// Handle login form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // In a real application, you would validate against database
    // For demo purposes, we'll use sample credentials
    if ($username === 'drugstore_admin' && $password === 'drugstore123') {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = $username;
        $_SESSION['user_type'] = 'drugstore_admin';
        $_SESSION['selected_system'] = 'drugstore';
        header('Location: /pos-system/views/dashboard/drugstore_dashboard.php');
        exit();
    } else {
        $error = 'Invalid username or password';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drugstore System - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        .system-icon {
            text-align: center;
            font-size: 4rem;
            margin-bottom: 20px;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
        }

        .system-name {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: #f5576c;
        }

        .login-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: opacity 0.3s ease;
        }

        .login-btn:hover {
            opacity: 0.9;
        }

        .error {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #f5576c;
            text-decoration: none;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .demo-credentials {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 0.9rem;
        }

        .demo-credentials p {
            margin: 5px 0;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="system-icon">💊</div>
        <h2>Drugstore System</h2>
        <div class="system-name">Pharmacy Management System</div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="login-btn">Login to Drugstore System</button>
        </form>
        
        <div class="back-link">
            <a href="/pos-system/index.php">← Back to System Selection</a>
        </div>
        
        <div class="demo-credentials">
            <strong>Demo Credentials:</strong>
            <p>Username: drugstore_admin</p>
            <p>Password: drugstore123</p>
        </div>
    </div>
</body>
</html>