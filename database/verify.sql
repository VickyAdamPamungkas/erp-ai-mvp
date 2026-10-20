-- ============================================================
-- WoodMind ERP — Verification Script
-- SEMUA pernyataan di bawah HARUS GAGAL.
-- Jalankan satu per satu untuk membuktikan constraint bekerja.
-- ============================================================

USE woodmind_erp;

-- -----------------------------------------------------------------
-- Tes 1: UPDATE audit_logs harus ditolak oleh trigger
-- Hasil yang diharapkan: ERROR 1644 (45000): audit_logs bersifat append-only
-- -----------------------------------------------------------------
-- Sisipkan baris dummy dahulu agar ada data untuk di-update/delete:
INSERT INTO audit_logs (user_id, raw_prompt, decision)
    VALUES (NULL, 'tes verifikasi', 'REJECTED');

-- Pernyataan ini HARUS GAGAL:
UPDATE audit_logs SET reason = 'diubah' WHERE raw_prompt = 'tes verifikasi';

-- -----------------------------------------------------------------
-- Tes 2: DELETE audit_logs harus ditolak oleh trigger
-- Hasil yang diharapkan: ERROR 1644 (45000): audit_logs bersifat append-only
-- -----------------------------------------------------------------
DELETE FROM audit_logs WHERE raw_prompt = 'tes verifikasi';

-- -----------------------------------------------------------------
-- Tes 3: INSERT stocks dengan quantity negatif harus ditolak oleh CHECK
-- Hasil yang diharapkan: ERROR 3819 (HY000): Check constraint 'chk_stocks_qty_non_negative' is violated.
-- -----------------------------------------------------------------
INSERT INTO stocks (product_id, warehouse_id, quantity)
    VALUES (
        (SELECT id FROM products   WHERE product_name  = 'Kayu Jati'),
        (SELECT id FROM warehouses WHERE warehouse_name = 'Gudang Sisa'),
        -5.000
    );

-- -----------------------------------------------------------------
-- Tes 4: INSERT stock_movements TRANSFER dengan asal = tujuan harus ditolak
-- Hasil yang diharapkan: ERROR 3819 (HY000): Check constraint 'chk_movements_consistency' is violated.
-- -----------------------------------------------------------------
INSERT INTO stock_movements (movement_type, product_id, qty, source_warehouse_id, dest_warehouse_id, user_id)
    VALUES (
        'TRANSFER',
        (SELECT id FROM products   WHERE product_name  = 'Kayu Jati'),
        10.000,
        (SELECT id FROM warehouses WHERE warehouse_name = 'Gudang Utama'),
        (SELECT id FROM warehouses WHERE warehouse_name = 'Gudang Utama'),
        (SELECT id FROM users      WHERE username       = 'staf1')
    );

-- -----------------------------------------------------------------
-- Tes 5: INSERT products dengan nama duplikat harus ditolak
-- Hasil yang diharapkan: ERROR 1062 (23000): Duplicate entry 'Kayu Jati' for key 'product_name'
-- -----------------------------------------------------------------
INSERT INTO products (product_name, unit) VALUES ('Kayu Jati', 'batang');
