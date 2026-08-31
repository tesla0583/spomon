<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SpoMon</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900">
    <nav class="bg-white border-b border-gray-200">
        <div class="max-w-6xl mx-auto px-6 py-3 flex items-center gap-6">
            <span class="font-semibold text-lg">SpoMon</span>
            <a href="{{ route('clients.index') }}"
               class="text-sm font-medium {{ request()->routeIs('clients.*') ? 'text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
                Реестр клиентов
            </a>
            <a href="{{ route('stats.index') }}"
               class="text-sm font-medium {{ request()->routeIs('stats.*') ? 'text-blue-600' : 'text-gray-600 hover:text-gray-900' }}">
                Статистика
            </a>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto p-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
