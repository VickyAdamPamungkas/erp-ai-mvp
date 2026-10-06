<?php

session_start();
header('Content-Type: application/json');


// ==========================================
// CEK AUTENTIKASI
// ==========================================

if (!isset($_SESSION['user_id'])) {

    http_response_code(401);

    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}


// ==========================================
// DATABASE & MOCK LLM
// ==========================================

require_once '../config/database.php';
require_once 'mock_llm.php';


// ==========================================
// AMBIL INPUT
// ==========================================

$input = trim($_POST['message'] ?? '');

if ($input === '') {

    echo json_encode([
        'ok' => false,
        'message' => 'Instruksi kosong.'
    ]);

    exit;
}


// ==========================================
// INTERPRETASI INPUT
// ==========================================

$result = mockLLM($input);

$intent = $result['intent'];
$params = $result['parameters'];


// ==========================================
// INTENT TIDAK DIKENALI
// ==========================================

if (!$intent) {

    echo json_encode([
        'ok' => true,
        'stage' => 'interpretation',
        'valid' => false,
        'message' =>
            'MVP demo belum mengenali instruksi ini. Coba: cek stok, catat material masuk, atau pindahkan stok.'
    ]);

    exit;
}


// ==========================================
// HELPER: CARI MATERIAL
// ==========================================

function findMaterial($pdo, $name)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM materials
        WHERE LOWER(name) = LOWER(?)
        LIMIT 1
    ");

    $stmt->execute([$name]);

    return $stmt->fetch();
}


// ==========================================
// HELPER: CARI LOKASI
// ==========================================

function findLocation($pdo, $name)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM locations
        WHERE LOWER(name) = LOWER(?)
        LIMIT 1
    ");

    $stmt->execute([$name]);

    return $stmt->fetch();
}


// ==========================================
// CHECK SELURUH STOCK
// ==========================================
// Tidak membutuhkan material/lokasi tertentu.
// Karena itu harus diproses SEBELUM validation
// yang mencari material dan lokasi.
// ==========================================

if ($intent === 'check_all_stock') {

    $stmt = $pdo->query("
        SELECT
            m.name AS material,
            m.unit,
            l.name AS location,
            COALESCE(s.quantity, 0) AS quantity
        FROM stock s
        INNER JOIN materials m
            ON s.material_id = m.id
        INNER JOIN locations l
            ON s.location_id = l.id
        ORDER BY m.name, l.name
    ");

    $stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // Tidak ada stok
    if (!$stocks) {

        echo json_encode([
            'ok' => true,
            'stage' => 'completed',
            'intent' => 'check_all_stock',
            'message' => 'Belum ada data stok.'
        ]);

        exit;
    }


    // Buat tampilan
    $message = '<b>Seluruh Stok</b><br><br>';


    foreach ($stocks as $stock) {

        $message .=
            htmlspecialchars($stock['material'])
            . ' - '
            . htmlspecialchars($stock['location'])
            . ': '
            . number_format(
                (float)$stock['quantity'],
                2
            )
            . ' '
            . htmlspecialchars($stock['unit'])
            . '<br>';
    }


    echo json_encode([
        'ok' => true,
        'stage' => 'completed',
        'intent' => 'check_all_stock',
        'stocks' => $stocks,
        'message' => $message
    ]);

    exit;
}


// ==========================================
// PROSES VALIDASI
// ==========================================

$validation = [];
$valid = true;


// ==========================================
// CARI MATERIAL
// ==========================================

$material = null;

if (!empty($params['material'])) {

    $material = findMaterial(
        $pdo,
        $params['material']
    );

    if (!$material) {

        $valid = false;

        $validation[] =
            'Material tidak ditemukan.';
    }

} else {

    $valid = false;

    $validation[] =
        'Material tidak ditentukan.';
}


// ==========================================
// VALIDASI CHECK STOCK
// ==========================================

$loc = null;

if ($intent === 'check_stock') {

    if (empty($params['location'])) {

        $valid = false;

        $validation[] =
            'Lokasi tidak ditentukan.';

    } else {

        $loc = findLocation(
            $pdo,
            $params['location']
        );

        if (!$loc) {

            $valid = false;

            $validation[] =
                'Lokasi tidak ditemukan.';
        }
    }
}


// ==========================================
// VALIDASI STOCK IN
// ==========================================

if ($intent === 'stock_in') {

    if (empty($params['location'])) {

        $valid = false;

        $validation[] =
            'Lokasi tidak ditentukan.';

    } else {

        $loc = findLocation(
            $pdo,
            $params['location']
        );

        if (!$loc) {

            $valid = false;

            $validation[] =
                'Lokasi tidak ditemukan.';
        }
    }


    // Quantity
    if (($params['quantity'] ?? 0) <= 0) {

        $valid = false;

        $validation[] =
            'Quantity harus lebih besar dari 0.';
    }
}


// ==========================================
// VALIDASI STOCK TRANSFER
// ==========================================

if ($intent === 'stock_transfer') {

    $src = null;
    $dst = null;


    // Cari lokasi sumber
    if (!empty($params['source'])) {

        $src = findLocation(
            $pdo,
            $params['source']
        );
    }


    // Cari lokasi tujuan
    if (!empty($params['destination'])) {

        $dst = findLocation(
            $pdo,
            $params['destination']
        );
    }


    // Cek lokasi
    if (!$src || !$dst) {

        $valid = false;

        $validation[] =
            'Lokasi sumber/destinasi tidak ditemukan.';
    }


    // Sumber dan tujuan tidak boleh sama
    if (
        $src &&
        $dst &&
        $src['id'] === $dst['id']
    ) {

        $valid = false;

        $validation[] =
            'Lokasi sumber dan tujuan harus berbeda.';
    }


    // Quantity
    if (($params['quantity'] ?? 0) <= 0) {

        $valid = false;

        $validation[] =
            'Quantity harus lebih besar dari 0.';
    }


    // Cek stok sumber
    if ($material && $src) {

        $stmt = $pdo->prepare("
            SELECT quantity
            FROM stock
            WHERE material_id = ?
            AND location_id = ?
        ");

        $stmt->execute([
            $material['id'],
            $src['id']
        ]);

        $q = $stmt->fetchColumn();


        if (
            $q === false ||
            $q < $params['quantity']
        ) {

            $valid = false;

            $validation[] =
                'Stok sumber tidak mencukupi.';
        }
    }
}


// ==========================================
// SIMPAN REQUEST AI
// ==========================================

$stmt = $pdo->prepare("
    INSERT INTO ai_requests
    (
        user_input,
        intent,
        parameters_json,
        validation_status,
        validation_message
    )
    VALUES (?, ?, ?, ?, ?)
");

$stmt->execute([
    $input,
    $intent,
    json_encode($params),
    $valid ? 'VALID' : 'INVALID',
    implode(' ', $validation)
]);


// ==========================================
// AMBIL REQUEST ID
// ==========================================

$requestId = $pdo->lastInsertId();


// ==========================================
// VALIDASI GAGAL
// ==========================================

if (!$valid) {

    echo json_encode([
        'ok' => true,
        'stage' => 'validation',
        'valid' => false,
        'request_id' => $requestId,
        'intent' => $intent,
        'parameters' => $params,
        'message' => implode(
            ' ',
            $validation
        )
    ]);

    exit;
}


// ==========================================
// CHECK STOCK
// ==========================================

if ($intent === 'check_stock') {

    $stmt = $pdo->prepare("
        SELECT quantity
        FROM stock
        WHERE material_id = ?
        AND location_id = ?
    ");

    $stmt->execute([
        $material['id'],
        $loc['id']
    ]);

    $quantity = $stmt->fetchColumn();


    if ($quantity === false) {
        $quantity = 0;
    }


    echo json_encode([
        'ok' => true,
        'stage' => 'completed',
        'valid' => true,
        'intent' => $intent,
        'material' => $material['name'],
        'location' => $loc['name'],
        'quantity' => (float)$quantity,
        'unit' => $material['unit'],
        'message' =>
            "Stok {$material['name']} di {$loc['name']} adalah {$quantity} {$material['unit']}."
    ]);

    exit;
}


// ==========================================
// OPERASI YANG MEMBUTUHKAN KONFIRMASI
// ==========================================

echo json_encode([
    'ok' => true,
    'stage' => 'confirmation',
    'valid' => true,
    'requires_confirmation' => true,
    'request_id' => $requestId,
    'intent' => $intent,
    'parameters' => $params,
    'message' =>
        'Instruksi valid. Silakan konfirmasi sebelum transaksi dijalankan.'
]);

exit;