<?php

/**
 * ProductModel — Operasi pembacaan data master produk (read-only).
 *
 * Sesuai arsitektur WoodMind ERP:
 * - Read-only (HANYA SELECT, tanpa operasi mutasi).
 * - Semua query menggunakan Prepared Statements PDO.
 */
class ProductModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mengambil seluruh produk yang berstatus aktif.
     *
     * @return array<int, array{id: int, product_name: string, unit: string}>
     */
    public function getAllActive(): array
    {
        $sql = 'SELECT id, product_name, unit FROM products WHERE is_active = 1 ORDER BY id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mencari produk berdasarkan nama (exact match, case-insensitive).
     *
     * @param string $name
     * @return array{id: int, product_name: string, unit: string, is_active: int}|null
     */
    public function findByName(string $name): ?array
    {
        $sql = 'SELECT id, product_name, unit, is_active FROM products WHERE LOWER(product_name) = LOWER(:name) LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':name' => trim($name)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Mencari produk aktif berdasarkan nama (exact match & is_active = 1).
     *
     * @param string $name
     * @return array{id: int, product_name: string, unit: string, is_active: int}|null
     */
    public function findActiveByName(string $name): ?array
    {
        $sql = 'SELECT id, product_name, unit, is_active FROM products WHERE LOWER(product_name) = LOWER(:name) AND is_active = 1 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':name' => trim($name)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
