# SmartBiz Hub — One Optics Clinic

Laravel optical-shop prototype using XAMPP Apache and MariaDB (mysql driver).

See [XAMPP setup](XAMPP-SETUP.txt) for installation, database creation, Apache configuration and demo accounts. PHP 8.2+ and Composer are required. Run `composer install --no-dev` for this source copy.

Start Apache and MySQL in XAMPP, then open http://127.0.0.1:8081.

See SCOPE-AND-LIMITATIONS.txt and ERD-ENTITY-MAP.txt for the scope and 11 entities. Payments and delivery updates are entered by staff. No live gateway, real-time tracking or production automation is included.

Local .env, live databases, uploads, vendor dependencies and runtime files are excluded. Keep migrations and seeders to recreate a fresh demo database.
