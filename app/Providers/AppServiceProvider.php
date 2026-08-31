<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Локально (OpenServer) приложение отдаётся из поддиректории
        // (http://localhost/spomon/public), а не с корня домена. Без принудительного
        // root url Laravel определяет базовый путь по фактическому HTTP-запросу и
        // теряет префикс /spomon/public для url()/route()/asset() — из-за этого
        // Livewire-рантайм (data-update-uri и т.п.) резолвился неверно на любой
        // странице. Статический /livewire/livewire.js (не через route()) публикуется
        // отдельно — см. `php artisan livewire:publish --assets`.
        if (config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }
    }
}
