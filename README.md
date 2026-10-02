# SmartBiz Hub — One Optics Clinic

Laravel school prototype for customer registration, online and walk-in orders,
billing, manually recorded payments, order status, and reports. The required
11-entity ERD also supports basic purchasing and product stock management.

## Requirements
PHP 8.2 or later with SQLite support, and Composer. The interface uses local
Bootstrap, CSS and JavaScript assets; no frontend build is needed to run it.

## Local setup (PowerShell)
Run these commands in a fresh clone:

```powershell
composer install --no-dev
Copy-Item .env.example .env
php artisan key:generate
New-Item database/database.sqlite -ItemType File
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8099
```

If PHP is not on PATH, use C:\xampp\php\php.exe instead of php.
After initial setup, START-SMARTBIZ.cmd also starts the local server.
Open http://127.0.0.1:8099. Keep the server running during the demonstration.
Do not recreate an existing database or run migrate:fresh on records you need.

## Demo accounts
Seeder-generated fictional accounts for local demonstration only:

| Role | Email | Password |
|---|---|---|
| Admin | admin@oneoptics.test | DemoAdmin123! |
| Staff | staff@oneoptics.test | DemoStaff123! |
| Customer | customer@oneoptics.test | DemoCustomer123! |

## Scope
See SCOPE-AND-LIMITATIONS.txt and ERD-ENTITY-MAP.txt.
Payment methods store staff-entered records, not live gateway transactions.
Delivery updates are entered by staff; there is no real-time courier tracking.
No manufacturing, advanced CRM or multi-tenant SaaS features are included.

## Repository contents
This source edition excludes .env, live databases, uploaded files, runtime
sessions/logs/cache and vendor dependencies. Composer installs dependencies;
migrations and the seeder recreate a fresh demo database. Never commit real
customer information, local configuration or credentials.

This simplified source copy omits automated tests and unused frontend build
scaffolding. Keep database/migrations and database/seeders: they are required
to recreate the database on another computer. Optional development packages
remain recorded in Composer but are skipped by the --no-dev setup command.
