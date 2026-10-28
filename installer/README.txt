POS System Desktop Installer

This installer is designed for another computer without XAMPP.

What it does:
- creates a local web server environment
- installs PHP and MySQL runtime if needed
- copies the POS System files
- runs the database installer
- creates the admin user automatically

Important:
This is still a PHP + MySQL web app. It is not a pure standalone desktop app.
But the end user will not need to manually install XAMPP.

Recommended setup:
- Use Electron or a packaged local server wrapper
- Or use a single Windows Setup script with built-in runtime download steps
