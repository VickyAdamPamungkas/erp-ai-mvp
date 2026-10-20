<?php

/**
 * FakeProductModel — Model tiruan in-memory untuk pengujian ValidationLayer.
 */
class FakeProductModel
{
    /** @var array<int, array{id: int, product_name: string, unit: string, is_active: int}> */
    private array $products;

    public function __construct(?array $products = null)
    {
        $this->products = $products ?? [
            ['id' => 1, 'product_name' => 'Kayu Jati',    'unit' => 'batang', 'is_active' => 1],
            ['id' => 2, 'product_name' => 'Kayu Mahoni',  'unit' => 'batang', 'is_active' => 1],
            ['id' => 3, 'product_name' => 'Kayu Sengon',  'unit' => 'm3',     'is_active' => 1],
            ['id' => 4, 'product_name' => 'Kayu Meranti', 'unit' => 'm3',     'is_active' => 1],
            ['id' => 5, 'product_name' => 'Kayu Pinus',   'unit' => 'batang', 'is_active' => 1],
            ['id' => 6, 'product_name' => 'Kayu Ulin',    'unit' => 'batang', 'is_active' => 0], // Nonaktif
        ];
    }

    /**
     * @return array<int, array{id: int, product_name: string, unit: string}>
     */
    public function getAllActive(): array
    {
        $active = [];
        foreach ($this->products as $p) {
            if (($p['is_active'] ?? 1) === 1) {
                $active[] = [
                    'id'           => (int) $p['id'],
                    'product_name' => (string) $p['product_name'],
                    'unit'         => (string) $p['unit'],
                ];
            }
        }
        return $active;
    }

    /**
     * @param string $name
     * @return array{id: int, product_name: string, unit: string, is_active: int}|null
     */
    public function findByName(string $name): ?array
    {
        $trimmed = trim(mb_strtolower($name));
        foreach ($this->products as $p) {
            if (mb_strtolower($p['product_name']) === $trimmed) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param string $name
     * @return array{id: int, product_name: string, unit: string, is_active: int}|null
     */
    public function findActiveByName(string $name): ?array
    {
        $trimmed = trim(mb_strtolower($name));
        foreach ($this->products as $p) {
            if (mb_strtolower($p['product_name']) === $trimmed && ($p['is_active'] ?? 1) === 1) {
                return $p;
            }
        }
        return null;
    }
}
