<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 - Akses Ditolak | WoodMind ERP</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen text-slate-800">
    <div class="bg-white p-8 rounded-2xl shadow-xl max-w-md w-full text-center border border-slate-200">
        <div class="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl font-bold">
            &times;
        </div>
        <h1 class="text-2xl font-bold text-slate-900 mb-2"><?= htmlspecialchars($errorTitle ?? '403 Akses Ditolak', ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-slate-600 mb-6"><?= htmlspecialchars($errorMessage ?? 'Anda tidak memiliki hak akses untuk membuka halaman ini.', ENT_QUOTES, 'UTF-8') ?></p>
        <a href="chat" class="inline-block px-5 py-2.5 bg-indigo-600 text-white font-medium rounded-xl hover:bg-indigo-700 transition shadow">
            &larr; Kembali ke AI Assistant
        </a>
    </div>
</body>
</html>
