<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Папка с входящими СПО XML-файлами
    |--------------------------------------------------------------------------
    |
    | processed/failed — соседние папки, путь к ним вычисляется относительно этой
    | (см. App\Services\Ingestion\SpoFileIngestionService::moveFile()). Вынесено в
    | конфиг, а не захардкожено storage_path('app/spo/incoming') напрямую в
    | App\Livewire\ClientRegistry и App\Console\Commands\SpoIngestCommand, чтобы
    | тесты могли указать изолированную временную директорию через config() и
    | никогда не трогать реальные файлы разработчика на диске — см.
    | tests/Feature/Livewire/ClientRegistryTest.php.
    |
    */
    'incoming_path' => env('SPO_INCOMING_PATH', storage_path('app/spo/incoming')),

];
