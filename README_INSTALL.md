# POS System Setup Without XAMPP

This project can run without XAMPP by using Docker.

## 1) Install Docker Desktop

Download and install Docker Desktop from:
https://www.docker.com/products/docker-desktop/

## 2) Start the app

From this project folder, run:

```bash
docker compose up --build -d
```

Then open the installer:

http://localhost:8080/pos-system/install.php

## 3) Database details

Use these values in the installer:

- Host: `db`
- Database: `pos_system`
- Username: `posuser`
- Password: `pospass`

The app is already set to use these values automatically in Docker, but the installer still needs to create the application tables and save the config.

## 4) Login

After installation, log in with:

- Username: `admin`
- Password: `admin123`

For other PCs on the same LAN, open `http://SERVER-IP:8080/pos-system/`, replacing `SERVER-IP` with the Docker host's LAN address. Keep the Docker host running while workstations use the system.

## 5) Stop the app

```bash
docker compose down
```

## 6) Reset database

```bash
docker compose down -v
```

This removes the database volume completely.
