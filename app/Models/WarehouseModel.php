<?php

/**
 * WarehouseModel — Operasi pembacaan data master gudang/lokasi (read-only).
 *
 * Sesuai arsitektur WoodMind ERP:
 * - Read-only (HANYA SELECT, tanpa operasi mutasi).
 * - Semua query menggunakan Prepared Statements PDO.
 */
class WarehouseModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mengambil seluruh gudang yang berstatus aktif.
     *
     * @return array<int, array{id: int, warehouse_name: string}>
     */
    public function getAllActive(): array
    {
        $sql = 'SELECT id, warehouse_name FROM warehouses WHERE is_active = 1 ORDER BY id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mencari gudang berdasarkan nama (exact match, case-insensitive).
     *
     * @param string $name
     * @return array{id: int, warehouse_name: string, is_active: int}|null
     */
    public function findByName(string $name): ?array
    {
        $sql = 'SELECT id, warehouse_name, is_active FROM warehouses WHERE LOWER(warehouse_name) = LOWER(:name) LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':name' => trim($name)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Mencari gudang aktif berdasarkan nama (exact match & is_active = 1).
     *
     * @param string $name
     * @return array{id: int, warehouse_name: string, is_active: int}|null
     */
    public function findActiveByName(string $name): ?array
    {
        $sql = 'SELECT id, warehouse_name, is_active FROM warehouses WHERE LOWER(warehouse_name) = LOWER(:name) AND is_active = 1 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':name' => trim($name)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
