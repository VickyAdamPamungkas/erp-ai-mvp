-- ============================================================
-- WoodMind ERP — Seed Data
-- Jalankan SETELAH schema.sql
-- Password: jalankan  php database/make_hash.php <password>
--           lalu ganti 'GANTI_DENGAN_HASH' di bawah.
-- ============================================================

USE woodmind_erp;

-- ------------------------------------------------------------
-- Roles
-- ------------------------------------------------------------
INSERT INTO roles (role_name) VALUES
    ('STAFF'),
    ('SUPERVISOR'),
    ('ADMIN');

-- ------------------------------------------------------------
-- Users (password_hash = placeholder, ganti sebelum dipakai)
-- ------------------------------------------------------------
INSERT INTO users (username, password_hash, role_id) VALUES
    ('staf1', 'GANTI_DENGAN_HASH', (SELECT id FROM roles WHERE role_name = 'STAFF')),
    ('spv1',  'GANTI_DENGAN_HASH', (SELECT id FROM roles WHERE role_name = 'SUPERVISOR'));

-- ------------------------------------------------------------
-- Products
-- Catatan: Kayu Meranti dan Kayu Pinus sengaja TIDAK diberi
--          baris stok agar bisa menguji skenario "stok belum ada".
-- JANGAN menambah produk 'Eboni' — dipakai untuk tes entitas fiktif.
-- ------------------------------------------------------------
INSERT INTO products (product_name, unit) VALUES
    ('Kayu Jati',    'batang'),
    ('Kayu Mahoni',  'batang'),
    ('Kayu Sengon',  'm3'),
    ('Kayu Meranti', 'm3'),
    ('Kayu Pinus',   'batang');

-- ------------------------------------------------------------
-- Warehouses
-- ------------------------------------------------------------
INSERT INTO warehouses (warehouse_name) VALUES
    ('Gudang Utama'),
    ('Gudang Sisa'),
    ('Gudang Pengeringan');

-- ------------------------------------------------------------
-- Stocks
-- Kayu Meranti dan Kayu Pinus sengaja tidak punya baris stok.
-- ------------------------------------------------------------
INSERT INTO stocks (product_id, warehouse_id, quantity) VALUES
    (
        (SELECT id FROM products   WHERE product_name   = 'Kayu Jati'),
        (SELECT id FROM warehouses WHERE warehouse_name  = 'Gudang Utama'),
        120.000
    ),
    (
        (SELECT id FROM products   WHERE product_name   = 'Kayu Mahoni'),
        (SELECT id FROM warehouses WHERE warehouse_name  = 'Gudang Sisa'),
        8.000
    ),
    (
        (SELECT id FROM products   WHERE product_name   = 'Kayu Sengon'),
        (SELECT id FROM warehouses WHERE warehouse_name  = 'Gudang Utama'),
        15.500
    );
