<?php

/**
 * Smoke test untuk LlmService.
 *
 * Membaca tests/fixtures/llm_cases.json, memanggil LlmService::extract()
 * untuk setiap kasus, dan mencetak tabel PASS/FAIL beserta statistik.
 *
 * Tidak mengakses database.
 * Exit code 1 jika ada FAIL.
 *
 * Penggunaan:
 *   C:\xampp\php\php.exe tests/llm_smoke.php
 */

require_once __DIR__ . '/../app/Services/LlmService.php';

// =========================================================================
// MUAT FIXTURE
// =========================================================================

$fixturePath = __DIR__ . '/fixtures/llm_cases.json';

if (!file_exists($fixturePath)) {
    fwrite(STDERR, "FATAL: Fixture tidak ditemukan: {$fixturePath}\n");
    exit(1);
}

$fixtureRaw = file_get_contents($fixturePath);
$cases       = json_decode($fixtureRaw, true);

if (!is_array($cases) || count($cases) === 0) {
    fwrite(STDERR, "FATAL: Fixture kosong atau bukan JSON array valid.\n");
    exit(1);
}

// =========================================================================
// JALANKAN TES
// =========================================================================

$llm = new LlmService();

$results    = [];
$passCount  = 0;
$failCount  = 0;
$latencies  = [];

echo "\n";
echo str_repeat('=', 120) . "\n";
echo "  LLM SMOKE TEST\n";
echo str_repeat('=', 120) . "\n";

// Header tabel
echo sprintf(
    "  %-4s %-18s %-40s %-18s %-18s %-6s %s\n",
    '#',
    'Category',
    'Text',
    'Expected',
    'Actual',
    'Result',
    'Latency'
);
echo str_repeat('-', 120) . "\n";

foreach ($cases as $i => $case) {
    $num            = $i + 1;
    $text           = $case['text'] ?? '';
    $expectedIntent = $case['expected_intent'] ?? '';
    $category       = $case['category'] ?? '';

    // Panggil LlmService
    $result = $llm->extract($text);

    // Tentukan actual intent
    $actualIntent = '';
    if ($result['ok'] && is_array($result['data'])) {
        $actualIntent = $result['data']['intent'] ?? 'MISSING_INTENT';
    } else {
        $actualIntent = 'ERROR:' . ($result['error'] ?? 'UNKNOWN');
    }

    // Bandingkan
    $passed = ($actualIntent === $expectedIntent);

    if ($passed) {
        $passCount++;
        $badge = 'PASS';
    } else {
        $failCount++;
        $badge = 'FAIL';
    }

    $latencyMs = $result['latency_ms'];
    $latencies[] = $latencyMs;

    // Truncate text untuk tampilan
    $displayText = mb_strlen($text) > 37
        ? mb_substr($text, 0, 37) . '...'
        : $text;

    echo sprintf(
        "  %-4s %-18s %-40s %-18s %-18s %-6s %s\n",
        $num,
        $category,
        $displayText,
        $expectedIntent,
        $actualIntent,
        $badge,
        $latencyMs . 'ms'
    );
}

// =========================================================================
// RINGKASAN
// =========================================================================

$total      = count($cases);
$accuracy   = $total > 0 ? round(($passCount / $total) * 100, 1) : 0;
$avgLatency = count($latencies) > 0 ? (int) round(array_sum($latencies) / count($latencies)) : 0;
$maxLatency = count($latencies) > 0 ? max($latencies) : 0;

echo str_repeat('-', 120) . "\n";
echo "\n";
echo "  Intent Accuracy : {$passCount}/{$total} ({$accuracy}%)\n";
echo "  Avg Latency     : {$avgLatency}ms\n";
echo "  Max Latency     : {$maxLatency}ms\n";
echo "\n";
echo str_repeat('=', 120) . "\n";

// =========================================================================
// EXIT CODE
// =========================================================================

if ($failCount > 0) {
    echo "\n  ** {$failCount} kasus FAIL. Exit code 1. **\n\n";
    exit(1);
}

echo "\n  Semua kasus PASS.\n\n";
exit(0);
