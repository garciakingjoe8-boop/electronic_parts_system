-- 2K2R Electronic Parts Shop - Inventory and Sales Management System
CREATE DATABASE IF NOT EXISTS electronic_parts_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE electronic_parts_db;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) NOT NULL UNIQUE,
    product_name VARCHAR(150) NOT NULL,
    category_id INT,
    description TEXT,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity INT NOT NULL DEFAULT 0,
    minimum_stock INT NOT NULL DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sales (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS sale_items (
    sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE IF NOT EXISTS stock_movements (
    movement_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    movement_type ENUM('IN', 'OUT', 'ADJUSTMENT') NOT NULL,
    quantity INT NOT NULL,
    notes VARCHAR(255),
    movement_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Sample categories (optional)
INSERT IGNORE INTO categories (category_name) VALUES
 ('Resistors'), ('Capacitors'), ('Wires & Cables'), ('Connectors'), ('ICs & Chips'), ('Other Components');

-- ===== Additions: payment, void, suppliers, returns/warranty =====

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS customer_name VARCHAR(100) NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS status ENUM('completed','voided') NOT NULL DEFAULT 'completed',
  ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS voided_by INT NULL,
  ADD COLUMN IF NOT EXISTS voided_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100) NOT NULL UNIQUE,
    contact_info VARCHAR(150) NULL
);

ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS supplier_id INT NULL;

CREATE TABLE IF NOT EXISTS returns (
    return_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NULL,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    customer_name VARCHAR(100) NULL,
    quantity INT NOT NULL DEFAULT 1,
    return_type ENUM('Return','Replacement','Warranty Check') NOT NULL DEFAULT 'Return',
    concern TEXT NOT NULL,
    status ENUM('Pending','Resolved','Rejected') NOT NULL DEFAULT 'Pending',
    resolution VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- NOTE: Create the first admin account by opening http://localhost/electronic_parts_system/setup.php
