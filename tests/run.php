<?php

/**
 * WoodMind ERP — Test Runner Utama
 *
 * Menjalankan seluruh test suite aplikasi tanpa ketergantungan library/framework luar.
 * Exit code 0 jika semua suite lulus, 1 jika ada yang gagal.
 *
 * Penggunaan:
 *   php tests/run.php
 */

$phpBinary = PHP_BINARY ?: 'php';
$tests = [
    'ValidationLayer Test Suite'   => __DIR__ . '/ValidationLayerTest.php',
    'Models & Normalizer DB Suite' => __DIR__ . '/unit_models_test.php',
    'ChatController & Audit Suite' => __DIR__ . '/chat_controller_test.php',
];

echo "\n" . str_repeat('#', 90) . "\n";
echo "  WOODMIND ERP - TEST RUNNER (ALL SUITES)\n";
echo str_repeat('#', 90) . "\n\n";

$allPassed = true;
$totalSuites = count($tests);
$passedSuites = 0;

foreach ($tests as $suiteName => $filePath) {
    if (!file_exists($filePath)) {
        echo "[-] SUITE NOT FOUND: {$suiteName} ({$filePath})\n";
        $allPassed = false;
        continue;
    }

    echo ">>> RUNNING: {$suiteName} ...\n";
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($filePath);
    passthru($cmd, $exitCode);

    if ($exitCode === 0) {
        $passedSuites++;
        echo ">>> SUCCESS: {$suiteName} PASSED.\n\n";
    } else {
        $allPassed = false;
        echo ">>> FAILURE: {$suiteName} FAILED (Exit Code: {$exitCode}).\n\n";
    }
}

echo str_repeat('#', 90) . "\n";
echo sprintf("  RINGKASAN RUNNER: %d/%d Suite Lolos\n", $passedSuites, $totalSuites);
echo str_repeat('#', 90) . "\n\n";

if (!$allPassed) {
    exit(1);
}

exit(0);
