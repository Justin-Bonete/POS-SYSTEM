<?php
// views/settings/index.php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

// Require authentication
requireAuth();

// Get database connection
$pdo = getDB();

// Get current user info
$userId = $_SESSION['user_id'];

// Fetch user data
$stmt = $pdo->prepare("SELECT id, username, full_name, email, role, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Set session last activity if not set
if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

$success = '';
$error = '';

// Handle member removal (admin only)
if (isset($_POST['delete_user']) && isAdmin()) {
    $deleteId = (int)$_POST['delete_user'];

    if ($deleteId === $userId) {
        $error = "You cannot delete your own account while logged in.";
    } else {
        // Check target user and guard against removing last admin
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $stmt->execute([$deleteId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $error = "User not found.";
        } else {
            if ($target['role'] === 'admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $error = "Cannot remove the last admin account.";
                }
            }

            if (!$error) {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$deleteId]);
                $success = "Member removed successfully.";
            }
        }
    }
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);

    if ($full_name === '') {
        $error = "Full name cannot be empty";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?");
        $stmt->execute([$full_name, $userId]);
        $_SESSION['user_name'] = $full_name;
        $success = "Profile updated successfully";
        
        // Refresh user data
        $stmt = $pdo->prepare("SELECT id, username, full_name, email, role, created_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];

    // Verify current password
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!password_verify($current, $userData['password'])) {
        $error = "Current password is incorrect";
    } elseif (strlen($new) < 6) {
        $error = "New password must be at least 6 characters";
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $userId]);
        $success = "Password updated successfully";
    }
}

// Get user role display name
$roleDisplay = ucfirst($user['role'] ?? 'User');

// Load users list for admin table
$allUsers = [];
if (isAdmin()) {
    $stmt = $pdo->query("SELECT id, username, full_name, role, status, created_at FROM users ORDER BY created_at DESC");
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings | POS System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f9f1;
            color: #064e3b;
            line-height: 1.5;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Welcome Header */
        .welcome-header {
            margin-bottom: 30px;
        }
        
        .welcome-header h1 {
            font-size: 32px;
            font-weight: 600;
            color: #064e3b;
            margin-bottom: 8px;
        }
        
        .welcome-header p {
            color: #047857;
            font-size: 15px;
        }
        
        .welcome-header i {
            margin-right: 5px;
        }
        
        .card {
            background: #f0fdf4;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 100, 0, 0.08);
            border: 1px solid #bbf7d0;
        }
        
        .card h2 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 25px;
            color: #065f46;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 2px solid #bbf7d0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            font-weight: 500;
            font-size: 14px;
            color: #047857;
            margin-bottom: 6px;
        }
        
        input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid #bbf7d0;
            background: #dcfce7;
            font-size: 15px;
            transition: all 0.2s;
        }
        
        input:focus {
            outline: none;
            border-color: #22c55e;
            background: #f0fdf4;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
        }
        
        input:disabled {
            background: #e5e7eb;
            border-color: #d1d5db;
            color: #6b7280;
            cursor: not-allowed;
        }
        
        input[type="file"] {
            padding: 12px;
            background: #dcfce7;
            cursor: pointer;
        }
        
        button,
        .btn {
            background: #16a34a;
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        button:hover,
        .btn:hover {
            background: #15803d;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(22, 163, 74, 0.3);
        }
        
        button i {
            font-size: 16px;
        }
        
        .alert-success {
            background: #dcfce7;
            border: 1px solid #22c55e;
            color: #166534;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .alert-error {
            background: #fef2f2;
            border: 1px solid #f87171;
            color: #b91c1c;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 10px;
        }
        
        .info-item {
            background: #dcfce7;
            padding: 15px;
            border-radius: 12px;
        }
        
        .info-item strong {
            display: block;
            font-size: 13px;
            color: #047857;
            margin-bottom: 5px;
        }
        
        .info-item span {
            font-size: 16px;
            font-weight: 600;
            color: #064e3b;
        }
        
        hr {
            border: 1px solid #bbf7d0;
            margin: 40px 0;
        }
        
        .developer-section {
            text-align: center;
            background: #f0fdf4;
            border-radius: 20px;
            padding: 30px;
            border: 1px solid #bbf7d0;
        }
        
        .developer-section h3 {
            color: #065f46;
            margin-bottom: 20px;
            font-size: 20px;
        }
        
        .developer-card {
            background: #dcfce7;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .developer-card h4 {
            color: #065f46;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .developer-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .developer-links a {
            color: #16a34a;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 16px;
            background: white;
            border-radius: 30px;
            transition: all 0.2s;
        }
        
        .developer-links a:hover {
            background: #16a34a;
            color: white;
            transform: translateY(-2px);
        }
        
        .copyright {
            margin-top: 25px;
            font-size: 13px;
            color: #047857;
        }
        
        .btn-secondary {
            background: #6b7280;
            margin-right: 10px;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }

        .btn-danger {
            background: #dc2626;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .admin-user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 14px;
        }

        .admin-user-table th,
        .admin-user-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #d1fae5;
            text-align: left;
        }

        .admin-user-table th {
            background: #ecfdf3;
            color: #065f46;
            font-weight: 700;
        }

        .admin-user-table tr:hover {
            background: #f7fef9;
        }

        .tag {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 12px;
        }

        .tag-role {
            background: #dcfce7;
            color: #065f46;
        }

        .tag-status-active {
            background: #22c55e;
            color: #fff;
        }

        .tag-status-inactive {
            background: #fbbf24;
            color: #92400e;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .developer-links {
                flex-direction: column;
                align-items: center;
            }
            
            .developer-links a {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<div class="container"> 
    <!-- Welcome Header -->
    <div class="welcome-header">
        <h1><i class="fas fa-cog"></i> Settings</h1>  
        <p><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?> | <i class="far fa-clock"></i> <?php echo date('h:i A'); ?></p>
        <div style="margin-top: 12px;">
            <a class="btn btn-secondary" href="/pos-system/views/dashboard/index.php">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
    
    <?php if($success): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    
    <?php if($error): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Account Settings -->
    <div class="card">
        <h2><i class="fas fa-user-circle"></i> Account Settings</h2>
        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Username</label>            
                <input type="text" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled>
            </div>

            <div class="form-group">
                <label><i class="fas fa-id-card"></i> Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
            </div>

            <button type="submit" name="update_profile">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </form>
    </div>

    <!-- Security -->
    <div class="card">
        <h2><i class="fas fa-lock"></i> Security</h2>
        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-key"></i> Current Password</label>
                <input type="password" name="current_password" required>
            </div>

            <div class="form-group">
                <label><i class="fas fa-key"></i> New Password</label>
                <input type="password" name="new_password" required>
            </div>

            <button type="submit" name="change_password">
                <i class="fas fa-edit"></i> Change Password
            </button>
        </form>
    </div>

    <!-- System Info -->
    <div class="card">
        <h2><i class="fas fa-info-circle"></i> System Information</h2>
        <div class="info-grid">
            <div class="info-item">
                <strong><i class="fas fa-building"></i> System Name</strong>
                <span>POS System</span>
            </div>
            <div class="info-item">
                <strong><i class="fas fa-tag"></i> User Role</strong>
                <span><?= htmlspecialchars($roleDisplay) ?></span>
            </div>
            <div class="info-item">
                <strong><i class="fas fa-user"></i> Username</strong>
                <span><?= htmlspecialchars($user['username'] ?? '') ?></span>
            </div>
            <div class="info-item">
                <strong><i class="fas fa-clock"></i> Session Started</strong>
                <span><?= date('F d, Y h:i A', $_SESSION['last_activity']) ?></span>
            </div>
        </div>
    </div>

    <?php if (isAdmin()): ?>
    <!-- User Management (Admin only) -->
    <div class="card">
        <h2><i class="fas fa-users-cog"></i> User Management</h2>
        <p style="color:#555; margin-bottom:12px;">Create, view, and remove members. Removing a member deletes their login account.</p>
        <a class="btn btn-primary" href="/pos-system/views/auth/register.php">
            <i class="fas fa-user-plus"></i> Add / Manage Users
        </a>

        <?php if (!empty($allUsers)): ?>
            <table class="admin-user-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th style="width: 120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allUsers as $u): ?>
                    <tr>
                        <td>#<?= (int)$u['id'] ?></td>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><span class="tag tag-role"><?= ucfirst($u['role']) ?></span></td>
                        <td>
                            <?php if (($u['status'] ?? '') === 'active'): ?>
                                <span class="tag tag-status-active">Active</span>
                            <?php else: ?>
                                <span class="tag tag-status-inactive"><?= ucfirst($u['status'] ?? 'inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                        <td>
                            <?php if ((int)$u['id'] !== (int)$userId): ?>
                            <form method="POST" onsubmit="return confirm('Remove this member? This action cannot be undone.');" style="display:inline;">
                                <input type="hidden" name="delete_user" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding:10px 14px;">
                                    <i class="fas fa-user-times"></i> Remove
                                </button>
                            </form>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-weight:600;">You</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="margin-top:10px; color:#6b7280;">No members found.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Developer Section -->
    <hr>
    <div class="developer-section">
        <h3><i class="fas fa-code"></i> Developed By</h3>
        
        <div class="developer-card">
            <h4>Carl Justin B. Bonete</h4>
            <div class="developer-links">
                <a href="https://www.facebook.com/share/16Ct1tjanw/?mibextid=wwXIfr" target="_blank">
                    <i class="fab fa-facebook"></i> Facebook
                </a>
                <a href="https://github.com/Xstacyy" target="_blank">
                    <i class="fab fa-github"></i> GitHub
                </a>
                <a href="mailto:bonetecarljustin@gmail.com">
                    <i class="fas fa-envelope"></i> Email
                </a>
            </div>
        </div>
        
        <div class="developer-card">
            <h4>Kyle P. Gacayan</h4>
            <div class="developer-links">
                <a href="https://www.facebook.com/share/1DoGMvyM4U/" target="_blank">
                    <i class="fab fa-facebook"></i> Facebook
                </a>
                <a href="https://github.com/kyleGacs" target="_blank">
                    <i class="fab fa-github"></i> GitHub
                </a>
                <a href="mailto:04042002kg@gmail.com">
                    <i class="fas fa-envelope"></i> Email
                </a>
            </div>
        </div>
        
        <div class="copyright">
            <i class="far fa-copyright"></i> <?= date('Y') ?> POS System. All rights reserved.
        </div>
    </div>
</div>

</body>
</html>
