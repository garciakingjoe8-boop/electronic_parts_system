-- Run this ONCE on an EXISTING electronic_parts_db (XAMPP's MariaDB). Safe to re-run.
USE electronic_parts_db;

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
