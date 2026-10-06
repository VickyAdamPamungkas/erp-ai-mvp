<?php

function mockLLM($input)
{
    $intent = null;
    $params = [];

    // ==========================================
    // NORMALISASI INPUT
    // ==========================================

    $text = strtolower(trim($input));


    // ==========================================
    // DETEKSI MATERIAL
    // ==========================================

    $material = null;

    if (preg_match('/kayu\s+jati/i', $text)) {

        $material = 'kayu jati';

    } elseif (preg_match('/kayu\s+mahoni/i', $text)) {

        $material = 'kayu mahoni';

    } elseif (preg_match('/kayu\s+pinus/i', $text)) {

        $material = 'kayu pinus';
    }


    // ==========================================
    // DETEKSI LOKASI
    // ==========================================

    $locations = [];

    if (preg_match_all(
        '/gudang\s+[ab]/i',
        $text,
        $matches
    )) {

        foreach ($matches[0] as $location) {

            $locations[] = strtolower($location);
        }
    }


    // ==========================================
    // DETEKSI QUANTITY
    // ==========================================

    $quantity = null;

    /*
     * Mendukung:
     *
     * 25 kg
     * 25kg
     * 25 kilo
     * 25kilogram
     * sebanyak 25 kg
     */

    if (preg_match(
        '/(?:sebanyak\s*)?(\d+(?:[.,]\d+)?)\s*(kg|kilo|kilogram)/i',
        $text,
        $match
    )) {

        $quantity = (float) str_replace(
            ',',
            '.',
            $match[1]
        );
    }


    // ==========================================
    // DETEKSI INTENT: CHECK ALL STOCK
    // ==========================================

    if (
        preg_match(
            '/\b(cek|lihat|tampilkan|tunjukkan)\b.*\b(semua|seluruh|total)\b.*\bstok\b/i',
            $text
        )
        ||
        preg_match(
            '/\b(semua|seluruh)\b.*\bstok\b/i',
            $text
        )
    ) {

        $intent = 'check_all_stock';

        $params = [];

    }


    // ==========================================
    // DETEKSI INTENT: CHECK STOCK
    // ==========================================

    elseif (
        preg_match(
            '/\b(berapa|cek|lihat|tampilkan|tersedia|ada)\b/i',
            $text
        )
        &&
        preg_match('/stok/i', $text)
        &&
        $material
        &&
        count($locations) >= 1
    ) {

        $intent = 'check_stock';

        $params = [
            'material' => $material,
            'location' => $locations[0]
        ];

    }


    // ==========================================
    // DETEKSI INTENT: STOCK IN
    // ==========================================

    elseif (
        preg_match(
            '/\b(catat|tambah|tambahkan|masukkan|masukin|input|masuk)\b/i',
            $text
        )
        &&
        $material
        &&
        count($locations) >= 1
        &&
        $quantity !== null
    ) {

        $intent = 'stock_in';

        $params = [
            'quantity' => $quantity,
            'unit' => 'kg',
            'material' => $material,
            'location' => $locations[0]
        ];

    }


    // ==========================================
    // DETEKSI INTENT: STOCK TRANSFER
    // ==========================================

    elseif (
        preg_match(
            '/\b(pindahkan|pindah|transfer)\b/i',
            $text
        )
        &&
        $material
        &&
        count($locations) >= 2
        &&
        $quantity !== null
    ) {

        $intent = 'stock_transfer';

        $params = [
            'quantity' => $quantity,
            'unit' => 'kg',
            'material' => $material,
            'source' => $locations[0],
            'destination' => $locations[1]
        ];
    }


    // ==========================================
    // RETURN HASIL
    // ==========================================

    return [
        'intent' => $intent,
        'parameters' => $params
    ];
}