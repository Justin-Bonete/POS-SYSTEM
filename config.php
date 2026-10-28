<?php
// config.php
require_once __DIR__ . '/config/database.php';

class Database {
    public function getConnection() {
        return ConfigDatabase::getInstance()->getConnection();
    }
}
