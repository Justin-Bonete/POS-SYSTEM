<?php
// config/database.php
$posDbConfig = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'db_name' => getenv('DB_NAME') ?: 'pos_system',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: ''
];

$configFile = __DIR__ . '/.env.php';
if (file_exists($configFile)) {
    $loadedConfig = require $configFile;
    if (is_array($loadedConfig)) {
        $posDbConfig = array_merge($posDbConfig, $loadedConfig);
    }
}

$GLOBALS['POS_DB_CONFIG'] = $posDbConfig;

class ConfigDatabase {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    private static $instance = null;

    private function __construct() {
        $config = $GLOBALS['POS_DB_CONFIG'] ?? [];
        $this->host = $config['host'] ?? 'localhost';
        $this->db_name = $config['db_name'] ?? 'pos_system';
        $this->username = $config['username'] ?? 'root';
        $this->password = $config['password'] ?? '';
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new ConfigDatabase();
        }
        return self::$instance;
    }

    public function getConnection() {
        if ($this->conn instanceof PDO) {
            return $this->conn;
        }

        try {
            $this->conn = new PDO(
                'mysql:host=' . $this->host . ';dbname=' . $this->db_name . ';charset=utf8mb4',
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'
                ]
            );
        } catch (PDOException $e) {
            throw $e;
        }

        return $this->conn;
    }
}

function getDB() {
    $database = ConfigDatabase::getInstance();
    return $database->getConnection();
}
