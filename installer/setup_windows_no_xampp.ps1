param(
    [string]$AppPath = "",
    [string]$WebPort = "8000",
    [string]$DbName = "pos_system",
    [string]$DbUser = "root",
    [string]$DbPassword = "",
    [switch]$SkipInstall
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($AppPath) -or $AppPath -eq 'C:\pos-system') {
    $AppPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
}

function Write-Step($msg) {
    Write-Host "" 
    Write-Host "[POS SYSTEM] $msg" -ForegroundColor Cyan
}

function Ensure-Command($commandName) {
    if (-not (Get-Command $commandName -ErrorAction SilentlyContinue)) {
        return $false
    }
    return $true
}

function Install-Prereqs {
    Write-Step "Checking for required runtime tools..."

    $hasWinget = Ensure-Command "winget"
    if (-not $hasWinget) {
        throw "Winget is required to install the local runtime automatically. Please install WinGet first or install PHP and MariaDB manually."
    }

    if (-not (Ensure-Command "php")) {
        Write-Step "Installing PHP..."
        winget install --id PHP.PHP --accept-source-agreements --accept-package-agreements --silent
    }

    if (-not (Ensure-Command "mysql")) {
        Write-Step "Installing MariaDB/MySQL server..."
        winget install --id MariaDB.Server --accept-source-agreements --accept-package-agreements --silent
    }

    $env:Path = [System.Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [System.Environment]::GetEnvironmentVariable('Path', 'User')
}

function Add-MariaDbToPath {
    $binPaths = @(
        'C:\Program Files\MariaDB 13.0\bin',
        'C:\Program Files\MariaDB 11.4\bin',
        'C:\Program Files\MariaDB 10.11\bin',
        'C:\Program Files\MySQL\MySQL Server 8.0\bin',
        'C:\Program Files\MySQL\MySQL Server 5.7\bin'
    )

    foreach ($binPath in $binPaths) {
        if (Test-Path $binPath) {
            $env:Path = "$binPath;$env:Path"
            return $binPath
        }
    }

    return $null
}

function Get-MySqlClientPath {
    $cmd = Get-Command mysql -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    $binPath = Add-MariaDbToPath
    if ($binPath) {
        $mysqlExe = Join-Path $binPath 'mysql.exe'
        if (Test-Path $mysqlExe) {
            return $mysqlExe
        }
    }

    $candidates = @(
        (Get-ChildItem -Path 'C:\Program Files', -Filter 'mysql.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1),
        (Get-ChildItem -Path 'C:\Program Files\MariaDB*', -Filter 'mysql.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1),
        (Get-ChildItem -Path 'C:\Program Files (x86)\MariaDB*', -Filter 'mysql.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1)
    ) | Where-Object { $_ -ne $null }

    if (-not $candidates) {
        return $null
    }

    return $candidates[0].FullName
}

function Ensure-Db {
    Write-Step "Preparing the local database..."

    $mysqlClient = Get-MySqlClientPath
    if (-not $mysqlClient) {
        throw "MySQL/MariaDB client was not found after installation. Please confirm MariaDB is installed and try again."
    }

    $serviceName = 'MariaDB'
    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if (-not $service) {
        $service = Get-Service -Name 'MySQL' -ErrorAction SilentlyContinue
        if ($service) { $serviceName = 'MySQL' }
    }

    if (-not $service) {
        Write-Step "MySQL service not found. Starting MariaDB manually..."
        $mariadbBin = Join-Path $env:ProgramFiles 'MariaDB 11.4\bin\mysql.exe'
        if (-not (Test-Path $mariadbBin)) {
            $mariadbBin = Join-Path $env:ProgramFiles 'MariaDB 10.11\bin\mysql.exe'
        }

        if (Test-Path $mariadbBin) {
            $dbDir = Split-Path $mariadbBin -Parent
            $mysqld = Join-Path $dbDir 'mysqld.exe'
            if (Test-Path $mysqld) {
                & $mysqld --install
            }
        }
    }

    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if ($service) {
        if ($service.Status -ne 'Running') {
            Start-Service -Name $serviceName
        }
    }

    $mysqlArgs = @('--skip-ssl', '-u' + $DbUser, '-e', "CREATE DATABASE IF NOT EXISTS `$DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;")
    if (-not [string]::IsNullOrEmpty($DbPassword)) {
        $mysqlArgs = @('--skip-ssl', '-u' + $DbUser, ('-p' + $DbPassword), '-e', "CREATE DATABASE IF NOT EXISTS `$DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;")
    }

    & $mysqlClient @mysqlArgs
}

function Create-ConfigFile {
    Write-Step "Writing database config..."

    $configFolder = Join-Path $AppPath 'config'
    $configPath = Join-Path $configFolder '.env.php'
    $dir = New-Item -ItemType Directory -Path $configFolder -Force

    $content = @"
<?php
return [
    'host' => 'localhost',
    'db_name' => '$DbName',
    'username' => '$DbUser',
    'password' => '$DbPassword',
];
"@

    Set-Content -Path $configPath -Value $content -Encoding UTF8
    Write-Host "Config saved: $configPath"
}

function Create-Launcher {
    Write-Step "Creating startup launcher..."

    $webRoot = Split-Path $AppPath -Parent
    $launcherPath = Join-Path $AppPath 'start-pos-system.ps1'
    $scriptContent = @"
param()
Set-Location '$webRoot'
php -S localhost:$WebPort -t '$webRoot'
"@

    Set-Content -Path $launcherPath -Value $scriptContent -Encoding UTF8
    Write-Host "Launcher created: $launcherPath"
}

try {
    if (-not $SkipInstall) {
        Install-Prereqs
    }

    if (-not (Test-Path $AppPath)) {
        throw "Application folder not found: $AppPath"
    }

    Create-ConfigFile
    Create-Launcher
    Ensure-Db

    Write-Step "Installation finished successfully."
    Write-Host "Run the app with:" -ForegroundColor Yellow
    Write-Host "  powershell -ExecutionPolicy Bypass -File '$AppPath\start-pos-system.ps1'" -ForegroundColor Yellow
    Write-Host "Then open: http://localhost:$WebPort/pos-system/" -ForegroundColor Yellow
    Write-Host "Default admin login after installation: admin / admin123" -ForegroundColor Yellow
}
catch {
    Write-Error "Installation failed: $($_.Exception.Message)"
    exit 1
}
