<?php

/**
 * Unit Test Suite untuk Read-Only Models & NameNormalizer (Langkah 5A - Milestone 3).
 *
 * Menguji:
 * 1. ProductModel (getAllActive, findByName, findActiveByName)
 * 2. WarehouseModel (getAllActive, findByName, findActiveByName)
 * 3. StockModel (getStock, getStockByNames, getStockSummaryByWarehouse)
 * 4. NameNormalizer (exact match, base match, typo ringan, typo berat, ambigu, invalid)
 *
 * Menghubungkan ke database woodmind_erp via config/db.php (port 3307).
 * Exit code 0 jika semua lulus, exit code 1 jika ada yang gagal.
 *
 * Penggunaan:
 *   C:\xampp\php\php.exe tests/unit_models_test.php
 */

require_once __DIR__ . '/../app/Models/ProductModel.php';
require_once __DIR__ . '/../app/Models/WarehouseModel.php';
require_once __DIR__ . '/../app/Models/StockModel.php';
require_once __DIR__ . '/../app/Services/NameNormalizer.php';

// =========================================================================
// KONEKSI DATABASE
// =========================================================================

$configPath = __DIR__ . '/../config/db.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

defined('DB_HOST')    || define('DB_HOST', '127.0.0.1');
defined('DB_PORT')    || define('DB_PORT', 3307);
defined('DB_NAME')    || define('DB_NAME', 'woodmind_erp');
defined('DB_USER')    || define('DB_USER', 'root');
defined('DB_PASS')    || define('DB_PASS', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_CHARSET
);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "FATAL: Gagal terkoneksi ke database: " . $e->getMessage() . "\n");
    exit(1);
}

// =========================================================================
// TEST HARNESS
// =========================================================================

$totalTests = 0;
$passCount  = 0;
$failCount  = 0;
$testGroup  = '';

function runTest(string $description, callable $fn): void
{
    global $totalTests, $passCount, $failCount, $testGroup;

    $totalTests++;
    try {
        $result = $fn();
        if ($result === true) {
            $passCount++;
            echo sprintf("  [PASS] %-20s : %s\n", $testGroup, $description);
        } else {
            $failCount++;
            echo sprintf("  [FAIL] %-20s : %s (Assertion returned false)\n", $testGroup, $description);
        }
    } catch (Throwable $e) {
        $failCount++;
        echo sprintf("  [FAIL] %-20s : %s (Exception: %s)\n", $testGroup, $description, $e->getMessage());
    }
}

echo "\n" . str_repeat('=', 90) . "\n";
echo "  WOODMIND ERP - UNIT TEST LANGKAH 5A (MODELS & NAME NORMALIZER)\n";
echo str_repeat('=', 90) . "\n\n";

// =========================================================================
// 1. PRODUCT MODEL TESTS
// =========================================================================
$testGroup = 'ProductModel';
$productModel = new ProductModel($pdo);

runTest('getAllActive() mengembalikan semua produk aktif', function () use ($productModel) {
    $products = $productModel->getAllActive();
    if (!is_array($products) || count($products) < 5) {
        return false;
    }
    // Cek struktur array item
    $first = $products[0];
    return isset($first['id'], $first['product_name'], $first['unit']);
});

runTest('findByName() dengan nama persis', function () use ($productModel) {
    $product = $productModel->findByName('Kayu Jati');
    return $product !== null && $product['product_name'] === 'Kayu Jati' && $product['unit'] === 'batang';
});

runTest('findByName() bersifat case-insensitive', function () use ($productModel) {
    $product = $productModel->findByName('kayu sengon');
    return $product !== null && $product['product_name'] === 'Kayu Sengon' && $product['unit'] === 'm3';
});

runTest('findByName() mengembalikan null jika produk tidak ada', function () use ($productModel) {
    $product = $productModel->findByName('Produk Antah Berantah');
    return $product === null;
});

runTest('findActiveByName() mengembalikan produk aktif', function () use ($productModel) {
    $product = $productModel->findActiveByName('kayu mahoni');
    return $product !== null && $product['product_name'] === 'Kayu Mahoni' && (int) $product['is_active'] === 1;
});

runTest('findActiveByName() mengembalikan null jika tidak ada', function () use ($productModel) {
    $product = $productModel->findActiveByName('Kayu Fiktif');
    return $product === null;
});

// =========================================================================
// 2. WAREHOUSE MODEL TESTS
// =========================================================================
$testGroup = 'WarehouseModel';
$warehouseModel = new WarehouseModel($pdo);

runTest('getAllActive() mengembalikan semua gudang aktif', function () use ($warehouseModel) {
    $warehouses = $warehouseModel->getAllActive();
    if (!is_array($warehouses) || count($warehouses) < 3) {
        return false;
    }
    $first = $warehouses[0];
    return isset($first['id'], $first['warehouse_name']);
});

runTest('findByName() dengan nama persis', function () use ($warehouseModel) {
    $warehouse = $warehouseModel->findByName('Gudang Utama');
    return $warehouse !== null && $warehouse['warehouse_name'] === 'Gudang Utama';
});

runTest('findByName() bersifat case-insensitive', function () use ($warehouseModel) {
    $warehouse = $warehouseModel->findByName('gudang sisa');
    return $warehouse !== null && $warehouse['warehouse_name'] === 'Gudang Sisa';
});

runTest('findByName() mengembalikan null jika gudang tidak ada', function () use ($warehouseModel) {
    $warehouse = $warehouseModel->findByName('Gudang Bayangan');
    return $warehouse === null;
});

runTest('findActiveByName() mengembalikan gudang aktif', function () use ($warehouseModel) {
    $warehouse = $warehouseModel->findActiveByName('Gudang Pengeringan');
    return $warehouse !== null && $warehouse['warehouse_name'] === 'Gudang Pengeringan' && (int) $warehouse['is_active'] === 1;
});

runTest('findActiveByName() mengembalikan null jika tidak ada', function () use ($warehouseModel) {
    $warehouse = $warehouseModel->findActiveByName('Gudang Fiktif');
    return $warehouse === null;
});

// =========================================================================
// 3. STOCK MODEL TESTS
// =========================================================================
$testGroup = 'StockModel';
$stockModel = new StockModel($pdo);

// Ambil ID dari database untuk uji dinamis
$jati = $productModel->findByName('Kayu Jati');
$meranti = $productModel->findByName('Kayu Meranti');
$utama = $warehouseModel->findByName('Gudang Utama');

runTest('getStock() mengembalikan nilai float stok yang ada (120.0)', function () use ($stockModel, $jati, $utama) {
    $qty = $stockModel->getStock((int) $jati['id'], (int) $utama['id']);
    return is_float($qty) && abs($qty - 120.0) < 0.0001;
});

runTest('getStock() mengembalikan 0.0 jika belum ada baris stok (Kayu Meranti)', function () use ($stockModel, $meranti, $utama) {
    $qty = $stockModel->getStock((int) $meranti['id'], (int) $utama['id']);
    return is_float($qty) && $qty === 0.0;
});

runTest('getStockByNames() dengan nama yang valid (Kayu Jati di Gudang Utama)', function () use ($stockModel) {
    $qty = $stockModel->getStockByNames('Kayu Jati', 'Gudang Utama');
    return is_float($qty) && abs($qty - 120.0) < 0.0001;
});

runTest('getStockByNames() case-insensitive (kayu sengon di gudang utama = 15.5)', function () use ($stockModel) {
    $qty = $stockModel->getStockByNames('kayu sengon', 'gudang utama');
    return is_float($qty) && abs($qty - 15.5) < 0.0001;
});

runTest('getStockByNames() produk tanpa stok mengembalikan 0.0', function () use ($stockModel) {
    $qty = $stockModel->getStockByNames('Kayu Pinus', 'Gudang Utama');
    return is_float($qty) && $qty === 0.0;
});

runTest('getStockByNames() produk/gudang tidak ada mengembalikan 0.0', function () use ($stockModel) {
    $qty = $stockModel->getStockByNames('Bukan Kayu', 'Bukan Gudang');
    return is_float($qty) && $qty === 0.0;
});

runTest('getStockSummaryByWarehouse() mengembalikan daftar stok gudang', function () use ($stockModel, $utama) {
    $summary = $stockModel->getStockSummaryByWarehouse((int) $utama['id']);
    if (!is_array($summary) || count($summary) < 2) {
        return false;
    }
    // Harus ada Kayu Jati dan Kayu Sengon
    $names = array_column($summary, 'product_name');
    return in_array('Kayu Jati', $names, true) && in_array('Kayu Sengon', $names, true);
});

// =========================================================================
// 4. NAME NORMALIZER TESTS
// =========================================================================
$testGroup = 'NameNormalizer';
$normalizer = new NameNormalizer();
$activeProducts = $productModel->getAllActive();
$activeWarehouses = $warehouseModel->getAllActive();

runTest('normalizeProduct() match persis nama lengkap', function () use ($normalizer, $activeProducts) {
    $res = $normalizer->normalizeProduct('Kayu Jati', $activeProducts);
    return $res !== null && $res['product_name'] === 'Kayu Jati';
});

runTest('normalizeProduct() case-insensitive ("kayu mahoni")', function () use ($normalizer, $activeProducts) {
    $res = $normalizer->normalizeProduct('kayu mahoni', $activeProducts);
    return $res !== null && $res['product_name'] === 'Kayu Mahoni';
});

runTest('normalizeProduct() nama dasar tanpa prefix ("jati" -> "Kayu Jati")', function () use ($normalizer, $activeProducts) {
    $res = $normalizer->normalizeProduct('jati', $activeProducts);
    return $res !== null && $res['product_name'] === 'Kayu Jati';
});

runTest('normalizeProduct() typo ringan nama dasar ("jatii" -> "Kayu Jati")', function () use ($normalizer, $activeProducts) {
    $res = $normalizer->normalizeProduct('jatii', $activeProducts);
    return $res !== null && $res['product_name'] === 'Kayu Jati';
});

runTest('normalizeProduct() typo ringan nama lengkap ("kayu jatii" -> "Kayu Jati")', function () use ($normalizer, $activeProducts) {
    $res = $normalizer->normalizeProduct('kayu jatii', $activeProducts);
    return $res !== null && $res['product_name'] === 'Kayu Jati';
});

runTest('normalizeProduct() typo ringan satu huruf ("mahonii" -> "Kayu Mahoni")', function () use ($normalizer, $activeProducts) {
    $res = $normalizer->normalizeProduct('mahonii', $activeProducts);
    return $res !== null && $res['product_name'] === 'Kayu Mahoni';
});

runTest('normalizeProduct() typo berat mengembalikan null ("besi")', function () use ($normalizer, $activeProducts) {
    $res = $normalizer->normalizeProduct('besi', $activeProducts);
    return $res === null;
});

runTest('normalizeProduct() entitas fiktif mengembalikan null ("eboni")', function () use ($normalizer, $activeProducts) {
    $res = $normalizer->normalizeProduct('eboni', $activeProducts);
    return $res === null;
});

runTest('normalizeProduct() input kosong mengembalikan null', function () use ($normalizer, $activeProducts) {
    $res1 = $normalizer->normalizeProduct('', $activeProducts);
    $res2 = $normalizer->normalizeProduct('   ', $activeProducts);
    return $res1 === null && $res2 === null;
});

runTest('normalizeWarehouse() match persis nama lengkap', function () use ($normalizer, $activeWarehouses) {
    $res = $normalizer->normalizeWarehouse('Gudang Utama', $activeWarehouses);
    return $res !== null && $res['warehouse_name'] === 'Gudang Utama';
});

runTest('normalizeWarehouse() nama dasar tanpa prefix ("utama" -> "Gudang Utama")', function () use ($normalizer, $activeWarehouses) {
    $res = $normalizer->normalizeWarehouse('utama', $activeWarehouses);
    return $res !== null && $res['warehouse_name'] === 'Gudang Utama';
});

runTest('normalizeWarehouse() typo ringan nama dasar ("utma" -> "Gudang Utama")', function () use ($normalizer, $activeWarehouses) {
    $res = $normalizer->normalizeWarehouse('utma', $activeWarehouses);
    return $res !== null && $res['warehouse_name'] === 'Gudang Utama';
});

runTest('normalizeWarehouse() typo ringan nama lengkap ("gudang utma" -> "Gudang Utama")', function () use ($normalizer, $activeWarehouses) {
    $res = $normalizer->normalizeWarehouse('gudang utma', $activeWarehouses);
    return $res !== null && $res['warehouse_name'] === 'Gudang Utama';
});

runTest('normalizeWarehouse() typo berat mengembalikan null ("gudang pelabuhan")', function () use ($normalizer, $activeWarehouses) {
    $res = $normalizer->normalizeWarehouse('gudang pelabuhan', $activeWarehouses);
    return $res === null;
});

runTest('normalizeWarehouse() input kosong mengembalikan null', function () use ($normalizer, $activeWarehouses) {
    return $normalizer->normalizeWarehouse('', $activeWarehouses) === null;
});

runTest('Ambiguitas ditolak / fail-closed jika ada kandidat dengan jarak sama', function () use ($normalizer) {
    // Kandidat sintetis dengan jarak yang sama ke input "tes"
    $synthetic = [
        ['product_name' => 'tea'],
        ['product_name' => 'ten'],
    ];
    // levenshtein('tes', 'tea') = 1, levenshtein('tes', 'ten') = 1
    $res = $normalizer->normalizeProduct('tes', $synthetic);
    return $res === null;
});

// =========================================================================
// SUMMARY & EXIT CODE
// =========================================================================

echo "\n" . str_repeat('-', 90) . "\n";
echo sprintf("  Total Tests : %d | Passed: %d | Failed: %d\n", $totalTests, $passCount, $failCount);
echo str_repeat('=', 90) . "\n\n";

if ($failCount > 0) {
    echo "  [HASIL] PENGUJIAN GAGAL: Ada {$failCount} tes yang tidak lolos.\n\n";
    exit(1);
}

echo "  [HASIL] SEMUA TES LOLOS (100% PASS).\n\n";
exit(0);
