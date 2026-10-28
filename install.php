<?php
session_start();

$configFile = __DIR__ . '/config/.env.php';
if (is_file($configFile)) {
    http_response_code(404);
    exit('The installer is disabled because this application is already configured.');
}

$installError = '';
$installSuccess = '';
$showDbForm = true;

function writeEnvConfig(array $config): void {
    $file = __DIR__ . '/config/.env.php';
    $content = "<?php\nreturn [\n";
    foreach ($config as $key => $value) {
        $value = str_replace("\\", "\\\\", $value);
        $value = str_replace("'", "\\'", $value);
        $content .= "    '{$key}' => '{$value}',\n";
    }
    $content .= "];\n";

    if (!is_dir(__DIR__ . '/config')) {
        mkdir(__DIR__ . '/config', 0777, true);
    }

    file_put_contents($file, $content);
}

function ensureDefaultUser(PDO $db): void {
    $check = $db->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
    $check->execute([':username' => 'admin']);

    if ($check->rowCount() === 0) {
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username, password, email, full_name, role, status, created_at) VALUES (:username, :password, :email, :full_name, :role, :status, NOW())")
            ->execute([
                ':username' => 'admin',
                ':password' => $password,
                ':email' => 'admin@example.com',
                ':full_name' => 'Administrator',
                ':role' => 'admin',
                ':status' => 'active'
            ]);
    }
}

function importSqlFile(PDO $db, string $filePath): void {
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Could not read SQL schema file.');
    }

    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = trim($sql);

    if ($sql === '') {
        return;
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }

        $db->exec($statement . ';');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? 'pos_system');
    $username = trim($_POST['username'] ?? 'root');
    $password = $_POST['password'] ?? '';

    if ($host === '' || $dbName === '' || $username === '') {
        $installError = 'Host, database name, and username are required.';
    } else {
        try {
            $pdo = new PDO(
                'mysql:host=' . $host . ';charset=utf8mb4',
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );

            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . str_replace('`', '``', $dbName) . '`');

            $config = [
                'host' => $host,
                'db_name' => $dbName,
                'username' => $username,
                'password' => $password,
            ];

            $db = new PDO(
                'mysql:host=' . $host . ';dbname=' . $dbName . ';charset=utf8mb4',
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );

            importSqlFile($db, __DIR__ . '/pos_system.sql');
            ensureDefaultUser($db);
            writeEnvConfig($config);

            $installSuccess = 'Installation complete. Your POS system is ready. Default login: admin / admin123';
            $showDbForm = false;
        } catch (Throwable $e) {
            $installError = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>POS System Installer</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #1f2937, #111827);
            color: #111827;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            width: 100%;
            max-width: 620px;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.25);
            padding: 32px;
        }
        h1 {
            margin-top: 0;
            color: #111827;
        }
        .alert {
            border-radius: 10px;
            padding: 12px 14px;
            margin: 0 0 16px;
            font-size: 14px;
        }
        .error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }
        .success {
            background: #dcfce7;
            border: 1px solid #22c55e;
            color: #166534;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
        }
        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 15px;
        }
        button {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
        }
        .note {
            margin-top: 18px;
            font-size: 13px;
            color: #4b5563;
            line-height: 1.5;
        }
        .full {
            grid-column: 1 / -1;
        }
        @media (max-width: 640px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>POS System Installer</h1>

        <?php if ($installError): ?>
            <div class="alert error"><?php echo htmlspecialchars($installError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($installSuccess): ?>
            <div class="alert success"><?php echo htmlspecialchars($installSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
            <p class="note">You can now open the app at <strong>/pos-system</strong>. Your database config was saved in <strong>config/.env.php</strong>.</p>
            <p><a href="/pos-system/index.php">Go to login page</a></p>
        <?php endif; ?>

        <?php if ($showDbForm): ?>
            <form method="POST">
                <div class="grid">
                    <div class="full">
                        <label for="host">Database Host</label>
                        <input id="host" name="host" value="localhost" required />
                    </div>

                    <div>
                        <label for="db_name">Database Name</label>
                        <input id="db_name" name="db_name" value="pos_system" required />
                    </div>

                    <div>
                        <label for="username">MySQL Username</label>
                        <input id="username" name="username" value="root" required />
                    </div>

                    <div class="full">
                        <label for="password">MySQL Password</label>
                        <input id="password" name="password" type="password" value="" placeholder="Leave blank for default XAMPP root user" />
                    </div>
                </div>

                <button type="submit">Install System</button>
            </form>

            <div class="note">
                This installer will create the database if it does not exist, import the database schema, and save the configuration file securely in the app folder.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
