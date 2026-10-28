# POS System - Point of Sale with QR Code Integration

A complete Point of Sale system built with PHP and MySQL, featuring QR code generation for products, inventory management, and sales tracking.

## Features

- ? Product Management (CRUD operations)
- ? Category Management
- ? Point of Sale Interface
- ? QR Code Generation for Products
- ? Barcode/QR Scanner Integration
- ? Sales History and Reporting
- ? Inventory Tracking
- ? Low Stock Alerts
- ? User Authentication
- ? Responsive Dashboard
- ? RESTful API

## Installation with XAMPP

1. Copy this project into `C:\xampp\htdocs\pos-system`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Open `http://localhost/pos-system/install.php` and enter the local MySQL connection details.
4. After installation, open `http://localhost/pos-system/` and sign in with `admin` / `admin123`.

The installer imports the application database and writes `config/.env.php`. It disables itself after installation. Do not manually import `database/schema.sql`; that legacy schema does not match the current application tables.

## Local Network Use

Run Apache and MySQL on one server PC. From other PCs, open `http://SERVER-IP/pos-system/` using the server PC's LAN IP address. All workstations must use the same server URL; do not run separate local databases for shared inventory.

## Tests

Run `composer test` from the project folder. PHPUnit is configured, but the `tests/` directory currently contains no tests.
