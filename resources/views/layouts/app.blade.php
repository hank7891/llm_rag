<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($autoRefresh ?? false)
        <meta http-equiv="refresh" content="3">
    @endif
    <title>@yield('title') · 內部知識庫</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
            <a href="{{ route('documents.index') }}" class="text-lg font-semibold text-slate-900">內部知識庫</a>
            <nav class="flex gap-6 text-sm">
                <a href="{{ route('documents.index') }}" class="{{ request()->routeIs('documents.index', 'documents.show') ? 'text-indigo-600 font-medium' : 'text-slate-500 hover:text-slate-900' }}">文件列表</a>
                <a href="{{ route('documents.create') }}" class="{{ request()->routeIs('documents.create') ? 'text-indigo-600 font-medium' : 'text-slate-500 hover:text-slate-900' }}">上傳文件</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-8">
        @if (session('success'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
