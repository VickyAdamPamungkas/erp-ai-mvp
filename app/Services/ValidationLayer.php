<?php

require_once __DIR__ . '/NameNormalizer.php';

/**
 * ValidationLayer — Controlled Execution Layer untuk validasi terstruktur hasil ekstraksi LLM.
 *
 * Sesuai arsitektur WoodMind ERP:
 * - Menjadi gerbang keamanan antara LLM (tidak tepercaya) dan database / eksekusi.
 * - Urutan tahapan validasi (berhenti di kegagalan pertama):
 *   1. Schema Validation (kunci lengkap, intent whitelist, field wajib, kuantitas numerik)
 *   2. Entity Validation (pencocokan nama produk & gudang aktif via NameNormalizer)
 *   3. Permission Validation (hak akses peran pengguna terhadap intent)
 *   4. Business Rule Validation (qty > 0, kesesuaian unit, integritas batang & m3, gudang transfer, kecukupan stok)
 * - Prinsip fail-closed: Setiap error / exception internal akan menolak eksekusi (status 'rejected').
 * - TIDAK melakukan mutasi data (INSERT/UPDATE/DELETE), echo, akses session, atau pemanggilan LLM.
 */
class ValidationLayer
{
    /** @var object ProductModel / FakeProductModel */
    private $productModel;

    /** @var object WarehouseModel / FakeWarehouseModel */
    private $warehouseModel;

    /** @var object StockModel / FakeStockModel */
    private $stockModel;

    private NameNormalizer $normalizer;

    /** Daftar intent yang diizinkan (whitelist). */
    private const ALLOWED_INTENTS = [
        'CHECK_STOCK',
        'STOCK_IN',
        'STOCK_OUT',
        'TRANSFER_STOCK',
        'CLARIFY_REQUIRED',
    ];

    /** Kunci dasar yang wajib ada dalam payload LLM. */
    private const BASE_KEYS = [
        'intent',
        'product',
        'quantity',
        'unit',
        'source_warehouse',
        'destination_warehouse',
    ];

    /** Pemetaan peran pengguna ke intent yang diizinkan (V1). */
    private const ROLE_PERMISSIONS = [
        'STAFF'      => ['CHECK_STOCK', 'STOCK_IN', 'STOCK_OUT', 'TRANSFER_STOCK'],
        'SUPERVISOR' => ['CHECK_STOCK', 'STOCK_IN', 'STOCK_OUT', 'TRANSFER_STOCK'],
        'ADMIN'      => ['CHECK_STOCK', 'STOCK_IN', 'STOCK_OUT', 'TRANSFER_STOCK'],
    ];

    /**
     * @param object $productModel   Model produk (tanpa typehint kelas konkret)
     * @param object $warehouseModel Model gudang (tanpa typehint kelas konkret)
     * @param object $stockModel     Model stok (tanpa typehint kelas konkret)
     * @param NameNormalizer|null $normalizer Service normalisasi nama (opsional)
     */
    public function __construct(
        $productModel,
        $warehouseModel,
        $stockModel,
        ?NameNormalizer $normalizer = null
    ) {
        $this->productModel   = $productModel;
        $this->warehouseModel = $warehouseModel;
        $this->stockModel     = $stockModel;
        $this->normalizer     = $normalizer ?? new NameNormalizer();
    }

    /**
     * Memvalidasi data ekstraksi LLM dan peran pengguna secara bertahap.
     *
     * @param array<string, mixed> $llmData
     * @param string $userRole
     * @return array{
     *     status: 'ok'|'clarify'|'rejected',
     *     stage: null|'schema'|'entity'|'permission'|'business_rule',
     *     reason: string,
     *     requires_confirmation: bool,
     *     normalized: null|array{
     *         intent: string,
     *         product_id: int,
     *         product_name: string,
     *         unit: string,
     *         qty: float|null,
     *         source_warehouse_id: int|null,
     *         dest_warehouse_id: int|null,
     *         source_stock: float|null
     *     }
     * }
     */
    public function validate(array $llmData, string $userRole): array
    {
        try {
            // -----------------------------------------------------------------
            // 1. TAHAP SCHEMA VALIDATION
            // -----------------------------------------------------------------
            $schemaResult = $this->validateSchema($llmData);
            if ($schemaResult !== null) {
                return $schemaResult;
            }

            $intent = (string) $llmData['intent'];

            // -----------------------------------------------------------------
            // 2. TAHAP ENTITY VALIDATION
            // -----------------------------------------------------------------
            $entityResult = $this->validateEntity($llmData);
            if (isset($entityResult['status'])) {
                return $entityResult;
            }

            $matchedProduct = $entityResult['product'];
            $matchedSrc     = $entityResult['source_warehouse'] ?? null;
            $matchedDst     = $entityResult['destination_warehouse'] ?? null;

            // -----------------------------------------------------------------
            // 3. TAHAP PERMISSION VALIDATION
            // -----------------------------------------------------------------
            $permResult = $this->validatePermission($userRole, $intent);
            if ($permResult !== null) {
                return $permResult;
            }

            // -----------------------------------------------------------------
            // 4. TAHAP BUSINESS RULE VALIDATION
            // -----------------------------------------------------------------
            return $this->validateBusinessRule(
                $llmData,
                $matchedProduct,
                $matchedSrc,
                $matchedDst
            );

        } catch (\Throwable $e) {
            // Fail-closed: Semua kegagalan tak terduga ditolak
            return [
                'status'                => 'rejected',
                'stage'                 => 'schema',
                'reason'                => 'Kesalahan validasi internal',
                'requires_confirmation' => false,
                'normalized'            => null,
            ];
        }
    }

    /**
     * Memvalidasi struktur skema data LLM.
     */
    private function validateSchema(array $llmData): ?array
    {
        // 1. Cek kelengkapan 6 kunci dasar (kunci ekstra diabaikan)
        foreach (self::BASE_KEYS as $key) {
            if (!array_key_exists($key, $llmData)) {
                return [
                    'status'                => 'rejected',
                    'stage'                 => 'schema',
                    'reason'                => "Kunci schema '{$key}' tidak ditemukan",
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        }

        $intent = $llmData['intent'];

        // 2. Intent harus string dan masuk dalam whitelist
        if (!is_string($intent) || !in_array($intent, self::ALLOWED_INTENTS, true)) {
            return [
                'status'                => 'rejected',
                'stage'                 => 'schema',
                'reason'                => "Intent '{$intent}' tidak valid",
                'requires_confirmation' => false,
                'normalized'            => null,
            ];
        }

        // 3. Penanganan CLARIFY_REQUIRED
        if ($intent === 'CLARIFY_REQUIRED') {
            return [
                'status'                => 'clarify',
                'stage'                 => 'schema',
                'reason'                => 'Maaf, perintah Anda kurang lengkap. Tolong sebutkan nama produk, jumlah, dan lokasinya.',
                'requires_confirmation' => false,
                'normalized'            => null,
            ];
        }

        // 4. Kunci wajib per intent
        $requiredKeys = match ($intent) {
            'STOCK_IN'       => ['product', 'quantity', 'destination_warehouse'],
            'STOCK_OUT'      => ['product', 'quantity', 'source_warehouse'],
            'TRANSFER_STOCK' => ['product', 'quantity', 'source_warehouse', 'destination_warehouse'],
            'CHECK_STOCK'    => ['product'],
            default          => [],
        };

        foreach ($requiredKeys as $reqKey) {
            $val = $llmData[$reqKey] ?? null;
            if ($val === null || (is_string($val) && trim($val) === '')) {
                return [
                    'status'                => 'clarify',
                    'stage'                 => 'schema',
                    'reason'                => "Informasi '{$reqKey}' belum lengkap atau tidak ditentukan",
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        }

        // 5. Quantity harus numerik untuk transaksi stok (jika tidak null)
        if ($llmData['quantity'] !== null && $llmData['quantity'] !== '') {
            if (!is_numeric($llmData['quantity'])) {
                return [
                    'status'                => 'rejected',
                    'stage'                 => 'schema',
                    'reason'                => 'Kuantitas harus berupa nilai numerik',
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        }

        return null;
    }

    /**
     * Memvalidasi keberadaan entitas produk dan gudang aktif di database.
     * Mengembalikan array berisi entitas yang cocok atau respons penolakan.
     *
     * @return array<string, mixed>
     */
    private function validateEntity(array $llmData): array
    {
        $intent = $llmData['intent'];

        // 1. Validasi Produk
        $rawProduct = trim((string) $llmData['product']);
        $activeProducts = $this->productModel->getAllActive();
        $matchedProduct = $this->normalizer->normalizeProduct($rawProduct, $activeProducts);

        if ($matchedProduct === null) {
            return [
                'status'                => 'rejected',
                'stage'                 => 'entity',
                'reason'                => "Validasi Gagal: Entitas '{$rawProduct}' tidak ditemukan di database",
                'requires_confirmation' => false,
                'normalized'            => null,
            ];
        }

        $activeWarehouses = $this->warehouseModel->getAllActive();
        $matchedSrc = null;
        $matchedDst = null;

        // 2. Validasi Gudang Asal
        $needsSource = in_array($intent, ['STOCK_OUT', 'TRANSFER_STOCK'], true);
        $optionalSource = ($intent === 'CHECK_STOCK' && !empty($llmData['source_warehouse']));

        if ($needsSource || $optionalSource) {
            $rawSrc = trim((string) $llmData['source_warehouse']);
            $matchedSrc = $this->normalizer->normalizeWarehouse($rawSrc, $activeWarehouses);

            if ($matchedSrc === null) {
                return [
                    'status'                => 'rejected',
                    'stage'                 => 'entity',
                    'reason'                => "Validasi Gagal: Entitas '{$rawSrc}' tidak ditemukan di database",
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        }

        // 3. Validasi Gudang Tujuan
        if (in_array($intent, ['STOCK_IN', 'TRANSFER_STOCK'], true)) {
            $rawDst = trim((string) $llmData['destination_warehouse']);
            $matchedDst = $this->normalizer->normalizeWarehouse($rawDst, $activeWarehouses);

            if ($matchedDst === null) {
                return [
                    'status'                => 'rejected',
                    'stage'                 => 'entity',
                    'reason'                => "Validasi Gagal: Entitas '{$rawDst}' tidak ditemukan di database",
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        }

        return [
            'product'               => $matchedProduct,
            'source_warehouse'      => $matchedSrc,
            'destination_warehouse' => $matchedDst,
        ];
    }

    /**
     * Memvalidasi izin hak akses peran pengguna terhadap intent.
     */
    private function validatePermission(string $userRole, string $intent): ?array
    {
        $role = strtoupper(trim($userRole));
        if (!isset(self::ROLE_PERMISSIONS[$role])) {
            return [
                'status'                => 'rejected',
                'stage'                 => 'permission',
                'reason'                => "Validasi Gagal: Peran pengguna '{$userRole}' tidak memiliki izin",
                'requires_confirmation' => false,
                'normalized'            => null,
            ];
        }

        if (!in_array($intent, self::ROLE_PERMISSIONS[$role], true)) {
            return [
                'status'                => 'rejected',
                'stage'                 => 'permission',
                'reason'                => "Validasi Gagal: Peran '{$userRole}' tidak diizinkan melakukan '{$intent}'",
                'requires_confirmation' => false,
                'normalized'            => null,
            ];
        }

        return null;
    }

    /**
     * Memvalidasi aturan bisnis (Business Rules).
     */
    private function validateBusinessRule(
        array $llmData,
        array $product,
        ?array $srcWarehouse,
        ?array $dstWarehouse
    ): array {
        $intent = $llmData['intent'];
        $productUnit = (string) $product['unit'];

        // 1. Normalisasi dan validasi unit
        $rawUnit = $llmData['unit'] !== null ? trim((string) $llmData['unit']) : '';
        if ($rawUnit === '') {
            $effectiveUnit = $productUnit;
        } else {
            $normalizedInputUnit = $this->normalizeUnitAlias($rawUnit);
            if ($normalizedInputUnit !== $productUnit) {
                return [
                    'status'                => 'rejected',
                    'stage'                 => 'business_rule',
                    'reason'                => "Validasi Gagal: Satuan '{$rawUnit}' tidak sesuai dengan produk ('{$productUnit}')",
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
            $effectiveUnit = $normalizedInputUnit;
        }

        // 2. CHECK_STOCK: Tidak memerlukan validasi qty atau stok sumber
        if ($intent === 'CHECK_STOCK') {
            $srcStock = null;
            if ($srcWarehouse !== null) {
                $srcStock = $this->stockModel->getStock((int) $product['id'], (int) $srcWarehouse['id']);
            }

            return [
                'status'                => 'ok',
                'stage'                 => null,
                'reason'                => '',
                'requires_confirmation' => false,
                'normalized'            => [
                    'intent'              => $intent,
                    'product_id'          => (int) $product['id'],
                    'product_name'        => (string) $product['product_name'],
                    'unit'                => $effectiveUnit,
                    'qty'                 => null,
                    'source_warehouse_id' => $srcWarehouse !== null ? (int) $srcWarehouse['id'] : null,
                    'dest_warehouse_id'   => null,
                    'source_stock'        => $srcStock,
                ],
            ];
        }

        // 3. Validasi kuantitas > 0
        $qty = (float) $llmData['quantity'];
        if ($qty <= 0) {
            return [
                'status'                => 'rejected',
                'stage'                 => 'business_rule',
                'reason'                => 'Kuantitas harus lebih besar dari 0',
                'requires_confirmation' => false,
                'normalized'            => null,
            ];
        }

        // 4. Validasi bentuk kuantitas berdasarkan unit
        if ($effectiveUnit === 'batang') {
            // Batang harus bilangan bulat
            if (fmod($qty, 1.0) !== 0.0) {
                return [
                    'status'                => 'rejected',
                    'stage'                 => 'business_rule',
                    'reason'                => 'Satuan batang wajib berupa bilangan bulat',
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        } elseif ($effectiveUnit === 'm3') {
            // m3 maksimal 3 desimal (periksa selisih pembulatan)
            $qtyMilli = (int) round($qty * 1000);
            $qtyTenThousand = (int) round($qty * 10000);
            if ($qtyTenThousand !== ($qtyMilli * 10)) {
                return [
                    'status'                => 'rejected',
                    'stage'                 => 'business_rule',
                    'reason'                => 'Satuan m3 maksimal 3 desimal',
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        }

        // 5. Validasi Transfer: Asal tidak boleh sama dengan tujuan
        if ($intent === 'TRANSFER_STOCK') {
            if ($srcWarehouse !== null && $dstWarehouse !== null && (int) $srcWarehouse['id'] === (int) $dstWarehouse['id']) {
                return [
                    'status'                => 'rejected',
                    'stage'                 => 'business_rule',
                    'reason'                => 'Gudang asal dan tujuan tidak boleh sama',
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        }

        // 6. Validasi kecukupan stok sumber untuk STOCK_OUT dan TRANSFER_STOCK
        $sourceStock = null;
        if (in_array($intent, ['STOCK_OUT', 'TRANSFER_STOCK'], true) && $srcWarehouse !== null) {
            $sourceStock = $this->stockModel->getStock((int) $product['id'], (int) $srcWarehouse['id']);

            // Bandingkan angka sebagai integer milli-unit
            $availMilli = (int) round($sourceStock * 1000);
            $reqMilli   = (int) round($qty * 1000);

            if ($availMilli < $reqMilli) {
                $availDisplay = (fmod($sourceStock, 1.0) === 0.0) ? (int) $sourceStock : $sourceStock;
                $reqDisplay   = (fmod($qty, 1.0) === 0.0) ? (int) $qty : $qty;

                return [
                    'status'                => 'rejected',
                    'stage'                 => 'business_rule',
                    'reason'                => "Validasi Gagal: Stok tidak cukup (tersedia: {$availDisplay} {$effectiveUnit}, diminta: {$reqDisplay} {$effectiveUnit})",
                    'requires_confirmation' => false,
                    'normalized'            => null,
                ];
            }
        }

        return [
            'status'                => 'ok',
            'stage'                 => null,
            'reason'                => '',
            'requires_confirmation' => true,
            'normalized'            => [
                'intent'              => $intent,
                'product_id'          => (int) $product['id'],
                'product_name'        => (string) $product['product_name'],
                'unit'                => $effectiveUnit,
                'qty'                 => $qty,
                'source_warehouse_id' => $srcWarehouse !== null ? (int) $srcWarehouse['id'] : null,
                'dest_warehouse_id'   => $dstWarehouse !== null ? (int) $dstWarehouse['id'] : null,
                'source_stock'        => $sourceStock,
            ],
        ];
    }

    /**
     * Menormalisasi alias satuan (misal btg -> batang, kubik -> m3).
     */
    private function normalizeUnitAlias(string $unit): string
    {
        $clean = mb_strtolower(trim($unit));
        return match ($clean) {
            'btg', 'batang'      => 'batang',
            'kubik', 'm3', 'm^3' => 'm3',
            default              => $clean,
        };
    }
}
