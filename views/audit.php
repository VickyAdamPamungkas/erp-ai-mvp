<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Log Transaksi | WoodMind ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 text-slate-800 font-['Inter',sans-serif] antialiased">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar Navigasi -->
        <aside class="w-64 bg-slate-900 text-white flex flex-col justify-between shrink-0 shadow-xl z-20">
            <div>
                <!-- Brand Header -->
                <div class="p-6 border-b border-slate-800 flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white font-bold text-lg shadow-md">
                        WM
                    </div>
                    <div>
                        <h1 class="font-bold text-base tracking-wide text-white">WoodMind ERP</h1>
                        <p class="text-xs text-indigo-300 font-medium tracking-wider uppercase">Controlled AI Layer</p>
                    </div>
                </div>

                <!-- Navigation Links -->
                <nav class="p-4 space-y-1.5 text-sm font-medium">
                    <a href="chat" class="flex items-center space-x-3 px-4 py-3 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition group">
                        <span class="text-lg">🤖</span>
                        <span>AI Assistant</span>
                    </a>
                    <a href="audit" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-indigo-600 text-white font-semibold shadow-md shadow-indigo-600/30">
                        <span class="text-lg">📋</span>
                        <span>Audit Log</span>
                    </a>
                </nav>
            </div>

            <!-- User Info & Logout -->
            <div class="p-4 border-t border-slate-800 bg-slate-950/40">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3 truncate">
                        <div class="w-9 h-9 rounded-full bg-indigo-500/20 border border-indigo-400/30 text-indigo-300 flex items-center justify-center font-bold text-xs uppercase">
                            <?= htmlspecialchars(substr($_SESSION['username'] ?? 'U', 0, 2), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="truncate">
                            <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></p>
                            <span class="inline-block px-1.5 py-0.5 text-[10px] font-bold rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 uppercase">
                                <?= htmlspecialchars($_SESSION['role'] ?? 'SUPERVISOR', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                    <a href="logout" title="Logout" class="p-2 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50">
            <!-- Header Top Bar -->
            <header class="h-16 bg-white border-b border-slate-200 px-8 flex items-center justify-between shrink-0 shadow-sm">
                <div>
                    <h2 class="text-xl font-bold text-slate-800">Tabel Audit Log Terpusat</h2>
                    <p class="text-xs text-slate-500">Append-only compliance log untuk setiap interaksi LLM & eksekusi stok</p>
                </div>
                <div class="flex items-center space-x-2 text-xs text-slate-500 bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Immutable Trigger Active</span>
                </div>
            </header>

            <!-- Scrollable Content -->
            <div class="flex-1 overflow-y-auto p-8 space-y-6">
                <!-- Metrics Summary Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Request</p>
                        <p class="text-2xl font-bold text-slate-800 mt-1"><?= (int) ($stats['total'] ?? 0) ?></p>
                        <span class="text-[11px] text-slate-400">Seluruh prompt tercatat</span>
                    </div>
                    <div class="bg-white p-5 rounded-2xl border border-emerald-100 shadow-sm bg-gradient-to-br from-emerald-50/40 to-white">
                        <p class="text-xs font-semibold text-emerald-600 uppercase tracking-wider">Executed</p>
                        <p class="text-2xl font-bold text-emerald-700 mt-1"><?= (int) ($stats['executed'] ?? 0) ?></p>
                        <span class="text-[11px] text-emerald-600/70">Transaksi/Cek Berhasil</span>
                    </div>
                    <div class="bg-white p-5 rounded-2xl border border-rose-100 shadow-sm bg-gradient-to-br from-rose-50/40 to-white">
                        <p class="text-xs font-semibold text-rose-600 uppercase tracking-wider">Rejected</p>
                        <p class="text-2xl font-bold text-rose-700 mt-1"><?= (int) ($stats['rejected'] ?? 0) ?></p>
                        <span class="text-[11px] text-rose-600/70">Ditolak Validasi/Guard</span>
                    </div>
                    <div class="bg-white p-5 rounded-2xl border border-amber-100 shadow-sm bg-gradient-to-br from-amber-50/40 to-white">
                        <p class="text-xs font-semibold text-amber-600 uppercase tracking-wider">Clarified</p>
                        <p class="text-2xl font-bold text-amber-700 mt-1"><?= (int) ($stats['clarified'] ?? 0) ?></p>
                        <span class="text-[11px] text-amber-600/70">Perintah Ambigu</span>
                    </div>
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm bg-gradient-to-br from-slate-50 to-white">
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Cancelled</p>
                        <p class="text-2xl font-bold text-slate-700 mt-1"><?= (int) ($stats['cancelled'] ?? 0) ?></p>
                        <span class="text-[11px] text-slate-400">Dibatalkan User</span>
                    </div>
                </div>

                <!-- Filter Controls Form -->
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                    <form method="GET" action="audit" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Status Keputusan</label>
                            <select name="decision" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none transition">
                                <option value="">Semua Keputusan</option>
                                <option value="EXECUTED" <?= ($filters['decision'] ?? '') === 'EXECUTED' ? 'selected' : '' ?>>EXECUTED (Sukses)</option>
                                <option value="REJECTED" <?= ($filters['decision'] ?? '') === 'REJECTED' ? 'selected' : '' ?>>REJECTED (Ditolak)</option>
                                <option value="CLARIFIED" <?= ($filters['decision'] ?? '') === 'CLARIFIED' ? 'selected' : '' ?>>CLARIFIED (Klarifikasi)</option>
                                <option value="CANCELLED" <?= ($filters['decision'] ?? '') === 'CANCELLED' ? 'selected' : '' ?>>CANCELLED (Dibatalkan)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Dari Tanggal</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Sampai Tanggal</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none transition">
                        </div>
                        <div class="flex items-center space-x-2">
                            <button type="submit" class="flex-1 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm rounded-xl transition shadow-md shadow-indigo-600/20">
                                Filter Data
                            </button>
                            <a href="audit" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium text-sm rounded-xl transition text-center">
                                Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Table Container -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-5 border-b border-slate-200 flex items-center justify-between">
                        <h3 class="font-bold text-slate-800 text-base">Riwayat Log Transaksi</h3>
                        <span class="text-xs text-slate-500">Menampilkan <?= count($logs ?? []) ?> entri terbaru</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 font-semibold text-xs uppercase tracking-wider">
                                    <th class="py-3.5 px-4">Waktu</th>
                                    <th class="py-3.5 px-4">User</th>
                                    <th class="py-3.5 px-4">Prompt Pengguna</th>
                                    <th class="py-3.5 px-4">Keputusan</th>
                                    <th class="py-3.5 px-4">Keterangan / Alasan</th>
                                    <th class="py-3.5 px-4 text-center">Latensi</th>
                                    <th class="py-3.5 px-4 text-center">Movement ID</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="7" class="py-12 text-center text-slate-400">
                                            <div class="text-3xl mb-2">📄</div>
                                            <p class="font-medium">Tidak ada log transaksi yang sesuai dengan filter.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <?php
                                            $decision = $log['decision'];
                                            $badgeClass = match ($decision) {
                                                'EXECUTED'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                                'REJECTED'  => 'bg-rose-50 text-rose-700 border-rose-200',
                                                'CLARIFIED' => 'bg-amber-50 text-amber-700 border-amber-200',
                                                'CANCELLED' => 'bg-slate-100 text-slate-600 border-slate-200',
                                                default     => 'bg-slate-50 text-slate-500 border-slate-200',
                                            };
                                        ?>
                                        <tr class="hover:bg-slate-50/70 transition">
                                            <td class="py-3.5 px-4 whitespace-nowrap text-xs text-slate-500 font-mono">
                                                <?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td class="py-3.5 px-4 whitespace-nowrap">
                                                <div class="font-medium text-slate-800 text-xs">
                                                    <?= htmlspecialchars($log['username'] ?? 'Anonymous', ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                                <div class="text-[10px] text-slate-400">
                                                    <?= htmlspecialchars($log['role_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </td>
                                            <td class="py-3.5 px-4 max-w-xs truncate font-medium text-slate-900" title="<?= htmlspecialchars($log['raw_prompt'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($log['raw_prompt'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td class="py-3.5 px-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border <?= $badgeClass ?>">
                                                    <?= htmlspecialchars($decision, ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 text-xs text-slate-600 max-w-sm truncate" title="<?= htmlspecialchars($log['reason'] ?? '-', ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($log['reason'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td class="py-3.5 px-4 whitespace-nowrap text-center text-xs font-mono text-slate-500">
                                                <?= $log['latency_ms'] !== null ? (int) $log['latency_ms'] . ' ms' : '-' ?>
                                            </td>
                                            <td class="py-3.5 px-4 whitespace-nowrap text-center text-xs font-mono font-medium text-indigo-600">
                                                <?= $log['movement_id'] !== null ? '#' . (int) $log['movement_id'] : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
