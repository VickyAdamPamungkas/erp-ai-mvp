<?php

/**
 * FakeStockModel — Model tiruan in-memory untuk pengujian ValidationLayer.
 */
class FakeStockModel
{
    /** @var array<string, float> key format: "{product_id}_{warehouse_id}" */
    private array $stocks;

    public function __construct(?array $stocks = null)
    {
        // Seed default:
        // Product 1 (Jati)   & Warehouse 1 (Gudang Utama) = 120.0
        // Product 2 (Mahoni) & Warehouse 2 (Gudang Sisa)  = 8.0
        // Product 3 (Sengon) & Warehouse 1 (Gudang Utama) = 15.5
        $this->stocks = $stocks ?? [
            '1_1' => 120.0,
            '2_2' => 8.0,
            '3_1' => 15.5,
        ];
    }

    /**
     * Mendapatkan kuantitas stok berdasarkan ID produk dan ID gudang.
     * Mengembalikan 0.0 jika kombinasi tidak ditemukan.
     *
     * @param int $productId
     * @param int $warehouseId
     * @return float
     */
    public function getStock(int $productId, int $warehouseId): float
    {
        $key = "{$productId}_{$warehouseId}";
        return isset($this->stocks[$key]) ? (float) $this->stocks[$key] : 0.0;
    }

    /**
     * Mendapatkan kuantitas stok berdasarkan nama produk dan nama gudang.
     *
     * @param string $productName
     * @param string $warehouseName
     * @return float
     */
    public function getStockByNames(string $productName, string $warehouseName): float
    {
        $p = mb_strtolower(trim($productName));
        $w = mb_strtolower(trim($warehouseName));

        // Pemetaan sederhana untuk keperluan test
        $pId = match ($p) {
            'kayu jati'    => 1,
            'kayu mahoni'  => 2,
            'kayu sengon'  => 3,
            'kayu meranti' => 4,
            'kayu pinus'   => 5,
            default        => null,
        };

        $wId = match ($w) {
            'gudang utama'       => 1,
            'gudang sisa'        => 2,
            'gudang pengeringan' => 3,
            default              => null,
        };

        if ($pId === null || $wId === null) {
            return 0.0;
        }

        return $this->getStock($pId, $wId);
    }

    /**
     * Mendapatkan ringkasan seluruh stok pada gudang tertentu.
     *
     * @param int $warehouseId
     * @return array<int, array{product_id: int, product_name: string, unit: string, quantity: float}>
     */
    public function getStockSummaryByWarehouse(int $warehouseId): array
    {
        $result = [];
        $meta = [
            1 => ['name' => 'Kayu Jati',   'unit' => 'batang'],
            2 => ['name' => 'Kayu Mahoni', 'unit' => 'batang'],
            3 => ['name' => 'Kayu Sengon', 'unit' => 'm3'],
        ];

        foreach ($this->stocks as $key => $qty) {
            [$pId, $wId] = explode('_', $key);
            if ((int) $wId === $warehouseId && isset($meta[(int) $pId])) {
                $result[] = [
                    'product_id'   => (int) $pId,
                    'product_name' => $meta[(int) $pId]['name'],
                    'unit'         => $meta[(int) $pId]['unit'],
                    'quantity'     => (float) $qty,
                ];
            }
        }

        return $result;
    }
}
