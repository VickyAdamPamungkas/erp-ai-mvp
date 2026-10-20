<?php

/**
 * NameNormalizer — Service pembantu pencocokan nama entitas (produk & gudang).
 *
 * Sesuai arsitektur WoodMind ERP:
 * - TIDAK memiliki akses ke database (menerima array kandidat resmi dari Model).
 * - Menangani variasi penulisan: exact match, case-insensitive, penghapusan prefix,
 *   dan fuzzy match dengan toleransi Levenshtein distance <= 2.
 * - Fail-closed: Mengembalikan null jika jarak > 2 atau terdapat ambiguitas.
 */
class NameNormalizer
{
    /** Ambang batas maksimal jarak Levenshtein untuk typo yang ditoleransi. */
    private const MAX_DISTANCE = 2;

    /**
     * Menormalisasi input nama produk terhadap daftar produk aktif.
     *
     * @param string $rawInput
     * @param array<int, array{id: int, product_name: string, unit: string}> $activeProducts
     * @return array{id: int, product_name: string, unit: string}|null
     */
    public function normalizeProduct(string $rawInput, array $activeProducts): ?array
    {
        return $this->matchCandidate($rawInput, $activeProducts, 'product_name', 'kayu');
    }

    /**
     * Menormalisasi input nama gudang terhadap daftar gudang aktif.
     *
     * @param string $rawInput
     * @param array<int, array{id: int, warehouse_name: string}> $activeWarehouses
     * @return array{id: int, warehouse_name: string}|null
     */
    public function normalizeWarehouse(string $rawInput, array $activeWarehouses): ?array
    {
        return $this->matchCandidate($rawInput, $activeWarehouses, 'warehouse_name', 'gudang');
    }

    /**
     * Logika inti pencocokan entitas.
     *
     * @param string $rawInput
     * @param array<int, array<string, mixed>> $candidates
     * @param string $field Nama field kunci dalam array kandidat ('product_name' / 'warehouse_name')
     * @param string $prefix Prefix umum yang mungkin dihilangkan pengguna ('kayu' / 'gudang')
     * @return array<string, mixed>|null
     */
    private function matchCandidate(string $rawInput, array $candidates, string $field, string $prefix): ?array
    {
        $raw = trim($rawInput);
        if ($raw === '' || empty($candidates)) {
            return null;
        }

        $inputClean = preg_replace('/\s+/', ' ', mb_strtolower($raw));
        $inputBase  = trim(preg_replace('/^' . preg_quote($prefix, '/') . '\s+/i', '', $inputClean));

        // 1. Exact Match: Cek pencocokan persis pada nama lengkap
        foreach ($candidates as $candidate) {
            $candClean = preg_replace('/\s+/', ' ', mb_strtolower($candidate[$field] ?? ''));
            if ($inputClean === $candClean) {
                return $candidate;
            }
        }

        // 2. Exact Base Match: Cek pencocokan persis pada nama dasar (misal "jati" vs "Kayu Jati")
        if ($inputBase !== '') {
            $baseMatches = [];
            foreach ($candidates as $candidate) {
                $candClean = preg_replace('/\s+/', ' ', mb_strtolower($candidate[$field] ?? ''));
                $candBase  = trim(preg_replace('/^' . preg_quote($prefix, '/') . '\s+/i', '', $candClean));
                if ($candBase !== '' && $inputBase === $candBase) {
                    $baseMatches[] = $candidate;
                }
            }

            if (count($baseMatches) === 1) {
                return $baseMatches[0];
            }
            if (count($baseMatches) > 1) {
                // Ambigu: lebih dari satu kandidat memiliki nama dasar yang sama persis
                return null;
            }
        }

        // 3. Fuzzy Match: Hitung jarak Levenshtein minimum
        $bestDistance   = null;
        $bestCandidates = [];

        foreach ($candidates as $candidate) {
            $candClean = preg_replace('/\s+/', ' ', mb_strtolower($candidate[$field] ?? ''));
            $candBase  = trim(preg_replace('/^' . preg_quote($prefix, '/') . '\s+/i', '', $candClean));

            $dFull = levenshtein($inputClean, $candClean);

            $dBase = PHP_INT_MAX;
            if ($inputBase !== '' && $candBase !== '') {
                $dBase = levenshtein($inputBase, $candBase);
            }

            $d = min($dFull, $dBase);

            if ($bestDistance === null || $d < $bestDistance) {
                $bestDistance   = $d;
                $bestCandidates = [$candidate];
            } elseif ($d === $bestDistance) {
                $bestCandidates[] = $candidate;
            }
        }

        // Jika jarak terkecil melebihi batas toleransi, tolak (typo berat / tidak cocok)
        if ($bestDistance === null || $bestDistance > self::MAX_DISTANCE) {
            return null;
        }

        // Jika ambigu (ada 2 atau lebih kandidat dengan jarak terbaik yang sama), tolak
        if (count($bestCandidates) !== 1) {
            return null;
        }

        return $bestCandidates[0];
    }
}
