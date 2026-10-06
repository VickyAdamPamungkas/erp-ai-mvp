<?php

session_start();

header('Content-Type: application/json');

// ===============================
// CEK LOGIN
// ===============================

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);

    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized'
    ]);

    exit;
}


// ===============================
// DATABASE
// ===============================

require_once '../config/database.php';


// ===============================
// AMBIL REQUEST ID
// ===============================

$requestId = (int)($_POST['request_id'] ?? 0);

if ($requestId <= 0) {

    echo json_encode([
        'ok' => false,
        'message' => 'Request ID tidak valid.'
    ]);

    exit;
}


// ===============================
// AMBIL AI REQUEST
// ===============================

$stmt = $pdo->prepare("
    SELECT *
    FROM ai_requests
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$requestId]);

$request = $stmt->fetch();


// Request tidak ditemukan
if (!$request) {

    echo json_encode([
        'ok' => false,
        'message' => 'Request tidak ditemukan.'
    ]);

    exit;
}


// Jangan eksekusi request yang sudah pernah dijalankan
if ($request['execution_status'] === 'EXECUTED') {

    echo json_encode([
        'ok' => false,
        'message' => 'Request ini sudah pernah dieksekusi.'
    ]);

    exit;
}


// Request harus valid
if ($request['validation_status'] !== 'VALID') {

    echo json_encode([
        'ok' => false,
        'message' => 'Request tidak lolos validasi.'
    ]);

    exit;
}


// ===============================
// AMBIL PARAMETERS
// ===============================

$params = json_decode(
    $request['parameters_json'],
    true
);

$intent = $request['intent'];


// ===============================
// MULAI TRANSACTION
// ===============================

try {

    $pdo->beginTransaction();


    // ==========================================
    // STOCK IN
    // ==========================================

    if ($intent === 'stock_in') {

        $materialId = null;
        $locationId = null;


        // Cari material
        $stmt = $pdo->prepare("
            SELECT id
            FROM materials
            WHERE LOWER(name) = LOWER(?)
            LIMIT 1
        ");

        $stmt->execute([
            $params['material']
        ]);

        $materialId = $stmt->fetchColumn();


        // Cari lokasi
        $stmt = $pdo->prepare("
            SELECT id
            FROM locations
            WHERE LOWER(name) = LOWER(?)
            LIMIT 1
        ");

        $stmt->execute([
            $params['location']
        ]);

        $locationId = $stmt->fetchColumn();


        if (!$materialId || !$locationId) {

            throw new Exception(
                'Material atau lokasi tidak ditemukan.'
            );
        }


        $quantity = (float)$params['quantity'];


        // ==========================================
        // CEK APAKAH STOCK SUDAH ADA
        // ==========================================

        $stmt = $pdo->prepare("
            SELECT id, quantity
            FROM stock
            WHERE material_id = ?
            AND location_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $materialId,
            $locationId
        ]);

        $stock = $stmt->fetch();


        // Stock sudah ada
        if ($stock) {

            $newQuantity =
                (float)$stock['quantity'] + $quantity;


            $stmt = $pdo->prepare("
                UPDATE stock
                SET quantity = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $newQuantity,
                $stock['id']
            ]);

        }

        // Stock belum ada
        else {

            $stmt = $pdo->prepare("
                INSERT INTO stock
                (
                    material_id,
                    location_id,
                    quantity
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $materialId,
                $locationId,
                $quantity
            ]);

            $newQuantity = $quantity;
        }


        // ==========================================
        // CATAT TRANSAKSI
        // ==========================================

        $stmt = $pdo->prepare("
            INSERT INTO stock_transactions
            (
                transaction_type,
                material_id,
                quantity,
                destination_location_id,
                note
            )
            VALUES
            (
                'IN',
                ?,
                ?,
                ?,
                ?
            )
        ");

        $stmt->execute([
            $materialId,
            $quantity,
            $locationId,
            'AI Controlled Execution'
        ]);


        // ==========================================
        // UPDATE AI REQUEST
        // ==========================================

        $stmt = $pdo->prepare("
            UPDATE ai_requests
            SET
                user_confirmed = 1,
                execution_status = 'EXECUTED'
            WHERE id = ?
        ");

        $stmt->execute([
            $requestId
        ]);


        $pdo->commit();


        echo json_encode([
            'ok' => true,
            'stage' => 'executed',
            'message' =>
                "Transaksi berhasil. Stok bertambah {$quantity} kg. Stok sekarang {$newQuantity} kg.",
            'new_quantity' => $newQuantity
        ]);

        exit;
    }


    // ==========================================
    // STOCK TRANSFER
    // ==========================================

    elseif ($intent === 'stock_transfer') {

        $materialId = null;
        $sourceId = null;
        $destinationId = null;


        // Material
        $stmt = $pdo->prepare("
            SELECT id
            FROM materials
            WHERE LOWER(name) = LOWER(?)
            LIMIT 1
        ");

        $stmt->execute([
            $params['material']
        ]);

        $materialId = $stmt->fetchColumn();


        // Source
        $stmt = $pdo->prepare("
            SELECT id
            FROM locations
            WHERE LOWER(name) = LOWER(?)
            LIMIT 1
        ");

        $stmt->execute([
            $params['source']
        ]);

        $sourceId = $stmt->fetchColumn();


        // Destination
        $stmt = $pdo->prepare("
            SELECT id
            FROM locations
            WHERE LOWER(name) = LOWER(?)
            LIMIT 1
        ");

        $stmt->execute([
            $params['destination']
        ]);

        $destinationId = $stmt->fetchColumn();


        if (!$materialId || !$sourceId || !$destinationId) {

            throw new Exception(
                'Material atau lokasi transfer tidak ditemukan.'
            );
        }


        $quantity = (float)$params['quantity'];


        // ==========================================
        // AMBIL STOK SUMBER
        // ==========================================

        $stmt = $pdo->prepare("
            SELECT id, quantity
            FROM stock
            WHERE material_id = ?
            AND location_id = ?
            FOR UPDATE
        ");

        $stmt->execute([
            $materialId,
            $sourceId
        ]);

        $sourceStock = $stmt->fetch();


        if (!$sourceStock) {

            throw new Exception(
                'Stok sumber tidak ditemukan.'
            );
        }


        if ((float)$sourceStock['quantity'] < $quantity) {

            throw new Exception(
                'Stok sumber tidak mencukupi.'
            );
        }


        // ==========================================
        // KURANGI STOK SUMBER
        // ==========================================

        $stmt = $pdo->prepare("
            UPDATE stock
            SET quantity = quantity - ?
            WHERE id = ?
        ");

        $stmt->execute([
            $quantity,
            $sourceStock['id']
        ]);


        // ==========================================
        // TAMBAH STOK TUJUAN
        // ==========================================

        $stmt = $pdo->prepare("
            SELECT id
            FROM stock
            WHERE material_id = ?
            AND location_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $materialId,
            $destinationId
        ]);

        $destinationStockId = $stmt->fetchColumn();


        if ($destinationStockId) {

            $stmt = $pdo->prepare("
                UPDATE stock
                SET quantity = quantity + ?
                WHERE id = ?
            ");

            $stmt->execute([
                $quantity,
                $destinationStockId
            ]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO stock
                (
                    material_id,
                    location_id,
                    quantity
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $materialId,
                $destinationId,
                $quantity
            ]);
        }


        // ==========================================
        // CATAT TRANSAKSI
        // ==========================================

        $stmt = $pdo->prepare("
            INSERT INTO stock_transactions
            (
                transaction_type,
                material_id,
                quantity,
                source_location_id,
                destination_location_id,
                note
            )
            VALUES
            (
                'TRANSFER',
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

        $stmt->execute([
            $materialId,
            $quantity,
            $sourceId,
            $destinationId,
            'AI Controlled Execution'
        ]);


        // ==========================================
        // UPDATE AI REQUEST
        // ==========================================

        $stmt = $pdo->prepare("
            UPDATE ai_requests
            SET
                user_confirmed = 1,
                execution_status = 'EXECUTED'
            WHERE id = ?
        ");

        $stmt->execute([
            $requestId
        ]);


        $pdo->commit();


        echo json_encode([
            'ok' => true,
            'stage' => 'executed',
            'message' =>
                "Transfer {$quantity} kg berhasil dieksekusi.",
        ]);

        exit;
    }


    // ==========================================
    // INTENT TIDAK DIDUKUNG
    // ==========================================

    else {

        throw new Exception(
            'Intent belum memiliki controlled execution.'
        );
    }


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    // Tandai execution gagal
    $stmt = $pdo->prepare("
        UPDATE ai_requests
        SET
            execution_status = 'FAILED',
            error_type = 'EXECUTION_ERROR'
        WHERE id = ?
    ");

    $stmt->execute([
        $requestId
    ]);


    echo json_encode([
        'ok' => false,
        'stage' => 'execution',
        'message' => $e->getMessage()
    ]);

    exit;
}