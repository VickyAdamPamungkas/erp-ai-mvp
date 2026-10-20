<?php

/**
 * ValidationLayerTest — Unit test suite untuk ValidationLayer.
 *
 * Menguji seluruh aturan 4-stage validation (Schema, Entity, Permission, Business Rule)
 * menggunakan model tiruan (Fake models) tanpa ketergantungan database fisik atau PHPUnit.
 *
 * Penggunaan:
 *   php tests/ValidationLayerTest.php
 */

require_once __DIR__ . '/../app/Services/ValidationLayer.php';
require_once __DIR__ . '/../app/Services/NameNormalizer.php';
require_once __DIR__ . '/Fakes/FakeProductModel.php';
require_once __DIR__ . '/Fakes/FakeWarehouseModel.php';
require_once __DIR__ . '/Fakes/FakeStockModel.php';

$totalTests = 0;
$passCount  = 0;
$failCount  = 0;
$testGroup  = '';

function assertTest(string $description, callable $fn): void
{
    global $totalTests, $passCount, $failCount, $testGroup;

    $totalTests++;
    try {
        $result = $fn();
        if ($result === true) {
            $passCount++;
            echo sprintf("  [PASS] %-22s : %s\n", $testGroup, $description);
        } else {
            $failCount++;
            echo sprintf("  [FAIL] %-22s : %s (Assertion returned false)\n", $testGroup, $description);
        }
    } catch (Throwable $e) {
        $failCount++;
        echo sprintf("  [FAIL] %-22s : %s (Exception: %s)\n", $testGroup, $description, $e->getMessage());
    }
}

echo "\n" . str_repeat('=', 90) . "\n";
echo "  WOODMIND ERP - VALIDATION LAYER TEST SUITE\n";
echo str_repeat('=', 90) . "\n\n";

// Inisialisasi Fake Models & ValidationLayer
$productModel   = new FakeProductModel();
$warehouseModel = new FakeWarehouseModel();
$stockModel     = new FakeStockModel();
$validator      = new ValidationLayer($productModel, $warehouseModel, $stockModel);

// =========================================================================
// 1. HAPPY PATH & TRANSFER STOCK
// =========================================================================
$testGroup = 'HappyPath / Transfer';

assertTest('1. Valid transfer (ok)', function () use ($validator) {
    $payload = [
        'intent'                => 'TRANSFER_STOCK',
        'product'               => 'Kayu Sengon',
        'quantity'              => 5,
        'unit'                  => 'm3',
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => 'Gudang Sisa',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'ok'
        && $res['stage'] === null
        && $res['requires_confirmation'] === true
        && is_array($res['normalized'])
        && $res['normalized']['product_id'] === 3
        && $res['normalized']['unit'] === 'm3'
        && $res['normalized']['qty'] === 5.0
        && $res['normalized']['source_warehouse_id'] === 1
        && $res['normalized']['dest_warehouse_id'] === 2
        && $res['normalized']['source_stock'] === 15.5;
});

assertTest('2. Transfer stok pas 120 dari 120 (ok)', function () use ($validator) {
    $payload = [
        'intent'                => 'TRANSFER_STOCK',
        'product'               => 'Kayu Jati',
        'quantity'              => 120,
        'unit'                  => 'batang',
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => 'Gudang Sisa',
    ];
    $res = $validator->validate($payload, 'SUPERVISOR');

    return $res['status'] === 'ok'
        && $res['requires_confirmation'] === true
        && $res['normalized']['source_stock'] === 120.0
        && $res['normalized']['qty'] === 120.0;
});

// =========================================================================
// 2. ENTITY VALIDATION
// =========================================================================
$testGroup = 'Entity Validation';

assertTest("3. Produk fiktif 'eboni' ditolak (rejected/entity)", function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_IN',
        'product'               => 'eboni',
        'quantity'              => 10,
        'unit'                  => 'batang',
        'source_warehouse'      => null,
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'ADMIN');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'entity'
        && $res['requires_confirmation'] === false
        && $res['normalized'] === null
        && str_contains($res['reason'], "Entitas 'eboni' tidak ditemukan di database");
});

assertTest("4. Typo 'jatii' cocok via NameNormalizer (ok)", function () use ($validator) {
    $payload = [
        'intent'                => 'CHECK_STOCK',
        'product'               => 'jatii',
        'quantity'              => null,
        'unit'                  => null,
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => null,
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'ok'
        && $res['normalized']['product_id'] === 1
        && $res['normalized']['product_name'] === 'Kayu Jati'
        && $res['normalized']['source_warehouse_id'] === 1
        && $res['normalized']['source_stock'] === 120.0;
});

assertTest('5. Gudang tujuan tidak ada ditolak (rejected/entity)', function () use ($validator) {
    $payload = [
        'intent'                => 'TRANSFER_STOCK',
        'product'               => 'Kayu Jati',
        'quantity'              => 10,
        'unit'                  => 'batang',
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => 'Gudang Gaib',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'entity'
        && str_contains($res['reason'], "Entitas 'Gudang Gaib' tidak ditemukan di database");
});

assertTest('6. Produk nonaktif ditolak (rejected/entity)', function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_IN',
        'product'               => 'Kayu Ulin', // is_active = 0
        'quantity'              => 10,
        'unit'                  => 'batang',
        'source_warehouse'      => null,
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'entity'
        && str_contains($res['reason'], "Entitas 'Kayu Ulin' tidak ditemukan di database");
});

// =========================================================================
// 3. BUSINESS RULE: STOK & KELAYAKAN
// =========================================================================
$testGroup = 'Business Rule / Stock';

assertTest('7. Stok kurang: Mahoni Gudang Sisa 8 diminta 9 (rejected/business_rule)', function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_OUT',
        'product'               => 'Kayu Mahoni',
        'quantity'              => 9,
        'unit'                  => 'batang',
        'source_warehouse'      => 'Gudang Sisa',
        'destination_warehouse' => null,
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'business_rule'
        && str_contains($res['reason'], 'Stok tidak cukup')
        && str_contains($res['reason'], 'tersedia: 8 batang')
        && str_contains($res['reason'], 'diminta: 9 batang');
});

assertTest('8. Kombinasi tanpa baris stok untuk STOCK_OUT (rejected/business_rule)', function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_OUT',
        'product'               => 'Kayu Pinus', // Tidak ada stok (0.0)
        'quantity'              => 5,
        'unit'                  => 'batang',
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => null,
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'business_rule'
        && str_contains($res['reason'], 'Stok tidak cukup')
        && str_contains($res['reason'], 'tersedia: 0 batang')
        && str_contains($res['reason'], 'diminta: 5 batang');
});

assertTest('9. Gudang asal sama dengan tujuan (rejected/business_rule)', function () use ($validator) {
    $payload = [
        'intent'                => 'TRANSFER_STOCK',
        'product'               => 'Kayu Jati',
        'quantity'              => 10,
        'unit'                  => 'batang',
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'business_rule'
        && str_contains($res['reason'], 'tidak boleh sama');
});

// =========================================================================
// 4. BUSINESS RULE: QUANTITY & UNIT
// =========================================================================
$testGroup = 'Quantity & Unit Rules';

assertTest('10a. Qty negatif ditolak (rejected/business_rule)', function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_IN',
        'product'               => 'Kayu Jati',
        'quantity'              => -5,
        'unit'                  => 'batang',
        'source_warehouse'      => null,
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'business_rule'
        && str_contains($res['reason'], 'lebih besar dari 0');
});

assertTest('10b. Qty nol ditolak (rejected/business_rule)', function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_IN',
        'product'               => 'Kayu Jati',
        'quantity'              => 0,
        'unit'                  => 'batang',
        'source_warehouse'      => null,
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'business_rule'
        && str_contains($res['reason'], 'lebih besar dari 0');
});

assertTest("10c. Qty non-numerik 'abc' ditolak (rejected/schema)", function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_IN',
        'product'               => 'Kayu Jati',
        'quantity'              => 'abc',
        'unit'                  => 'batang',
        'source_warehouse'      => null,
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'schema'
        && str_contains($res['reason'], 'numerik');
});

assertTest('11. Qty 2.5 untuk batang ditolak (rejected/business_rule)', function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_IN',
        'product'               => 'Kayu Jati',
        'quantity'              => 2.5,
        'unit'                  => 'batang',
        'source_warehouse'      => null,
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'business_rule'
        && str_contains($res['reason'], 'bilangan bulat');
});

assertTest('12. Qty 2.5 untuk m3 diterima (ok)', function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_IN',
        'product'               => 'Kayu Sengon',
        'quantity'              => 2.5,
        'unit'                  => 'm3',
        'source_warehouse'      => null,
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'ok'
        && $res['normalized']['qty'] === 2.5
        && $res['normalized']['unit'] === 'm3';
});

assertTest("13. Unit 'kg' pada produk batang ditolak (rejected/business_rule)", function () use ($validator) {
    $payload = [
        'intent'                => 'STOCK_IN',
        'product'               => 'Kayu Jati',
        'quantity'              => 10,
        'unit'                  => 'kg',
        'source_warehouse'      => null,
        'destination_warehouse' => 'Gudang Utama',
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'business_rule'
        && str_contains($res['reason'], 'tidak sesuai');
});

// =========================================================================
// 5. SCHEMA & INTENT VALIDATION
// =========================================================================
$testGroup = 'Schema & Intent';

assertTest("14. Intent 'DELETE_STOCK' ditolak (rejected/schema)", function () use ($validator) {
    $payload = [
        'intent'                => 'DELETE_STOCK',
        'product'               => 'Kayu Jati',
        'quantity'              => 5,
        'unit'                  => 'batang',
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => null,
    ];
    $res = $validator->validate($payload, 'ADMIN');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'schema';
});

assertTest('15. TRANSFER tanpa gudang tujuan meminta klarifikasi (clarify/schema)', function () use ($validator) {
    $payload = [
        'intent'                => 'TRANSFER_STOCK',
        'product'               => 'Kayu Jati',
        'quantity'              => 10,
        'unit'                  => 'batang',
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => null,
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'clarify'
        && $res['stage'] === 'schema'
        && str_contains($res['reason'], 'destination_warehouse');
});

assertTest('16. Intent CLARIFY_REQUIRED meminta klarifikasi dengan pesan baku (clarify/schema)', function () use ($validator) {
    $payload = [
        'intent'                => 'CLARIFY_REQUIRED',
        'product'               => null,
        'quantity'              => null,
        'unit'                  => null,
        'source_warehouse'      => null,
        'destination_warehouse' => null,
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'clarify'
        && $res['stage'] === 'schema'
        && $res['reason'] === 'Maaf, perintah Anda kurang lengkap. Tolong sebutkan nama produk, jumlah, dan lokasinya.';
});

assertTest('17. CHECK_STOCK tanpa gudang (ok, requires_confirmation=false)', function () use ($validator) {
    $payload = [
        'intent'                => 'CHECK_STOCK',
        'product'               => 'Kayu Jati',
        'quantity'              => null,
        'unit'                  => null,
        'source_warehouse'      => null,
        'destination_warehouse' => null,
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'ok'
        && $res['requires_confirmation'] === false
        && $res['normalized']['product_id'] === 1
        && $res['normalized']['source_warehouse_id'] === null
        && $res['normalized']['source_stock'] === null;
});

// =========================================================================
// 6. PERMISSION & ROBUSTNESS
// =========================================================================
$testGroup = 'Permission & Extra Keys';

assertTest("18. Peran tidak dikenal 'HACKER' ditolak (rejected/permission)", function () use ($validator) {
    $payload = [
        'intent'                => 'CHECK_STOCK',
        'product'               => 'Kayu Jati',
        'quantity'              => null,
        'unit'                  => null,
        'source_warehouse'      => null,
        'destination_warehouse' => null,
    ];
    $res = $validator->validate($payload, 'HACKER');

    return $res['status'] === 'rejected'
        && $res['stage'] === 'permission'
        && str_contains($res['reason'], 'tidak memiliki izin');
});

assertTest('19. Kunci ekstra di JSON diabaikan dan tidak error (ok)', function () use ($validator) {
    $payload = [
        'intent'                => 'CHECK_STOCK',
        'product'               => 'Kayu Jati',
        'quantity'              => null,
        'unit'                  => null,
        'source_warehouse'      => 'Gudang Utama',
        'destination_warehouse' => null,
        'extra_comment'         => 'ignore me',
        'hack_attempt'          => 12345,
    ];
    $res = $validator->validate($payload, 'STAFF');

    return $res['status'] === 'ok'
        && $res['normalized']['product_id'] === 1;
});

// =========================================================================
// REKAPITULASI
// =========================================================================
echo "\n" . str_repeat('=', 90) . "\n";
echo sprintf("  HASIL PENGUJIAN: Total: %d | Lulus: %d | Gagal: %d\n", $totalTests, $passCount, $failCount);
echo str_repeat('=', 90) . "\n\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
