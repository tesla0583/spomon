<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SpoMon</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cpath d='M32 4 L54 12 V30 C54 46 44 56 32 60 C20 56 10 46 10 30 V12 Z' fill='%232563eb' stroke='%231d4ed8' stroke-width='2'/%3E%3Ctext x='32' y='40' font-family='Arial, sans-serif' font-size='30' font-weight='bold' fill='white' text-anchor='middle'%3ES%3C/text%3E%3C/svg%3E">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900">
    <nav class="bg-white border-b border-gray-200">
        <div class="max-w-6xl mx-auto px-6 py-3 flex items-center gap-6">
            <span class="flex items-center gap-2 font-semibold text-lg">
                <svg viewBox="0 0 64 64" class="w-7 h-7 shrink-0" xmlns="http://www.w3.org/2000/svg">
                    <path d="M32 4 L54 12 V30 C54 46 44 56 32 60 C20 56 10 46 10 30 V12 Z" fill="#2563eb" stroke="#1d4ed8" stroke-width="2"/>
                    <text x="32" y="40" font-family="Arial, sans-serif" font-size="30" font-weight="bold" fill="white" text-anchor="middle">S</text>
                </svg>
                SpoMon
            </span>
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
