CREATE DATABASE IF NOT EXISTS smartphone_stock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartphone_stock;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE suppliers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    contact_person VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_suppliers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_supplier_owner_name (user_id, name),
    KEY idx_suppliers_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NULL,
    sku VARCHAR(80) NOT NULL,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(160) NOT NULL,
    storage VARCHAR(40) NOT NULL,
    ram VARCHAR(40) NULL,
    color VARCHAR(80) NOT NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    minimum_stock INT UNSIGNED NOT NULL DEFAULT 0,
    safety_stock INT UNSIGNED NOT NULL DEFAULT 0,
    lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    image_url VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    UNIQUE KEY uq_product_owner_sku (user_id, sku),
    KEY idx_products_owner_brand (user_id, brand),
    KEY idx_products_owner_stock (user_id, stock)
) ENGINE=InnoDB;

CREATE TABLE sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    payment_method ENUM('cash', 'card', 'bank_transfer', 'other') NOT NULL,
    sale_date DATE NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('completed', 'cancelled') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_sales_user_date (user_id, sale_date)
) ENGINE=InnoDB;

CREATE TABLE sale_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    selling_price DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    KEY idx_sale_items_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE stock_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    type ENUM('in', 'out', 'adjustment', 'sale', 'purchase') NOT NULL,
    quantity INT NOT NULL,
    reference_type VARCHAR(40) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_transactions_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    KEY idx_stock_transactions_product_created (product_id, created_at),
    KEY idx_stock_transactions_reference (reference_type, reference_id)
) ENGINE=InnoDB;

CREATE TABLE purchase_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft', 'pending', 'ordered', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
    total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    received_at DATETIME NULL,
    CONSTRAINT fk_purchase_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
    KEY idx_purchase_orders_owner_status (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE purchase_order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL,
    received_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_po_items_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_po_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_po_product (purchase_order_id, product_id)
) ENGINE=InnoDB;

CREATE TABLE forecast_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    model_name VARCHAR(80) NOT NULL,
    horizon_days SMALLINT UNSIGNED NOT NULL,
    equation TEXT NOT NULL,
    metrics JSON NULL,
    forecast_data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_forecasts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_forecasts_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    KEY idx_forecasts_product_created (product_id, created_at)
) ENGINE=InnoDB;
