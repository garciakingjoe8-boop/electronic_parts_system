# 2K2R Electronic Parts Shop – Inventory and Sales Management System
PHP + MySQL + XAMPP.

## Install
1. Copy this folder to `/Applications/XAMPP/htdocs/electronic_parts_system` (Mac) or `C:\xampp\htdocs\` (Windows).
2. Start Apache and MySQL in XAMPP.
3. Open http://localhost/phpmyadmin → SQL tab → paste/run `sql/database.sql`.
4. Open http://localhost/electronic_parts_system/ — you will be sent to the one-time setup page to create the admin account.
5. Log in. Add categories → products → record sales.

## Upgrading an existing install
If you already ran the first `database.sql`, run `sql/migration.sql` once in phpMyAdmin (SQL tab), then replace the PHP files.

## Features
Products, categories, suppliers, stock-in, adjustments, low-stock alerts, sales with customer name / payment / change,
printable receipts, admin void of mistaken sales (stock restored), returns & warranty tracking, reports (daily sales,
best-sellers, slow-moving, restock list), CSV export, and full SQL backup (Reports page, admin).

## Roles
- **Admin**: everything (products, categories, users, stock adjustments, reports).
- **Staff**: view/search products, stock-in, record sales, view history and reports.

If your MySQL root has a password, edit `config/database.php`. Delete `setup.php` after setup (optional; it disables itself once a user exists).
