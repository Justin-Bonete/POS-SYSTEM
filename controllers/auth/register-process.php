<?php
// controllers/auth/register-process.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error'] = "You don't have permission to register users.";
    header("Location: ../../views/auth/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get PDO connection from your database class
    $db = ConfigDatabase::getInstance()->getConnection();
    
    $full_name = $_POST['full_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    $status = $_POST['status'] ?? 'active';
    
    // Basic validation
    if (empty($full_name) || empty($username) || empty($password)) {
        $_SESSION['error'] = "All fields are required!";
        header("Location: ../../views/auth/register.php");
        exit();
    }
    
    // Check if username already exists using PDO
    $check_query = "SELECT id FROM users WHERE username = :username";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([':username' => $username]);
    
    if ($check_stmt->rowCount() > 0) {
        $_SESSION['error'] = "Username already exists!";
        header("Location: ../../views/auth/register.php");
        exit();
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new user using PDO
    $insert_query = "INSERT INTO users (username, password, full_name, role, status, created_at) 
                     VALUES (:username, :password, :full_name, :role, :status, NOW())";
    
    $insert_stmt = $db->prepare($insert_query);
    $result = $insert_stmt->execute([
        ':username' => $username,
        ':password' => $hashed_password,
        ':full_name' => $full_name,
        ':role' => $role,
        ':status' => $status
    ]);
    
    if ($result) {
        $_SESSION['success'] = "User registered successfully!";
        header("Location: ../../views/auth/register.php");
    } else {
        $_SESSION['error'] = "Registration failed: " . implode(", ", $insert_stmt->errorInfo());
        header("Location: ../../views/auth/register.php");
    }
} else {
    header("Location: ../../views/auth/register.php");
    exit();
}
?>