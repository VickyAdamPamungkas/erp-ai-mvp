<?php

/**
 * StockModel — Operasi pembacaan data stok (read-only).
 *
 * Sesuai arsitektur WoodMind ERP:
 * - Read-only (HANYA SELECT, tanpa operasi mutasi).
 * - Semua query menggunakan Prepared Statements PDO.
 */
class StockModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mendapatkan kuantitas stok berdasarkan ID produk dan ID gudang.
     * Mengembalikan 0.0 jika tidak ditemukan baris stok.
     *
     * @param int $productId
     * @param int $warehouseId
     * @return float
     */
    public function getStock(int $productId, int $warehouseId): float
    {
        $sql = 'SELECT quantity FROM stocks WHERE product_id = :product_id AND warehouse_id = :warehouse_id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':product_id'   => $productId,
            ':warehouse_id' => $warehouseId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (float) $row['quantity'] : 0.0;
    }

    /**
     * Mendapatkan kuantitas stok berdasarkan nama produk dan nama gudang.
     * Mengembalikan 0.0 jika tidak ditemukan baris stok.
     *
     * @param string $productName
     * @param string $warehouseName
     * @return float
     */
    public function getStockByNames(string $productName, string $warehouseName): float
    {
        $sql = 'SELECT s.quantity
                FROM stocks s
                INNER JOIN products p ON s.product_id = p.id
                INNER JOIN warehouses w ON s.warehouse_id = w.id
                WHERE LOWER(p.product_name) = LOWER(:product_name)
                  AND LOWER(w.warehouse_name) = LOWER(:warehouse_name)
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':product_name'   => trim($productName),
            ':warehouse_name' => trim($warehouseName),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (float) $row['quantity'] : 0.0;
    }

    /**
     * Mendapatkan ringkasan seluruh stok pada gudang tertentu.
     *
     * @param int $warehouseId
     * @return array<int, array{product_id: int, product_name: string, unit: string, quantity: float}>
     */
    public function getStockSummaryByWarehouse(int $warehouseId): array
    {
        $sql = 'SELECT s.product_id, p.product_name, p.unit, s.quantity
                FROM stocks s
                INNER JOIN products p ON s.product_id = p.id
                WHERE s.warehouse_id = :warehouse_id
                ORDER BY p.product_name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':warehouse_id' => $warehouseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'product_id'   => (int) $row['product_id'],
                'product_name' => (string) $row['product_name'],
                'unit'         => (string) $row['unit'],
                'quantity'     => (float) $row['quantity'],
            ];
        }, $rows);
    }
}
