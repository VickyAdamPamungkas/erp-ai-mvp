-- ============================================================
-- WoodMind ERP — Database Schema
-- Database : woodmind_erp
-- Compat   : MySQL 8.0 & MariaDB 10.4+
-- Idempoten: CREATE ... IF NOT EXISTS
-- ============================================================

CREATE DATABASE IF NOT EXISTS woodmind_erp
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE woodmind_erp;

-- ------------------------------------------------------------
-- 1. roles
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id        INT          AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50)  NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT           AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)   NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    role_id       INT           NOT NULL,
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. products
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id           INT          AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL UNIQUE,
    unit         VARCHAR(20)  NOT NULL,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. warehouses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS warehouses (
    id             INT          AUTO_INCREMENT PRIMARY KEY,
    warehouse_name VARCHAR(100) NOT NULL UNIQUE,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. stocks
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stocks (
    id           INT           AUTO_INCREMENT PRIMARY KEY,
    product_id   INT           NOT NULL,
    warehouse_id INT           NOT NULL,
    quantity     DECIMAL(12,3) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_product_warehouse (product_id, warehouse_id),
    CONSTRAINT fk_stocks_product
        FOREIGN KEY (product_id)   REFERENCES products   (id) ON DELETE RESTRICT,
    CONSTRAINT fk_stocks_warehouse
        FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT,
    CONSTRAINT chk_stocks_qty_non_negative
        CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. stock_movements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
    id                    INT                            AUTO_INCREMENT PRIMARY KEY,
    movement_type         ENUM('IN','OUT','TRANSFER')    NOT NULL,
    product_id            INT                            NOT NULL,
    qty                   DECIMAL(12,3)                  NOT NULL,
    source_warehouse_id   INT                            NULL,
    dest_warehouse_id     INT                            NULL,
    user_id               INT                            NOT NULL,
    created_at            TIMESTAMP                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_movements_product
        FOREIGN KEY (product_id)          REFERENCES products   (id) ON DELETE RESTRICT,
    CONSTRAINT fk_movements_source
        FOREIGN KEY (source_warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT,
    CONSTRAINT fk_movements_dest
        FOREIGN KEY (dest_warehouse_id)   REFERENCES warehouses (id) ON DELETE RESTRICT,
    CONSTRAINT fk_movements_user
        FOREIGN KEY (user_id)             REFERENCES users      (id) ON DELETE RESTRICT,
    CONSTRAINT chk_movements_qty_positive
        CHECK (qty > 0),
    CONSTRAINT chk_movements_consistency
        CHECK (
            (movement_type = 'IN'
                AND source_warehouse_id IS NULL
                AND dest_warehouse_id   IS NOT NULL)
            OR
            (movement_type = 'OUT'
                AND source_warehouse_id IS NOT NULL
                AND dest_warehouse_id   IS NULL)
            OR
            (movement_type = 'TRANSFER'
                AND source_warehouse_id IS NOT NULL
                AND dest_warehouse_id   IS NOT NULL
                AND source_warehouse_id <> dest_warehouse_id)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- index: pencarian riwayat per produk
CREATE INDEX idx_movements_product_created
    ON stock_movements (product_id, created_at);

-- ------------------------------------------------------------
-- 7. audit_logs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id                INT                                                    AUTO_INCREMENT PRIMARY KEY,
    user_id           INT                                                    NULL,
    raw_prompt        TEXT                                                   NOT NULL,
    llm_json_response JSON                                                   NULL,
    decision          ENUM('EXECUTED','REJECTED','CLARIFIED','CANCELLED')    NOT NULL,
    reason            VARCHAR(255)                                           NULL,
    movement_id       INT                                                    NULL,
    model_name        VARCHAR(100)                                           NULL,
    latency_ms        INT                                                    NULL,
    created_at        TIMESTAMP                                              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id)     REFERENCES users           (id) ON DELETE RESTRICT,
    CONSTRAINT fk_audit_movement
        FOREIGN KEY (movement_id) REFERENCES stock_movements (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_audit_created_at ON audit_logs (created_at);
CREATE INDEX idx_audit_decision   ON audit_logs (decision);

-- ------------------------------------------------------------
-- Trigger: audit_logs append-only
-- ------------------------------------------------------------
DELIMITER //

CREATE TRIGGER trg_audit_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'audit_logs bersifat append-only';
END //

CREATE TRIGGER trg_audit_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'audit_logs bersifat append-only';
END //

DELIMITER ;
