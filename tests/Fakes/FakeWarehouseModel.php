<?php

/**
 * FakeWarehouseModel — Model tiruan in-memory untuk pengujian ValidationLayer.
 */
class FakeWarehouseModel
{
    /** @var array<int, array{id: int, warehouse_name: string, is_active: int}> */
    private array $warehouses;

    public function __construct(?array $warehouses = null)
    {
        $this->warehouses = $warehouses ?? [
            ['id' => 1, 'warehouse_name' => 'Gudang Utama',       'is_active' => 1],
            ['id' => 2, 'warehouse_name' => 'Gudang Sisa',        'is_active' => 1],
            ['id' => 3, 'warehouse_name' => 'Gudang Pengeringan', 'is_active' => 1],
            ['id' => 4, 'warehouse_name' => 'Gudang Rusak',       'is_active' => 0], // Nonaktif
        ];
    }

    /**
     * @return array<int, array{id: int, warehouse_name: string}>
     */
    public function getAllActive(): array
    {
        $active = [];
        foreach ($this->warehouses as $w) {
            if (($w['is_active'] ?? 1) === 1) {
                $active[] = [
                    'id'             => (int) $w['id'],
                    'warehouse_name' => (string) $w['warehouse_name'],
                ];
            }
        }
        return $active;
    }

    /**
     * @param string $name
     * @return array{id: int, warehouse_name: string, is_active: int}|null
     */
    public function findByName(string $name): ?array
    {
        $trimmed = trim(mb_strtolower($name));
        foreach ($this->warehouses as $w) {
            if (mb_strtolower($w['warehouse_name']) === $trimmed) {
                return $w;
            }
        }
        return null;
    }

    /**
     * @param string $name
     * @return array{id: int, warehouse_name: string, is_active: int}|null
     */
    public function findActiveByName(string $name): ?array
    {
        $trimmed = trim(mb_strtolower($name));
        foreach ($this->warehouses as $w) {
            if (mb_strtolower($w['warehouse_name']) === $trimmed && ($w['is_active'] ?? 1) === 1) {
                return $w;
            }
        }
        return null;
    }
}
