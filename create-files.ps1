# Go to your POS system directory
cd C:\xampp\htdocs\pos-system

Write-Host "Creating POS System files..." -ForegroundColor Yellow

# Create .htaccess with content
$htaccessContent = @"
# .htaccess for POS System
Options -Indexes
Options +FollowSymLinks

# Enable Rewrite Engine
RewriteEngine On

# Security Headers
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "SAMEORIGIN"
Header set X-XSS-Protection "1; mode=block"

# Block access to sensitive directories
RewriteRule ^(config|classes|includes|database|logs)/ - [F,L]

# Protect .env file
<Files .env>
    Order Allow,Deny
    Deny from all
</Files>

# Custom error pages
ErrorDocument 404 /pos-system/404.php
ErrorDocument 403 /pos-system/403.php
ErrorDocument 500 /pos-system/500.php

# PHP Settings
php_value upload_max_filesize 10M
php_value post_max_size 10M
php_value max_execution_time 300
php_value max_input_time 300
"@
Set-Content -Path .htaccess -Value $htaccessContent
Write-Host "? Created .htaccess" -ForegroundColor Green

# Create .env file
$envContent = @"
# Database Configuration
DB_HOST=localhost
DB_NAME=pos_system
DB_USER=root
DB_PASS=

# Application Settings
APP_NAME="POS System"
APP_URL="http://localhost/pos-system"
APP_ENV=development
APP_DEBUG=true

# Company Information
COMPANY_NAME="Your Company Name"
COMPANY_EMAIL="info@yourcompany.com"
COMPANY_PHONE="+1234567890"
COMPANY_ADDRESS="123 Business Street, City, Country"

# Tax Settings
TAX_RATE=10
CURRENCY_SYMBOL="$"
CURRENCY_CODE="USD"

# Security
SESSION_LIFETIME=120
CSRF_PROTECTION=true
"@
Set-Content -Path .env -Value $envContent
Write-Host "? Created .env" -ForegroundColor Green

# Create composer.json
$composerContent = @"
{
    "name": "yourcompany/pos-system",
    "description": "Point of Sale System with QR Code Integration",
    "type": "project",
    "require": {
        "php": ">=7.4",
        "ext-pdo": "*",
        "ext-json": "*",
        "ext-gd": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.0"
    },
    "autoload": {
        "psr-4": {
            "POS\\": "classes/"
        }
    },
    "scripts": {
        "test": "phpunit tests"
    }
}
"@
Set-Content -Path composer.json -Value $composerContent
Write-Host "? Created composer.json" -ForegroundColor Green

# Create package.json
$packageContent = @"
{
    "name": "pos-system",
    "version": "1.0.0",
    "description": "Point of Sale System",
    "main": "index.js",
    "dependencies": {
        "bootstrap": "^5.3.0",
        "jquery": "^3.7.0",
        "chart.js": "^4.3.0",
        "sweetalert2": "^11.7.0"
    },
    "devDependencies": {
        "laravel-mix": "^6.0.49",
        "sass": "^1.62.1"
    },
    "scripts": {
        "dev": "npm run development",
        "watch": "mix watch",
        "prod": "npm run production"
    }
}
"@
Set-Content -Path package.json -Value $packageContent
Write-Host "? Created package.json" -ForegroundColor Green

# Create README.md
$readmeContent = @"
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

## Installation

1. Create database and import schema.sql
2. Update config/database.php
3. Access at http://localhost/pos-system/
"@
Set-Content -Path README.md -Value $readmeContent
Write-Host "? Created README.md" -ForegroundColor Green

# Create robots.txt
"User-agent: *`nDisallow: /api/`nDisallow: /config/`nDisallow: /classes/`nDisallow: /includes/`nDisallow: /database/`nDisallow: /logs/" | Set-Content -Path robots.txt
Write-Host "? Created robots.txt" -ForegroundColor Green

# Create directories
New-Item -ItemType Directory -Path "database\migrations" -Force | Out-Null
New-Item -ItemType Directory -Path "logs" -Force | Out-Null
New-Item -ItemType Directory -Path "tests" -Force | Out-Null
New-Item -ItemType Directory -Path "docs" -Force | Out-Null
Write-Host "? Created directories" -ForegroundColor Green

# Create log files
"" | Set-Content -Path "logs\error.log"
"" | Set-Content -Path "logs\access.log"
Write-Host "? Created log files" -ForegroundColor Green

Write-Host "`n? All files created successfully!" -ForegroundColor Green
Write-Host "`nNext steps:" -ForegroundColor Yellow
Write-Host "1. Import database/schema.sql to MySQL"
Write-Host "2. Update config/database.php with your credentials"
Write-Host "3. Access the system at http://localhost/pos-system/"
