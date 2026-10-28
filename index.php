<?php
// C:\xampp\htdocs\pos-system\index.php

if (!file_exists(__DIR__ . '/config/.env.php')) {
    header('Location: /pos-system/install.php');
    exit();
}

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function resetSessionForSelection(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function prepareFreshSystemSession(): void {
    $_SESSION = [];

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);
}

// Check if system type is selected
if (isset($_GET['system'])) {
    $system = $_GET['system'];

    // Choosing a system should start with a clean session without deleting
    // the session cookie needed by the next page.
    prepareFreshSystemSession();

    // Store selected system type in session
    $_SESSION['selected_system'] = $system;

    // Redirect to appropriate login page based on system type
    switch ($system) {
        case 'drugstore':
            header('Location: /pos-system/views/auth/drugstore_login.php');
            break;
        case 'school_supply':
            header('Location: /pos-system/views/school/home.php');
            break;
        case 'pos_system':
            header('Location: /pos-system/views/auth/login.php');
            break;
        default:
            // Invalid system type, redirect back to index
            header('Location: /pos-system/index.php');
            break;
    }
    exit();
}

// Visiting index.php directly acts as logout and returns to system selection.
if (!empty($_SESSION)) {
    resetSessionForSelection();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System - Select System Type</title>
    <style>
        /* Your existing CSS styles remain the same */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            width: 100%;
        }

        h1 {
            text-align: center;
            color: white;
            margin-bottom: 50px;
            font-size: 2.5rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .system-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            padding: 20px;
        }

        .system-card {
            background: white;
            border-radius: 15px;
            padding: 40px 30px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .system-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .system-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        .system-card h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.8rem;
        }

        .system-card p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .system-button {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            transition: opacity 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }

        .system-button:hover {
            opacity: 0.9;
        }

        /* Card specific colors */
        .card-drugstore .system-button {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .card-school .system-button {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .card-pos .system-button {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 1.8rem;
                margin-bottom: 30px;
            }
            
            .system-grid {
                gap: 20px;
            }
            
            .system-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome to POS System</h1>
        <h1 style="font-size: 1.2rem; margin-top: -30px; margin-bottom: 30px;">Please select your system type</h1>
        
        <div class="system-grid">
            <!-- Drugstore System -->
            <div class="system-card card-drugstore" onclick="selectSystem('drugstore')">
                <div class="system-icon">💊</div>
                <h2>Drugstore System</h2>
                <p>Pharmacy management system for medicines, prescriptions, and health products inventory.</p>
                <button class="system-button">Login to Drugstore</button>
            </div>

            <!-- School Supply System -->
            <div class="system-card card-school" onclick="selectSystem('school_supply')">
                <div class="system-icon">📚</div>
                <h2>School Supply System</h2>
                <p>School supplies management system for books, stationery, and educational materials.</p>
                <button class="system-button">Login to School Supply</button>
            </div>

            <!-- POS System -->
            <div class="system-card card-pos" onclick="selectSystem('pos_system')">
                <div class="system-icon">🛒</div>
                <h2>MotorParts</h2>
                <p>MotorParts management system for automotive components and accessories.</p>
                <button class="system-button">Login to MotorParts</button>
            </div>
        </div>
    </div>

    <script>
        function selectSystem(systemType) {
            // Add loading effect
            const buttons = document.querySelectorAll('.system-button');
            buttons.forEach(btn => {
                btn.textContent = 'Loading...';
                btn.disabled = true;
            });
            
            // Redirect with system parameter
            window.location.href = '?system=' + systemType;
        }
    </script>
</body>
</html>
